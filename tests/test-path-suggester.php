<?php
/**
 * Tests of the redirect target suggestions.
 *
 * @package Lostlink
 */

use Lostlink\Path_Provider;
use Lostlink\Path_Suggester;
use Lostlink\Redirect_Service;

/**
 * @covers \Lostlink\Path_Provider
 * @covers \Lostlink\Path_Suggester
 */
class Test_Path_Suggester extends WP_UnitTestCase {

	protected Path_Suggester $suggester;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		// The term permastructs are added when the taxonomies are registered.
		create_initial_taxonomies();
		Path_Provider::flush();
		$this->suggester = new Path_Suggester( new Path_Provider() );
	}

	public function tear_down(): void {
		remove_all_filters( 'lostlink_candidate_paths' );
		Path_Provider::flush();
		parent::tear_down();
	}

	/**
	 * Returns the suggested paths, keyed by path, with their score.
	 *
	 * @return array<string, int>
	 */
	protected function scores( string $path, int $limit = 5, int $min_score = Path_Suggester::MIN_SCORE ): array {
		return array_column( $this->suggester->suggest( $path, $limit, $min_score ), 'score', 'path' );
	}

	public function test_case_and_trailing_slash_are_ignored(): void {
		self::factory()->post->create(
			array(
				'post_name' => 'contact-us',
				'post_type' => 'page',
			)
		);

		// Compared lowercased, without the trailing slash: "contact-us" vs "contact-us-now".
		$this->assertSame( array( '/contact-us/' => 83 ), $this->scores( '/Contact-Us-Now/' ) );
	}

	public function test_same_path_with_another_case_is_not_suggested(): void {
		self::factory()->post->create(
			array(
				'post_name' => 'about-us',
				'post_type' => 'page',
			)
		);

		// Redirect_Service::fix() would refuse it as a loop.
		$this->assertSame( array(), $this->scores( '/About-Us' ) );
	}

	public function test_typo_is_suggested(): void {
		self::factory()->post->create( array( 'post_name' => 'contact-form' ) );

		$scores = $this->scores( '/contcat-form' );
		$this->assertArrayHasKey( '/contact-form/', $scores );
		$this->assertGreaterThanOrEqual( Path_Suggester::MIN_SCORE, $scores['/contact-form/'] );
	}

	public function test_moved_slug_is_suggested(): void {
		self::factory()->post->create( array( 'post_name' => 'my-great-article' ) );

		// The full paths are only ~60% similar: the slug match wins.
		$this->assertSame( array( '/my-great-article/' => 100 ), $this->scores( '/category/news/2021/05/my-great-article' ) );
	}

	public function test_short_or_numeric_last_segments_are_not_compared_alone(): void {
		self::factory()->post->create( array( 'post_name' => 'faq' ) );
		self::factory()->post->create( array( 'post_name' => '2021' ) );

		$this->assertSame( array(), $this->scores( '/some/unrelated/path/faq' ) );
		$this->assertSame( array(), $this->scores( '/archives/of/the/year/2021' ) );
	}

	public function test_unrelated_path_has_no_suggestion(): void {
		self::factory()->post->create( array( 'post_name' => 'hello-world' ) );

		$this->assertSame( array(), $this->scores( '/wp-login-backup.php' ) );
	}

	public function test_limit_and_order(): void {
		foreach ( array( 'page-abcd', 'page-abcde', 'page-abcdef', 'page-abcdefg' ) as $slug ) {
			self::factory()->post->create( array( 'post_name' => $slug ) );
		}

		$scores = $this->scores( '/page-abcdeff', 3, 0 );
		$this->assertSame(
			array(
				'/page-abcdef/'  => 96,
				'/page-abcdefg/' => 92,
				'/page-abcde/'   => 91,
			),
			$scores
		);
	}

	public function test_equal_scores_prefer_the_shortest_path(): void {
		self::factory()->post->create( array( 'post_name' => 'my-recipe' ) );
		$parent = self::factory()->post->create(
			array(
				'post_name' => 'archives',
				'post_type' => 'page',
			)
		);
		self::factory()->post->create(
			array(
				'post_name'   => 'my-recipe',
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);

		$this->assertSame( array( '/my-recipe/', '/archives/my-recipe/' ), array_keys( $this->scores( '/old/my-recipe' ) ) );
	}

	public function test_very_long_paths_are_compared_on_their_start(): void {
		$candidates = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$candidates[ '/' . str_repeat( 'a', 60 ) . "-$i/" ] = array(
				'title' => "Page $i",
				'type'  => 'Page',
			);
		}
		add_filter( 'lostlink_candidate_paths', static fn(): array => $candidates );

		// The worst case of similar_text() (repeated characters), on a path
		// as long as Apache accepts: about 3 seconds without the cap.
		$start = microtime( true );
		$this->assertSame( array(), $this->scores( '/' . str_repeat( 'a', 8000 ) ) );
		$this->assertLessThan( 0.5, microtime( true ) - $start );
	}

	public function test_the_language_of_the_broken_path_comes_first(): void {
		add_filter(
			'lostlink_candidate_paths',
			static fn(): array => array(
				'/red-shoes/'    => array(
					'title' => 'Red shoes',
					'type'  => 'Page',
				),
				'/en/red-shoe/'  => array(
					'title' => 'Red shoe',
					'type'  => 'Page',
				),
				'/fr/red-shoes/' => array(
					'title' => 'Red shoes (fr)',
					'type'  => 'Page',
				),
			)
		);
		// "/en/..." is English, the rest French (default language without prefix).
		add_filter( 'lostlink_path_language', static fn( $language, string $path ): string => str_starts_with( $path, '/en/' ) || '/en' === $path ? 'en' : 'fr', 10, 2 );

		$suggestions = $this->suggester->suggest( '/en/red-shoes', 5, 50 );
		// Then by score: both French ones match the last segment exactly, the shorter first.
		$this->assertSame( array( '/en/red-shoe/', '/red-shoes/', '/fr/red-shoes/' ), array_column( $suggestions, 'path' ) );
		$this->assertSame( array( 'en', 'fr', 'fr' ), array_column( $suggestions, 'language' ) );
		// The English page scores lower, but is in the language of the broken URL.
		$this->assertLessThan( $suggestions[1]['score'], $suggestions[0]['score'] );

		remove_all_filters( 'lostlink_path_language' );
		$this->assertSame( '', $this->suggester->suggest( '/en/red-shoes', 5, 50 )[0]['language'] );
	}

	public function test_min_score_filters(): void {
		self::factory()->post->create( array( 'post_name' => 'page-1' ) );

		$this->assertSame( array(), $this->scores( '/pag' ) );
		$this->assertArrayHasKey( '/page-1/', $this->scores( '/pag', 5, 50 ) );
	}

	public function test_only_published_public_content_is_a_candidate(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'draft-article',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_name'   => 'private-article',
				'post_status' => 'private',
			)
		);
		register_post_type( 'lostlink_internal', array( 'public' => false ) );
		self::factory()->post->create(
			array(
				'post_name' => 'internal-article',
				'post_type' => 'lostlink_internal',
			)
		);

		$candidates = ( new Path_Provider() )->get_candidates();
		$this->assertArrayHasKey( '/', $candidates );
		foreach ( array_keys( $candidates ) as $path ) {
			$this->assertStringNotContainsString( 'article', $path );
		}
		unregister_post_type( 'lostlink_internal' );
	}

	public function test_terms_and_archives_are_candidates(): void {
		$post = self::factory()->post->create();
		$term = self::factory()->category->create(
			array(
				'slug' => 'recipes',
				'name' => 'Recipes',
			)
		);
		wp_set_post_categories( $post, array( $term ) );
		self::factory()->category->create( array( 'slug' => 'empty-category' ) );
		register_post_type(
			'lostlink_product',
			array(
				'public'      => true,
				'has_archive' => 'products',
				'label'       => 'Products',
			)
		);

		$candidates = ( new Path_Provider() )->get_candidates();
		$this->assertSame( 'Recipes', $candidates['/category/recipes/']['title'] ?? null );
		$this->assertArrayNotHasKey( '/category/empty-category/', $candidates );
		$this->assertSame( 'Products', $candidates['/products/']['title'] ?? null );
		unregister_post_type( 'lostlink_product' );
	}

	public function test_plain_permalinks_are_left_out(): void {
		$this->set_permalink_structure( '' );
		Path_Provider::flush();
		self::factory()->post->create( array( 'post_name' => 'hello-plain' ) );

		$this->assertSame( array( '/' ), array_keys( ( new Path_Provider() )->get_candidates() ) );
	}

	public function test_cache_is_cleared_when_content_changes(): void {
		$provider = new Path_Provider();
		$this->assertArrayNotHasKey( '/new-article/', $provider->get_candidates() );

		$post = self::factory()->post->create( array( 'post_name' => 'new-article' ) );
		$this->assertArrayHasKey( '/new-article/', $provider->get_candidates() );

		wp_trash_post( $post );
		$this->assertArrayNotHasKey( '/new-article/', $provider->get_candidates() );
	}

	public function test_cache_is_per_locale(): void {
		$builds = 0;
		add_filter(
			'lostlink_candidate_paths',
			static function ( array $candidates ) use ( &$builds ): array {
				++$builds;
				return $candidates;
			}
		);
		$provider = new Path_Provider();

		$provider->get_candidates();
		$provider->get_candidates();
		$this->assertSame( 1, $builds );

		// A user of another language: its labels are translated differently.
		add_filter( 'determine_locale', static fn(): string => 'fr_FR' );
		$provider->get_candidates();
		$this->assertSame( 2, $builds );
		remove_all_filters( 'determine_locale' );
	}

	public function test_encoded_permalinks_are_compared_decoded(): void {
		self::factory()->post->create( array( 'post_name' => 'привет-мир' ) );

		$scores = $this->scores( '/Привет-Мир-2' );
		$this->assertSame( array( 95 ), array_values( $scores ) );
		$this->assertSame( '/привет-мир/', rawurldecode( (string) array_key_first( $scores ) ) );
	}

	public function test_filter_adds_candidates(): void {
		add_filter(
			'lostlink_candidate_paths',
			static function ( array $candidates ): array {
				$candidates['/shop/cart'] = array(
					'title' => 'Cart',
					'type'  => 'Custom',
				);
				return $candidates;
			}
		);

		$this->assertSame( 'Cart', $this->suggester->suggest( '/shop/kart' )[0]['title'] ?? null );
		// A broken path is never suggested as its own target.
		$this->assertSame( array(), $this->scores( '/Shop/Cart/' ) );
	}

	public function test_malformed_filtered_candidates_are_dropped(): void {
		add_filter(
			'lostlink_candidate_paths',
			static fn(): array => array(
				'/shop/cart'       => array( 'title' => array( 'not a string' ) ),
				'no-leading-slash' => array( 'title' => 'Cart' ),
				'/shop/basket'     => 'not an array',
				42                 => array( 'title' => 'Cart' ),
			)
		);

		$this->assertSame(
			array(
				'/shop/cart' => array(
					'title' => '',
					'type'  => '',
				),
			),
			( new Path_Provider() )->get_candidates()
		);
		$this->assertSame( '/shop/cart', $this->suggester->suggest( '/shop/kart' )[0]['path'] ?? null );

		// Not an array at all: the candidates WordPress knows are kept.
		remove_all_filters( 'lostlink_candidate_paths' );
		Path_Provider::flush();
		add_filter( 'lostlink_candidate_paths', '__return_null' );
		$this->assertArrayHasKey( '/', ( new Path_Provider() )->get_candidates() );
	}

	public function test_relative_url(): void {
		$this->assertSame( '/a/b?c=1', Redirect_Service::relative_url( home_url( '/a/b?c=1' ) ) );
		$this->assertSame( '/', Redirect_Service::relative_url( set_url_scheme( home_url(), 'https' ) ) );
		$this->assertNull( Redirect_Service::relative_url( 'https://example.com/a' ) );
	}
}
