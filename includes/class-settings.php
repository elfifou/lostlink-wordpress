<?php
/**
 * Plugin settings.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin settings, stored in a single option.
 *
 * @phpstan-type Values array{
 *     log_assets: bool,
 *     exclude_patterns: string,
 *     row_limit: int,
 *     referer_limit: int,
 *     referer_retention_days: int,
 *     priority_critical_count: int,
 *     priority_critical_daily_count: int,
 *     priority_critical_recent_days: int,
 *     priority_high_week_count: int,
 *     priority_medium_daily_count: int,
 *     priority_medium_recent_days: int,
 *     redirect_backend: 'auto'|'builtin'|'redirection',
 *     sitemap_url: string,
 *     cached_404s: 'auto'|'always'|'never',
 * }
 */
final class Settings {

	/**
	 * Name of the option holding the settings.
	 */
	public const OPTION = 'lostlink_settings';

	/**
	 * Extensions of the asset 404s ignored unless "log_assets" is enabled.
	 *
	 * Regex fragments: "woff2?" matches both woff and woff2.
	 */
	public const ASSET_EXTENSIONS = array(
		'css',
		'js',
		'map',
		'png',
		'jpg',
		'jpeg',
		'jfif',
		'gif',
		'svg',
		'webp',
		'avif',
		'bmp',
		'ico',
		'woff2?',
		'ttf',
		'eot',
	);

	/**
	 * Default values, also the list of known settings and their types.
	 *
	 * @var Values
	 */
	public const DEFAULTS = array(
		'log_assets'                    => false,
		// One pattern per line, see Exclusion_Pattern_Matcher.
		'exclude_patterns'              => '',
		// Maximum number of broken URLs kept, least recently seen removed first. 0: no limit.
		'row_limit'                     => 10000,
		// Maximum number of referers kept per broken URL. 0: no limit.
		'referer_limit'                 => 50,
		// Referers not seen for this many days are removed. 0: kept forever.
		'referer_retention_days'        => 90,
		// Priority thresholds, see Priority::compute().
		'priority_critical_count'       => 200,
		'priority_critical_daily_count' => 20,
		'priority_critical_recent_days' => 1,
		'priority_high_week_count'      => 50,
		'priority_medium_daily_count'   => 5,
		'priority_medium_recent_days'   => 3,
		// Where redirects are created: "auto", "builtin" or "redirection", see Redirect_Service::backend().
		'redirect_backend'              => 'auto',
		// Sitemap whose URLs are also suggested as targets: a path of the site or
		// an http(s) URL. Empty: none, see Path_Provider::add_sitemap().
		'sitemap_url'                   => '',
		// Whether the 404s served by a page cache are counted: "auto", "always"
		// or "never", see Cache_Beacon.
		'cached_404s'                   => 'auto',
	);

	/**
	 * Values of the "cached_404s" setting.
	 *
	 * @var list<'auto'|'always'|'never'>
	 */
	public const CACHED_404S = array( 'auto', 'always', 'never' );

	/**
	 * Values of the "redirect_backend" setting.
	 *
	 * @var list<'auto'|'builtin'|'redirection'>
	 */
	public const REDIRECT_BACKENDS = array( 'auto', 'builtin', 'redirection' );

	/**
	 * Settings holding a number, with their minimum value.
	 *
	 * @var array<string, int>
	 */
	public const NUMBER_MINIMUMS = array(
		'row_limit'                     => 0,
		'referer_limit'                 => 0,
		'referer_retention_days'        => 0,
		'priority_critical_count'       => 1,
		'priority_critical_daily_count' => 1,
		'priority_critical_recent_days' => 1,
		'priority_high_week_count'      => 1,
		'priority_medium_daily_count'   => 1,
		'priority_medium_recent_days'   => 1,
	);

