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
		$_GET['target'] = wp_slash( "/l'ete?a=1" );

		$this->assertSame( 'letea1', Request::key( 'target' ) );
		$this->assertSame( "/l'ete?a=1", Request::search( 'target' ) );
	}

	public function test_missing_parameters_are_null(): void {
		$this->assertNull( Request::url( 'target' ) );
		$this->assertNull( Request::key( 'tab' ) );
		$this->assertNull( Request::int( 'paged' ) );
		$this->assertSame( '', Request::search( 's' ) );
		$this->assertSame( array(), Request::keys( 'items' ) );
		$this->assertSame( array(), Request::ints( 'redirects' ) );
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
		$this->assertNull( Request::url( 'target' ) );
		$this->assertNull( Request::int( 'paged' ) );
	}

	public function test_lists_drop_nested_arrays(): void {
		$_REQUEST['items']     = array( 'ABC', array( 'nested' ), 'd-e', '!' );
		$_REQUEST['redirects'] = array( '7', array( '8' ), 'abc', '0', '-9' );

		$this->assertSame( array( 'abc', 'd-e' ), Request::keys( 'items', Request::ANY ) );
		$this->assertSame( array( 7, 9 ), Request::ints( 'redirects', Request::ANY ) );
		// A single value is a list of one.
		$_REQUEST['redirects'] = '7';
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

	public function test_url_is_sanitized_like_a_redirect_location(): void {
		$_POST['target'] = wp_slash( " /l'été/<x> " );
		// Non-ASCII encoded, characters not allowed dropped, spaces around ignored.
		$this->assertSame( '/l%C3%A9t%C3%A9/x', Request::url( 'target', Request::POST ) );

		// A relative value stays relative (esc_url_raw() would add "http://"), to be refused.
		$_GET['target'] = 'new-page';
		$this->assertSame( 'new-page', Request::url( 'target' ) );
	}

	public function test_uri_keeps_its_meaning(): void {
		// Encoded octets stay: the decoded path is the one recorded and matched.
		$_SERVER['REQUEST_URI'] = wp_slash( "/l'%C3%A9t%C3%A9/%3Cx%3E?a=1&b[]=2" );
		$this->assertSame( "/l'%C3%A9t%C3%A9/%3Cx%3E?a=1&b%5B%5D=2", Request::uri() );

		// Raw non-ASCII bytes are kept, spaces encoded, characters not allowed in a URL dropped.
		$_SERVER['REQUEST_URI'] = '/été/a b/<x>';
		$this->assertSame( '/été/a%20b/x', Request::uri() );

		unset( $_SERVER['REQUEST_URI'] );
		$this->assertNull( Request::uri() );
	}

	/**
	 * @dataProvider invalid_uris
	 *
	 * @param string $uri A request URI.
	 */
	public function test_invalid_uris_are_ignored( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		$this->assertNull( Request::uri() );
		$this->assertNull( Request::sanitize_uri( $uri ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalid_uris(): array {
		return array(
			'empty'                => array( '' ),
			'absolute form'        => array( 'http://example.com/page' ),
			'asterisk'             => array( '*' ),
			'encoded null byte'    => array( '/a%00b' ),
			'encoded control char' => array( '/a%1Bb' ),
			'malformed encoding'   => array( '/100%' ),
			'malformed octet'      => array( '/a%zzb' ),
			'too long'             => array( '/' . str_repeat( 'a', Request::MAX_URI_LENGTH ) ),
		);
	}

	public function test_long_uris_up_to_the_limit(): void {
		$uri = '/' . str_repeat( 'a', Request::MAX_URI_LENGTH - 1 );
		$this->assertSame( $uri, Request::sanitize_uri( $uri ) );
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
