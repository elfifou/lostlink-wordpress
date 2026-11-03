<?php
/**
 * Tests of the dashboard queries and rendering.
 *
 * @package Lostlink
 */

use Lostlink\Admin;
use Lostlink\Dashboard_Table;
use Lostlink\Storage;

/**
 * @covers \Lostlink\Storage
 * @covers \Lostlink\Dashboard_Table
 * @covers \Lostlink\Admin
 */
class Test_Dashboard extends WP_UnitTestCase {

	protected Storage $storage;

	public function set_up() {
		parent::set_up();
		$this->storage = new Storage();
	}

	public function tear_down() {
		unset( $_REQUEST['status'], $_REQUEST['s'], $_GET['orderby'], $_GET['order'] );
		parent::tear_down();
	}

	/**
	 * Records hits on paths.
	 *
	 * @param array<string, int> $hits Number of hits, keyed by path.
	 * @param int|null           $time Timestamp of the hits.
	 */
	protected function hit( array $hits, ?int $time = null ): void {
		foreach ( $hits as $path => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->storage->record_hit( $path, $time ?? time() );
			}
		}
	}

	public function test_query_filters_by_status_and_hides_fixed_urls() {
		$this->hit(
			array(
				'/active'  => 1,
				'/ignored' => 1,
				'/fixed'   => 1,
			)
		);
		$this->storage->set_state( array( Storage::path_hash( '/ignored' ) ), 'ignored' );
		$this->storage->set_state( array( Storage::path_hash( '/fixed' ) ), 'fixed' );

		$this->assertSame( array( '/active' ), array_column( $this->storage->query_paths(), 'path' ) );
		$this->assertSame( array( '/ignored' ), array_column( $this->storage->query_paths( array( 'status' => 'ignored' ) ), 'path' ) );
		$this->assertSame( 1, $this->storage->count_paths( array( 'status' => 'ignored' ) ) );
		$this->assertNotNull( $this->storage->get_path( '/fixed' )['fixed_at'] );
	}

	public function test_set_state_restores_ignored_urls() {
		$this->hit( array( '/x' => 1 ) );
		$hash = Storage::path_hash( '/x' );

		$this->assertSame( 1, $this->storage->set_state( array( $hash, $hash ), 'ignored' ) );
		$this->assertSame( 1, $this->storage->set_state( array( $hash ), 'active' ) );
		$this->assertSame( '0', $this->storage->get_path( '/x' )['ignored'] );
	}

	public function test_search_escapes_like_wildcards() {
		$this->hit(
			array(
				'/100%-sure'   => 1,
				'/100-percent' => 1,
				'/under_score' => 1,
				'/underXscore' => 1,
			)
		);

		$this->assertSame( array( '/100%-sure' ), array_column( $this->storage->query_paths( array( 'search' => '100%' ) ), 'path' ) );
		$this->assertSame( array( '/under_score' ), array_column( $this->storage->query_paths( array( 'search' => 'under_' ) ), 'path' ) );
		$this->assertSame( 1, $this->storage->count_paths( array( 'search' => 'under_' ) ) );
	}

	public function test_sorting_and_pagination() {
		$this->hit(
			array(
				'/b' => 3,
				'/a' => 1,
				'/c' => 2,
			)
		);

		$this->assertSame(
			array( '/b', '/c', '/a' ),
			array_column(
				$this->storage->query_paths(
					array(
						'orderby' => 'count',
						'order'   => 'desc',
					)
				),
				'path'
			)
		);
		$this->assertSame(
			array( '/b' ),
			array_column(
				$this->storage->query_paths(
					array(
						'orderby' => 'path',
						'order'   => 'asc',
						'limit'   => 1,
						'offset'  => 1,
					)
				),
				'path'
			)
		);
	}

	public function test_unknown_sort_column_falls_back_to_last_seen() {
		$this->hit( array( '/old' => 1 ), time() - 60 );
		$this->hit( array( '/new' => 1 ) );

		$rows = $this->storage->query_paths(
			array(
				'orderby' => 'path_hash DESC, (SELECT SLEEP(1))',
				'order'   => 'asc; DROP TABLE x',
			)
		);
		$this->assertSame( array( '/new', '/old' ), array_column( $rows, 'path' ) );
	}

	public function test_listed_counters_are_current_even_before_the_cron_reset() {
		update_option( 'timezone_string', 'Europe/Paris' );
		$monday = ( new DateTimeImmutable( 'monday this week 10:00', wp_timezone() ) )->getTimestamp();
		$this->hit( array( '/x' => 2 ), $monday - 7 * DAY_IN_SECONDS );

		$row = $this->storage->query_paths( array( 'now' => $monday ) )[0];
		$this->assertSame( array( '2', '0', '0' ), array( $row['count'], $row['daily_count'], $row['week_count'] ) );
		// The stored counters are untouched.
		$this->assertSame( '2', $this->storage->get_path( '/x' )['week_count'] );
	}

	public function test_referer_counts_and_history_of_several_urls() {
		$this->storage->record_hit( '/a', time(), 'https://1.example/' );
		$this->storage->record_hit( '/a', time(), 'https://2.example/' );
		$this->storage->record_hit( '/b', time() - 20 * DAY_IN_SECONDS );

		$hashes = array( Storage::path_hash( '/a' ), Storage::path_hash( '/b' ) );
		$this->assertSame( array( $hashes[0] => 2 ), $this->storage->count_referers( $hashes ) );
		$this->assertSame( array(), $this->storage->count_referers( array() ) );

		$history = $this->storage->get_daily_history( $hashes, wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$this->assertSame( array( $hashes[0] ), array_keys( $history ) );
		$this->assertSame( array( 2 ), array_values( $history[ $hashes[0] ] ) );
	}

	public function test_delete_by_hashes_counts_existing_urls_only() {
		$this->storage->record_hit( '/a', time(), 'https://1.example/' );

		$this->assertSame( 1, $this->storage->delete_by_hashes( array( Storage::path_hash( '/a' ), str_repeat( 'f', 64 ) ) ) );
		$this->assertNull( $this->storage->get_path( '/a' ) );
		$this->assertSame( array(), $this->storage->list_referers( '/a' ) );
	}

	public function test_table_renders_escaped_rows() {
		set_current_screen( 'tools_page_' . Admin::PAGE );
		$this->storage->record_hit( '/<script>x</script>', time(), 'javascript:alert(1)' );
		$this->storage->record_hit( '/safe', time(), 'https://example.com/?a=1&b=<2>' );

		$table = new Dashboard_Table( $this->storage );
		$table->prepare_items();
		ob_start();
		$table->display();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( '&lt;script&gt;x', $html );
		$this->assertStringNotContainsString( 'href="javascript:', $html );
		$this->assertStringContainsString( 'href="https://example.com/?a=1&#038;b=2"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="items[]"' ) );
	}

	public function test_bulk_actions_depend_on_the_view() {
		set_current_screen( 'tools_page_' . Admin::PAGE );

		$this->assertSame( array( 'mark_fixed', 'ignore', 'delete' ), array_keys( ( new Dashboard_Table( $this->storage ) )->get_bulk_actions() ) );
		$_REQUEST['status'] = 'ignored';
		$this->assertSame( array( 'unignore', 'delete' ), array_keys( ( new Dashboard_Table( $this->storage ) )->get_bulk_actions() ) );
	}

	public function test_csv_export() {
		$this->hit(
			array(
				'/a' => 2,
				'/b' => 1,
			)
		);
		$this->storage->set_state( array( Storage::path_hash( '/b' ) ), 'ignored' );

		$handle = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		Admin::write_csv( $handle, $this->storage, array( 'status' => 'active' ), time() );
		rewind( $handle );
		$lines = explode( "\n", trim( stream_get_contents( $handle ) ) );

		$this->assertCount( 2, $lines );
		$this->assertStringStartsWith( 'path,count,', $lines[0] );
		$this->assertStringStartsWith( '/a,2,2,2,', $lines[1] );
		$this->assertStringEndsWith( ',medium,no', $lines[1] );
	}

	public function test_referer_link() {
		$this->assertSame( 'javascript:alert(1)', Admin::referer_link( 'javascript:alert(1)' ) );
		$this->assertStringContainsString( 'rel="noopener noreferrer nofollow"', Admin::referer_link( 'https://example.com/' ) );
	}
}
