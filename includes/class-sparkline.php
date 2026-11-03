<?php
/**
 * Trend chart of the broken URLs.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a daily hit history as a small inline SVG bar chart.
 */
final class Sparkline {

	/**
	 * Width and height of the chart, in pixels.
	 */
	private const WIDTH  = 70;
	private const HEIGHT = 20;

	/**
	 * Returns the hits of the last Storage::HISTORY_DAYS days, oldest first.
	 *
	 * Zero-padded, so every row renders a chart of the same size, instead of a
	 * single bar for a URL that only has one or two days of history yet (the
	 * common case right after a URL starts returning 404).
	 *
	 * @param array<string, int> $history Hits keyed by day ("Y-m-d").
	 * @param int                $now     Current timestamp.
	 * @return list<int>
	 */
	public static function series( array $history, int $now ): array {
		$today  = Storage::local_date( $now );
		$series = array();
		for ( $i = Storage::HISTORY_DAYS - 1; $i >= 0; $i-- ) {
			$series[] = $history[ $today->modify( "-$i days" )->format( 'Y-m-d' ) ] ?? 0;
		}
		return $series;
	}

	/**
	 * Returns the trend of a series: "up", "down" or "flat".
	 *
	 * Compares the last day with the first one.
	 *
	 * @param int[] $series Daily hits, oldest first.
	 */
	public static function trend( array $series ): string {
		$delta = (int) end( $series ) - (int) reset( $series );
		return $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
	}

	/**
	 * Renders the chart of a daily hit history.
	 *
	 * @param array<string, int> $history Hits keyed by day ("Y-m-d").
	 * @param int                $now     Current timestamp.
	 * @return string SVG markup, safe to output.
	 */
	public static function svg( array $history, int $now ): string {
		$series    = self::series( $history, $now );
		$bar_width = self::WIDTH / count( $series );
		$max       = max( 1, ...$series );

		$bars = '';
		foreach ( $series as $index => $value ) {
			$bar_height = $value > 0 ? max( 2, ( $value / $max ) * ( self::HEIGHT - 2 ) ) : 1;
			$bars      .= sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" rx="1"><title>%s</title></rect>',
				round( $index * $bar_width, 2 ),
				round( self::HEIGHT - $bar_height, 2 ),
				round( $bar_width - 1, 2 ),
				round( $bar_height, 2 ),
				esc_html( (string) $value )
			);
		}

		return sprintf(
			'<svg class="lostlink-sparkline lostlink-sparkline--%1$s" viewBox="0 0 %2$d %3$d" width="%2$d" height="%3$d" role="img" aria-label="%4$s">%5$s</svg>',
			esc_attr( self::trend( $series ) ),
			self::WIDTH,
			self::HEIGHT,
			/* translators: %d: number of days. */
			esc_attr( sprintf( __( 'Hits over the last %d days', 'lostlink' ), Storage::HISTORY_DAYS ) ),
			$bars
		);
	}
}
