<?php
/**
 * Valid paths of the site.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the valid paths of the site, the candidate targets of a redirect.
 *
 * Built from the content itself rather than from a sitemap: WordPress core
 * and the SEO plugins generate their sitemaps from the same data, and
 * reading it directly needs no HTTP request. The list is cached in a
 * transient, cleared whenever a post, a term or the permalinks change.
 */
class Path_Provider {

	/**
	 * Transient holding the candidates, with the locale of their labels.
	 */
	public const TRANSIENT = 'lostlink_candidates';

	/**
	 * Maximum number of posts listed, most recently modified first.
	 *
	 * Bounds the time and memory of a rebuild on large sites.
	 */
	public const MAX_POSTS = 5000;

	/**
	 * Maximum number of terms listed, most used first.
	 */
	public const MAX_TERMS = 2000;

	/**
	 * Number of posts loaded at once.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Transient holding the paths of the sitemap.
	 *
	 * Apart from the candidates: it costs HTTP requests, so it is not
	 * refetched each time a post changes.
	 */
	public const SITEMAP_TRANSIENT = 'lostlink_sitemap_paths';

	/**
	 * Maximum number of sitemap files fetched, the index included.
	 */
	public const MAX_SITEMAPS = 50;

	/**
	 * Maximum number of paths kept from the sitemap.
	 */
	public const MAX_SITEMAP_PATHS = 20000;

	/**
	 * Timeout of each sitemap request, in seconds.
	 */
	private const SITEMAP_TIMEOUT = 5;

	/**
	 * Time the sitemap files can take in all, in seconds: they are fetched
	 * while the fix page loads, and MAX_SITEMAPS slow files would take minutes.
	 */
	public const SITEMAP_TIME_LIMIT = 15.0;

	/**
	 * Maximum size of a sitemap file read, in bytes (the protocol limit is 50 MB
	 * uncompressed, real sitemaps are far smaller).
	 */
	private const SITEMAP_MAX_BYTES = 10 * MB_IN_BYTES;

	/**
	 * Hooks the cache invalidation into WordPress.
	 */
	public static function register(): void {
		foreach ( array( 'clean_post_cache', 'clean_term_cache', 'permalink_structure_changed', 'update_option_category_base', 'update_option_tag_base' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'flush_sitemap' ) );
	}

	/**
	 * Clears the cached candidates.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Clears the cached sitemap paths, and the candidates built from them.
	 */
	public static function flush_sitemap(): void {
		delete_transient( self::SITEMAP_TRANSIENT );
		self::flush();
	}

	/**
	 * Returns the candidate targets, keyed by path.
	 *
	 * Paths are relative to the home URL and encoded as in the permalinks.
	 * Content reachable only through a query string (plain permalinks) is
	 * left out: its URL says nothing a broken path could be compared with.
	 *
	 * @return array<string, array{title: string, type: string}>
	 */
	public function get_candidates(): array {
		// The type labels are translated: rebuilt for a user of another language.
		$locale = determine_locale();
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ( $cached['locale'] ?? null ) === $locale && is_array( $cached['candidates'] ?? null ) ) {
			/** @var array<string, array{title: string, type: string}> Built below. */
			return $cached['candidates'];
		}

		$candidates = array();
		$add        = static function ( string|false|null $url, string $title, string $type ) use ( &$candidates ): void {
			if ( ! is_string( $url ) || '' === $url || str_contains( $url, '?' ) ) {
				return;
			}
			$path = Redirect_Service::relative_url( $url );
			if ( null !== $path && ! isset( $candidates[ $path ] ) ) {
				$candidates[ $path ] = array(
					'title' => $title,
					'type'  => $type,
				);
			}
		};

		$add( home_url( '/' ), __( 'Home page', 'lostlink' ), __( 'Home page', 'lostlink' ) );
		foreach ( Languages::home_urls() as $language => $url ) {
			/* translators: %s: language name. */
			$add( $url, sprintf( __( 'Home page (%s)', 'lostlink' ), Languages::name( $language ) ), __( 'Home page', 'lostlink' ) );
		}
		$this->add_posts( $add );
		$this->add_archives( $add );
		$this->add_terms( $add );
		$this->add_sitemap( $add );

