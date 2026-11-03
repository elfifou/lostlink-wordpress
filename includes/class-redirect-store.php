<?php
/**
 * Built-in redirects.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the redirects created by the plugin and serves them.
 *
 * Used when the Redirection plugin isn't, see Redirect_Service::backend().
 * A redirect only fires on a request WordPress answers with a 404: it can
 * never hide an existing page.
 *
 * Rows are returned as wpdb reads them: numbers as numeric strings.
 *
 * @phpstan-type Redirect_Row array{id: numeric-string, source_hash: string, source: string, target: string, status_code: numeric-string, hits: numeric-string, last_hit: numeric-string|null, created_at: numeric-string, created_by: numeric-string}
 */
class Redirect_Store {

	/**
	 * HTTP status codes a redirect can use.
	 */
	public const STATUS_CODES = array( 301, 302, 307, 308 );

	/**
	 * Priority on template_redirect.
	 *
	 * Before redirect_canonical() (priority 10): an explicit redirect wins
	 * over the permalink WordPress guesses for a 404.
	 */
	public const PRIORITY = 1;

	/**
	 * Columns the redirects list can be sorted by.
	 */
	public const SORTABLE_COLUMNS = array( 'source', 'target', 'hits', 'last_hit', 'created_at' );

	/**
	 * Hooks the redirect serving into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), self::PRIORITY );
	}

	/**
	 * Redirects the current request if it is a 404 with a redirect.
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}

		$uri   = Request::server( 'REQUEST_URI' ) ?? '/';
		$match = $this->match( $uri );
		if ( null === $match ) {
			return;
		}

		$this->count_hit( $match['id'], time() );
		// Not wp_safe_redirect(): external targets are allowed by design, and
		// only users with the settings capability can create them.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		if ( wp_redirect( $match['location'], $match['status_code'], 'Lostlink' ) ) {
			exit;
		}
	}

	/**
	 * Returns the redirect matching a request URI.
	 *
	 * The query string of the request is passed on to the target, so campaign
	 * parameters survive the redirect.
	 *
	 * @param string $uri The request URI.
	 * @return array{id: int, location: string, status_code: int}|null
	 */
	public function match( string $uri ): ?array {
		$redirect = $this->find( Hit_Recorder::decode_path( Hit_Recorder::get_raw_path( $uri ) ) );
		if ( null === $redirect ) {
			return null;
		}

		$location = self::location( $redirect['target'] );
		$query    = (string) wp_parse_url( 'http://x' . strtok( $uri, '#' ), PHP_URL_QUERY );
		if ( '' !== $query ) {
			$fragment = (string) strstr( $location, '#' );
			$location = substr( $location, 0, strlen( $location ) - strlen( $fragment ) );
			$location = $location . ( str_contains( $location, '?' ) ? '&' : '?' ) . $query . $fragment;
		}

		return array(
			'id'          => (int) $redirect['id'],
			'location'    => $location,
			'status_code' => (int) $redirect['status_code'],
		);
	}

	/**
	 * Returns the absolute URL of a target: a path is relative to the site's home.
	 *
	 * @param string $target A path starting with "/", or an absolute URL.
	 */
	public static function location( string $target ): string {
		return str_starts_with( $target, '/' ) ? home_url( $target ) : $target;
	}

