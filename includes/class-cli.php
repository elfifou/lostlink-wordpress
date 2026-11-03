<?php
/**
 * WP-CLI commands.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects and maintains the recorded 404s.
 */
class Cli {

	/**
	 * Lists the recorded 404s, most hit first.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum number of URLs listed.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lostlink list --limit=10
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = ( new Storage() )->list_paths( max( 1, (int) $assoc_args['limit'] ) );
		foreach ( $rows as &$row ) {
			$row['last_seen'] = wp_date( 'Y-m-d H:i', (int) $row['last_seen'] );
		}
		unset( $row );

		\WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'path', 'count', 'daily_count', 'week_count', 'last_seen', 'fixed', 'ignored' ) );
	}

	/**
	 * Lists the referers of a recorded 404.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : The broken path, e.g. /old-page.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function referers( array $args, array $assoc_args ): void {
		$rows = ( new Storage() )->list_referers( $args[0] );
		foreach ( $rows as &$row ) {
			$row['last_seen'] = wp_date( 'Y-m-d H:i', (int) $row['last_seen'] );
		}
		unset( $row );

		\WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'referer', 'count', 'last_seen' ) );
	}

	/**
	 * Suggests redirect targets for a path, as the fix page does.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : The broken path, e.g. /old-page.
	 *
	 * [--limit=<limit>]
	 * : Maximum number of suggestions.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--min-score=<min-score>]
	 * : Minimum similarity, in percent.
	 * ---
	 * default: 80
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lostlink suggest /2021/05/hello-world --min-score=50
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function suggest( array $args, array $assoc_args ): void {
		$suggestions = ( new Path_Suggester( new Path_Provider() ) )->suggest( $args[0], max( 1, (int) $assoc_args['limit'] ), (int) $assoc_args['min-score'] );
		\WP_CLI\Utils\format_items( $assoc_args['format'], $suggestions, array( 'path', 'score', 'title', 'type', 'language' ) );
	}

	/**
	 * Imports the 404s the Redirection plugin logged before this plugin was installed.
	 *
	 * Resumes where a previous import stopped; rows already imported are skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lostlink import-redirection
	 *
	 * @subcommand import-redirection
	 */
	public function import_redirection(): void {
		$importer = Redirection_Importer::create_default();
		if ( ! $importer->is_available() ) {
			\WP_CLI::error( sprintf( 'The Redirection 404 log table %s does not exist.', Redirection_Importer::table() ) );
		}

		$pending = $importer->count_pending();
		if ( 0 === $pending ) {
			\WP_CLI::success( 'Nothing to import.' );
			return;
		}

		if ( ! $importer->lock() ) {
			\WP_CLI::error( Redirection_Importer::locked_error()->get_error_message() );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing', $pending );
		$read     = 0;
		$imported = 0;
		try {
			do {
				$batch     = $importer->import_batch();
				$read     += $batch['read'];
				$imported += $batch['imported'];
				$progress->tick( $batch['read'] );
			} while ( Redirection_Importer::BATCH_SIZE === $batch['read'] );
		} finally {
			$importer->unlock();
		}
		$progress->finish();

		\WP_CLI::success( sprintf( '%d log rows read, %d hits counted (the others are excluded or on fixed URLs).', $read, $imported ) );
	}

	/**
	 * Resets the stale counters and purges old data now, as the hourly cron does.
	 */
	public function maintenance(): void {
		$deleted = Cron::run();
		\WP_CLI::success( sprintf( 'Purged %d history rows, %d referers, %d URLs.', $deleted['history'], $deleted['referers'], $deleted['urls'] ) );
	}
}
