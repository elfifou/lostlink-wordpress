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
	 * Returns a $_SERVER value as sent, unslashed, null if missing.
	 *
	 * @param string $key E.g. "REQUEST_URI".
	 */
	public static function server( string $key ): ?string {
		$value = $_SERVER[ $key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Unslashed below, raw: see text().
		return is_string( $value ) ? wp_unslash( $value ) : null;
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
