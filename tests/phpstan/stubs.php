<?php
/**
 * Declarations of the Polylang and Redirection APIs the plugin uses, for
 * PHPStan only: both plugins are optional. Signatures as of Polylang 3.9
 * and Redirection 5.10.
 *
 * @package Lostlink
 */

// phpcs:ignoreFile -- Third-party declarations.

/**
 * @param array<string, mixed> $args
 * @return string[]
 */
function pll_languages_list( $args = array() ) {}

/**
 * @return string
 */
function pll_home_url( string $lang = '' ) {}

/**
 * @return string|int|bool|string[]|object
 */
function pll_default_language( string $field = 'slug' ) {}

/**
 * @return PLL_Base
 */
function PLL() {}

class PLL_Base {
	/**
	 * @var PLL_Links_Model|null Not set before Polylang has languages.
	 */
	public $links_model;
}

abstract class PLL_Links_Model {
	/**
	 * @return string The language slug, '' if the URL has none.
	 */
	public function get_language_from_url( string $url = '' ) {}
}

class Red_Group {
	/**
	 * @return list<array{id: int, enabled: bool}>
	 */
	public static function get_all_for_module( int $module_id ) {}
}

class Red_Item {
	/**
	 * @return Red_Item[]
	 */
	public static function get_for_matched_url( string $url ) {}

	/**
	 * @param array<string, mixed> $details
	 * @return WP_Error|Red_Item
	 */
	public static function create( array $details ) {}

	/**
	 * @return bool
	 */
	public function is_enabled() {}

	/**
	 * @return bool
	 */
	public function is_regex() {}

	/**
	 * @return string
	 */
	public function get_match_type() {}

	/**
	 * @return string
	 */
	public function get_action_type() {}

	/**
	 * @return string
	 */
	public function get_action_data() {}
}
