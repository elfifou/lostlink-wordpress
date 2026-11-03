<?php
/**
 * Tests of the Redirection plugin backend, against the real plugin.
 *
 * @package Lostlink
 */

use Lostlink\Redirect_Service;
use Lostlink\Redirection_Backend;
use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Redirection_Backend
 * @group redirection
 */
class Test_Redirection_Backend extends WP_UnitTestCase {

	protected Redirection_Backend $backend;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Red_Item' ) ) {
			$this->markTestSkipped( 'The Redirection plugin is not installed next to this one.' );
		}
		$this->backend = new Redirection_Backend();
	}

	public function test_available_once_set_up() {
		$this->assertTrue( $this->backend->is_available() );

		$options = get_option( 'redirection_options' );
		update_option( 'redirection_options', array( 'database' => '' ) + $options );
		$this->assertFalse( $this->backend->is_available() );
		$this->assertWPError( $this->backend->create( '/a', '/b', 301 ) );
	}

	public function test_create_and_find_target() {
		$this->assertTrue( $this->backend->create( '/old-page', '/new-page/', 302 ) );

		$item = Red_Item::get_for_matched_url( '/old-page' )[0];
		$this->assertSame( '/new-page/', $item->get_action_data() );
		$this->assertSame( 302, $item->get_action_code() );
		$this->assertSame( 'pass', $item->get_match_data()['source']['flag_query'] ?? null );
		// Redirection matches case and trailing slash insensitively by default.
		$this->assertSame( '/new-page/', $this->backend->find_target( '/Old-Page/' ) );
		$this->assertNull( $this->backend->find_target( '/other' ) );
	}

	public function test_create_ignores_case_and_trailing_slash_whatever_the_site_defaults() {
		// The defaults of the sites where Redirection was installed by an older version.
		update_option(
			'redirection_options',
			array_merge(
				get_option( 'redirection_options' ),
				array(
					'flag_case'     => false,
					'flag_trailing' => false,
				)
			)
		);
		$this->assertTrue( $this->backend->create( '/old-page', '/new-page/', 301 ) );

		// Redirection only stores the flags that differ from the site defaults.
		$flags = Red_Item::get_for_matched_url( '/old-page' )[0]->get_match_data()['source'] ?? array();
		$this->assertTrue( $flags['flag_case'] ?? null );
		$this->assertTrue( $flags['flag_trailing'] ?? null );
	}

	public function test_regex_redirects_are_not_followed() {
		Red_Item::create(
			array(
				'url'         => '/regex/.*',
				'regex'       => true,
				'action_data' => array( 'url' => '/x' ),
				'action_type' => 'url',
				'match_type'  => 'url',
				'group_id'    => 1,
			)
		);

		$this->assertNull( $this->backend->find_target( '/regex/a' ) );
	}

	public function test_auto_backend_uses_redirection_and_detects_loops_across_backends() {
		update_option( Settings::OPTION, array( 'redirect_backend' => 'auto' ) );
		$service = Redirect_Service::create_default();
		$this->assertSame( 'redirection', $service->backend()->id() );

		$storage = new Storage();
		$storage->record_hit( '/a', time() );
		$storage->record_hit( '/b', time() );

		// /a -> /b in Redirection, then /b -> /a would loop.
		$this->assertIsArray( $service->fix( Storage::path_hash( '/a' ), '/b' ) );
		$this->assertSame( 'lostlink_loop', $service->fix( Storage::path_hash( '/b' ), '/a' )->get_error_code() );

		update_option( Settings::OPTION, array( 'redirect_backend' => 'builtin' ) );
		$this->assertSame( 'builtin', Redirect_Service::create_default()->backend()->id() );
	}
}
