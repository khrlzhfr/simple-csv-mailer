<?php
/**
 * Removes the plugin's data when it is deleted: sending jobs, drafts, notices
 * and each site's uploads/mailer folder (lists, completed lists and email records).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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
	// Sending jobs, locks and notices have hashed or per-user names, so remove them by prefix.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'scm\\_job\\_%' OR option_name LIKE '\\_transient\\_scm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_scm\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$uploads = wp_upload_dir( null, false );
	simple_csv_mailer_uninstall_remove_dir( untrailingslashit( $uploads['basedir'] ) . '/mailer' );
}

/** Cleans up every site, plus the user meta shared across the network. */
function simple_csv_mailer_uninstall() {
	delete_metadata( 'user', 0, 'scm_draft', '', true );
	delete_metadata( 'user', 0, 'scm_last_file', '', true );

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
