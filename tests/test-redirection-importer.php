<?php
/**
 * Tests of the import of the Redirection plugin's 404 log.
 *
 * @package Lostlink
 */

use Lostlink\Hit_Recorder;
use Lostlink\Installer;
use Lostlink\Redirection_Importer;
use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Redirection_Importer
 * @covers \Lostlink\Storage::recompute_counters
 * @group redirection
 */
class Test_Redirection_Importer extends WP_UnitTestCase {

	protected Storage $storage;

	protected Redirection_Importer $importer;

	protected int $now;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$this->storage  = new Storage();
		$this->importer = new Redirection_Importer( new Hit_Recorder( $this->storage ), $this->storage );
		if ( ! $this->importer->is_available() ) {
			$this->markTestSkipped( 'The Redirection plugin tables are not installed.' );
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Redirection_Importer::table() ) );
		foreach ( Storage::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
		delete_option( Redirection_Importer::STATE_OPTION );
		update_option( 'timezone_string', 'Europe/Paris' );

		// Midday, so "a few hours ago" is the same local day.
		$this->now = Storage::local_date( time() )->setTime( 12, 0 )->getTimestamp();
		update_option( Installer::INSTALLED_AT_OPTION, $this->now - HOUR_IN_SECONDS );
	}

	/**
	 * Adds a row to Redirection's 404 log, as Redirection does (local date, decoded URL).
	 */
	protected function log( string $url, int $time, string $referrer = '' ): void {
		global $wpdb;
		$wpdb->insert(
			Redirection_Importer::table(),
			array(
				'created'   => wp_date( 'Y-m-d H:i:s', $time ),
				'url'       => $url,
				'referrer'  => $referrer,
				'ip'        => '127.0.0.1',
				'http_code' => 404,
			)
		);
	}

	public function test_imports_the_rows_logged_before_the_install(): void {
		$this->log( '/old-page?utm_source=x', $this->now - 3 * DAY_IN_SECONDS, 'https://example.com/a' );
		$this->log( '/Old-Page/', $this->now - 2 * HOUR_IN_SECONDS, 'https://example.com/a' );
		$this->log( '/style.css', $this->now - 2 * HOUR_IN_SECONDS );
		// Logged by both plugins.
		$this->log( '/old-page', $this->now - HOUR_IN_SECONDS + 60 );

		$this->assertSame( 3, $this->importer->count_pending() );
		$this->assertSame(
			array(
				'read'      => 3,
				'imported'  => 2,
				'remaining' => 0,
			),
			$this->importer->import()
		);

		$row = $this->storage->get_path( '/old-page' );
		$this->assertSame( '2', $row['count'] );
		$this->assertSame( (string) ( $this->now - 3 * DAY_IN_SECONDS ), $row['first_seen'] );
		$this->assertSame( (string) ( $this->now - 2 * HOUR_IN_SECONDS ), $row['last_seen'] );
		$this->assertSame( '1', $row['daily_count'] );
		$this->assertSame( array( 'https://example.com/a' ), array_column( $this->storage->list_referers( '/old-page' ), 'referer' ) );
		$this->assertCount( 2, $this->storage->get_daily_hits( '/old-page' ) );
		$this->assertNull( $this->storage->get_path( '/style.css' ) );
	}

	public function test_dates_are_read_in_the_site_timezone(): void {
		global $wpdb;
		$wpdb->insert(
			Redirection_Importer::table(),
			array(
				'created' => '2026-01-15 10:00:00',
				'url'     => '/winter',
				'ip'      => '127.0.0.1',
			)
		);

		$this->importer->import();
		$this->assertSame( (string) ( new DateTimeImmutable( '2026-01-15 09:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(), $this->storage->get_path( '/winter' )['last_seen'] );
	}

	public function test_never_imports_a_row_twice(): void {
		$this->log( '/a', $this->now - DAY_IN_SECONDS );
		$this->importer->import();

		$this->assertSame( 0, $this->importer->count_pending() );
		$this->assertSame( 0, $this->importer->import()['read'] );

		// A batch interrupted then resumed starts after the last imported row.
		$this->log( '/a', $this->now - 2 * HOUR_IN_SECONDS );
		$this->assertSame( 1, $this->importer->import()['read'] );
		$this->assertSame( '2', $this->storage->get_path( '/a' )['count'] );
	}

	public function test_counters_are_recomputed_when_older_hits_are_imported(): void {
		// Recorded live, after the install: the imported hits are older.
		( new Hit_Recorder( $this->storage ) )->record( '/a', null, $this->now );
		$this->log( '/a', $this->now - 8 * DAY_IN_SECONDS );
		$this->log( '/a', $this->now - 8 * DAY_IN_SECONDS + 60 );
		$this->log( '/a', $this->now - 2 * HOUR_IN_SECONDS );

		$this->importer->import();

		$row   = $this->storage->get_path( '/a' );
		$today = Storage::local_date( $this->now );
		$week  = array_sum(
			array_filter(
				$this->storage->get_daily_hits( '/a' ),
				static fn( string $day ): bool => $day >= $today->modify( 'monday this week' )->format( 'Y-m-d' ),
				ARRAY_FILTER_USE_KEY
			)
		);
		$this->assertSame( '4', $row['count'] );
		$this->assertSame( '2', $row['daily_count'] );
		$this->assertSame( (string) $week, $row['week_count'] );
		$this->assertSame( (string) $this->now, $row['last_seen'] );
		$this->assertSame( (string) ( $this->now - 8 * DAY_IN_SECONDS ), $row['first_seen'] );
	}

	public function test_fixed_and_excluded_urls_are_skipped(): void {
		update_option( Settings::OPTION, array_merge( Settings::all(), array( 'exclude_patterns' => '/wp-admin/*' ) ) );
		( new Hit_Recorder( $this->storage ) )->record( '/fixed', null, $this->now - 5 * DAY_IN_SECONDS );
		$this->storage->set_state( array( Storage::path_hash( '/fixed' ) ), 'fixed' );
		$this->log( '/fixed', $this->now - 2 * DAY_IN_SECONDS );
		$this->log( '/wp-admin/x.php', $this->now - 2 * DAY_IN_SECONDS );

		$this->assertSame( 0, $this->importer->import()['imported'] );
		$this->assertSame( '1', $this->storage->get_path( '/fixed' )['count'] );
		$this->assertNull( $this->storage->get_path( '/wp-admin/x.php' ) );
	}

	public function test_nothing_is_imported_without_the_install_date(): void {
		delete_option( Installer::INSTALLED_AT_OPTION );
		$this->log( '/a', $this->now - DAY_IN_SECONDS );

		$this->assertSame( 0, $this->importer->count_pending() );
		$this->assertNull( $this->importer->pending_range() );
	}

	public function test_install_date_is_set_once(): void {
		delete_option( Installer::INSTALLED_AT_OPTION );
		Installer::install();
		$installed_at = (int) get_option( Installer::INSTALLED_AT_OPTION );
		$this->assertEqualsWithDelta( time(), $installed_at, 5 );

		// Kept on later installs (reactivation).
		update_option( Installer::INSTALLED_AT_OPTION, $installed_at - DAY_IN_SECONDS );
		Installer::install();
		$this->assertSame( $installed_at - DAY_IN_SECONDS, (int) get_option( Installer::INSTALLED_AT_OPTION ) );
	}

	public function test_an_imported_hit_never_reopens_a_url_fixed_by_a_redirect(): void {
		( new Hit_Recorder( $this->storage ) )->record( '/redirected', null, $this->now - 5 * DAY_IN_SECONDS );
		$this->storage->set_state( array( Storage::path_hash( '/redirected' ) ), 'fixed_redirect' );
		$this->log( '/redirected', $this->now - 2 * DAY_IN_SECONDS );

		$this->assertSame( 0, $this->importer->import()['imported'] );
		$this->assertSame( '1', $this->storage->get_path( '/redirected' )['fixed'] );
	}

	public function test_two_imports_never_run_at_once(): void {
		$this->log( '/a', $this->now - DAY_IN_SECONDS );

		$this->assertTrue( $this->importer->lock() );
		$this->assertFalse( $this->importer->lock() );
		$this->assertSame( 'lostlink_import_locked', $this->importer->import()->get_error_code() );
		$this->assertNull( $this->storage->get_path( '/a' ) );

		$this->importer->unlock();
		$this->assertSame( 1, $this->importer->import()['imported'] );
		// Released after the import.
		$this->assertTrue( $this->importer->lock() );
	}

	public function test_a_lock_left_by_a_crashed_import_is_taken_over(): void {
		global $wpdb;

		$this->assertTrue( $this->importer->lock() );
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - HOUR_IN_SECONDS ) ), array( 'option_name' => Redirection_Importer::LOCK_OPTION ) );
		$this->assertTrue( $this->importer->lock() );
		$this->assertFalse( $this->importer->lock() );
	}

	public function test_pending_range(): void {
		$this->log( '/a', $this->now - 3 * DAY_IN_SECONDS );
		$this->log( '/b', $this->now - 2 * HOUR_IN_SECONDS );

		$this->assertSame( array( $this->now - 3 * DAY_IN_SECONDS, $this->now - 2 * HOUR_IN_SECONDS ), $this->importer->pending_range() );
	}
}
