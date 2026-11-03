<?php
/**
 * Tests of the schema installation.
 *
 * @package Lostlink
 */

use Lostlink\Installer;

/**
 * @covers \Lostlink\Installer
 */
class Test_Installer extends WP_UnitTestCase {

	public function test_schema_version_is_autoloaded() {
		global $wpdb;

		delete_option( Installer::DB_VERSION_OPTION );
		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::DB_VERSION_OPTION ) );
		// maybe_upgrade() reads it on every request.
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Installer::DB_VERSION_OPTION ) ), wp_autoload_values_to_autoload() );
	}
}