	/**
	 * Returns the redirect of a source path.
	 *
	 * @param string $path A decoded path, normalized or not.
	 * @return Redirect_Row|null
	 */
	public function find( string $path ): ?array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/** @var Redirect_Row|null */
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_hash = %s', self::table(), Storage::path_hash( $path ) ), ARRAY_A );
	}

	/**
	 * Returns a redirect by ID.
	 *
	 * @param int $id Redirect ID.
	 * @return Redirect_Row|null
	 */
	public function get( int $id ): ?array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/** @var Redirect_Row|null */
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
	}

	/**
	 * Creates a redirect.
	 *
	 * Validating the target is the caller's job, see Redirect_Service::fix().
	 *
	 * @param string $source      Source path, decoded.
	 * @param string $target      A path relative to the site's home, or an absolute URL.
	 * @param int    $status_code One of STATUS_CODES.
	 * @param int    $user_id     ID of the user creating it.
	 * @return int|\WP_Error The redirect ID.
	 */
	public function create( string $source, string $target, int $status_code = 301, int $user_id = 0 ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( ! in_array( $status_code, self::STATUS_CODES, true ) ) {
			return new \WP_Error( 'lostlink_invalid_status', __( 'Invalid redirect status code.', 'lostlink' ) );
		}
		if ( null !== $this->find( $source ) ) {
			return new \WP_Error( 'lostlink_redirect_exists', __( 'A redirect for this URL already exists.', 'lostlink' ) );
		}

		$source   = Storage::normalize_path( $source );
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'source_hash' => Storage::path_hash( $source ),
				'source'      => $source,
				'target'      => $target,
				'status_code' => $status_code,
				'created_at'  => time(),
				'created_by'  => $user_id,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'lostlink_redirect_failed', __( 'Unable to save the redirect.', 'lostlink' ) );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Changes the target and status code of a redirect.
	 *
	 * The source can't change: it is the broken URL the redirect fixes.
	 * Validating the target is the caller's job, see Redirect_Service::edit().
	 *
	 * @param int    $id          Redirect ID.
	 * @param string $target      A path relative to the site's home, or an absolute URL.
	 * @param int    $status_code One of STATUS_CODES.
	 * @return true|\WP_Error
	 */
	public function update( int $id, string $target, int $status_code ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( ! in_array( $status_code, self::STATUS_CODES, true ) ) {
			return new \WP_Error( 'lostlink_invalid_status', __( 'Invalid redirect status code.', 'lostlink' ) );
		}
		if ( null === $this->get( $id ) ) {
			return new \WP_Error( 'lostlink_unknown_redirect', __( 'This redirect no longer exists.', 'lostlink' ) );
		}

		// 0 rows changed is fine: the redirect was saved unchanged.
		$updated = $wpdb->update(
			self::table(),
			array(
				'target'      => $target,
				'status_code' => $status_code,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new \WP_Error( 'lostlink_redirect_failed', __( 'Unable to save the redirect.', 'lostlink' ) );
		}

		return true;
	}

	/**
	 * Deletes redirects.
	 *
	 * @param int[] $ids Redirect IDs.
	 * @return list<Redirect_Row> The deleted redirects.
	 */
	public function delete( array $ids ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$deleted = array();
		foreach ( array_unique( $ids ) as $id ) {
			$redirect = $this->get( $id );
			if ( null !== $redirect && 1 === $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ) ) {
				$deleted[] = $redirect;
			}
		}
		return $deleted;
	}

	/**
	 * Counts a hit on a redirect.
	 *
	 * @param int $id   Redirect ID.
	 * @param int $time Timestamp of the hit.
	 */
	public function count_hit( int $id, int $time ): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %d WHERE id = %d', self::table(), $time, $id ) );
	}

	/**
	 * Returns the redirects, filtered, sorted and paginated.
	 *
	 * @param array{search?: string, orderby?: string, order?: string, limit?: int, offset?: int} $args Query arguments:
	 *     search: substring of the source or target; orderby: a column of
	 *     SORTABLE_COLUMNS; order: "asc" or "desc"; limit: maximum number of
	 *     rows, 0 for all; offset: rows skipped.
	 * @return list<Redirect_Row>
	 */
	public function query( array $args = array() ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$orderby = in_array( $args['orderby'] ?? '', self::SORTABLE_COLUMNS, true ) ? $args['orderby'] : 'created_at';
		$order   = 'asc' === strtolower( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$limit   = $args['limit'] ?? 0;
		$limit   = $limit > 0 ? $wpdb->prepare( ' LIMIT %d, %d', max( 0, $args['offset'] ?? 0 ), $limit ) : '';

		/** @var list<Redirect_Row> */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted and prepared above.
		return $wpdb->get_results( "SELECT * FROM {$this->where( $args )} ORDER BY $orderby $order, id DESC$limit", ARRAY_A );
	}

	/**
	 * Counts the redirects matching the filters of query().
	 *
	 * @param array{search?: string} $args Query arguments, see query().
	 */
	public function count( array $args = array() ): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in where().
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->where( $args )}" );
	}

	/**
	 * Returns the prepared "table WHERE ..." clause of the list queries.
	 *
	 * @param array{search?: string} $args Query arguments, see query().
	 */
	protected function where( array $args ): string {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$sql = $wpdb->prepare( '%i WHERE 1 = 1', self::table() );
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql .= $wpdb->prepare( ' AND (source LIKE %s OR target LIKE %s)', $like, $like );
		}
		return $sql;
	}

	/**
	 * Returns the redirects table name.
	 */
	protected static function table(): string {
		return Storage::tables()['redirects'];
	}
}
