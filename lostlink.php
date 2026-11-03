<?php
/**
 * Plugin Name:       Lostlink – 404 Monitor & Smart Redirects
 * Description:       Finds the broken URLs that cost you visitors, ranks them by priority and fixes them with the right 301 redirect in one click.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            elfifou
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lostlink
 * Domain Path:       /languages
 *
 * @package Lostlink
 */

defined( 'ABSPATH' ) || exit;

define( 'LOSTLINK_VERSION', '1.0.0' );
define( 'LOSTLINK_FILE', __FILE__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( Lostlink\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lostlink\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Lostlink\Plugin::class, 'init' ) );
