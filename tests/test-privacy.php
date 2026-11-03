<?php
/**
 * Tests of the suggested privacy policy content.
 *
 * @package Lostlink
 */

use Lostlink\Admin;

/**
 * @covers \Lostlink\Admin::add_privacy_policy_content
 */
class Test_Privacy extends WP_UnitTestCase {

	public function test_a_paragraph_is_suggested_for_the_privacy_policy(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'options-privacy' );
		// As on admin_init, without running the other callbacks of the action.
		$GLOBALS['wp_actions']['admin_init'] = 1;

		Admin::add_privacy_policy_content();

		$suggested = array_column( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertArrayHasKey( 'Lostlink', $suggested );
		$this->assertStringContainsString( 'No IP address', $suggested['Lostlink'] );
	}
}
