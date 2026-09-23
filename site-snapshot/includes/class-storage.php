<?php
/**
 * Private storage directory for backups.
 *
 * Lives in uploads under a random, unguessable name and is protected against
 * direct HTTP access (.htaccess for Apache/LiteSpeed, web.config for IIS,
 * index.php everywhere). On nginx the random name is the protection, so the
 * admin UI tells the user to delete backups after downloading them.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const OPTION_SUFFIX = 'sitesnap_dir_suffix';

	public static function dir() {
		$suffix = get_option( self::OPTION_SUFFIX );
		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-z0-9]{16,}$/', $suffix ) ) {
			$suffix = strtolower( wp_generate_password( 24, false, false ) );
			update_option( self::OPTION_SUFFIX, $suffix, false );
		}
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( $uploads['basedir'] ); // Match the realpath()-based file browser paths.
		return wp_normalize_path( trailingslashit( false !== $base ? $base : $uploads['basedir'] ) . 'site-snapshot-' . $suffix );
	}

	/**
	 * True for any Site Snapshot storage dir – also other multisite subsites'
	 * (uploads/sites/N/site-snapshot-*), which have their own random suffix.
	 */
	public static function is_storage_dir( $path ) {
		return 1 === preg_match( '/^site-snapshot-[a-z0-9]{16,}$/', basename( $path ) )
			&& is_file( $path . '/.htaccess' )
			&& false !== strpos( (string) @file_get_contents( $path . '/.htaccess', false, null, 0, 64 ), 'Site Snapshot' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Creates the storage dir with protection files. Returns the path or WP_Error.
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'sitesnap_storage', sprintf( __( 'Nelze vytvořit složku pro zálohy: %s', 'site-snapshot' ), $dir ) );
		}
		$guards = array(
			'.htaccess'  => "# Site Snapshot – přímý přístup zakázán\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $guards as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	/**
	 * Recursively deletes a directory that must live inside the storage dir.
	 */
	public static function delete_tree( $path ) {
		$path = wp_normalize_path( $path );
		$root = self::dir();
		if ( 0 !== strpos( $path, $root . '/' ) || ! file_exists( $path ) ) {
			return false;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Streams a file to the browser as an attachment and exits.
	 */
	public static function send_file( $path, $download_name, $content_type = 'application/octet-stream' ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Soubor nelze přečíst.', 'site-snapshot' ), 404 );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '_', remove_accents( $download_name ) );
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			flush();
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
