<?php
/**
 * Removes the plugin's data when it is deleted: sending jobs, drafts, notices
 * and each site's uploads/khrlzhfr-simple-csv-mailer folder (lists, completed lists and email records).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Whether another copy of this plugin (same main file, other folder) is still installed. */
function khrlscm_other_copy_installed() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( basename( $file ) === basename( WP_UNINSTALL_PLUGIN ) && $file !== WP_UNINSTALL_PLUGIN ) {
			return true;
		}
	}
	return false;
}

// Deleting one copy while another stays installed must not remove the data they share.
if ( khrlscm_other_copy_installed() ) {
	return;
}

/** Deletes a folder and everything in it. */
function khrlscm_uninstall_remove_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/** Cleans up the current site. */
function khrlscm_uninstall_site() {
	global $wpdb;

	delete_option( 'khrlscm_storage_version' );
	wp_unschedule_hook( 'khrlscm_send' );
	// Sending jobs, locks and notices have hashed or per-user names, so remove them by prefix.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'khrlscm\\_job\\_%' OR option_name LIKE '\\_transient\\_khrlscm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_khrlscm\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$uploads = wp_upload_dir( null, false );
	khrlscm_uninstall_remove_dir( untrailingslashit( $uploads['basedir'] ) . '/khrlzhfr-simple-csv-mailer' );
}

/** Cleans up every site, plus the user meta shared across the network. */
function khrlscm_uninstall() {
	delete_metadata( 'user', 0, 'khrlscm_draft', '', true );
	delete_metadata( 'user', 0, 'khrlscm_last_file', '', true );
	delete_metadata( 'user', 0, 'khrlscm_csv_url', '', true );

	if ( is_multisite() ) {
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			switch_to_blog( $site_id );
			khrlscm_uninstall_site();
			restore_current_blog();
		}
	} else {
		khrlscm_uninstall_site();
	}
}

khrlscm_uninstall();
