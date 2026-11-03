<?php
/**
 * Tests of the 404 fixing: target checks, loops, chains, backends.
 *
 * @package Lostlink
 */

use Lostlink\Builtin_Backend;
use Lostlink\Redirect_Backend;
use Lostlink\Redirect_Service;
use Lostlink\Redirect_Store;
use Lostlink\Settings;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Redirect_Service
 * @covers \Lostlink\Builtin_Backend
 */
class Test_Redirect_Service extends WP_UnitTestCase {

	protected Storage $storage;

	protected Redirect_Store $store;

	protected Redirect_Service $service;

	/**
	 * Returns a backend made unavailable, standing in for Redirection when not set up.
	 */
	protected static function unavailable_backend(): Redirect_Backend {
		return new class() implements Redirect_Backend {

			public function id(): string {
				return 'redirection';
			}

			public function label(): string {
				return 'Unavailable';
			}

			public function is_available(): bool {
				return false;
			}

			public function find_target( string $path ): ?string {
				return '/never';
			}

			public function create( string $source, string $target, int $status_code ) {
				return new WP_Error( 'unavailable', 'Unavailable' );
			}

			public function manage_url(): string {
				return '';
			}
		};
	}

	public function set_up() {
		parent::set_up();
		$this->storage = new Storage();
		$this->store   = new Redirect_Store();
		$this->service = new Redirect_Service( $this->storage, array( new Builtin_Backend( $this->store ), self::unavailable_backend() ) );
	}

	/**
	 * Records a broken URL and returns its hash.
	 *
	 * @param string $path Path.
	 */
	protected function broken( string $path ): string {
		$this->storage->record_hit( $path, time() );
		return Storage::path_hash( $path );
	}

	public function test_fix_creates_the_redirect_and_marks_the_url_fixed() {
		$hash   = $this->broken( '/old' );
		$result = $this->service->fix( $hash, '/new/', 302 );

		$this->assertSame( '/new/', $result['target'] );
		$this->assertSame( 'builtin', $result['backend']->id() );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( '302', $this->store->find( '/old' )['status_code'] );

		$record = $this->storage->get_by_hash( $hash );
		$this->assertSame( array( '1', '1' ), array( $record['fixed'], $record['fixed_redirect'] ) );
		// Fixed URLs can't be fixed again.
		$this->assertSame( 'lostlink_already_fixed', $this->service->fix( $hash, '/other' )->get_error_code() );
	}

	public function test_fix_rejects_unknown_urls_and_invalid_status_codes() {
		$this->assertSame( 'lostlink_unknown_url', $this->service->fix( str_repeat( 'a', 64 ), '/new' )->get_error_code() );
		$this->assertSame( 'lostlink_invalid_status', $this->service->fix( $this->broken( '/old' ), '/new', 200 )->get_error_code() );
	}

	/**
	 * @dataProvider data_invalid_targets
	 */
	public function test_invalid_targets( string $target, string $code ) {
		$this->assertSame( $code, $this->service->normalize_target( $target, true )->get_error_code() );
	}

	public function data_invalid_targets() {
		return array(
			'empty'             => array( '  ', 'lostlink_empty_target' ),
			'space'             => array( '/a b', 'lostlink_invalid_target' ),
			'newline'           => array( "/a\nLocation: x", 'lostlink_invalid_target' ),
			'javascript'        => array( 'javascript:alert(1)', 'lostlink_invalid_target' ),
			'data'              => array( 'data:text/html,x', 'lostlink_invalid_target' ),
			'protocol-relative' => array( '//evil.example/x', 'lostlink_invalid_target' ),
			'relative'          => array( 'new-page', 'lostlink_invalid_target' ),
			'ftp'               => array( 'ftp://example.com/', 'lostlink_invalid_target' ),
		);
	}

	public function test_same_site_urls_become_paths() {
		$this->assertSame( '/new/?a=1#b', $this->service->normalize_target( 'http://EXAMPLE.org/new/?a=1#b', false ) );
		$this->assertSame( '/', $this->service->normalize_target( 'http://example.org', false ) );
		// Another port is another site.
		$this->assertWPError( $this->service->normalize_target( 'http://example.org:8080/new', false ) );
	}

	public function test_same_site_urls_in_a_subdirectory_install() {
		update_option( 'home', 'http://example.org/blog' );

		$this->assertSame( '/new', $this->service->normalize_target( 'http://example.org/blog/new', false ) );
		// Outside the home path: another site, for WordPress.
		$this->assertWPError( $this->service->normalize_target( 'http://example.org/shop/x', false ) );
	}

	public function test_external_targets_need_the_permission() {
		$this->assertSame( 'lostlink_external_target', $this->service->normalize_target( 'https://other.example/x', false )->get_error_code() );
		$this->assertSame( 'https://other.example/x', $this->service->normalize_target( 'https://other.example/x', true ) );
	}

