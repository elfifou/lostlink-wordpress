<?php
/**
 * Tests of the hits storage.
 *
 * @package Lostlink
 */

use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Storage
 */
class Test_Storage extends WP_UnitTestCase {

	protected Storage $storage;

	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Paris' );
		$this->storage = new Storage();
	}

	/**
	 * Returns a timestamp for a local date and time of the site.
	 *
	 * @param string $datetime Date and time, in the site's timezone.
	 */
	protected static function local_time( string $datetime ): int {
		return ( new DateTimeImmutable( $datetime, wp_timezone() ) )->getTimestamp();
	}

	public function test_normalize_path() {
		$this->assertSame( '/old-page', Storage::normalize_path( '/Old-Page/' ) );
		$this->assertSame( '/', Storage::normalize_path( '/' ) );
		$this->assertSame( '/éte', Storage::normalize_path( '/ÉTE' ) );
		$this->assertSame( Storage::path_hash( '/a/' ), Storage::path_hash( '/A' ) );
	}

	public function test_first_hit_creates_the_record() {
		$time = self::local_time( '2026-10-21 10:00' );
		$this->assertTrue( $this->storage->record_hit( '/Old-Page/', $time ) );

		$row = $this->storage->get_path( '/old-page' );
		$this->assertSame( '/old-page', $row['path'] );
		$this->assertSame( array( '1', '1', '1' ), array( $row['count'], $row['daily_count'], $row['week_count'] ) );
		$this->assertSame( (string) $time, $row['first_seen'] );
		$this->assertSame( (string) $time, $row['last_seen'] );
	}

	public function test_daily_and_weekly_counters_reset_on_local_calendar_boundaries() {
		// Wednesday 2026-10-21, then the same day, then just after local midnight.
		$this->storage->record_hit( '/x', self::local_time( '2026-10-21 10:00' ) );
		$this->storage->record_hit( '/x', self::local_time( '2026-10-21 23:30' ) );
		$this->storage->record_hit( '/x', self::local_time( '2026-10-22 00:10' ) );
		$row = $this->storage->get_path( '/x' );
		$this->assertSame( array( '3', '1', '3' ), array( $row['count'], $row['daily_count'], $row['week_count'] ) );

		// Next Monday: new week.
		$this->storage->record_hit( '/x', self::local_time( '2026-10-26 08:00' ) );
		$row = $this->storage->get_path( '/x' );
		$this->assertSame( array( '4', '1', '1' ), array( $row['count'], $row['daily_count'], $row['week_count'] ) );
	}

	public function test_daily_history() {
		$this->storage->record_hit( '/x', self::local_time( '2026-10-21 10:00' ) );
		$this->storage->record_hit( '/x', self::local_time( '2026-10-21 11:00' ) );
		// 23:30 UTC is already the next day in Paris.
		$this->storage->record_hit( '/x', ( new DateTimeImmutable( '2026-10-21 23:30', new DateTimeZone( 'UTC' ) ) )->getTimestamp() );

		$this->assertSame(
			array(
				'2026-10-21' => 2,
				'2026-10-22' => 1,
			),
			$this->storage->get_daily_hits( '/x' )
		);
	}

	public function test_referers_are_counted_per_url() {
		$time = self::local_time( '2026-10-21 10:00' );
		$this->storage->record_hit( '/x', $time, 'https://a.example/' );
		$this->storage->record_hit( '/x', $time + 60, 'https://a.example/' );
		$this->storage->record_hit( '/x', $time, 'https://b.example/' );
		$this->storage->record_hit( '/x', $time );
		$this->storage->record_hit( '/y', $time, 'https://a.example/' );

		$referers = $this->storage->list_referers( '/x' );
		$this->assertSame( array( 'https://a.example/', 'https://b.example/' ), array_column( $referers, 'referer' ) );
		$this->assertSame( array( '2', '1' ), array_column( $referers, 'count' ) );
		$this->assertSame( (string) ( $time + 60 ), $referers[0]['last_seen'] );
	}

	public function test_referer_limit() {
		update_option( Settings::OPTION, array( 'referer_limit' => 2 ) );
		$time = self::local_time( '2026-10-21 10:00' );

		foreach ( array( 'a', 'b', 'c', 'a' ) as $referer ) {
			$this->storage->record_hit( '/x', $time, "https://$referer.example/" );
		}

		$referers = $this->storage->list_referers( '/x' );
		$this->assertSame( array( 'https://a.example/', 'https://b.example/' ), array_column( $referers, 'referer' ) );
		// Known referers keep counting once the limit is reached.
		$this->assertSame( '2', $referers[0]['count'] );
		// The hit itself is always counted.
		$this->assertSame( '4', $this->storage->get_path( '/x' )['count'] );
	}

	public function test_fixed_urls_stop_counting_ignored_ones_keep_counting() {
		global $wpdb;

		$time = self::local_time( '2026-10-21 10:00' );
		$this->storage->record_hit( '/fixed', $time );
		$this->storage->record_hit( '/ignored', $time );
		$table = Storage::tables()['paths'];
		$wpdb->update( $table, array( 'fixed' => 1 ), array( 'path_hash' => Storage::path_hash( '/fixed' ) ) );
		$wpdb->update( $table, array( 'ignored' => 1 ), array( 'path_hash' => Storage::path_hash( '/ignored' ) ) );

		$this->assertFalse( $this->storage->record_hit( '/fixed', $time + 60 ) );
		$this->assertTrue( $this->storage->record_hit( '/ignored', $time + 60 ) );

		$this->assertSame( '1', $this->storage->get_path( '/fixed' )['count'] );
		$this->assertSame( '2', $this->storage->get_path( '/ignored' )['count'] );
	}

	public function test_a_live_404_reopens_a_url_whose_redirect_is_gone() {
		$time = self::local_time( '2026-10-21 10:00' );
		$this->storage->record_hit( '/redirected', $time );
		$this->storage->record_hit( '/manual', $time );
		$this->storage->set_state( array( Storage::path_hash( '/redirected' ) ), 'fixed_redirect' );
		$this->storage->set_state( array( Storage::path_hash( '/manual' ) ), 'fixed' );

		// Imported hits may predate the fix: never reopen.
		$this->assertFalse( $this->storage->record_hit( '/redirected', $time + 60 ) );
		// Fixed by hand: kept fixed, as in the Drupal module.
		$this->assertFalse( $this->storage->record_hit( '/manual', $time + 60, null, true ) );

		$this->assertTrue( $this->storage->record_hit( '/Redirected/', $time + 120, 'https://example.com/', true ) );
		$record = $this->storage->get_path( '/redirected' );
		$this->assertSame( array( '0', '0', null, '2' ), array( $record['fixed'], $record['fixed_redirect'], $record['fixed_at'], $record['count'] ) );
		$this->assertSame( '1', $this->storage->get_path( '/manual' )['fixed'] );
	}

	public function test_long_path() {
		$path = '/' . str_repeat( 'a', 5000 );
		$this->assertTrue( $this->storage->record_hit( $path, time() ) );
		$this->assertSame( $path, $this->storage->get_path( $path )['path'] );
	}

	public function test_reset_counters() {
		$this->storage->record_hit( '/yesterday', self::local_time( '2026-10-21 10:00' ) );
		$this->storage->record_hit( '/last-week', self::local_time( '2026-10-16 10:00' ) );
		$this->storage->record_hit( '/today', self::local_time( '2026-10-22 09:00' ) );

		$this->storage->reset_counters( self::local_time( '2026-10-22 12:00' ) );

		$counters = static function ( array $row ): array {
			return array( $row['daily_count'], $row['week_count'] );
		};
		$this->assertSame( array( '0', '1' ), $counters( $this->storage->get_path( '/yesterday' ) ) );
		$this->assertSame( array( '0', '0' ), $counters( $this->storage->get_path( '/last-week' ) ) );
		$this->assertSame( array( '1', '1' ), $counters( $this->storage->get_path( '/today' ) ) );
	}

	public function test_purge_history_and_old_referers() {
		$now = self::local_time( '2026-10-22 12:00' );
		$this->storage->record_hit( '/x', $now - 20 * DAY_IN_SECONDS, 'https://old.example/' );
		$this->storage->record_hit( '/x', $now - 13 * DAY_IN_SECONDS, 'https://recent.example/' );
		$this->storage->record_hit( '/x', $now );

		update_option( Settings::OPTION, array( 'referer_retention_days' => 15 ) );
		$deleted = $this->storage->purge( $now );

		$this->assertSame( 1, $deleted['history'] );
		$this->assertSame( 1, $deleted['referers'] );
		$this->assertSame( array( '2026-10-09', '2026-10-22' ), array_keys( $this->storage->get_daily_hits( '/x' ) ) );
		$this->assertSame( array( 'https://recent.example/' ), array_column( $this->storage->list_referers( '/x' ), 'referer' ) );
	}

	public function test_purge_referers_beyond_the_limit_keeps_the_most_recent() {
		$now = self::local_time( '2026-10-22 12:00' );
		foreach ( array( 'a', 'b', 'c' ) as $i => $referer ) {
			$this->storage->record_hit( '/x', $now + $i, "https://$referer.example/" );
		}

		update_option( Settings::OPTION, array( 'referer_limit' => 2 ) );
		$this->assertSame( 1, $this->storage->purge( $now )['referers'] );

		$this->assertSame( array( 'https://c.example/', 'https://b.example/' ), array_column( $this->storage->list_referers( '/x' ), 'referer' ) );
	}

	public function test_purge_urls_beyond_the_row_limit_removes_the_least_recently_seen() {
		$now = self::local_time( '2026-10-22 12:00' );
		foreach ( array( '/a', '/b', '/c' ) as $i => $path ) {
			$this->storage->record_hit( $path, $now + $i, 'https://example.com/' );
		}

		update_option( Settings::OPTION, array( 'row_limit' => 2 ) );
		$this->assertSame( 1, $this->storage->purge( $now )['urls'] );

		$this->assertNull( $this->storage->get_path( '/a' ) );
		$this->assertSame( array(), $this->storage->list_referers( '/a' ) );
		$this->assertSame( array(), $this->storage->get_daily_hits( '/a' ) );
		$this->assertNotNull( $this->storage->get_path( '/b' ) );
	}

	public function test_purge_goes_beyond_one_batch() {
		global $wpdb;

		// Inserted directly: recording 2,500 hits one by one is slow.
		$now  = self::local_time( '2026-10-22 12:00' );
		$rows = array();
		for ( $i = 0; $i < 2 * Storage::PURGE_BATCH_SIZE + 500; $i++ ) {
			$rows[] = $wpdb->prepare( '(%s, %s, %d, %d)', Storage::path_hash( "/bot-$i" ), "/bot-$i", $now - $i, $now - $i );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rows prepared above.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (path_hash, path, first_seen, last_seen) VALUES ', Storage::tables()['paths'] ) . implode( ',', $rows ) );

		update_option( Settings::OPTION, array( 'row_limit' => 100 ) );
		$this->assertSame( 2 * Storage::PURGE_BATCH_SIZE + 400, $this->storage->purge( $now )['urls'] );
		$this->assertSame( 100, $this->storage->count_paths() );
		$this->assertNotNull( $this->storage->get_path( '/bot-99' ) );
		$this->assertNull( $this->storage->get_path( '/bot-100' ) );
	}

	public function test_delete_by_hashes_counts_the_deleted_urls() {
		$this->storage->record_hit( '/a', time(), 'https://example.com/' );
		$this->storage->record_hit( '/b', time() );

		$this->assertSame( 2, $this->storage->delete_by_hashes( array( Storage::path_hash( '/a' ), Storage::path_hash( '/a' ), Storage::path_hash( '/b' ), str_repeat( 'f', 64 ) ) ) );
		$this->assertSame( array(), $this->storage->list_referers( '/a' ) );
		$this->assertSame( array(), $this->storage->get_daily_hits( '/b' ) );
	}

	public function test_zero_disables_the_limits() {
		$now = self::local_time( '2026-10-22 12:00' );
		$this->storage->record_hit( '/x', $now - 400 * DAY_IN_SECONDS, 'https://old.example/' );
		update_option(
			Settings::OPTION,
			array(
				'row_limit'              => 0,
				'referer_limit'          => 0,
				'referer_retention_days' => 0,
			)
		);

		$deleted = $this->storage->purge( $now );
		$this->assertSame( array( 0, 0 ), array( $deleted['referers'], $deleted['urls'] ) );
	}
}
