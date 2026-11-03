<?php
/**
 * Priority of the broken URLs.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * How urgently a broken URL should be fixed, computed from its hit counts.
 *
 * The value is used as a CSS class suffix and in the CSV export.
 *
 * @phpstan-import-type Values from Settings
 * @phpstan-type Counters array{count: int|numeric-string, daily_count: int|numeric-string, week_count: int|numeric-string, last_seen: int|numeric-string}
 */
enum Priority: string {

	case Critical = 'critical';
	case High     = 'high';
	case Medium   = 'medium';
	case Low      = 'low';

	/**
	 * Computes the priority of a broken URL.
	 *
	 * - Critical: many hits in total, or many hits in one day recently;
	 * - High: many hits this week;
	 * - Medium: several hits today, or seen recently;
	 * - Low: anything else.
	 *
	 * @param array $row      A record of the paths table.
	 * @param int   $now      Current timestamp.
	 * @param array $settings The settings, see Settings::all().
	 *
	 * @phpstan-param Counters $row
	 * @phpstan-param Values $settings
	 */
	public static function compute( array $row, int $now, array $settings ): self {
		$age = $now - (int) $row['last_seen'];

		if ( (int) $row['count'] > $settings['priority_critical_count']
			|| ( (int) $row['daily_count'] > $settings['priority_critical_daily_count'] && $age < $settings['priority_critical_recent_days'] * DAY_IN_SECONDS ) ) {
			return self::Critical;
		}
		if ( (int) $row['week_count'] > $settings['priority_high_week_count'] ) {
			return self::High;
		}
		if ( (int) $row['daily_count'] > $settings['priority_medium_daily_count'] || $age < $settings['priority_medium_recent_days'] * DAY_IN_SECONDS ) {
			return self::Medium;
		}

		return self::Low;
	}

	/**
	 * Returns the translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Critical => __( 'Critical', 'lostlink' ),
			self::High     => __( 'High', 'lostlink' ),
			self::Medium   => __( 'Medium', 'lostlink' ),
			self::Low      => __( 'Low', 'lostlink' ),
		};
	}
}
