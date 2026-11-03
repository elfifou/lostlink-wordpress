<?php
/**
 * Tests of the sitemap as a source of target suggestions.
 *
 * @package Lostlink
 */

use Lostlink\Path_Provider;
use Lostlink\Settings;

/**
 * @covers \Lostlink\Path_Provider
 * @covers \Lostlink\Settings::is_valid_sitemap_url
 */
class Test_Sitemap extends WP_UnitTestCase {

	/**
	 * Sitemap responses, keyed by URL.
	 *
	 * @var array<string, string>
	 */
	protected array $responses = array();

	/**
	 * URLs requested, with the arguments of each request.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Path_Provider::flush_sitemap();
		add_filter( 'pre_http_request', array( $this, 'respond' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'respond' ), 10 );
		Path_Provider::flush_sitemap();
		parent::tear_down();
	}

	/**
	 * Answers the sitemap requests from $responses, 404 for any other URL.
	 *
	 * @param false|array $pre  Preemptive response.
	 * @param array       $args Request arguments.
	 * @param string      $url  Request URL.
	 */
	public function respond( $pre, array $args, string $url ): array {
		$this->requests[ $url ] = $args;
		return array(
			'headers'  => array(),
			'body'     => $this->responses[ $url ] ?? '',
			'response' => array(
				'code'    => isset( $this->responses[ $url ] ) ? 200 : 404,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Returns a urlset listing the given URLs.
	 *
	 * @param string[] $urls URLs.
	 */
	protected static function urlset( array $urls ): string {
		$items = implode( '', array_map( static fn( string $url ): string => '<url><loc>' . esc_html( $url ) . '</loc></url>', $urls ) );
		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</urlset>';
	}

	/**
	 * Returns a sitemap index listing the given sitemaps.
	 *
	 * @param string[] $urls Sitemap URLs.
	 */
	protected static function index( array $urls ): string {
		$items = implode( '', array_map( static fn( string $url ): string => '<sitemap><loc>' . esc_html( $url ) . '</loc></sitemap>', $urls ) );
		return '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</sitemapindex>';
	}

	public function test_no_sitemap_by_default(): void {
		$this->assertSame( array(), ( new Path_Provider() )->get_sitemap_paths() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_index_and_urlsets_of_the_site(): void {
		update_option( Settings::OPTION, array( 'sitemap_url' => '/sitemap_index.xml' ) );
		$this->responses = array(
			'http://example.org/sitemap_index.xml' => self::index( array( 'http://example.org/pages.xml', 'https://other.example/evil.xml', 'http://example.org/missing.xml' ) ),
			'http://example.org/pages.xml'         => self::urlset(
				array(
					'http://example.org/custom/route/',
					'https://example.org/caf%C3%A9/#top',
					'http://example.org/search/?s=x',
					'https://other.example/page/',
				)
			),
		);

		$paths = ( new Path_Provider() )->get_sitemap_paths();

		$this->assertSame( array( '/custom/route/', '/caf%C3%A9/' ), $paths );
		// Sub-sitemaps on another host are never fetched.
		$this->assertArrayNotHasKey( 'https://other.example/evil.xml', $this->requests );
		// Only through wp_safe_remote_get(): no private address, no unusual port.
		$this->assertTrue( $this->requests['http://example.org/sitemap_index.xml']['reject_unsafe_urls'] );

		// The sitemap paths are suggested, with the content ones.
		$candidates = ( new Path_Provider() )->get_candidates();
		$this->assertSame( 'Sitemap', $candidates['/custom/route/']['type'] ?? null );
	}

	public function test_cached_until_the_settings_change(): void {
		update_option( Settings::OPTION, array( 'sitemap_url' => 'http://example.org/sitemap.xml' ) );
		$this->responses = array( 'http://example.org/sitemap.xml' => self::urlset( array( 'http://example.org/a/' ) ) );
		( new Path_Provider() )->get_sitemap_paths();

		$this->responses = array( 'http://example.org/sitemap.xml' => self::urlset( array( 'http://example.org/b/' ) ) );
		$this->assertSame( array( '/a/' ), ( new Path_Provider() )->get_sitemap_paths() );
		// Content changes don't refetch it.
		Path_Provider::flush();
		$this->assertSame( array( '/a/' ), ( new Path_Provider() )->get_sitemap_paths() );

		update_option( Settings::OPTION, array( 'sitemap_url' => 'http://example.org/sitemap.xml?v=2' ) );
		$this->responses['http://example.org/sitemap.xml?v=2'] = $this->responses['http://example.org/sitemap.xml'];
		$this->assertSame( array( '/b/' ), ( new Path_Provider() )->get_sitemap_paths() );
	}

	public function test_invalid_or_hostile_documents_are_ignored(): void {
		update_option( Settings::OPTION, array( 'sitemap_url' => '/sitemap.xml' ) );
		$this->responses = array(
			'http://example.org/sitemap.xml' => '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset><url><loc>http://example.org/&xxe;</loc></url><url><loc>http://example.org/ok/</loc></url></urlset>',
		);
		// The external entity is never resolved: its URL is read without it.
		$this->assertSame( array( '/', '/ok/' ), ( new Path_Provider() )->get_sitemap_paths() );

		Path_Provider::flush_sitemap();
		$this->responses = array( 'http://example.org/sitemap.xml' => 'not xml' );
		$this->assertSame( array(), ( new Path_Provider() )->get_sitemap_paths() );
	}

	public function test_number_of_sitemaps_is_capped(): void {
		update_option( Settings::OPTION, array( 'sitemap_url' => '/sitemap.xml' ) );
		$sitemaps = array();
		for ( $i = 0; $i < 2 * Path_Provider::MAX_SITEMAPS; $i++ ) {
			$sitemaps[] = "http://example.org/sitemap-$i.xml";
			$this->responses[ "http://example.org/sitemap-$i.xml" ] = self::urlset( array( "http://example.org/page-$i/" ) );
		}
		$this->responses['http://example.org/sitemap.xml'] = self::index( $sitemaps );

		$this->assertCount( Path_Provider::MAX_SITEMAPS - 1, ( new Path_Provider() )->get_sitemap_paths() );
		$this->assertCount( Path_Provider::MAX_SITEMAPS, $this->requests );
	}

	public function test_total_fetch_time_is_capped(): void {
		update_option( Settings::OPTION, array( 'sitemap_url' => '/sitemap.xml' ) );
		$sitemaps = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$sitemaps[] = "http://example.org/sitemap-$i.xml";
			$this->responses[ "http://example.org/sitemap-$i.xml" ] = self::urlset( array( "http://example.org/page-$i/" ) );
		}
		$this->responses['http://example.org/sitemap.xml'] = self::index( $sitemaps );

		// Each request takes 4 seconds.
		$provider = new class() extends Path_Provider {
			public float $clock = 1000.0;

			protected function now(): float {
				return $this->clock;
			}
		};
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( $provider ) {
				$provider->clock += 4;
				return $pre;
			},
			5
		);

		// The index at 0 s, sitemaps at 4, 8 and 12 s (3 s left: its timeout),
		// then none.
		$this->assertSame( array( '/page-0/', '/page-1/', '/page-2/' ), $provider->get_sitemap_paths() );
		$this->assertCount( 4, $this->requests );
		$this->assertEqualsWithDelta( 3.0, $this->requests['http://example.org/sitemap-2.xml']['timeout'], 0.001 );
	}

	/**
	 * @dataProvider data_sitemap_urls
	 */
	public function test_sitemap_url_setting( string $url, bool $valid ): void {
		$this->assertSame( $valid, Settings::is_valid_sitemap_url( $url ) );
	}

	public function data_sitemap_urls(): array {
		return array(
			'path'              => array( '/wp-sitemap.xml', true ),
			'https'             => array( 'https://example.org/sitemap.xml', true ),
			'protocol-relative' => array( '//evil.example/sitemap.xml', false ),
			'file'              => array( 'file:///etc/passwd', false ),
			'relative'          => array( 'sitemap.xml', false ),
			'space'             => array( '/site map.xml', false ),
		);
	}
}
