<?php
/**
 * Fixing a 404 with a redirect.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the redirect fixing a broken URL, after checking its target.
 *
 * Blocks what would make the redirect useless or harmful (an invalid target,
 * a redirect loop, a source already redirected), and warns about what is
 * allowed but probably a mistake (a redirect chain, a target that is itself
 * a broken URL).
 */
class Redirect_Service {

	/**
	 * Maximum number of redirects followed to detect loops and chains.
	 */
	public const MAX_HOPS = 10;

	/**
	 * Backends, keyed by ID.
	 *
	 * @var array<string, Redirect_Backend>
	 */
	protected array $backends = array();

	/**
	 * Constructor.
	 *
	 * @param Storage            $storage  The 404 storage.
	 * @param Redirect_Backend[] $backends The backends, the built-in one first.
	 */
	public function __construct( protected Storage $storage, array $backends ) {
		foreach ( $backends as $backend ) {
			$this->backends[ $backend->id() ] = $backend;
		}
	}

	/**
	 * Returns the service with the plugin's backends.
	 */
	public static function create_default(): self {
		return new self( new Storage(), array( new Builtin_Backend( new Redirect_Store() ), new Redirection_Backend() ) );
	}

	/**
	 * Returns the backends, keyed by ID.
	 *
	 * @return array<string, Redirect_Backend>
	 */
	public function backends(): array {
		return $this->backends;
	}

	/**
	 * Returns the backend new redirects are created in.
	 *
	 * "auto" (the default) uses Redirection when it is set up, so its users
	 * keep all their redirects in one place, and the built-in store otherwise.
	 * A backend that is not available falls back to the built-in one.
	 */
	public function backend(): Redirect_Backend {
		$setting = Settings::all()['redirect_backend'];
		if ( 'auto' === $setting ) {
			$setting = isset( $this->backends['redirection'] ) && $this->backends['redirection']->is_available() ? 'redirection' : 'builtin';
		}

		$backend = $this->backends[ $setting ] ?? null;
		return null !== $backend && $backend->is_available() ? $backend : $this->backends['builtin'];
	}

	/**
	 * Fixes a broken URL with a redirect.
	 *
	 * The source is the recorded broken URL, never a free parameter: fixing
	 * detected 404s doesn't allow redirecting any path of the site.
	 *
	 * @param string $hash           Path hash of the broken URL.
	 * @param string $target         Target, as entered: a path relative to the site's home, or an http(s) URL.
	 * @param int    $status_code    One of Redirect_Store::STATUS_CODES.
	 * @param bool   $allow_external Whether the target can be on another site.
	 * @return array{target: string, backend: Redirect_Backend, warnings: string[]}|\WP_Error
	 */
	public function fix( string $hash, string $target, int $status_code = 301, bool $allow_external = false ) {
		$record = $this->storage->get_by_hash( $hash );
		if ( null === $record ) {
			return new \WP_Error( 'lostlink_unknown_url', __( 'This broken URL is no longer in the list.', 'lostlink' ) );
		}
		if ( '1' === $record['fixed'] ) {
			return new \WP_Error( 'lostlink_already_fixed', __( 'This URL is already fixed.', 'lostlink' ) );
		}
		if ( ! in_array( $status_code, Redirect_Store::STATUS_CODES, true ) ) {
			return new \WP_Error( 'lostlink_invalid_status', __( 'Invalid redirect status code.', 'lostlink' ) );
		}

		$target = $this->normalize_target( $target, $allow_external );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$source   = $record['path'];
		$existing = $this->find_target( $source );
		if ( null !== $existing ) {
			return new \WP_Error(
				'lostlink_redirect_exists',
				/* translators: %s: redirect target. */
				sprintf( __( 'A redirect for this URL already exists, to %s.', 'lostlink' ), $existing )
			);
		}

		$warnings = $this->check_target( $source, $target );
		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}

		$backend = $this->backend();
		$created = $backend->create( $source, $target, $status_code );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$this->storage->set_state( array( $hash ), 'fixed_redirect' );