		/**
		 * Filters the candidate targets of the redirect suggestions.
		 *
		 * Add the paths WordPress doesn't know as content, e.g. pages of a
		 * custom router.
		 *
		 * @param array<string, array{title: string, type: string}> $candidates Keyed by path relative to the home URL.
		 */
		$filtered = apply_filters( 'lostlink_candidate_paths', $candidates );
		// @phpstan-ignore function.alreadyNarrowedType (A callback can return any type.)
		$candidates = is_array( $filtered ) ? self::valid_candidates( $filtered ) : $candidates;

		set_transient(
			self::TRANSIENT,
			array(
				'locale'     => $locale,
				'candidates' => $candidates,
			),
			HOUR_IN_SECONDS
		);
		return $candidates;
	}

	/**
	 * Adds the published posts of the public post types.
	 *
	 * Attachments are left out: WordPress redirects their pages to the file
	 * by default.
	 *
	 * @param callable $add Adds a candidate: URL, title, type label.
	 */
	protected function add_posts( callable $add ): void {
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		if ( array() === $types ) {
			return;
		}

		for ( $offset = 0; $offset < self::MAX_POSTS; $offset += self::BATCH_SIZE ) {
			$posts = get_posts(
				array(
					'post_type'              => array_values( $types ),
					'post_status'            => 'publish',
					'posts_per_page'         => min( self::BATCH_SIZE, self::MAX_POSTS - $offset ),
					'offset'                 => $offset,
					'orderby'                => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					// All languages with Polylang, whatever the admin language filter.
					'lang'                   => '',
				)
			);

			foreach ( $posts as $post ) {
				$type = get_post_type_object( $post->post_type );
				$add( get_permalink( $post ), get_the_title( $post ), null !== $type ? $type->labels->singular_name : $post->post_type );
			}
			if ( count( $posts ) < self::BATCH_SIZE ) {
				break;
			}
		}//end for
	}

	/**
	 * Adds the archives of the public post types that have one.
	 *
	 * @param callable $add Adds a candidate: URL, title, type label.
	 */
	protected function add_archives( callable $add ): void {
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( false !== $type->has_archive ) {
				$add( get_post_type_archive_link( $type->name ), $type->labels->name, __( 'Archive', 'lostlink' ) );
			}
		}
	}

	/**
	 * Adds the URLs of the sitemap set in the settings, if any.
	 *
	 * Added last: a page also known as content keeps its title.
	 *
	 * @param callable $add Adds a candidate: URL, title, type label.
	 */
	protected function add_sitemap( callable $add ): void {
		foreach ( $this->get_sitemap_paths() as $path ) {
			$add( home_url( $path ), '', __( 'Sitemap', 'lostlink' ) );
		}
	}

	/**
	 * Returns the paths listed in the sitemap set in the settings, cached.
	 *
	 * @return string[] Paths relative to the home URL.
	 */
	public function get_sitemap_paths(): array {
		$url = Settings::all()['sitemap_url'];
		if ( '' === $url ) {
			return array();
		}

		$cached = get_transient( self::SITEMAP_TRANSIENT );
		if ( is_array( $cached ) ) {
			/** @var list<string> Built below. */
			return $cached;
		}

		$sitemap = str_starts_with( $url, '/' ) ? home_url( $url ) : $url;
		$paths   = array();
		$visited = array();
		$this->fetch_sitemap( $sitemap, (string) wp_parse_url( $sitemap, PHP_URL_HOST ), $this->now() + self::SITEMAP_TIME_LIMIT, $paths, $visited );

		// Cached even when empty or failed: a missing sitemap must not cost
		// a request on each fix page.
		$paths = array_map( 'strval', array_keys( $paths ) );
		set_transient( self::SITEMAP_TRANSIENT, $paths, HOUR_IN_SECONDS );
		return $paths;
	}

	/**
	 * Fetches a sitemap or sitemap index, and adds the paths of the site it lists.
	 *
	 * Requests go through wp_safe_remote_get(), which only reaches public
	 * addresses (the site itself aside), also after a redirect, so the setting
	 * can't be used to probe internal services. The sitemaps of an index are
	 * only followed on the host of the configured one, and their number is
	 * capped, as is the time they take in all.
	 *
	 * @param string              $url      Sitemap URL.
	 * @param string              $host     Host of the configured sitemap.
	 * @param float               $deadline Time after which no more file is fetched.
	 * @param array<string, true> $paths    Paths found, as keys.
	 * @param array<string, true> $visited  Sitemaps already fetched, as keys.
	 */
	protected function fetch_sitemap( string $url, string $host, float $deadline, array &$paths, array &$visited ): void {
		$time_left = $deadline - $this->now();
		if ( isset( $visited[ $url ] ) || count( $visited ) >= self::MAX_SITEMAPS || count( $paths ) >= self::MAX_SITEMAP_PATHS
			|| $time_left < 1 || 0 !== strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) {
			return;
		}
		$visited[ $url ] = true;

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => min( self::SITEMAP_TIMEOUT, $time_left ),
				'limit_response_size' => self::SITEMAP_MAX_BYTES,
				'redirection'         => 3,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		// LIBXML_NONET: never fetch anything the document refers to. External
		// entities are not loaded since PHP 8.0, and entities not substituted.
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( wp_remote_retrieve_body( $response ), \SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return;
		}

		if ( 'sitemapindex' === $xml->getName() ) {
			foreach ( self::locs( $xml, 'sitemapindex', 'sitemap' ) as $loc ) {
				$this->fetch_sitemap( $loc, $host, $deadline, $paths, $visited );
			}
		} elseif ( 'urlset' === $xml->getName() ) {
			foreach ( self::locs( $xml, 'urlset', 'url' ) as $loc ) {
				$path = Redirect_Service::relative_url( $loc );
				// Paths with a query string are left out, as for the content.
				if ( null !== $path && ! str_contains( $path, '?' ) ) {
					$paths[ explode( '#', $path, 2 )[0] ] = true;
					if ( count( $paths ) >= self::MAX_SITEMAP_PATHS ) {
						return;
					}
				}
			}
		}
	}

	/**
	 * Returns the <loc> values of a sitemap or sitemap index.
	 *
	 * The sitemap namespace is the default one: the elements are read by
	 * local name, whatever their namespace.
	 *
	 * @param \SimpleXMLElement $xml    The document.
	 * @param string            $root   "sitemapindex" or "urlset".
	 * @param string            $entry  "sitemap" or "url".
	 * @return list<string>
	 */
	private static function locs( \SimpleXMLElement $xml, string $root, string $entry ): array {
		$locs = $xml->xpath( "/*[local-name()='$root']/*[local-name()='$entry']/*[local-name()='loc']" );
		return is_array( $locs ) ? array_map( static fn( \SimpleXMLElement $loc ): string => trim( (string) $loc ), array_values( $locs ) ) : array();
	}

	/**
	 * Keeps the well-formed candidates of the lostlink_candidate_paths
	 * filter, so a faulty callback can't break the suggestions.
	 *
	 * @param array<mixed> $candidates The filtered candidates.
	 * @return array<string, array{title: string, type: string}>
	 */
	private static function valid_candidates( array $candidates ): array {
		$valid = array();
		foreach ( $candidates as $path => $info ) {
			if ( is_string( $path ) && str_starts_with( $path, '/' ) && is_array( $info ) ) {
				$valid[ $path ] = array(
					'title' => is_string( $info['title'] ?? null ) ? $info['title'] : '',
					'type'  => is_string( $info['type'] ?? null ) ? $info['type'] : '',
				);
			}
		}
		return $valid;
	}

	/**
	 * Returns the current time, in seconds with microseconds.
	 */
	protected function now(): float {
		return microtime( true );
	}

	/**
	 * Adds the non-empty terms of the public taxonomies.
	 *
	 * @param callable $add Adds a candidate: URL, title, type label.
	 */
	protected function add_terms( callable $add ): void {
		$taxonomies = array_diff(
			get_taxonomies(
				array(
					'public'             => true,
					'publicly_queryable' => true,
				)
			),
			array( 'post_format' )
		);
		if ( array() === $taxonomies ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'               => array_values( $taxonomies ),
				'hide_empty'             => true,
				'number'                 => self::MAX_TERMS,
				'orderby'                => 'count',
				'order'                  => 'DESC',
				'update_term_meta_cache' => false,
				'lang'                   => '',
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$link     = get_term_link( $term );
			$taxonomy = get_taxonomy( $term->taxonomy );
			$add( is_wp_error( $link ) ? null : $link, $term->name, false !== $taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy );
		}
	}
}
