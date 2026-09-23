<?php
/**
 * Removes plugin data: options, stored credentials, audit log and all backups.
 *
 * @package SiteSnapshot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sitesnap_suffix = get_option( 'sitesnap_dir_suffix' );
if ( is_string( $sitesnap_suffix ) && preg_match( '/^[a-z0-9]{16,}$/', $sitesnap_suffix ) ) {
	$sitesnap_uploads = wp_upload_dir( null, false );
	$sitesnap_dir     = trailingslashit( $sitesnap_uploads['basedir'] ) . 'site-snapshot-' . $sitesnap_suffix;
	if ( is_dir( $sitesnap_dir ) ) {
		$sitesnap_items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $sitesnap_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $sitesnap_items as $sitesnap_item ) {
			if ( $sitesnap_item->isDir() && ! $sitesnap_item->isLink() ) {
				@rmdir( $sitesnap_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $sitesnap_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $sitesnap_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

foreach ( array( 'sitesnap_dir_suffix', 'sitesnap_log', 'sitesnap_access', 'sitesnap_active_job' ) as $sitesnap_option ) {
	delete_option( $sitesnap_option );
}
wp_clear_scheduled_hook( 'sitesnap_cleanup' );
