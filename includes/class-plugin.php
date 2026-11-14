<?php
/**
 * Plugin bootstrap.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin services to WordPress hooks.
 */
final class Plugin {

	/**
	 * Registers the hooks, on plugins_loaded.
	 */
	public static function init(): void {
		Installer::maybe_upgrade();

		$recorder = new Hit_Recorder( new Storage() );
		$recorder->register();
		( new Cache_Beacon( $recorder ) )->register();

		( new Redirect_Store() )->register();
		Path_Provider::register();
		Cron::register();

		if ( is_admin() ) {
			Admin::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'lostlink', Cli::class );
		}
	}
}
