<?php
/**
 * Removes the plugin's data when it is deleted: sending jobs, drafts, notices
 * and each site's uploads/mailer folder (lists, completed lists and email records).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Whether another copy of this plugin (same main file, other folder) is still installed. */
function simple_csv_mailer_other_copy_installed() {
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
if ( simple_csv_mailer_other_copy_installed() ) {
	return;
}

/** Deletes a folder and everything in it. */
function simple_csv_mailer_uninstall_remove_dir( $dir ) {
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
function simple_csv_mailer_uninstall_site() {
	global $wpdb;

	delete_option( 'scm_storage_version' );
	wp_unschedule_hook( 'scm_send' );
	// Sending jobs, locks and notices have hashed or per-user names, so remove them by prefix.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'scm\\_job\\_%' OR option_name LIKE '\\_transient\\_scm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_scm\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$uploads = wp_upload_dir( null, false );
	simple_csv_mailer_uninstall_remove_dir( untrailingslashit( $uploads['basedir'] ) . '/mailer' );
}

/** Cleans up every site, plus the user meta shared across the network. */
function simple_csv_mailer_uninstall() {
	delete_metadata( 'user', 0, 'scm_draft', '', true );
	delete_metadata( 'user', 0, 'scm_last_file', '', true );
	delete_metadata( 'user', 0, 'scm_csv_url', '', true );

	if ( is_multisite() ) {
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			switch_to_blog( $site_id );
			simple_csv_mailer_uninstall_site();
			restore_current_blog();
		}
	} else {
		simple_csv_mailer_uninstall_site();
	}
}

simple_csv_mailer_uninstall();
