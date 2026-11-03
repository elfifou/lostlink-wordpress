<?php
/**
 * Tests of the settings.
 *
 * @package Lostlink
 */

use Lostlink\Settings;

/**
 * @covers \Lostlink\Settings
 */
class Test_Settings extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$GLOBALS['wp_settings_errors'] = array();
	}

	/**
	 * Returns a complete valid submission.
	 */
	protected static function submission( array $values = array() ): array {
		return array_merge( array_map( 'strval', Settings::DEFAULTS ), array( 'log_assets' => '1' ), $values );
	}

	public function test_all_merges_defaults_and_drops_unknown_keys() {
		update_option(
			Settings::OPTION,
			array(
				'row_limit' => 7,
				'unknown'   => 'x',
			)
		);

		$settings = Settings::all();
		$this->assertSame( 7, $settings['row_limit'] );
		$this->assertSame( Settings::DEFAULTS['referer_limit'], $settings['referer_limit'] );
		$this->assertArrayNotHasKey( 'unknown', $settings );
	}

	public function test_all_replaces_values_of_another_type() {
		// E.g. an option edited by hand in the database.
		update_option(
			Settings::OPTION,
			array(
				'row_limit'        => '500',
				'log_assets'       => 1,
				'redirect_backend' => 'elsewhere',
				'cached_404s'      => 'never',
			)
		);

		$settings = Settings::all();
		$this->assertSame( Settings::DEFAULTS['row_limit'], $settings['row_limit'] );
		$this->assertFalse( $settings['log_assets'] );
		$this->assertSame( 'auto', $settings['redirect_backend'] );
		$this->assertSame( 'never', $settings['cached_404s'] );
	}

	public function test_sanitize_valid_values() {
		$clean = Settings::sanitize(
			self::submission(
				array(
					'row_limit'        => ' 0 ',
					'exclude_patterns' => "  /feed/*\r\n\r\n#^/\\.env#  ",
				)
			)
		);

		$this->assertTrue( $clean['log_assets'] );
		$this->assertSame( 0, $clean['row_limit'] );
		$this->assertSame( "/feed/*\n#^/\\.env#", $clean['exclude_patterns'] );
		$this->assertSame( array(), get_settings_errors( Settings::OPTION ) );
	}

	public function test_sanitize_unchecked_checkbox() {
		$submission = self::submission();
		unset( $submission['log_assets'] );

		$this->assertFalse( Settings::sanitize( $submission )['log_assets'] );
	}

	public function test_sanitize_keeps_current_value_on_invalid_number() {
		update_option( Settings::OPTION, array( 'row_limit' => 123 ) );

		foreach ( array( '-1', 'abc', '1.5', '' ) as $invalid ) {
			$this->assertSame( 123, Settings::sanitize( self::submission( array( 'row_limit' => $invalid ) ) )['row_limit'], $invalid );
		}
		// Priority thresholds can't be 0.
		$this->assertSame( Settings::DEFAULTS['priority_high_week_count'], Settings::sanitize( self::submission( array( 'priority_high_week_count' => '0' ) ) )['priority_high_week_count'] );

		$this->assertContains( 'row_limit', array_column( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	public function test_sanitize_keeps_current_patterns_on_invalid_regex() {
		update_option( Settings::OPTION, array( 'exclude_patterns' => '/old/*' ) );

		$clean = Settings::sanitize( self::submission( array( 'exclude_patterns' => "/ok/*\n#(#" ) ) );

		$this->assertSame( '/old/*', $clean['exclude_patterns'] );
		$this->assertSame( array( 'exclude_patterns' ), array_column( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	public function test_sanitize_non_array_input() {
		$this->assertFalse( Settings::sanitize( 'garbage' )['log_assets'] );
	}
}