		return array(
			'target'   => $target,
			'backend'  => $backend,
			'warnings' => $warnings,
		);
	}

	/**
	 * Changes the target and status code of a built-in redirect.
	 *
	 * Same checks as fix(). The source stays the broken URL it fixes. An
	 * external target saved unchanged is allowed even without the permission
	 * to create one: only the status code is changed.
	 *
	 * @param int    $id             ID of the built-in redirect.
	 * @param string $target         Target, as entered.
	 * @param int    $status_code    One of Redirect_Store::STATUS_CODES.
	 * @param bool   $allow_external Whether the target can be on another site.
	 * @return array{source: string, target: string, warnings: string[]}|\WP_Error
	 */
	public function edit( int $id, string $target, int $status_code = 301, bool $allow_external = false ) {
		$store    = $this->builtin_store();
		$redirect = $store?->get( $id );
		if ( null === $redirect ) {
			return new \WP_Error( 'lostlink_unknown_redirect', __( 'This redirect no longer exists.', 'lostlink' ) );
		}
		if ( ! in_array( $status_code, Redirect_Store::STATUS_CODES, true ) ) {
			return new \WP_Error( 'lostlink_invalid_status', __( 'Invalid redirect status code.', 'lostlink' ) );
		}

		$target = $this->normalize_target( $target, $allow_external || trim( $target ) === $redirect['target'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$warnings = $this->check_target( $redirect['source'], $target );
		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}

		$updated = $store->update( $id, $target, $status_code );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'source'   => $redirect['source'],
			'target'   => $target,
			'warnings' => $warnings,
		);
	}

	/**
	 * Returns the table of the built-in redirects.
	 */
	protected function builtin_store(): ?Redirect_Store {
		$backend = $this->backends['builtin'] ?? null;
		return $backend instanceof Builtin_Backend ? $backend->store() : null;
	}

	/**
	 * Validates and normalizes a redirect target.
	 *
	 * An absolute URL of the site itself becomes a path relative to its home,
	 * so the redirect keeps working if the domain changes.
	 *
	 * @param string $target         Target, as entered.
	 * @param bool   $allow_external Whether the target can be on another site.
	 * @return string|\WP_Error A path starting with "/", or an http(s) URL.
	 */
	public function normalize_target( string $target, bool $allow_external ) {
		$target = trim( $target );
		if ( '' === $target ) {
			return new \WP_Error( 'lostlink_empty_target', __( 'The redirect target is empty.', 'lostlink' ) );
		}
		if ( 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $target ) ) {
			return new \WP_Error( 'lostlink_invalid_target', __( 'The redirect target must not contain spaces or control characters.', 'lostlink' ) );
		}

		// "//host/path" is protocol-relative: an external URL, not a path.
		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			return $target;
		}

		if ( ! self::is_http_url( $target ) ) {
			return new \WP_Error( 'lostlink_invalid_target', __( 'The redirect target must be a path starting with "/" or an http(s) URL.', 'lostlink' ) );
		}

		$relative = self::relative_url( $target );
		if ( null !== $relative ) {
			return $relative;
		}

		if ( ! $allow_external ) {
			return new \WP_Error( 'lostlink_external_target', __( 'You are not allowed to redirect to another site.', 'lostlink' ) );
		}
		return $target;
	}

	/**
	 * Whether a URL is an absolute http(s) URL, with a host.
	 *
	 * @param string $url The URL.
	 */
	public static function is_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) && '' !== ( $parts['host'] ?? '' );
	}

	/**
	 * Returns an absolute URL of the site relative to its home, null for another site.
	 *
	 * The scheme is ignored, the host and port must match. The path stays
	 * encoded, the query string and fragment are kept.
	 *
	 * @param string $url An absolute http(s) URL.
	 * @return string|null A path starting with "/".
	 */
	public static function relative_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || '' === ( $parts['host'] ?? '' ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return null;
		}

		$home_path = untrailingslashit( $home['path'] ?? '' );
		$path      = $parts['path'] ?? '/';
		if ( '' !== $home_path && $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
			return null;
		}
		return '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Checks where a redirect from source to target would lead.
	 *
	 * Follows the existing redirects from the target: reaching the source
	 * again is a loop, which is blocked. Being redirected at all is a chain,
	 * and landing on a broken URL is probably a mistake: both are warnings.
	 *
	 * @param string $source Source path.
	 * @param string $target Normalized target.
	 * @return string[]|\WP_Error Warnings, or the loop error.
	 */
	protected function check_target( string $source, string $target ) {
		$warnings = array();
		$visited  = array( Storage::normalize_path( $source ) => true );
		$current  = $target;

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			$path = self::internal_path( $current );
			if ( null === $path ) {
				break;
			}
			if ( isset( $visited[ Storage::normalize_path( $path ) ] ) ) {
				return new \WP_Error(
					'lostlink_loop',
					0 === $hop
						? __( 'The target is the broken URL itself.', 'lostlink' )
						/* translators: %s: redirect target. */
						: sprintf( __( 'This redirect would create a loop: %s redirects back to this URL.', 'lostlink' ), $target )
				);
			}
			$visited[ Storage::normalize_path( $path ) ] = true;

			$next = $this->find_target( $path );
			if ( null === $next ) {
				break;
			}
			if ( 0 === $hop ) {
				$warnings[] = sprintf(
					/* translators: 1: redirect target, 2: where it is redirected to. */
					__( 'The target %1$s is itself redirected to %2$s: visitors will go through two redirects. Consider using the final URL as target.', 'lostlink' ),
					$target,
					$next
				);
			}
			$current = $next;
		}//end for

		$path = self::internal_path( $target );
		if ( null !== $path ) {
			$broken = $this->storage->get_path( $path );
			if ( null !== $broken && '0' === $broken['fixed'] && '0' === $broken['ignored'] ) {
				$warnings[] = sprintf(
					/* translators: %s: redirect target. */
					__( 'The target %s is itself a broken URL that has not been fixed or ignored yet.', 'lostlink' ),
					$target
				);
			}
		}

		return $warnings;
	}

	/**
	 * Returns the target of the redirect of a path, in any available backend.
	 *
	 * @param string $path A decoded path.
	 */
	protected function find_target( string $path ): ?string {
		foreach ( $this->backends as $backend ) {
			if ( $backend->is_available() ) {
				$target = $backend->find_target( $path );
				if ( null !== $target && '' !== $target ) {
					return $target;
				}
			}
		}
		return null;
	}

	/**
	 * Returns the decoded path of an internal target, null for an external one.
	 *
	 * @param string $target A normalized target, or one found in a backend.
	 */
	public static function internal_path( string $target ): ?string {
		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) ) {
			$normalized = self::same_site_path( $target );
			if ( null === $normalized ) {
				return null;
			}
			$target = $normalized;
		}
		return Hit_Recorder::decode_path( '/' . ltrim( (string) strtok( $target, '?#' ), '/' ) );
	}

	/**
	 * Returns the path of an absolute URL of the site, relative to its home.
	 *
	 * Redirection stores its targets as entered: they can be absolute URLs of
	 * the site.
	 *
	 * @param string $url An absolute URL.
	 */
	protected static function same_site_path( string $url ): ?string {
		$home = untrailingslashit( home_url() );
		foreach ( array( $home, set_url_scheme( $home, 'http' ), set_url_scheme( $home, 'https' ) ) as $prefix ) {
			if ( 0 === strcasecmp( $url, $prefix ) ) {
				return '/';
			}
			if ( 0 === strncasecmp( $url, $prefix . '/', strlen( $prefix ) + 1 ) || 0 === strncasecmp( $url, $prefix . '?', strlen( $prefix ) + 1 ) ) {
				return '/' . ltrim( substr( $url, strlen( $prefix ) ), '/' );
			}
		}
		return null;
	}
}
