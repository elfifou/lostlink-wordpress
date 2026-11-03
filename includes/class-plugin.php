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
		add_action( 'init', array( self::class, 'load_textdomain' ) );

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

	/**
	 * Loads the translations shipped in languages/ (init).
	 *
	 * WordPress 6.8 and later find them from the Domain Path header, but the
	 * plugin supports 6.5. Those of translate.wordpress.org, once the plugin is
	 * published there, take precedence either way.
	 */
	public static function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Needed before WordPress 6.8, see above.
		load_plugin_textdomain( 'lostlink', false, dirname( plugin_basename( LOSTLINK_FILE ) ) . '/languages' );
	}
}
