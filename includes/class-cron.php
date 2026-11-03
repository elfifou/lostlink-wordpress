<?php
/**
 * Scheduled maintenance.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Resets the stale counters and purges old data, every hour.
 */
final class Cron {

	/**
	 * Cron hook name.
	 */
	public const HOOK = 'lostlink_maintenance';

	/**
	 * Hooks the maintenance task, and schedules it if it went missing.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the maintenance task, if not already scheduled.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/**
	 * Unschedules the maintenance task.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the maintenance task, the cron hook callback.
	 */
	public static function run_scheduled(): void {
		self::run();
	}

	/**
	 * Runs the maintenance task.
	 *
	 * @return array{history: int, referers: int, urls: int} Number of rows purged, by kind.
	 */
	public static function run(): array {
		$storage = new Storage();
		$now     = time();

		$storage->reset_counters( $now );
		return $storage->purge( $now );
	}
}
