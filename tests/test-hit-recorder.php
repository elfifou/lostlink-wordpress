<?php
/**
 * Tests of the 404 detection and filtering.
 *
 * @package Lostlink
 */

use Lostlink\Hit_Recorder;
use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Hit_Recorder
 */
class Test_Hit_Recorder extends WP_UnitTestCase {

	protected Storage $storage;

	protected Hit_Recorder $recorder;

	public function set_up() {
		parent::set_up();
		$this->storage  = new Storage();
		$this->recorder = new Hit_Recorder( $this->storage );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
	}

	public function test_raw_path_drops_query_string_and_fragment() {
		$this->assertSame( '/old-page', Hit_Recorder::get_raw_path( '/old-page?utm_source=x#top' ) );
		$this->assertSame( '/', Hit_Recorder::get_raw_path( '' ) );
		// Not read as a protocol-relative URL.
		$this->assertSame( '/example.com/x', Hit_Recorder::get_raw_path( '//example.com/x' ) );
	}

	public function test_raw_path_is_relative_to_a_subdirectory_home() {
		update_option( 'home', 'http://example.org/blog' );

		$this->assertSame( '/old-page', Hit_Recorder::get_raw_path( '/blog/old-page' ) );
		$this->assertSame( '/', Hit_Recorder::get_raw_path( '/blog' ) );
		// Only a whole segment is the home path.
		$this->assertSame( '/blogroll', Hit_Recorder::get_raw_path( '/blogroll' ) );
	}

	public function test_decode_path_keeps_invalid_utf8_encoded() {
		$this->assertSame( '/café', Hit_Recorder::decode_path( '/caf%C3%A9' ) );
		$this->assertSame( '/caf%E9', Hit_Recorder::decode_path( '/caf%E9' ) );
	}

	public function test_records_decoded_path() {
		$this->assertTrue( $this->recorder->record( '/Caf%C3%A9/?x=1', 'https://example.com/' ) );

		$row = $this->storage->get_path( '/café' );
		$this->assertSame( '/café', $row['path'] );
		$this->assertSame( '1', $row['count'] );
	}

	public function test_assets_are_ignored_unless_enabled() {
		$this->assertFalse( $this->recorder->record( '/missing.PNG' ) );
		$this->assertFalse( $this->recorder->record( '/fonts/x.woff2' ) );
		$this->assertNull( $this->storage->get_path( '/missing.png' ) );

		update_option( Settings::OPTION, array( 'log_assets' => true ) );
		$this->assertTrue( $this->recorder->record( '/missing.png' ) );
	}

	public function test_exclusion_patterns_match_decoded_and_raw_paths() {
		update_option( Settings::OPTION, array( 'exclude_patterns' => "/feed/*\n/caf%C3%A9-raw" ) );

		$this->assertFalse( $this->recorder->record( '/feed/rss' ) );
		$this->assertFalse( $this->recorder->record( '/caf%C3%A9-raw' ) );
		$this->assertTrue( $this->recorder->record( '/other' ) );
	}

	public function test_should_record_filter() {
		add_filter( 'lostlink_should_record', static fn( $record, $path ) => '/skip' !== $path, 10, 2 );

		$this->assertFalse( $this->recorder->record( '/skip' ) );
		$this->assertTrue( $this->recorder->record( '/keep' ) );
	}

	public function test_maybe_record_only_records_404s() {
		$this->go_to( '/' );
		$this->recorder->maybe_record();
		$this->assertSame( array(), $this->storage->list_paths() );

		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['HTTP_REFERER'] = 'https://example.com/source';
		$this->go_to( '/does-not-exist/' );
		$this->assertTrue( is_404() );
		$this->recorder->maybe_record();
		// The same request is never counted twice.
		$this->recorder->maybe_record();

		$this->assertSame( '1', $this->storage->get_path( '/does-not-exist' )['count'] );
		$this->assertSame( 'https://example.com/source', $this->storage->list_referers( '/does-not-exist' )[0]['referer'] );
	}

	public function test_a_404_served_now_reopens_a_url_fixed_by_a_redirect() {
		$this->recorder->record( '/lost-redirect' );
		$this->storage->set_state( array( Storage::path_hash( '/lost-redirect' ) ), 'fixed_redirect' );

		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( '/lost-redirect/' );
		$this->recorder->maybe_record();

		$this->assertSame( array( '0', '2' ), array( $this->storage->get_path( '/lost-redirect' )['fixed'], $this->storage->get_path( '/lost-redirect' )['count'] ) );
	}

	public function test_is_hooked_after_redirect_canonical() {
		$this->assertSame( 10, has_action( 'template_redirect', 'redirect_canonical' ) );
		$this->assertGreaterThan( 10, Hit_Recorder::PRIORITY );
	}

	public function test_storage_failure_never_throws() {
		$storage = $this->createMock( Storage::class );
		$storage->method( 'record_hit' )->willThrowException( new RuntimeException( 'Database is down' ) );

		$log = ini_set( 'error_log', '/dev/null' );
		try {
			$this->assertFalse( ( new Hit_Recorder( $storage ) )->record( '/x' ) );
		} finally {
			ini_set( 'error_log', $log );
		}
	}
}
