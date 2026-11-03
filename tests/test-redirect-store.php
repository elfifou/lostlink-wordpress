<?php
/**
 * Tests of the built-in redirects.
 *
 * @package Lostlink
 */

use Lostlink\Redirect_Store;

/**
 * @covers \Lostlink\Redirect_Store
 */
class Test_Redirect_Store extends WP_UnitTestCase {

	protected Redirect_Store $store;

	public function set_up() {
		parent::set_up();
		$this->store = new Redirect_Store();
	}

	public function test_create_and_find_with_normalized_source() {
		$id = $this->store->create( '/Old-Page/', '/new-page/', 301, 7 );

		$this->assertIsInt( $id );
		$redirect = $this->store->find( '/old-page' );
		$this->assertSame( '/old-page', $redirect['source'] );
		$this->assertSame( '/new-page/', $redirect['target'] );
		$this->assertSame( '7', $redirect['created_by'] );
		$this->assertSame( $redirect, $this->store->get( $id ) );
	}

	public function test_create_rejects_duplicates_and_invalid_status_codes() {
		$this->store->create( '/a', '/b' );

		$this->assertWPError( $this->store->create( '/A/', '/c' ) );
		$this->assertWPError( $this->store->create( '/x', '/y', 303 ) );
		$this->assertSame( 1, $this->store->count() );
	}

	public function test_update_keeps_the_source_and_the_hits() {
		$id = $this->store->create( '/a', '/b' );
		$this->store->count_hit( $id, 1000 );

		$this->assertTrue( $this->store->update( $id, '/c', 302 ) );
		// Saved unchanged: no row changed, still a success.
		$this->assertTrue( $this->store->update( $id, '/c', 302 ) );

		$redirect = $this->store->get( $id );
		$this->assertSame( array( '/a', '/c', '302', '1' ), array( $redirect['source'], $redirect['target'], $redirect['status_code'], $redirect['hits'] ) );
		$this->assertSame( 'lostlink_invalid_status', $this->store->update( $id, '/d', 303 )->get_error_code() );
		$this->assertSame( 'lostlink_unknown_redirect', $this->store->update( $id + 1, '/d', 301 )->get_error_code() );
	}

	public function test_match_passes_the_query_string_on() {
		$this->store->create( '/a', '/b/' );
		$this->store->create( '/q', '/b/?ref=1#top', 302 );

		$this->assertNull( $this->store->match( '/unknown' ) );
		$this->assertSame( home_url( '/b/' ), $this->store->match( '/A/' )['location'] );
		$this->assertSame( home_url( '/b/?utm_source=x' ), $this->store->match( '/a?utm_source=x' )['location'] );

		$match = $this->store->match( '/q?utm_source=x#ignored' );
		$this->assertSame( home_url( '/b/?ref=1&utm_source=x#top' ), $match['location'] );
		$this->assertSame( 302, $match['status_code'] );
	}

	public function test_match_decodes_the_path() {
		$this->store->create( '/café', 'https://example.com/' );

		$this->assertSame( 'https://example.com/', $this->store->match( '/caf%C3%A9' )['location'] );
	}

	public function test_location_is_relative_to_a_subdirectory_home() {
		update_option( 'home', 'http://example.org/blog' );
		$this->store->create( '/old', '/new' );

		$this->assertSame( 'http://example.org/blog/new', $this->store->match( '/blog/old' )['location'] );
		$this->assertSame( 'https://other.example/x', Redirect_Store::location( 'https://other.example/x' ) );
	}

	public function test_count_hit() {
		$id = $this->store->create( '/a', '/b' );
		$this->store->count_hit( $id, 1234 );
		$this->store->count_hit( $id, 5678 );

		$redirect = $this->store->get( $id );
		$this->assertSame( array( '2', '5678' ), array( $redirect['hits'], $redirect['last_hit'] ) );
	}

	public function test_delete_returns_the_deleted_redirects() {
		$a = $this->store->create( '/a', '/x' );
		$this->store->create( '/b', '/x' );

		$deleted = $this->store->delete( array( $a, $a, 999999 ) );
		$this->assertSame( array( '/a' ), array_column( $deleted, 'source' ) );
		$this->assertSame( array( '/b' ), array_column( $this->store->query(), 'source' ) );
	}

	public function test_query_search_sort_and_pagination() {
		$this->store->create( '/b-source', '/target-1' );
		$this->store->create( '/a-source', '/target-2' );
		$this->store->create( '/c-other', '/target-1' );

		$this->assertSame( 2, $this->store->count( array( 'search' => 'target-1' ) ) );
		// "_" is a literal, not a LIKE wildcard.
		$this->assertSame( 0, $this->store->count( array( 'search' => '_' ) ) );
		$sorted = $this->store->query(
			array(
				'orderby' => 'source',
				'order'   => 'asc',
			)
		);
		$this->assertSame( array( '/a-source', '/b-source', '/c-other' ), array_column( $sorted, 'source' ) );
		$page = $this->store->query(
			array(
				'orderby' => 'source; DROP TABLE x',
				'limit'   => 1,
				'offset'  => 2,
			)
		);
		// Unknown column: newest first.
		$this->assertSame( array( '/b-source' ), array_column( $page, 'source' ) );
	}
}