	/**
	 * Sanitizes the submitted settings, the register_setting() callback.
	 *
	 * Invalid values keep the current ones, with an error shown on the
	 * settings page, so a typo never silently resets a setting.
	 *
	 * @param mixed $input The submitted value.
	 * @return Values
	 */
	public static function sanitize( $input ): array {
		$clean = self::all();
		$input = is_array( $input ) ? $input : array();

		$clean['log_assets'] = ! in_array( self::text( $input['log_assets'] ?? null ), array( '', '0' ), true );

		$backend = self::text( $input['redirect_backend'] ?? null );
		foreach ( self::REDIRECT_BACKENDS as $allowed ) {
			if ( $allowed === $backend ) {
				$clean['redirect_backend'] = $allowed;
			}
		}

		$cached = self::text( $input['cached_404s'] ?? null );
		foreach ( self::CACHED_404S as $allowed ) {
			if ( $allowed === $cached ) {
				$clean['cached_404s'] = $allowed;
			}
		}

		$sitemap = trim( self::text( $input['sitemap_url'] ?? null ) );
		if ( '' === $sitemap || self::is_valid_sitemap_url( $sitemap ) ) {
			$clean['sitemap_url'] = $sitemap;
		} else {
			self::add_error( 'sitemap_url', __( 'The sitemap URL must be a path of this site starting with "/", or an http(s) URL. The previous value was kept.', 'lostlink' ) );
		}

		foreach ( self::NUMBER_MINIMUMS as $key => $minimum ) {
			$value = trim( self::text( $input[ $key ] ?? null ) );
			if ( 1 === preg_match( '/^\d+$/', $value ) && (int) $value >= $minimum ) {
				$clean[ $key ] = (int) $value;
				continue;
			}

			self::add_error(
				$key,
				/* translators: 1: setting label, 2: minimum value. */
				sprintf( __( '"%1$s" must be a whole number of at least %2$d. The previous value was kept.', 'lostlink' ), Settings_Page::label( $key ), $minimum )
			);
		}

		$patterns = Exclusion_Pattern_Matcher::split_patterns( self::text( $input['exclude_patterns'] ?? null ) );
		$invalid  = array_filter( $patterns, static fn( string $pattern ): bool => ! Exclusion_Pattern_Matcher::is_valid( $pattern ) );
		if ( array() !== $invalid ) {
			self::add_error(
				'exclude_patterns',
				/* translators: %s: list of patterns. */
				sprintf( __( 'Invalid regular expression: %s. The previous exclusion patterns were kept.', 'lostlink' ), implode( ', ', $invalid ) )
			);
		} else {
			$clean['exclude_patterns'] = implode( "\n", $patterns );
		}

		return $clean;
	}

	/**
	 * Whether a sitemap URL is a path of the site or an http(s) URL.
	 *
	 * Only the syntax: which hosts can be fetched is checked when fetching,
	 * see Path_Provider::fetch_sitemap().
	 *
	 * @param string $url The setting value.
	 */
	public static function is_valid_sitemap_url( string $url ): bool {
		if ( 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
			return false;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return ! str_starts_with( $url, '//' );
		}
		return Redirect_Service::is_http_url( $url );
	}

	/**
	 * Reports a settings error, shown on the settings page.
	 *
	 * The settings can also be saved outside the admin (WP-CLI, code), where
	 * the Settings API isn't loaded: the error is then dropped.
	 *
	 * @param string $code    Error code, the setting key.
	 * @param string $message Translated message.
	 */
	private static function add_error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}

	/**
	 * Returns all the settings, with the defaults for the missing ones.
	 *
	 * A saved value of another type than its default, or not among the
	 * allowed ones (an option edited by hand, say), is replaced by the default.
	 *
	 * @return Values
	 */
	public static function all(): array {
		$saved    = get_option( self::OPTION, array() );
		$settings = self::DEFAULTS;
		foreach ( is_array( $saved ) ? $saved : array() as $key => $value ) {
			if ( array_key_exists( $key, $settings ) && get_debug_type( $value ) === get_debug_type( $settings[ $key ] ) ) {
				$settings[ $key ] = $value;
			}
		}
		if ( ! in_array( $settings['redirect_backend'], self::REDIRECT_BACKENDS, true ) ) {
			$settings['redirect_backend'] = self::DEFAULTS['redirect_backend'];
		}
		if ( ! in_array( $settings['cached_404s'], self::CACHED_404S, true ) ) {
			$settings['cached_404s'] = self::DEFAULTS['cached_404s'];
		}

		/** @var Values $settings Every value checked above. */
		return $settings;
	}

	/**
	 * Returns a submitted value as a string, '' for an array or null.
	 *
	 * @param mixed $value A value of the submitted form.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
