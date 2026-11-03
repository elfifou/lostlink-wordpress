<?php
/**
 * Tests of the priority and trend computations.
 *
 * @package Lostlink
 */

use Lostlink\Priority;
use Lostlink\Settings;
use Lostlink\Sparkline;

/**
 * @covers \Lostlink\Priority
 * @covers \Lostlink\Sparkline
 */
class Test_Priority_Sparkline extends WP_UnitTestCase {

	/**
	 * Returns a paths table row.
	 *
	 * @param int $count       Total hits.
	 * @param int $daily_count Hits today.
	 * @param int $week_count  Hits this week.
	 * @param int $age         Seconds since last seen.
	 */
	protected static function row( int $count, int $daily_count, int $week_count, int $age ): array {
		return array(
			'count'       => $count,
			'daily_count' => $daily_count,
			'week_count'  => $week_count,
			'last_seen'   => 1000000000 - $age,
		);
	}

	/**
	 * @dataProvider data_priority
	 */
	public function test_priority( array $row, Priority $expected ) {
		$this->assertSame( $expected, Priority::compute( $row, 1000000000, Settings::DEFAULTS ) );
	}

	public function data_priority() {
		$day = DAY_IN_SECONDS;
		return array(
			'many hits in total, long ago'  => array( self::row( 201, 0, 0, 30 * $day ), Priority::Critical ),
			'many hits today, recently'     => array( self::row( 30, 21, 21, 3600 ), Priority::Critical ),
			'many daily hits, not recently' => array( self::row( 30, 21, 21, 2 * $day ), Priority::Medium ),
			'many hits this week'           => array( self::row( 60, 0, 51, 4 * $day ), Priority::High ),
			'several hits today'            => array( self::row( 10, 6, 6, 4 * $day ), Priority::Medium ),
			'seen recently'                 => array( self::row( 1, 1, 1, 2 * $day ), Priority::Medium ),
			'thresholds are exclusive'      => array( self::row( 200, 5, 50, 3 * $day ), Priority::Low ),
			'few hits, not recently'        => array( self::row( 3, 0, 0, 10 * $day ), Priority::Low ),
		);
	}

	public function test_priority_label_and_value(): void {
		$this->assertSame( 'Critical', Priority::Critical->label() );
		// Used as a CSS class suffix and in the CSV export.
		$this->assertSame( 'critical', Priority::Critical->value );
	}

	public function test_priority_thresholds_are_settings() {
		$settings = array_merge( Settings::DEFAULTS, array( 'priority_critical_count' => 2 ) );
		$this->assertSame( Priority::Critical, Priority::compute( self::row( 3, 0, 0, 30 * DAY_IN_SECONDS ), 1000000000, $settings ) );
	}

	public function test_sparkline_series_is_zero_padded_in_the_site_timezone() {
		update_option( 'timezone_string', 'Europe/Paris' );
		// 2026-10-22 00:30 in Paris, still 2026-10-21 in UTC.
		$now = ( new DateTimeImmutable( '2026-10-22 00:30', wp_timezone() ) )->getTimestamp();

		$series = Sparkline::series(
			array(
				'2026-10-22' => 5,
				'2026-10-09' => 2,
				'2026-10-08' => 9,
			),
			$now
		);

		$this->assertCount( 14, $series );
		$this->assertSame( 2, $series[0] );
		$this->assertSame( 5, $series[13] );
		$this->assertSame( 7, array_sum( $series ) );
	}

	public function test_sparkline_trend() {
		$this->assertSame( 'up', Sparkline::trend( array( 1, 0, 3 ) ) );
		$this->assertSame( 'down', Sparkline::trend( array( 3, 9, 1 ) ) );
		$this->assertSame( 'flat', Sparkline::trend( array( 2, 0, 2 ) ) );
	}

	public function test_sparkline_svg() {
		$svg = Sparkline::svg( array( wp_date( 'Y-m-d' ) => 4 ), time() );

		$this->assertStringContainsString( 'lostlink-sparkline--up', $svg );
		$this->assertSame( 14, substr_count( $svg, '<rect ' ) );
		$this->assertStringContainsString( '<title>4</title>', $svg );
	}
}
