<?php
/**
 * Storage of the 404 hits.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin tables.
 *
 * Three tables, keyed by the hash of the normalized path:
 * - paths: one row per broken URL, with its counters and state;
 * - referers: the pages linking to each broken URL, with their own count;
 * - daily: hits per URL and per day, for the trend.
 *
 * Rows are returned as wpdb reads them: numbers as numeric strings.
 *
 * @phpstan-type Path_Row array{path_hash: string, path: string, count: numeric-string, daily_count: numeric-string, week_count: numeric-string, first_seen: numeric-string, last_seen: numeric-string, fixed: '0'|'1', fixed_at: numeric-string|null, ignored: '0'|'1'}
 * @phpstan-type Path_Record array{path_hash: string, path: string, count: numeric-string, daily_count: numeric-string, week_count: numeric-string, first_seen: numeric-string, last_seen: numeric-string, fixed: '0'|'1', fixed_at: numeric-string|null, fixed_redirect: '0'|'1', ignored: '0'|'1'}
 * @phpstan-type Referer_Row array{referer: string, count: numeric-string, last_seen: numeric-string}
 */
class Storage {
	/**
	 * Number of days of daily history kept, the days the trend shows.
	 */
	public const HISTORY_DAYS = 14;

	/**
	 * Maximum number of broken URLs deleted by one purge run.
	 */
	public const PURGE_BATCH_SIZE = 1000;

	/**
	 * Seconds after which a purge run starts no new batch of URLs, to stay
	 * below the usual PHP timeouts of a cron request. The next run goes on.
	 */
	public const PURGE_TIME_LIMIT = 20.0;

	/**
	 * Columns the dashboard can be sorted by.
	 */
	public const SORTABLE_COLUMNS = array( 'path', 'last_seen', 'count', 'daily_count', 'week_count' );

