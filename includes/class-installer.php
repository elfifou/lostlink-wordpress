<?php
/**
 * Database schema and lifecycle.
 *
 * @package Lostlink
 */

namespace Lostlink;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables.
 */
final class Installer {

	/**
	 * Version of the schema below. Not released yet: the schema is changed in
	 * place, and DB_VERSION stays 1 until the first release. After it, bump it
	 * when changing get_schema() or what install() stores.
	 */
	public const DB_VERSION = 1;

	/**
	 * Option storing the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'lostlink_db_version';

	/**
	 * Option storing when the plugin started recording 404s (timestamp).
	 *
	 * The 404s logged by other plugins before that time can be imported
	 * without counting a hit twice, see Redirection_Importer.
	 */
	public const INSTALLED_AT_OPTION = 'lostlink_installed_at';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
		Cron::schedule();
	}

	/**
	 * Deactivation hook. Data is kept, it is only removed on uninstall.
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Installs the schema if the stored version is behind.
	 *
	 * Activation hooks don't run on plugin updates, so the version is also
	 * checked on each load.
	 */
	public static function maybe_upgrade(): void {
		$version = get_option( self::DB_VERSION_OPTION, 0 );
		if ( ! is_numeric( $version ) || (int) $version < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the tables (dbDelta only adds, never drops).
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::get_schema() );
		// Autoloaded: maybe_upgrade() reads it on every request.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		if ( false === get_option( self::INSTALLED_AT_OPTION ) ) {
			add_option( self::INSTALLED_AT_OPTION, time(), '', false );
		}
	}

	/**
	 * Returns the CREATE TABLE statements, in the format dbDelta() expects.
	 *
	 * Paths are hashed (SHA-256 of the normalized path) so the primary keys
	 * stay short whatever the path length.
	 *
	 * @return string[]
	 */
	public static function get_schema(): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = Storage::tables();

		return array(
			"CREATE TABLE {$tables['paths']} (
  path_hash char(64) NOT NULL,
  path text NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 1,
  daily_count int(10) unsigned NOT NULL DEFAULT 1,
  week_count int(10) unsigned NOT NULL DEFAULT 1,
  first_seen int(10) unsigned NOT NULL,
  last_seen int(10) unsigned NOT NULL,
  fixed tinyint(1) unsigned NOT NULL DEFAULT 0,
  fixed_at int(10) unsigned DEFAULT NULL,
  fixed_redirect tinyint(1) unsigned NOT NULL DEFAULT 0,
  ignored tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (path_hash),
  KEY last_seen (last_seen),
  KEY count (count),
  KEY week_count (week_count),
  KEY fixed (fixed),
  KEY ignored (ignored)
) $charset_collate;",
			"CREATE TABLE {$tables['referers']} (
  path_hash char(64) NOT NULL,
  referer_hash char(64) NOT NULL,
  referer text NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 1,
  last_seen int(10) unsigned NOT NULL,
  PRIMARY KEY  (path_hash,referer_hash),
  KEY last_seen (last_seen)
) $charset_collate;",
			"CREATE TABLE {$tables['daily']} (
  path_hash char(64) NOT NULL,
  day date NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (path_hash,day),
  KEY day (day)
) $charset_collate;",
			"CREATE TABLE {$tables['redirects']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_hash char(64) NOT NULL,
  source text NOT NULL,
  target text NOT NULL,
  status_code smallint(3) unsigned NOT NULL DEFAULT 301,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  last_hit int(10) unsigned DEFAULT NULL,
  created_at int(10) unsigned NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash),
  KEY created_at (created_at)
) $charset_collate;",
		);
	}
}
