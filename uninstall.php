<?php
/**
 * Removes the plugin data when the plugin is deleted.
 *
 * @package Lostlink
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

/** @var \wpdb $wpdb */
global $wpdb;

foreach ( Lostlink\Storage::tables() as $lostlink_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $lostlink_table ) );
}

delete_option( Lostlink\Settings::OPTION );
delete_option( Lostlink\Installer::DB_VERSION_OPTION );
delete_option( Lostlink\Installer::INSTALLED_AT_OPTION );
delete_option( Lostlink\Redirection_Importer::STATE_OPTION );
delete_option( Lostlink\Redirection_Importer::LOCK_OPTION );
delete_transient( Lostlink\Path_Provider::TRANSIENT );
delete_transient( Lostlink\Path_Provider::SITEMAP_TRANSIENT );
wp_clear_scheduled_hook( Lostlink\Cron::HOOK );

// Screen options of each user: URLs per page, hidden columns.
delete_metadata( 'user', 0, Lostlink\Dashboard_Table::PER_PAGE_OPTION, '', true );
delete_metadata( 'user', 0, 'managetools_page_lostlinkcolumnshidden', '', true );
