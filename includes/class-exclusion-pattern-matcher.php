<?php
/**
 * Exclusion patterns.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and matches the "exclude from 404 logging" patterns of the settings.
 *
 * A pattern is either a PCRE regex delimited by #, ~ or % (e.g. "#^/wp-json/#"),
 * or, for anything else, a glob with "*" and "?" wildcards (e.g. "/feed/*").
 *
 * "/" is deliberately not accepted as a regex delimiter: every path starts
 * with "/", so a path such as "/user/login" would otherwise be read as the
 * regex "user" with the modifiers "login".
 */
final class Exclusion_Pattern_Matcher {

	/**
	 * Splits the raw textarea value into a list of trimmed, non-empty lines.
	 *
	 * @param string|null $raw The setting value, one pattern per line.
	 * @return string[]
	 */
	public static function split_patterns( ?string $raw ): array {
		if ( null === $raw || '' === trim( $raw ) ) {
			return array();
		}

		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $raw ) );
		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Whether a pattern is written as a delimited PCRE regex.
	 *
	 * @param string $pattern A pattern.
	 */
	public static function is_regex( string $pattern ): bool {
		return (bool) preg_match( '/^([#~%]).+\1[a-zA-Z]*$/', $pattern );
	}

	/**
	 * Whether a single pattern is syntactically valid.
	 *
	 * @param string $pattern A pattern.
	 */
	public static function is_valid( string $pattern ): bool {
		if ( ! self::is_regex( $pattern ) ) {
			// Globs have no invalid syntax.
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Testing the pattern validity.
		return false !== @preg_match( $pattern, '' );
	}

	/**
	 * Whether the given path matches at least one of the patterns.
	 *
	 * Patterns are admin-supplied but matched against attacker-controlled
	 * request paths, so a poorly written regex (catastrophic backtracking)
	 * must not be able to hang the site: the backtrack limit is temporarily
	 * lowered so a pathological match fails fast instead.
	 *
	 * @param string   $path     A path.
	 * @param string[] $patterns Patterns, see split_patterns().
	 */
	public static function matches_any( string $path, array $patterns ): bool {
		$previous_limit = ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Bounds the regexes run on attacker-controlled paths, restored below.

		try {
			foreach ( $patterns as $pattern ) {
				$regex = self::is_regex( $pattern ) ? $pattern : self::glob_to_regex( $pattern );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid pattern must not break the request.
				if ( 1 === @preg_match( $regex, $path ) ) {
					return true;
				}
			}

			return false;
		} finally {
			if ( false !== $previous_limit ) {
				ini_set( 'pcre.backtrack_limit', $previous_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restores the limit.
			}
		}
	}

	/**
	 * Converts a glob pattern ("*" and "?" wildcards) to a delimited regex.
	 *
	 * @param string $glob A glob pattern.
	 */
	protected static function glob_to_regex( string $glob ): string {
		$escaped = preg_quote( $glob, '#' );
		$escaped = str_replace( array( '\*', '\?' ), array( '.*', '.' ), $escaped );
		return '#^' . $escaped . '$#';
	}
}
