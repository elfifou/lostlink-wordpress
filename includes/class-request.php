<?php
/**
 * Request parameters.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the parameters of the current request, typed and sanitized.
 *
 * Each method reads one parameter and unslashes, sanitizes and validates it
 * in the same expression, with the function that fits its type: the
 * superglobals are only read here, one key at a time.
 *
 * Any parameter can be sent as an array (?tab[]=x): a parameter read as a
 * string or a number is then missing, and a list drops its nested arrays.
 * Nonces are checked by the handlers of the actions, not here: most
 * parameters only choose what is shown.
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
	 * Maximum length of a request URI, in bytes: longer ones are ignored.
	 */
	public const MAX_URI_LENGTH = 2048;

	/**
	 * Maximum length of a referer kept, in bytes: longer ones are dropped.
	 */
	public const MAX_REFERER_LENGTH = 2048;

	/**
	 * Returns a parameter through sanitize_key(), null if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function key( string $key, string $source = self::GET ): ?string {
		return match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : null,
			self::POST => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : null,
			self::ANY  => isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : null,
		};
	}

	/**
	 * Returns a parameter through sanitize_text_field(), '' if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function search( string $key, string $source = self::GET ): string {
		return match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '',
			self::POST => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '',
			self::ANY  => isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '',
		};
	}

	/**
	 * Returns a parameter as a non-negative integer, null if missing.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function int( string $key, string $source = self::GET ): ?int {
		return match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : null,
			self::POST => isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : null,
			self::ANY  => isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ? absint( wp_unslash( $_REQUEST[ $key ] ) ) : null,
		};
	}

	/**
	 * Returns a list parameter (items[]=...) through sanitize_key().
	 *
	 * A single value counts as a list of one. Nested arrays (sanitized
	 * item by item by map_deep()) and empty keys are dropped.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return list<string>
	 */
	public static function keys( string $key, string $source = self::GET ): array {
		$keys = match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) ? map_deep( wp_unslash( (array) $_GET[ $key ] ), 'sanitize_key' ) : array(),
			self::POST => isset( $_POST[ $key ] ) ? map_deep( wp_unslash( (array) $_POST[ $key ] ), 'sanitize_key' ) : array(),
			self::ANY  => isset( $_REQUEST[ $key ] ) ? map_deep( wp_unslash( (array) $_REQUEST[ $key ] ), 'sanitize_key' ) : array(),
		};
		$list = array();
		foreach ( (array) $keys as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$list[] = $item;
			}
		}
		return $list;
	}

	/**
	 * Returns a list parameter (items[]=...) as positive integers (IDs).
	 *
	 * A single value counts as a list of one. Nested arrays and values that
	 * are not positive integers are dropped.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 * @return list<int>
	 */
	public static function ints( string $key, string $source = self::GET ): array {
		$ints = match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) ? map_deep( wp_unslash( (array) $_GET[ $key ] ), 'absint' ) : array(),
			self::POST => isset( $_POST[ $key ] ) ? map_deep( wp_unslash( (array) $_POST[ $key ] ), 'absint' ) : array(),
			self::ANY  => isset( $_REQUEST[ $key ] ) ? map_deep( wp_unslash( (array) $_REQUEST[ $key ] ), 'absint' ) : array(),
		};
		$list = array();
		foreach ( (array) $ints as $item ) {
			if ( is_int( $item ) && 0 !== $item ) {
				$list[] = $item;
			}
		}
		return $list;
	}

	/**
	 * Returns a URL parameter through wp_sanitize_redirect(), null if missing.
	 *
	 * For redirect targets: the function wp_redirect() applies to its
	 * location (spaces and non-ASCII characters percent-encoded, characters
	 * not allowed in a URL dropped), so the target saved is the one served.
	 * Unlike esc_url_raw(), it never adds "http://" to a relative value,
	 * which Redirect_Service::normalize_target() must see to refuse it.
	 * Spaces around the value (pasted URLs) are ignored.
	 *
	 * @param string                         $key    Parameter name.
	 * @param self::GET|self::POST|self::ANY $source Where the parameter is read.
	 */
	public static function url( string $key, string $source = self::GET ): ?string {
		$url = match ( $source ) {
			self::GET  => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_sanitize_redirect( wp_unslash( $_GET[ $key ] ) ) : null,
			self::POST => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_sanitize_redirect( wp_unslash( $_POST[ $key ] ) ) : null,
			self::ANY  => isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? wp_sanitize_redirect( wp_unslash( $_REQUEST[ $key ] ) ) : null,
		};
		// wp_sanitize_redirect() encodes the spaces around the value too.
		return null === $url ? null : preg_replace( '/^(?:%20)+|(?:%20)+$/', '', $url );
	}

	/**
	 * Returns the request URI, sanitized and validated, null if missing or
	 * invalid (no 404 is then recorded nor redirected for the request).
	 *
	 * The URI is what a 404 is recorded for and a redirect matched against,
	 * so it must keep its meaning: see sanitize_uri().
	 */
	public static function uri(): ?string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		return self::validate_uri( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}

	/**
	 * Sanitizes and validates a request URI (path and query string).
	 *
	 * - Sanitized with esc_url_raw(): characters not allowed in a URL are
	 *   dropped, spaces encoded, encoded line breaks (%0A, %0D) removed. The
	 *   characters browsers send percent-encoded stay encoded, so a URL a
	 *   browser requests keeps its meaning, and its decoded path stays the
	 *   one recorded and matched.
	 * - Validated: a path starting with "/", at most MAX_URI_LENGTH bytes,
	 *   with a well-formed percent-encoding and no control character once
	 *   decoded; null otherwise.
	 *
	 * The decoded path is then only hashed, passed to $wpdb->prepare() and
	 * escaped on output.
	 *
	 * @param string $uri A request URI.
	 */
	public static function sanitize_uri( string $uri ): ?string {
		return self::validate_uri( esc_url_raw( $uri ) );
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
	 * Validates a request URI already sanitized with esc_url_raw(), see
	 * sanitize_uri().
	 *
	 * @param string $uri A sanitized request URI.
	 */
	private static function validate_uri( string $uri ): ?string {
		if ( ! str_starts_with( $uri, '/' ) || strlen( $uri ) > self::MAX_URI_LENGTH ) {
			return null;
		}
		// A "%" must start an encoded octet.
		if ( 1 === preg_match( '/%(?![0-9a-f]{2})/i', $uri ) ) {
			return null;
		}
		$path = strtok( $uri, '?#' );
		return 1 === preg_match( '/[\x00-\x1f\x7f]/', rawurldecode( $path ) ) ? null : $uri;
	}
}
