<?php
/**
 * Redirect backends.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Where the redirects fixing the 404s are stored and served from.
 */
interface Redirect_Backend {

	/**
	 * Returns the backend identifier, the value of the "redirect_backend" setting.
	 */
	public function id(): string;

	/**
	 * Returns the translated name of the backend.
	 */
	public function label(): string;

	/**
	 * Whether the backend can be used on this site.
	 */
	public function is_available(): bool;

	/**
	 * Returns the target of the redirect of a path, if there is one.
	 *
	 * @param string $path A decoded path, relative to the site's home.
	 * @return string|null A path relative to the site's home, or an absolute URL.
	 */
	public function find_target( string $path ): ?string;

	/**
	 * Creates a redirect.
	 *
	 * @param string $source      Source path, decoded, relative to the site's home.
	 * @param string $target      A path relative to the site's home, or an absolute URL.
	 * @param int    $status_code One of Redirect_Store::STATUS_CODES.
	 * @return bool|\WP_Error True on success.
	 */
	public function create( string $source, string $target, int $status_code );

	/**
	 * Returns the URL of the admin page listing the backend's redirects.
	 */
	public function manage_url(): string;
}
