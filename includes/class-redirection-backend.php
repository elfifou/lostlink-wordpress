<?php
/**
 * Redirection plugin backend.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the redirects in the Redirection plugin (redirection.me).
 *
 * Redirection has no public PHP API: this goes through its Red_Item and
 * Red_Group classes. Their legacy names work both in its default mode and in
 * its refactored, namespaced mode (REDIRECTION_REFACTOR), which aliases them.
 * Everything Redirection-specific stays in this class.
 */
class Redirection_Backend implements Redirect_Backend {

	/**
	 * ID of Redirection's "WordPress" module, the one serving redirects from PHP.
	 */
	private const WORDPRESS_MODULE = 1;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'redirection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Redirection plugin', 'lostlink' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Active is not enough: Redirection creates its tables in its setup
	 * wizard, and has none until it is completed.
	 */
	public function is_available(): bool {
		if ( ! class_exists( 'Red_Item' ) || ! class_exists( 'Red_Group' ) ) {
			return false;
		}

		$options = get_option( 'redirection_options' );
		return is_array( $options ) && is_string( $options['database'] ?? null ) && '' !== $options['database'] && null !== $this->group_id();
	}

	/**
	 * Returns the group new redirects go in: the first enabled group of the
	 * WordPress module ("Redirections" on a new install).
	 */
	protected function group_id(): ?int {
		foreach ( \Red_Group::get_all_for_module( self::WORDPRESS_MODULE ) as $group ) {
			if ( $group['enabled'] ) {
				return $group['id'];
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only plain redirects are considered (no regex, no condition): the
	 * target of the others depends on the request.
	 *
	 * @param string $path A decoded path, relative to the site's home.
	 */
	public function find_target( string $path ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		foreach ( \Red_Item::get_for_matched_url( $path ) as $item ) {
			if ( $item->is_enabled() && ! $item->is_regex() && 'url' === $item->get_match_type() && 'url' === $item->get_action_type() ) {
				return $item->get_action_data();
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source      Source path.
	 * @param string $target      Target path or URL.
	 * @param int    $status_code HTTP status code.
	 */
	public function create( string $source, string $target, int $status_code ) {
		$group_id = $this->is_available() ? $this->group_id() : null;
		if ( null === $group_id ) {
			return new \WP_Error( 'lostlink_redirection_unavailable', __( 'The Redirection plugin is not set up: complete its setup first.', 'lostlink' ) );
		}

		$item = \Red_Item::create(
			array(
				'url'         => $source,
				'action_data' => array( 'url' => $target ),
				'action_type' => 'url',
				'action_code' => $status_code,
				'match_type'  => 'url',
				'group_id'    => $group_id,
				// The broken URL was recorded without its query string, lowercased
				// and without trailing slash: match it the same way, as the built-in
				// redirects do, and pass the query string on. Redirection's site
				// defaults can't be relied on: only its fresh installs ignore the case
				// and the trailing slash, older ones match them exactly.
				'match_data'  => array(
					'source' => array(
						'flag_query'    => 'pass',
						'flag_case'     => true,
						'flag_trailing' => true,
					),
				),
			)
		);
		return is_wp_error( $item ) ? $item : true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function manage_url(): string {
		return admin_url( 'tools.php?page=redirection.php' );
	}
}
