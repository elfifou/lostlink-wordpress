<?php
/**
 * Tests of the counting of the 404s served by a page cache.
 *
 * @package Lostlink
 */

use Lostlink\Cache_Beacon;
use Lostlink\Hit_Recorder;
use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Cache_Beacon
 */
class Test_Cache_Beacon extends WP_UnitTestCase {

	protected Storage $storage;

	protected Cache_Beacon $beacon;

	protected ?string $request_uri;

	public function set_up(): void {
		parent::set_up();
		$this->storage     = new Storage();
		$this->beacon      = new Cache_Beacon( new Hit_Recorder( $this->storage ) );
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as is in tear_down().
		update_option( Settings::OPTION, array( 'cached_404s' => 'always' ) );
	}

	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		parent::tear_down();
	}

	public function test_enabled_setting(): void {
		$this->assertTrue( Cache_Beacon::is_enabled() );
		update_option( Settings::OPTION, array( 'cached_404s' => 'never' ) );
		$this->assertFalse( Cache_Beacon::is_enabled() );
		update_option( Settings::OPTION, array( 'cached_404s' => 'auto' ) );
		$this->assertSame( defined( 'WP_CACHE' ) && WP_CACHE, Cache_Beacon::is_enabled() );
	}

	public function test_views_of_cached_copies_are_counted(): void {
		$time = time() - HOUR_IN_SECONDS;
		$sig  = Cache_Beacon::sign( '/old-page?utm=1', $time );

		$this->assertTrue( $this->beacon->report( '/old-page?utm=1', $time, $sig, 'https://example.com/', time() ) );
		$this->assertTrue( $this->beacon->report( '/old-page?utm=1', $time, $sig, '', time() ) );

		$record = $this->storage->get_path( '/old-page' );
		$this->assertSame( '2', $record['count'] );
		$this->assertSame( array( 'https://example.com/' ), array_column( $this->storage->list_referers( '/old-page' ), 'referer' ) );
	}

	public function test_the_view_of_the_request_that_generated_the_page_is_not_counted_twice(): void {
		$time = time();
		$this->assertFalse( $this->beacon->report( '/old-page', $time, Cache_Beacon::sign( '/old-page', $time ), '', $time + 2 ) );
		$this->assertNull( $this->storage->get_path( '/old-page' ) );
	}

	public function test_forged_old_or_future_reports_are_ignored(): void {
		$time = time() - HOUR_IN_SECONDS;
		$sig  = Cache_Beacon::sign( '/old-page', $time );

		$this->assertFalse( $this->beacon->report( '/any-path', $time, $sig, '', time() ) );
		$this->assertFalse( $this->beacon->report( '/old-page', $time - 1, $sig, '', time() ) );
		$this->assertFalse( $this->beacon->report( '/old-page', $time, 'x', '', time() ) );
		$old = time() - Cache_Beacon::MAX_AGE - 1;
		$this->assertFalse( $this->beacon->report( '/old-page', $old, Cache_Beacon::sign( '/old-page', $old ), '', time() ) );
		$future = time() + HOUR_IN_SECONDS;
		$this->assertFalse( $this->beacon->report( '/old-page', $future, Cache_Beacon::sign( '/old-page', $future ), '', time() ) );
		$this->assertSame( array(), $this->storage->list_paths() );

		update_option( Settings::OPTION, array( 'cached_404s' => 'never' ) );
		$this->assertFalse( $this->beacon->report( '/old-page', $time, $sig, '', time() ) );
	}

	public function test_fixed_urls_are_not_reopened_by_a_cached_copy(): void {
		$this->storage->record_hit( '/redirected', time() - DAY_IN_SECONDS );
		$this->storage->set_state( array( Storage::path_hash( '/redirected' ) ), 'fixed_redirect' );
		$time = time() - HOUR_IN_SECONDS;

		$this->assertFalse( $this->beacon->report( '/redirected', $time, Cache_Beacon::sign( '/redirected', $time ), '', time() ) );
		$this->assertSame( '1', $this->storage->get_path( '/redirected' )['fixed'] );
	}

	public function test_script_only_on_recorded_404s(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$this->go_to( '/' );
		$this->assertSame( '', get_echo( array( $this->beacon, 'print_script' ) ) );

		$uri                    = '/missing-page/?a=1&b=</script>';
		$_SERVER['REQUEST_URI'] = $uri;
		$this->go_to( $uri );
		$html = get_echo( array( $this->beacon, 'print_script' ) );
		$this->assertStringContainsString( 'navigator.sendBeacon', $html );
		$this->assertStringContainsString( 'lostlink\/v1\/cached-hit', str_replace( '/', '\/', $html ) );
		// The URI can't close the script tag.
		$this->assertSame( 1, substr_count( $html, '</script>' ) );

		// Excluded paths have no script.
		$uri                    = '/missing.png';
		$_SERVER['REQUEST_URI'] = $uri;
		$this->go_to( $uri );
		$this->assertSame( '', get_echo( array( $this->beacon, 'print_script' ) ) );
	}

	public function test_rest_route(): void {
		do_action( 'rest_api_init' );
		$this->beacon->register_route();
		$time    = time() - HOUR_IN_SECONDS;
		$request = new WP_REST_Request( 'POST', '/lostlink/v1/cached-hit' );
		$request->set_body_params(
			array(
				'uri'  => '/old-page',
				'time' => (string) $time,
				'sig'  => Cache_Beacon::sign( '/old-page', $time ),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '1', $this->storage->get_path( '/old-page' )['count'] );

		// Invalid reports get the same answer.
		$request->set_param( 'sig', 'forged' );
		$this->assertSame( 204, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( '1', $this->storage->get_path( '/old-page' )['count'] );
	}
}
