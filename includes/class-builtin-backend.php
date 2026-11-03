<?php
/**
 * Built-in redirect backend.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the redirects in the plugin's own table, see Redirect_Store.
 */
class Builtin_Backend implements Redirect_Backend {

	/**
	 * Constructor.
	 *
	 * @param Redirect_Store $store The redirects table.
	 */
	public function __construct( protected Redirect_Store $store ) {}

	/**
	 * Returns the redirects table, for what only built-in redirects support (editing).
	 */
	public function store(): Redirect_Store {
		return $this->store;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'builtin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Lostlink (built in)', 'lostlink' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path A decoded path, relative to the site's home.
	 */
	public function find_target( string $path ): ?string {
		$redirect = $this->store->find( $path );
		return null === $redirect ? null : $redirect['target'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source      Source path.
	 * @param string $target      Target path or URL.
	 * @param int    $status_code HTTP status code.
	 */
	public function create( string $source, string $target, int $status_code ) {
		$id = $this->store->create( $source, $target, $status_code, get_current_user_id() );
		return is_wp_error( $id ) ? $id : true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function manage_url(): string {
		return Admin::url( array( 'tab' => 'redirects' ) );
	}
}
