<?php
/**
 * Removes plugin data: options, stored credentials, audit log and all backups –
 * on multisite for every site of the network (each has its own storage dir).
 *
 * @package SiteSnapshot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the current site's backup storage and plugin options.
 */
function sitesnap_uninstall_site() {
	$suffix = get_option( 'sitesnap_dir_suffix' );
	if ( is_string( $suffix ) && preg_match( '/^[a-z0-9]{16,}$/', $suffix ) ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'site-snapshot-' . $suffix;
		if ( is_dir( $dir ) ) {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				if ( $item->isDir() && ! $item->isLink() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	foreach ( array( 'sitesnap_dir_suffix', 'sitesnap_probe_token', 'sitesnap_log', 'sitesnap_access', 'sitesnap_active_job' ) as $option ) {
		delete_option( $option );
	}
	global $wpdb;
	// Transients: storage self-test result and per-user "credentials viewed" throttles.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sitesnap\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sitesnap\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
	wp_clear_scheduled_hook( 'sitesnap_cleanup' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sitesnap_site_id ) {
		switch_to_blog( $sitesnap_site_id );
		sitesnap_uninstall_site();
		restore_current_blog();
	}
} else {
	sitesnap_uninstall_site();
}
