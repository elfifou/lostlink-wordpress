<?php
/**
 * Tests of the request parameters.
 *
 * @package Lostlink
 */

use Lostlink\Request;

/**
 * @covers \Lostlink\Request
 */
class Test_Request extends WP_UnitTestCase {

	/**
	 * The request URI before the test.
	 *
	 * @var mixed
	 */
	private $request_uri;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as is in tear_down().
	}

	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		$_GET                   = array();
		$_POST                  = array();
		$_REQUEST               = array();
		unset( $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
	}

	public function test_values_are_unslashed(): void {
		// WordPress adds slashes to all the request parameters.
		$_GET['target'] = wp_slash( "/l'été?a=1" );

		$this->assertSame( "/l'été?a=1", Request::text( 'target' ) );
		$this->assertSame( 'lta1', Request::key( 'target' ) );
	}

	public function test_missing_parameters_are_null(): void {
		$this->assertNull( Request::text( 'target' ) );
		$this->assertNull( Request::key( 'tab' ) );
		$this->assertNull( Request::int( 'paged' ) );
		$this->assertSame( '', Request::search( 's' ) );
		$this->assertSame( array(), Request::keys( 'items' ) );
		$this->assertNull( Request::referer() );
	}

	public function test_an_array_is_not_a_string(): void {
		// Sent as arrays, e.g. tab[]=x: read as strings, they would raise warnings.
		$_GET = array(
			'tab'    => array( 'x' ),
			's'      => array( 'x' ),
			'target' => array( 'x' ),
			'paged'  => array( '2' ),
		);

		$this->assertNull( Request::key( 'tab' ) );
		$this->assertSame( '', Request::search( 's' ) );
		$this->assertNull( Request::text( 'target' ) );
		$this->assertNull( Request::int( 'paged' ) );
	}

	public function test_lists_keep_their_scalar_items(): void {
		$_REQUEST['items']     = array( 'ABC', array( 'nested' ), 'd-e' );
		$_REQUEST['redirects'] = '7';

		$this->assertSame( array( 'abc', 'd-e' ), Request::keys( 'items', Request::ANY ) );
		// A single value is a list of one.
		$this->assertSame( array( 7 ), Request::ints( 'redirects', Request::ANY ) );
	}

	public function test_sources(): void {
		$_GET['redirect']     = '1';
		$_POST['redirect']    = '2';
		$_REQUEST['redirect'] = '3';

		$this->assertSame( 1, Request::int( 'redirect' ) );
		$this->assertSame( 2, Request::int( 'redirect', Request::POST ) );
		$this->assertSame( 3, Request::int( 'redirect', Request::ANY ) );
	}

	public function test_numbers_are_non_negative(): void {
		$_GET['paged'] = '-3';
		$this->assertSame( 3, Request::int( 'paged' ) );
	}

	public function test_search_is_sanitized(): void {
		$_GET['s'] = '  <b>old</b>  page ';
		$this->assertSame( 'old page', Request::search( 's' ) );
	}

	public function test_uri_is_kept_as_requested(): void {
		// Encoded octets and special characters must stay, or a redirect for the URL would never match it.
		$_SERVER['REQUEST_URI'] = wp_slash( "/l'%C3%A9t%C3%A9/<x>?a=1" );
		$this->assertSame( "/l'%C3%A9t%C3%A9/<x>?a=1", Request::uri() );

		unset( $_SERVER['REQUEST_URI'] );
		$this->assertSame( '/', Request::uri() );
	}

	public function test_referer_is_sanitized(): void {
		$_SERVER['HTTP_REFERER'] = wp_slash( "https://example.com/l'été?a=1&b=<2>" );
		$this->assertSame( "https://example.com/l'été?a=1&b=2", Request::referer() );
	}

	/**
	 * @dataProvider invalid_referers
	 *
	 * @param string $referer A referer.
	 */
	public function test_invalid_referers_are_dropped( string $referer ): void {
		$_SERVER['HTTP_REFERER'] = $referer;
		$this->assertNull( Request::referer() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalid_referers(): array {
		return array(
			'empty'      => array( '' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'relative'   => array( '/page' ),
			'other'      => array( 'ftp://example.com/' ),
			'too long'   => array( 'https://example.com/' . str_repeat( 'a', Request::MAX_REFERER_LENGTH ) ),
			'no host'    => array( 'https://' ),
		);
	}
}