	/**
	 * Returns the table names, with the site prefix.
	 *
	 * @return array{paths: string, referers: string, daily: string, redirects: string}
	 */
	public static function tables(): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		return array(
			'paths'     => $wpdb->prefix . 'lostlink',
			'referers'  => $wpdb->prefix . 'lostlink_referers',
			'daily'     => $wpdb->prefix . 'lostlink_daily',
			'redirects' => $wpdb->prefix . 'lostlink_redirects',
		);
	}

	/**
	 * Normalizes a path, so its variants are counted as one broken URL.
	 *
	 * Lowercased, and without trailing slash: "/Old-Page/" and "/old-page"
	 * are the same broken link for the editor fixing it.
	 *
	 * @param string $path A decoded path, starting with "/".
	 */
	public static function normalize_path( string $path ): string {
		$path = mb_strtolower( $path, 'UTF-8' );
		return '/' === $path ? $path : untrailingslashit( $path );
	}

	/**
	 * Returns the hash identifying a path.
	 *
	 * @param string $path A decoded path, normalized or not.
	 */
	public static function path_hash( string $path ): string {
		return hash( 'sha256', self::normalize_path( $path ) );
	}

	/**
	 * Records one 404 hit.
	 *
	 * Hits on a URL marked as fixed are not counted. An ignored URL keeps
	 * counting, so its history is accurate if it is restored later.
	 *
	 * A URL fixed by a redirect that returns a 404 again has lost its
	 * redirect (deleted or disabled in the Redirection plugin, Redirection
	 * deactivated...): with $reopen, it is set back to unfixed and counted.
	 * Only for the hits happening now: an imported hit can predate the fix.
	 *
	 * @param string      $path    The decoded path that returned a 404.
	 * @param int         $time    Timestamp of the hit.
	 * @param string|null $referer The Referer header, if any.
	 * @param bool        $reopen  Whether a URL fixed by a redirect is reopened.
	 * @return bool Whether the hit was counted.
	 */
	public function record_hit( string $path, int $time, ?string $referer = null, bool $reopen = false ): bool {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$tables = self::tables();
		$path   = self::normalize_path( $path );
		$hash   = self::path_hash( $path );

		/** @var array{fixed: '0'|'1', fixed_redirect: '0'|'1'}|null $state */
		$state = $wpdb->get_row( $wpdb->prepare( 'SELECT fixed, fixed_redirect FROM %i WHERE path_hash = %s', $tables['paths'], $hash ), ARRAY_A );
		if ( null !== $state && '1' === $state['fixed'] ) {
			if ( ! $reopen || '1' !== $state['fixed_redirect'] ) {
				return false;
			}
			$this->set_state( array( $hash ), 'unfixed' );
		}

		$local = self::local_date( $time );

		// One atomic statement, so concurrent hits on the same URL can't lose
		// counts. The counters are reset when the previous hit happened before
		// the current day (or week): MySQL evaluates the assignments in order,
		// so they read last_seen before it is updated. That assumes hits in
		// chronological order: an import of older hits must then call
		// recompute_counters().
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (path_hash, path, count, daily_count, week_count, first_seen, last_seen)
				VALUES (%s, %s, 1, 1, 1, %d, %d)
				ON DUPLICATE KEY UPDATE
					count = count + 1,
					daily_count = IF(last_seen < %d, 1, daily_count + 1),
					week_count = IF(last_seen < %d, 1, week_count + 1),
					first_seen = LEAST(first_seen, %d),
					last_seen = GREATEST(last_seen, %d)',
				$tables['paths'],
				$hash,
				$path,
				$time,
				$time,
				$local->setTime( 0, 0 )->getTimestamp(),
				$local->modify( 'monday this week' )->setTime( 0, 0 )->getTimestamp(),
				$time,
				$time
			)
		);
		if ( false === $result ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (path_hash, day, hits) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
				$tables['daily'],
				$hash,
				$local->format( 'Y-m-d' )
			)
		);

		if ( null !== $referer && '' !== $referer ) {
			$this->record_referer( $hash, $referer, $time );
		}

		return true;
	}

	/**
	 * Counts a referer of a broken URL, within the per-URL referer limit.
	 *
	 * @param string $hash    Path hash.
	 * @param string $referer The Referer header.
	 * @param int    $time    Timestamp of the hit.
	 */
	protected function record_referer( string $hash, string $referer, int $time ): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$table        = self::tables()['referers'];
		$referer_hash = hash( 'sha256', $referer );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET count = count + 1, last_seen = GREATEST(last_seen, %d) WHERE path_hash = %s AND referer_hash = %s',
				$table,
				$time,
				$hash,
				$referer_hash
			)
		);
		if ( 0 !== $updated || ! $this->can_add_referer( $hash ) ) {
			return;
		}

		// IGNORE: a concurrent request may have inserted the same referer.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (path_hash, referer_hash, referer, count, last_seen) VALUES (%s, %s, %s, 1, %d)',
				$table,
				$hash,
				$referer_hash,
				$referer,
				$time
			)
		);
	}

	/**
	 * Whether one more referer can be stored for a broken URL.
	 *
	 * @param string $hash Path hash.
	 */
	protected function can_add_referer( string $hash ): bool {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$limit = Settings::all()['referer_limit'];
		if ( $limit <= 0 ) {
			return true;
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE path_hash = %s', self::tables()['referers'], $hash ) );
		return $count < $limit;
	}

	/**
	 * Returns a broken URL record.
	 *
	 * @param string $path A decoded path, normalized or not.
	 * @return Path_Record|null
	 */
	public function get_path( string $path ): ?array {
		return $this->get_by_hash( self::path_hash( $path ) );
	}

	/**
	 * Returns a broken URL record by its path hash, with the stored counters.
	 *
	 * @param string $hash Path hash.
	 * @return Path_Record|null
	 */
	public function get_by_hash( string $hash ): ?array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/** @var Path_Record|null */
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE path_hash = %s', self::tables()['paths'], $hash ), ARRAY_A );
	}

	/**
	 * Returns the broken URLs listed on the dashboard: not fixed, filtered by
	 * status and search, sorted and paginated.
	 *
	 * @param array{search?: string, status?: string, orderby?: string, order?: string, limit?: int, offset?: int, now?: int} $args Query arguments:
	 *     search: substring of the path; status: "active" or "ignored";
	 *     orderby: a column of SORTABLE_COLUMNS; order: "asc" or "desc";
	 *     limit: maximum number of rows, 0 for all; offset: rows skipped;
	 *     now: timestamp the daily and weekly counters are read at.
	 * @return list<Path_Row>
	 */
	public function query_paths( array $args = array() ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$orderby = in_array( $args['orderby'] ?? '', self::SORTABLE_COLUMNS, true ) ? $args['orderby'] : 'last_seen';
		$order   = 'asc' === strtolower( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$limit   = $args['limit'] ?? 0;
		$limit   = $limit > 0 ? $wpdb->prepare( ' LIMIT %d, %d', max( 0, $args['offset'] ?? 0 ), $limit ) : '';

		// $orderby and $order are whitelisted above; path_hash makes the order
		// stable across pages when the sorted values are equal.
		/** @var list<Path_Row> */
		return $wpdb->get_results( "SELECT {$this->columns( $args['now'] ?? time() )} FROM {$this->dashboard_where( $args )} ORDER BY $orderby $order, path_hash ASC$limit", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the prepared column list of the paths table, with the daily and
	 * weekly counters as they are at a given time.
	 *
	 * Stored counters are only reset by the next hit or by the hourly cron:
	 * a URL last seen yesterday still stores yesterday's daily count. Reading
	 * them through this list shows and sorts 0 for it, without waiting.
	 *
	 * @param int $now Timestamp the counters are read at.
	 */
	protected function columns( int $now ): string {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$local = self::local_date( $now );
		return $wpdb->prepare(
			'path_hash, path, count, first_seen, last_seen, fixed, fixed_at, ignored,
			IF(last_seen < %d, 0, daily_count) AS daily_count,
			IF(last_seen < %d, 0, week_count) AS week_count',
			$local->setTime( 0, 0 )->getTimestamp(),
			$local->modify( 'monday this week' )->setTime( 0, 0 )->getTimestamp()
		);
	}

	/**
	 * Counts the broken URLs matching the dashboard filters of query_paths().
	 *
	 * @param array{search?: string, status?: string} $args Query arguments, see query_paths().
	 */
	public function count_paths( array $args = array() ): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->dashboard_where( $args )}" );
	}

	/**
	 * Returns the prepared "table WHERE ..." clause of the dashboard queries.
	 *
	 * @param array{search?: string, status?: string} $args Query arguments, see query_paths().
	 */
	protected function dashboard_where( array $args ): string {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$ignored = isset( $args['status'] ) && 'ignored' === $args['status'];
		$sql     = $wpdb->prepare( '%i WHERE fixed = 0 AND ignored = %d', self::tables()['paths'], (int) $ignored );
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$sql .= $wpdb->prepare( ' AND path LIKE %s', '%' . $wpdb->esc_like( $args['search'] ) . '%' );
		}
		return $sql;
	}

	/**
	 * Sets the state of broken URLs.
	 *
	 * @param string[] $hashes Path hashes.
	 * @param string   $state  "fixed" (manually), "fixed_redirect" (by a redirect), "unfixed",
	 *                          "ignored" or "active" (not ignored).
	 * @param int|null $time   Timestamp the URLs are marked as fixed at, now by default.
	 * @return int Number of URLs updated.
	 *
	 * @phpstan-param 'fixed'|'fixed_redirect'|'unfixed'|'ignored'|'active' $state
	 */
	public function set_state( array $hashes, string $state, ?int $time = null ): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$fields = match ( $state ) {
			'fixed'          => array(
				'fixed'    => 1,
				'fixed_at' => $time ?? time(),
			),
			'fixed_redirect' => array(
				'fixed'          => 1,
				'fixed_redirect' => 1,
				'fixed_at'       => $time ?? time(),
			),
			'unfixed'        => array(
				'fixed'          => 0,
				'fixed_redirect' => 0,
				'fixed_at'       => null,
			),
			'ignored'        => array( 'ignored' => 1 ),
			'active'         => array( 'ignored' => 0 ),
		};

		$updated = 0;
		foreach ( array_unique( $hashes ) as $hash ) {
			$updated += (int) $wpdb->update( self::tables()['paths'], $fields, array( 'path_hash' => $hash ) );
		}
		return $updated;
	}

	/**
	 * Returns the broken URLs, most hit first.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return list<Path_Row>
	 */
	public function list_paths( int $limit = 50 ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$from = $wpdb->prepare( '%i ORDER BY count DESC, last_seen DESC LIMIT %d', self::tables()['paths'], $limit );
		/** @var list<Path_Row> */
		return $wpdb->get_results( "SELECT {$this->columns( time() )} FROM $from", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both parts are prepared.
	}

	/**
	 * Returns the referers of a broken URL, most frequent first.
	 *
	 * @param string $path A decoded path, normalized or not.
	 * @return list<Referer_Row>
	 */
	public function list_referers( string $path ): array {
		return $this->get_referers( self::path_hash( $path ) );
	}

	/**
	 * Returns the referers of a broken URL by path hash, most frequent first.
	 *
	 * @param string $hash   Path hash.
	 * @param int    $limit  Maximum number of referers, 0 for all.
	 * @param int    $offset Number of referers skipped.
	 * @return list<Referer_Row>
	 */
	public function get_referers( string $hash, int $limit = 0, int $offset = 0 ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$sql = $wpdb->prepare( 'SELECT referer, count, last_seen FROM %i WHERE path_hash = %s ORDER BY count DESC, last_seen DESC, referer_hash', self::tables()['referers'], $hash );
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d, %d', max( 0, $offset ), $limit );
		}
		/** @var list<Referer_Row> */
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Counts the referers of broken URLs.
	 *
	 * Referers are attacker-controlled (any visitor can send any Referer
	 * header): the dashboard counts them in SQL and only loads the few it
	 * shows inline.
	 *
	 * @param string[] $hashes Path hashes.
	 * @return array<string, int> Number of referers, keyed by path hash (missing when none).
	 */
	public function count_referers( array $hashes ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( array() === $hashes ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		/** @var list<array{path_hash: string, total: numeric-string}> $rows */
		$rows   = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- $in holds one %s per hash.
				'SELECT path_hash, COUNT(*) AS total FROM %i WHERE path_hash IN (' . $in . ') GROUP BY path_hash', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::tables()['referers'],
				...array_values( $hashes )
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['path_hash'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Returns the daily hits of a broken URL, keyed by "Y-m-d".
	 *
	 * @param string $path A decoded path, normalized or not.
	 * @return array<string, int>
	 */
	public function get_daily_hits( string $path ): array {
		$hash = self::path_hash( $path );
		return $this->get_daily_history( array( $hash ) )[ $hash ] ?? array();
	}

	/**
	 * Returns the daily hits of broken URLs.
	 *
	 * @param string[]    $hashes Path hashes.
	 * @param string|null $since  First day returned ("Y-m-d"), all by default.
	 * @return array<string, array<string, int>> Hits keyed by path hash then by day, in day order.
	 */
	public function get_daily_history( array $hashes, ?string $since = null ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( array() === $hashes ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		/** @var list<array{path_hash: string, day: string, hits: numeric-string}> $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- $in holds one %s per hash.
				'SELECT path_hash, day, hits FROM %i WHERE day >= %s AND path_hash IN (' . $in . ') ORDER BY day', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::tables()['daily'],
				$since ?? '0000-00-00',
				...array_values( $hashes )
			),
			ARRAY_A
		);

		$history = array();
		foreach ( $rows as $row ) {
			$history[ $row['path_hash'] ][ $row['day'] ] = (int) $row['hits'];
		}
		return $history;
	}

	/**
	 * Resets the stale daily and weekly counters, run by cron.
	 *
	 * A counter is stale when its URL was last seen before the start of the
	 * current day (or week). The next hit resets it too, see record_hit():
	 * this keeps the counters right for the URLs that are no longer hit.
	 *
	 * @param int $now Current timestamp.
	 */
	public function reset_counters( int $now ): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$table = self::tables()['paths'];
		$local = self::local_date( $now );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET daily_count = 0 WHERE last_seen < %d AND daily_count <> 0', $table, $local->setTime( 0, 0 )->getTimestamp() ) );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET week_count = 0 WHERE last_seen < %d AND week_count <> 0', $table, $local->modify( 'monday this week' )->setTime( 0, 0 )->getTimestamp() ) );
	}

	/**
	 * Recomputes the daily and weekly counters of URLs from their daily history.
	 *
	 * For hits recorded out of chronological order (imports), which
	 * record_hit() would add to the counters of the last day seen. The
	 * counters are those of the day and week of the last hit, as record_hit()
	 * keeps them; beyond the history kept, they are 0.
	 *
	 * @param string[] $hashes Path hashes.
	 */
	public function recompute_counters( array $hashes ): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$tables = self::tables();
		foreach ( $hashes as $hash ) {
			$last_seen = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM %i WHERE path_hash = %s', $tables['paths'], $hash ) );
			if ( null === $last_seen ) {
				continue;
			}

			$local = self::local_date( (int) $last_seen );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET
						daily_count = (SELECT COALESCE(SUM(hits), 0) FROM %i WHERE path_hash = %s AND day = %s),
						week_count = (SELECT COALESCE(SUM(hits), 0) FROM %i WHERE path_hash = %s AND day BETWEEN %s AND %s)
					WHERE path_hash = %s',
					$tables['paths'],
					$tables['daily'],
					$hash,
					$local->format( 'Y-m-d' ),
					$tables['daily'],
					$hash,
					$local->modify( 'monday this week' )->format( 'Y-m-d' ),
					$local->format( 'Y-m-d' ),
					$hash
				)
			);
		}//end foreach
	}

	/**
	 * Purges the data beyond the retention settings, run by cron.
	 *
	 * The tables are filled by anonymous traffic, so they must not grow
	 * without limit: removes the daily history the trend no longer shows,
	 * the referers (which can contain personal data) not seen for a while,
	 * the referers beyond the per-URL cap, and the least recently seen
	 * broken URLs beyond the row limit.
	 *
	 * @param int $now Current timestamp.
	 * @return array{history: int, referers: int, urls: int} Number of rows deleted, by kind.
	 */
	public function purge( int $now ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$tables   = self::tables();
		$settings = Settings::all();
		$deleted  = array(
			'history'  => 0,
			'referers' => 0,
			'urls'     => 0,
		);

		$oldest_day = self::local_date( $now )->modify( '-' . ( self::HISTORY_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		$deleted['history'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', $tables['daily'], $oldest_day ) );

		$retention_days = $settings['referer_retention_days'];
		if ( $retention_days > 0 ) {
			$deleted['referers'] += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %d', $tables['referers'], $now - $retention_days * DAY_IN_SECONDS ) );
		}

		// Referers beyond the per-URL cap, for instance recorded before the
		// cap was lowered: keep the most recently seen ones.
		$referer_limit = $settings['referer_limit'];
		if ( $referer_limit > 0 ) {
			$hashes = $wpdb->get_col( $wpdb->prepare( 'SELECT path_hash FROM %i GROUP BY path_hash HAVING COUNT(*) > %d LIMIT %d', $tables['referers'], $referer_limit, self::PURGE_BATCH_SIZE ) );
			foreach ( $hashes as $hash ) {
				$extra = $wpdb->get_col( $wpdb->prepare( 'SELECT referer_hash FROM %i WHERE path_hash = %s ORDER BY last_seen DESC LIMIT %d, 18446744073709551615', $tables['referers'], $hash, $referer_limit ) );
				foreach ( $extra as $referer_hash ) {
					$deleted['referers'] += (int) $wpdb->delete(
						$tables['referers'],
						array(
							'path_hash'    => $hash,
							'referer_hash' => $referer_hash,
						)
					);
				}
			}
		}

		// Broken URLs beyond the row limit, least recently seen first. In
		// batches until back under the limit: bots probing random URLs can
		// add far more than one batch per hour.
		$row_limit = $settings['row_limit'];
		if ( $row_limit > 0 ) {
			$start = microtime( true );
			do {
				/** @var list<string> $hashes */
				$hashes           = $wpdb->get_col( $wpdb->prepare( 'SELECT path_hash FROM %i ORDER BY last_seen DESC, path_hash LIMIT %d, %d', $tables['paths'], $row_limit, self::PURGE_BATCH_SIZE ) );
				$deleted['urls'] += $this->delete_by_hashes( $hashes );
				$full_batch       = count( $hashes ) === self::PURGE_BATCH_SIZE;
			} while ( $full_batch && microtime( true ) - $start < self::PURGE_TIME_LIMIT );
		}

		return $deleted;
	}

	/**
	 * Deletes a broken URL with its referers and history.
	 *
	 * @param string $hash Path hash.
	 */
	public function delete_by_hash( string $hash ): void {
		$this->delete_by_hashes( array( $hash ) );
	}

	/**
	 * Deletes broken URLs with their referers and history.
	 *
	 * @param string[] $hashes Path hashes.
	 * @return int Number of URLs deleted.
	 */
	public function delete_by_hashes( array $hashes ): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$tables  = self::tables();
		$deleted = 0;
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), self::PURGE_BATCH_SIZE ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( array( $tables['paths'], $tables['referers'], $tables['daily'] ) as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $in holds one %s per hash.
				$rows = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE path_hash IN (' . $in . ')', $table, ...$chunk ) );
				if ( $tables['paths'] === $table ) {
					$deleted += $rows;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Returns a timestamp as a date in the site's timezone.
	 *
	 * Days and weeks follow the site's timezone, not UTC (WordPress's PHP
	 * default timezone).
	 *
	 * @param int $time Timestamp.
	 */
	public static function local_date( int $time ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( wp_timezone() );
	}
}
