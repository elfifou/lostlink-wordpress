<?php
/**
 * Tests of the exclusion patterns.
 *
 * @package Lostlink
 */

use Lostlink\Exclusion_Pattern_Matcher;

/**
 * @covers \Lostlink\Exclusion_Pattern_Matcher
 */
class Test_Exclusion_Pattern_Matcher extends WP_UnitTestCase {

	public function test_split_patterns_trims_and_skips_empty_lines() {
		$this->assertSame( array( '/a', '#b#' ), Exclusion_Pattern_Matcher::split_patterns( "  /a \r\n\n\t#b#\r" ) );
		$this->assertSame( array(), Exclusion_Pattern_Matcher::split_patterns( "  \n " ) );
		$this->assertSame( array(), Exclusion_Pattern_Matcher::split_patterns( null ) );
	}

	public function test_glob_patterns() {
		$this->assertTrue( Exclusion_Pattern_Matcher::matches_any( '/feed/rss', array( '/feed/*' ) ) );
		$this->assertTrue( Exclusion_Pattern_Matcher::matches_any( '/page-1', array( '/page-?' ) ) );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/feed', array( '/feed/*' ) ) );
		// A glob matches the whole path, and its regex characters are literal.
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/old/feed/x', array( '/feed/*' ) ) );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/aXb', array( '/a.b' ) ) );
	}

	public function test_regex_patterns() {
		$this->assertTrue( Exclusion_Pattern_Matcher::matches_any( '/wp-json/x', array( '#^/wp-json/#' ) ) );
		$this->assertTrue( Exclusion_Pattern_Matcher::matches_any( '/ADMIN', array( '~admin~i' ) ) );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/blog/wp-json/x', array( '#^/wp-json/#' ) ) );
	}

	public function test_slash_is_not_a_regex_delimiter() {
		$this->assertFalse( Exclusion_Pattern_Matcher::is_regex( '/user/login' ) );
		$this->assertTrue( Exclusion_Pattern_Matcher::matches_any( '/user/login', array( '/user/login' ) ) );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/user', array( '/user/login' ) ) );
	}

	public function test_invalid_regex_does_not_match_nor_fail() {
		$this->assertFalse( Exclusion_Pattern_Matcher::is_valid( '#(#' ) );
		$this->assertTrue( Exclusion_Pattern_Matcher::is_valid( '#^/a#' ) );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/a', array( '#(#' ) ) );
	}

	public function test_catastrophic_backtracking_fails_fast_and_restores_the_limit() {
		$limit = ini_get( 'pcre.backtrack_limit' );
		$this->assertFalse( Exclusion_Pattern_Matcher::matches_any( '/' . str_repeat( 'a', 40 ) . '!', array( '#^/(a+)+$#' ) ) );
		$this->assertSame( $limit, ini_get( 'pcre.backtrack_limit' ) );
	}
}
