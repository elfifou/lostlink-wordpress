<?php
/**
 * Request parameters.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the parameters of the current request, typed.
 *
 * Any parameter can be sent as an array (?tab[]=x): a parameter read as a
 * string or a number is then missing, and a list keeps only its scalar items.
 * Values are unslashed. Nonces are checked by the handlers of the actions,
 * not here: most parameters only choose what is shown.
 *
 * phpcs:disable WordPress.Security.NonceVerification -- See above.
 */
final class Request {

	/**
	 * Sources of the parameters.
	 */
	public const GET  = 'get';
	public const POST = 'post';
	public const ANY  = 'request';

	/**
	 * Returns a parameter as sent, unslashed, null if missing or not a string.
	 *
	 * Not sanitized: for values validated by the caller, e.g. a redirect target.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function text( string $key, string $source = self::GET ): ?string {
		$value = self::raw( $key, $source );
		return is_string( $value ) ? wp_unslash( $value ) : null;
	}

	/**
	 * Returns a parameter through sanitize_key(), null if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function key( string $key, string $source = self::GET ): ?string {
		$value = self::text( $key, $source );
		return null === $value ? null : sanitize_key( $value );
	}

	/**
	 * Returns a parameter through sanitize_text_field(), '' if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function search( string $key, string $source = self::GET ): string {
		return sanitize_text_field( self::text( $key, $source ) ?? '' );
	}

	/**
	 * Returns a parameter as a non-negative integer, null if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function int( string $key, string $source = self::GET ): ?int {
		$value = self::raw( $key, $source );
		return is_scalar( $value ) ? absint( $value ) : null;
	}

	/**
	 * Returns a list parameter (items[]=...) through sanitize_key().
	 *
	 * A single value counts as a list of one.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return list<string>
	 */
	public static function keys( string $key, string $source = self::GET ): array {
		return array_map( 'sanitize_key', self::strings( $key, $source ) );
	}

	/**
	 * Returns a list parameter (items[]=...) as non-negative integers.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return list<int>
	 */
	public static function ints( string $key, string $source = self::GET ): array {
		return array_map( 'absint', self::strings( $key, $source ) );
	}

	/**
	 * Maximum length of a referer kept, in bytes: longer ones are dropped.
	 */
	public const MAX_REFERER_LENGTH = 2048;

	/**
	 * Returns the request URI, unslashed, "/" if missing.
	 *
	 * Not sanitized on purpose: it is the URL a 404 is recorded for and a
	 * redirect matched against, so it must stay exactly as requested
	 * (sanitize_text_field() would drop its %XX octets, esc_url_raw() some
	 * characters, and a redirect would then never match the requested URL).
	 * It is never output nor queried as is: its path is URL-decoded, hashed
	 * and passed to $wpdb->prepare(), and escaped on output.
	 */
	public static function uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared and hashed, never output as is: see above.
		$value = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return '' === $value ? '/' : $value;
	}

	/**
	 * Returns the Referer header as a sanitized http(s) URL, null if missing
	 * or not a valid one.
	 */
	public static function referer(): ?string {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) || ! is_string( $_SERVER['HTTP_REFERER'] ) ) {
			return null;
		}
		return self::sanitize_referer( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ), array( 'http', 'https' ) ) );
	}

	/**
	 * Sanitizes a referer: an http(s) URL with a host, through esc_url_raw(),
	 * at most MAX_REFERER_LENGTH bytes; null for anything else.
	 *
	 * The referer is sent by the client: a bot can send any value.
	 *
	 * @param string $referer A referer.
	 */
	public static function sanitize_referer( string $referer ): ?string {
		$url = esc_url_raw( $referer, array( 'http', 'https' ) );
		if ( '' === $url || strlen( $url ) > self::MAX_REFERER_LENGTH || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $url : null;
	}

	/**
	 * Returns the string items of a list parameter, unslashed.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return list<string>
	 */
	private static function strings( string $key, string $source ): array {
		$value = self::raw( $key, $source );
		$items = is_array( $value ) ? $value : array( $value );
		return array_values( array_map( 'wp_unslash', array_filter( $items, 'is_string' ) ) );
	}

	/**
	 * Returns a parameter, of any type.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return mixed
	 */
	private static function raw( string $key, string $source ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Typed and unslashed by the callers.
		$params = match ( $source ) {
			self::GET  => $_GET,
			self::POST => $_POST,
			self::ANY  => $_REQUEST,
		};
		return $params[ $key ] ?? null;
	}
}
