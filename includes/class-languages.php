<?php
/**
 * Languages of a multilingual site.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the language of the paths of a multilingual site.
 *
 * WordPress redirects are path based: a broken /en/old-page is recorded and
 * redirected with its language prefix, like any path. The language is only
 * needed to suggest targets in the language of the broken URL. Everything
 * Polylang-specific stays in this class; other multilingual plugins can
 * tell the language of a path with the lostlink_path_language
 * filter.
 */
final class Languages {

	/**
	 * Returns the language of a path, null on a site with one language.
	 *
	 * @param string $path A path relative to the home URL, encoded or not.
	 * @return string|null A language code, e.g. "en".
	 */
	public static function of_path( string $path ): ?string {
		$language = null;
		$links    = self::polylang() && count( pll_languages_list() ) > 1 ? PLL()->links_model : null;
		if ( null !== $links ) {
			// '' for a URL without language: the default one, when its
			// prefix is hidden.
			$language = $links->get_language_from_url( home_url( $path ) );
			if ( '' === $language ) {
				$default  = pll_default_language();
				$language = is_string( $default ) ? $default : null;
			}
		}

		/**
		 * Filters the language of a path.
		 *
		 * @param string|null $language A language code, null on a site with one language.
		 * @param string      $path     A path relative to the home URL.
		 */
		$language = apply_filters( 'lostlink_path_language', $language, $path );
		return is_string( $language ) && '' !== $language ? $language : null;
	}

	/**
	 * Returns the home URL of each language other than the default one.
	 *
	 * @return array<string, string> URLs keyed by language code.
	 */
	public static function home_urls(): array {
		if ( ! self::polylang() ) {
			return array();
		}

		$urls = array();
		foreach ( pll_languages_list() as $language ) {
			$url = pll_home_url( $language );
			if ( untrailingslashit( $url ) !== untrailingslashit( home_url() ) ) {
				$urls[ $language ] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Returns the name of a language, its code if unknown.
	 *
	 * @param string $language A language code.
	 */
	public static function name( string $language ): string {
		if ( self::polylang() ) {
			$names = array_combine( pll_languages_list(), pll_languages_list( array( 'fields' => 'name' ) ) );
			if ( isset( $names[ $language ] ) ) {
				return $names[ $language ];
			}
		}
		return $language;
	}

	/**
	 * Whether Polylang is active.
	 */
	private static function polylang(): bool {
		return function_exists( 'pll_languages_list' ) && function_exists( 'PLL' );
	}
}
