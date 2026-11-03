<?php
/**
 * Import of the Redirection plugin's 404 log.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the 404s logged by the Redirection plugin before this one was installed.
 *
 * Redirection logs one row per 404 in {prefix}redirection_404 (kept 7 days
 * by default). Only the rows older than Installer::INSTALLED_AT_OPTION are
 * imported, as the later ones were recorded by this plugin too. Each row
 * goes through Hit_Recorder, so the asset and exclusion filters apply and
 * fixed URLs are skipped. Works from the table alone: Redirection can be
 * deactivated.
 *
 * The import runs in batches by increasing row ID and remembers the last
 * one, so it can be interrupted and resumed, and never imports a row twice.
 */
class Redirection_Importer {

	/**
	 * Option holding the ID of the last imported row.
	 */
	public const STATE_OPTION = 'lostlink_redirection_import';

	/**
	 * Option locking the import, holding when the lock was taken.
	 */
	public const LOCK_OPTION = 'lostlink_redirection_import_lock';

	/**
	 * Seconds after which a lock is considered left by a crashed import.
	 */
	private const LOCK_TIMEOUT = 10 * MINUTE_IN_SECONDS;

	/**
	 * Rows read per batch.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param Hit_Recorder $recorder Records the imported hits.
	 * @param Storage      $storage  The hits storage.
	 */
	public function __construct( protected Hit_Recorder $recorder, protected Storage $storage ) {
	}

	/**
	 * Returns an importer recording into the plugin storage.
	 */
	public static function create_default(): self {
		$storage = new Storage();
		return new self( new Hit_Recorder( $storage ), $storage );
	}

	/**
	 * Returns the name of Redirection's 404 log table.
	 */
	public static function table(): string {
		/** @var \wpdb $wpdb */
		global $wpdb;
		return $wpdb->prefix . 'redirection_404';
	}

	/**
	 * Whether Redirection's 404 log table exists.
	 */
	public function is_available(): bool {
		/** @var \wpdb $wpdb */
		global $wpdb;
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
	}

	/**
	 * Returns the number of rows left to import.
	 */
	public function count_pending(): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( ! $this->is_available() ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND created < %s', self::table(), self::last_id(), self::cutoff() )
		);
	}

	/**
	 * Returns the dates of the oldest and newest rows left to import.
	 *
	 * @return array{0: int, 1: int}|null Timestamps, null if none is left.
	 */
	public function pending_range(): ?array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		if ( ! $this->is_available() ) {
			return null;
		}
		/** @var array{oldest: string|null, newest: string|null}|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT MIN(created) AS oldest, MAX(created) AS newest FROM %i WHERE id > %d AND created < %s', self::table(), self::last_id(), self::cutoff() ),
			ARRAY_A
		);
		if ( null === $row || null === $row['oldest'] || null === $row['newest'] ) {
			return null;
		}
		return array( self::timestamp( $row['oldest'] ), self::timestamp( $row['newest'] ) );
	}

	/**
	 * Imports the rows left, until done or out of time.
	 *
	 * @param float|null $time_limit Seconds after which no new batch starts, null for no limit.
	 * @return array{read: int, imported: int, remaining: int}|\WP_Error An error if another import is running.
	 */
	public function import( ?float $time_limit = null ) {
		if ( ! $this->lock() ) {
			return self::locked_error();
		}

		$start  = microtime( true );
		$totals = array(
			'read'     => 0,
			'imported' => 0,
		);
		try {
			if ( $this->is_available() ) {
				do {
					$batch               = $this->import_batch();
					$totals['read']     += $batch['read'];
					$totals['imported'] += $batch['imported'];
				} while ( self::BATCH_SIZE === $batch['read'] && ( null === $time_limit || microtime( true ) - $start < $time_limit ) );
			}
		} finally {
			$this->unlock();
		}

		$totals['remaining'] = $this->count_pending();
		return $totals;
	}

	/**
	 * Takes the import lock, so two imports running at once (two clicks, the
	 * dashboard and WP-CLI) can't read the same rows and count them twice.
	 *
	 * Atomic, like WP_Upgrader::create_lock(): add_option() is not. A lock
	 * older than LOCK_TIMEOUT was left by a crashed import and is taken over.
	 *
	 * @return bool Whether the lock was taken.
	 */
	public function lock(): bool {
		/** @var \wpdb $wpdb */
		global $wpdb;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::LOCK_OPTION, (string) time() ) );
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			if ( 1 === $inserted ) {
				return true;
			}

			$since = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) );
			if ( is_numeric( $since ) && (int) $since > time() - self::LOCK_TIMEOUT ) {
				return false;
			}
			$this->unlock();
		}
		return false;
	}

	/**
	 * Releases the import lock.
	 */
	public function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Returns the error of an import refused because another one is running.
	 */
	public static function locked_error(): \WP_Error {
		return new \WP_Error( 'lostlink_import_locked', __( 'Another import of the Redirection 404 log is running. Try again in a few minutes.', 'lostlink' ) );
	}

	/**
	 * Imports the next batch of rows. The caller holds the lock, see lock().
	 *
	 * @return array{read: int, imported: int} Rows read, and hits counted (the
	 *                                         others were filtered out or on a fixed URL).
	 */
	public function import_batch(): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/** @var list<array{id: numeric-string, created: string, url: string, referrer: string|null}> $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created, url, referrer FROM %i WHERE id > %d AND created < %s ORDER BY id LIMIT %d',
				self::table(),
				self::last_id(),
				self::cutoff(),
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		$hashes   = array();
		$imported = 0;
		foreach ( $rows as $row ) {
			// Redirection stores the request URI decoded: decoding it again
			// only changes the rare paths with a literal "%".
			$url     = $row['url'];
			$referer = $row['referrer'] ?? '';
			if ( '' !== $url && $this->recorder->record( $url, '' !== $referer ? $referer : null, self::timestamp( $row['created'] ) ) ) {
				$hashes[ Storage::path_hash( Hit_Recorder::decode_path( Hit_Recorder::get_raw_path( $url ) ) ) ] = true;
				++$imported;
			}
		}

		// The imported hits are older than the ones already recorded.
		$this->storage->recompute_counters( array_keys( $hashes ) );
		if ( array() !== $rows ) {
			update_option( self::STATE_OPTION, (int) $rows[ count( $rows ) - 1 ]['id'], false );
		}

		return array(
			'read'     => count( $rows ),
			'imported' => $imported,
		);
	}

	/**
	 * Returns the ID of the last imported row.
	 */
	protected static function last_id(): int {
		$id = get_option( self::STATE_OPTION, 0 );
		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * Returns the date before which rows are imported, in Redirection's format.
	 *
	 * Redirection stores the dates in the site timezone (current_time( 'mysql' )).
	 * Without the option (never installed), nothing is imported.
	 */
	protected static function cutoff(): string {
		$installed_at = get_option( Installer::INSTALLED_AT_OPTION, 0 );
		return is_numeric( $installed_at ) && (int) $installed_at > 0 ? (string) wp_date( 'Y-m-d H:i:s', (int) $installed_at ) : '0000-00-00 00:00:00';
	}

	/**
	 * Converts a date of Redirection's log to a timestamp.
	 *
	 * @param string $date "Y-m-d H:i:s" in the site timezone.
	 */
	protected static function timestamp( string $date ): int {
		try {
			return ( new \DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return time();
		}
	}
}
