<?php
/**
 * Redirect target suggestions.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests the valid paths closest to a broken one.
 *
 * Same scoring as the Drupal module (similar_text() percentage on the
 * lowercased paths), plus a comparison of the last segments alone: the slug
 * of a WordPress post usually survives a change of permalink structure or
 * category (/2021/05/my-post/ to /blog/my-post/), while the full paths then
 * differ a lot.
 */
class Path_Suggester {

	/**
	 * Minimum score of a suggestion shown on the fix page, in percent.
	 */
	public const MIN_SCORE = 80;

	/**
	 * Minimum length of the last segments compared on their own.
	 *
	 * Shorter ones ("en", "2", "amp") match too many unrelated paths.
	 */
	private const MIN_SLUG_LENGTH = 4;

	/**
	 * Maximum number of characters of a path compared.
	 *
	 * The time of similar_text() grows with the product of the lengths,
	 * and a broken path can be thousands of characters long (bots): without
	 * a cap, the fix page of such a path could time out on a large site.
	 * Beyond this length, a path is no link an editor would have written.
	 */
	private const MAX_LENGTH = 255;

	/**
	 * Constructor.
	 *
	 * @param Path_Provider $provider Source of the valid paths.
	 */
	public function __construct( protected Path_Provider $provider ) {
	}

	/**
	 * Returns the valid paths closest to a broken one, best first.
	 *
	 * On a multilingual site, the paths in the language of the broken one
	 * come first: a visitor of the English site should land on an English
	 * page, even if a page in another language has a closer URL.
	 *
	 * @param string $path      The broken path, decoded.
	 * @param int    $limit     Maximum number of suggestions.
	 * @param int    $min_score Minimum score, in percent.
	 * @return list<array{path: string, title: string, type: string, language: string, score: int}>
	 *     language: code of the language of the path, "" on a site with one language.
	 */
	public function suggest( string $path, int $limit = 5, int $min_score = self::MIN_SCORE ): array {
		$broken      = self::comparable( $path );
		$broken_slug = self::slug( $broken );
		$language    = Languages::of_path( $path ) ?? '';
		$suggestions = array();

		foreach ( $this->provider->get_candidates() as $candidate => $info ) {
			$valid = self::comparable( Hit_Recorder::decode_path( $candidate ) );
			// The broken path itself can't be valid: only listed by a filter.
			if ( $valid === $broken ) {
				continue;
			}

			similar_text( $broken, $valid, $percent );
			$valid_slug = self::slug( $valid );
			if ( null !== $broken_slug && null !== $valid_slug ) {
				similar_text( $broken_slug, $valid_slug, $slug_percent );
				$percent = max( $percent, $slug_percent );
			}

			$score = (int) round( $percent );
			if ( $score >= $min_score && $score > 0 ) {
				$suggestions[] = array(
					'path'     => $candidate,
					'title'    => $info['title'],
					'type'     => $info['type'],
					'language' => Languages::of_path( $candidate ) ?? '',
					'score'    => $score,
				);
			}
		}//end foreach

		// The language of the broken path first, then the best score, then
		// the shortest path: the more general page.
		usort(
			$suggestions,
			static fn( array $a, array $b ): int => array( (int) ( $b['language'] === $language ), $b['score'], strlen( $a['path'] ), $a['path'] )
				<=> array( (int) ( $a['language'] === $language ), $a['score'], strlen( $b['path'] ), $b['path'] )
		);
		return array_slice( $suggestions, 0, max( 0, $limit ) );
	}

	/**
	 * Returns a path as compared: lowercased, without leading and trailing
	 * slashes, cut to MAX_LENGTH characters.
	 *
	 * Case and trailing slash don't make a different URL for WordPress, so
	 * /About-Us and /about-us/ score as a perfect match.
	 *
	 * @param string $path A decoded path.
	 */
	private static function comparable( string $path ): string {
		return mb_substr( trim( mb_strtolower( $path, 'UTF-8' ), '/' ), 0, self::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Returns the last segment of a comparable path, if long enough to compare alone.
	 *
	 * @param string $path A comparable path.
	 */
	private static function slug( string $path ): ?string {
		$position = strrpos( $path, '/' );
		$slug     = false === $position ? $path : substr( $path, $position + 1 );
		return mb_strlen( $slug, 'UTF-8' ) >= self::MIN_SLUG_LENGTH && ! ctype_digit( $slug ) ? $slug : null;
	}
}