	public function test_target_equal_to_the_source_is_a_loop() {
		$error = $this->service->fix( $this->broken( '/old' ), '/OLD/?x=1' );
		$this->assertSame( 'lostlink_loop', $error->get_error_code() );
		$this->assertNull( $this->store->find( '/old' ) );
	}

	public function test_loops_through_existing_redirects_are_blocked() {
		// /c -> /a, then /b -> /c: fixing /a with /b would loop a -> b -> c -> a.
		$this->store->create( '/c', '/a' );
		$this->store->create( '/b', 'http://example.org/c' );

		$this->assertSame( 'lostlink_loop', $this->service->fix( $this->broken( '/a' ), '/b' )->get_error_code() );
	}

	public function test_chain_warning() {
		$this->store->create( '/b', '/c' );
		$this->store->create( '/c', 'https://other.example/' );

		$result = $this->service->fix( $this->broken( '/a' ), '/b' );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( '/b is itself redirected to /c', $result['warnings'][0] );
	}

	public function test_broken_target_warning_unless_ignored() {
		$this->broken( '/broken-target' );
		$ignored = $this->broken( '/ignored-target' );
		$this->storage->set_state( array( $ignored ), 'ignored' );

		$this->assertCount( 1, $this->service->fix( $this->broken( '/a' ), '/broken-target/' )['warnings'] );
		$this->assertSame( array(), $this->service->fix( $this->broken( '/b' ), '/ignored-target' )['warnings'] );
	}

	public function test_existing_redirect_for_the_source() {
		$hash = $this->broken( '/a' );
		$this->store->create( '/a', '/elsewhere' );

		$this->assertSame( 'lostlink_redirect_exists', $this->service->fix( $hash, '/b' )->get_error_code() );
	}

	public function test_unavailable_backends_fall_back_to_built_in() {
		foreach ( Settings::REDIRECT_BACKENDS as $setting ) {
			update_option( Settings::OPTION, array( 'redirect_backend' => $setting ) );
			$this->assertSame( 'builtin', $this->service->backend()->id(), $setting );
		}
		// An unavailable backend is never looked up for loops or chains.
		$this->assertSame( array(), $this->service->fix( $this->broken( '/a' ), '/never' )['warnings'] );
	}

	public function test_edit_changes_the_target_with_the_fix_checks() {
		$this->service->fix( $this->broken( '/a' ), '/b' );
		$id = (int) $this->store->find( '/a' )['id'];

		$result = $this->service->edit( $id, 'http://example.org/c/', 308 );
		$this->assertSame(
			array(
				'source'   => '/a',
				'target'   => '/c/',
				'warnings' => array(),
			),
			$result
		);
		$this->assertSame( '308', $this->store->get( $id )['status_code'] );

		// /d -> /a: redirecting /a to /d would loop.
		$this->store->create( '/d', '/a' );
		$this->assertSame( 'lostlink_loop', $this->service->edit( $id, '/d' )->get_error_code() );
		$this->assertSame( 'lostlink_loop', $this->service->edit( $id, '/A/' )->get_error_code() );
		$this->assertSame( 'lostlink_empty_target', $this->service->edit( $id, '' )->get_error_code() );
		$this->assertSame( 'lostlink_invalid_status', $this->service->edit( $id, '/e', 200 )->get_error_code() );
		$this->assertSame( 'lostlink_unknown_redirect', $this->service->edit( $id + 100, '/e' )->get_error_code() );
		$this->assertSame( '/c/', $this->store->get( $id )['target'] );

		// Warnings as when fixing.
		$this->broken( '/still-broken' );
		$this->assertCount( 1, $this->service->edit( $id, '/still-broken' )['warnings'] );
		// The URL stays fixed.
		$this->assertSame( '1', $this->storage->get_path( '/a' )['fixed'] );
	}

	public function test_edit_keeps_an_external_target_without_the_permission() {
		$id = $this->store->create( '/a', 'https://other.example/x' );

		$this->assertSame( 'lostlink_external_target', $this->service->edit( $id, 'https://other.example/y' )->get_error_code() );
		$this->assertSame( 'https://other.example/x', $this->service->edit( $id, ' https://other.example/x ', 302 )['target'] );
		$this->assertSame( '302', $this->store->get( $id )['status_code'] );
		$this->assertSame( 'https://other.example/y', $this->service->edit( $id, 'https://other.example/y', 302, true )['target'] );
	}

	public function test_internal_path() {
		$this->assertSame( '/café', Redirect_Service::internal_path( '/caf%C3%A9?x=1#y' ) );
		$this->assertSame( '/x', Redirect_Service::internal_path( 'https://example.org/x' ) );
		$this->assertNull( Redirect_Service::internal_path( 'https://other.example/x' ) );
		$this->assertNull( Redirect_Service::internal_path( '//example.org/x' ) );
	}
}
