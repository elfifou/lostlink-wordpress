<?php
/**
 * Plugin Name:       Lostlink – 404 Monitor & Smart Redirects
 * Description:       Tracks the 404 errors of your site and helps you fix them with 301 redirects.
 * Version:           0.1.0-dev
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

define( 'LOSTLINK_VERSION', '0.1.0-dev' );
define( 'LOSTLINK_FILE', __FILE__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( Lostlink\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lostlink\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Lostlink\Plugin::class, 'init' ) );
