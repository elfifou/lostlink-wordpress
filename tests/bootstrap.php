<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with the plugin active.
 *
 * Expects the dev tools of the wordpress-test environment (src/composer.json):
 * wp-phpunit/wp-phpunit and yoast/phpunit-polyfills, and a wp-tests-config.php
 * pointing at a dedicated database. Both locations can be overridden with the
 * LOSTLINK_VENDOR_DIR and WP_PHPUNIT__TESTS_CONFIG environment variables.
 *
 * @package Lostlink
 */

$lostlink_project = dirname( __DIR__, 5 );
$lostlink_vendor  = getenv( 'LOSTLINK_VENDOR_DIR' ) ? getenv( 'LOSTLINK_VENDOR_DIR' ) : $lostlink_project . '/vendor';

require_once $lostlink_vendor . '/autoload.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $lostlink_project . '/wp-tests-config.php' );
}

$lostlink_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
require_once $lostlink_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/lostlink.php';
		// The Redirection backend is tested against the real plugin, when it is
		// installed next to this one; its tests are skipped otherwise.
		$redirection = dirname( __DIR__, 2 ) . '/redirection/redirection.php';
		if ( file_exists( $redirection ) ) {
			require $redirection;
		}
	}
);

require $lostlink_tests_dir . '/includes/bootstrap.php';

// Created once, outside the test transactions: the test suite turns the
// CREATE TABLE statements run during a test into temporary tables.
Lostlink\Installer::install();
if ( class_exists( 'Redirection\Database\Database' ) ) {
	Redirection\Database\Database::get_latest_database()->install();
}
