<?php
/**
 * Constants of the plugin and WordPress, for PHPStan.
 *
 * @package Lostlink
 */

// phpcs:ignoreFile -- Analysis bootstrap.

define( 'LOSTLINK_VERSION', '0.1.0-dev' );
define( 'LOSTLINK_FILE', dirname( __DIR__, 2 ) . '/lostlink.php' );
define( 'WP_CACHE', false );
define( 'WP_CLI', false );
