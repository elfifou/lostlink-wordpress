<?php
/**
 * Detection of the 404 hits.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Records the 404s served by WordPress, applying the plugin's filtering rules.
 */
class Hit_Recorder {

	/**
	 * Priority on template_redirect.
	 *
	 * Right after redirect_canonical() (priority 10): a 404 WordPress fixes
	 * itself by redirecting to the guessed permalink is not a 404 for the
	 * visitor. Running before the later handlers still records the 404s that
	 * other plugins hide behind a redirect to the home page or a search.
	 */
	public const PRIORITY = 11;

	/**
	 * Whether the current request was already recorded.
	 *
	 * @var bool
	 */
	protected bool $handled = false;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage The hits storage.
	 */
	public function __construct( protected Storage $storage ) {}

	/**
	 * Hooks the recorder into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_record' ), self::PRIORITY );
	}

	/**
	 * Records the current request if WordPress is serving a 404 for it.
	 */
	public function maybe_record(): void {
		if ( $this->handled || ! is_404() ) {
			return;
		}
		$this->handled = true;

		// Raw: the path is only stored and compared, the referer escaped on output.
		$uri     = Request::server( 'REQUEST_URI' ) ?? '/';
		$referer = Request::server( 'HTTP_REFERER' );

		$this->record( $uri, $referer, null, true );
	}

	/**
	 * Records a 404 hit on a request URI, unless it is filtered out.
	 *
	 * Never throws: the URI and referer are attacker-controlled, and a failure
	 * to record them must never turn the visitor's 404 page into an error.
	 *
	 * @param string      $uri     The request URI (path and query string, as sent by the client).
	 * @param string|null $referer The Referer header, if any.
	 * @param int|null    $time    Timestamp of the hit, now by default.
	 * @param bool        $live    Whether the 404 is being served now, not imported: a URL fixed by
	 *                             a redirect then gets reopened, see Storage::record_hit().
	 * @return bool Whether the hit was counted.
	 */
	public function record( string $uri, ?string $referer = null, ?int $time = null, bool $live = false ): bool {
		try {
			$raw_path = self::get_raw_path( $uri );
			$path     = self::decode_path( $raw_path );

			// Patterns may target either the decoded or the raw form.
			if ( ! $this->should_record( $path ) || ( $raw_path !== $path && ! $this->should_record( $raw_path ) ) ) {
				return false;
			}

			return $this->storage->record_hit( $path, $time ?? time(), $referer, $live );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No logger in WordPress core.
			error_log( 'Lostlink: unable to record a 404 hit: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Whether a 404 on the given path should be recorded.
	 *
	 * @param string $path A path, starting with "/".
	 */
	public function should_record( string $path ): bool {
		$settings = Settings::all();

		if ( ! $settings['log_assets'] && 1 === preg_match( '/\.(' . implode( '|', Settings::ASSET_EXTENSIONS ) . ')$/i', $path ) ) {
			return false;
		}

		$patterns = Exclusion_Pattern_Matcher::split_patterns( $settings['exclude_patterns'] );
		if ( array() !== $patterns && Exclusion_Pattern_Matcher::matches_any( $path, $patterns ) ) {
			return false;
		}

		/**
		 * Filters whether a 404 on a path is recorded.
		 *
		 * @param bool   $record Whether the 404 is recorded.
		 * @param string $path   The path, starting with "/", relative to the site's home.
		 */
		// @phpstan-ignore cast.useless (A callback can return any type.)
		return (bool) apply_filters( 'lostlink_should_record', true, $path );
	}

	/**
	 * Returns the path of a request URI, relative to the site's home.
	 *
	 * Without query string, and without the home path of a site installed in
	 * a subdirectory: "/blog/old-page?x=1" is "/old-page" for a site at
	 * "/blog/", the path a redirect is created for.
	 *
	 * @param string $uri The request URI.
	 */
	public static function get_raw_path( string $uri ): string {
		// Not wp_parse_url(): it reads "//example.com/x" as a host.
		$path = (string) strtok( $uri, '?#' );

		$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '' !== $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * URL-decodes a path, keeping it encoded if the result isn't valid UTF-8.
	 *
	 * Invalid UTF-8 can't be stored in the database, and is never a link an
	 * editor wrote: it is kept in its encoded form.
	 *
	 * @param string $raw_path An URL-encoded path.
	 */
	public static function decode_path( string $raw_path ): string {
		$path = rawurldecode( $raw_path );
		return mb_check_encoding( $path, 'UTF-8' ) ? $path : $raw_path;
	}
}
