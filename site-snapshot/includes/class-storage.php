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

	const OPTION_SUFFIX      = 'sitesnap_dir_suffix';
	const OPTION_PROBE       = 'sitesnap_probe_token';
	const TRANSIENT_EXPOSURE = 'sitesnap_exposure';

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
			// IIS URL Authorization (system.webServer/security/authorization).
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'probe.txt'  => 'site-snapshot-probe:' . self::probe_token() . "\n",
		);
		foreach ( $guards as $name => $content ) {
			$path = $dir . '/' . $name;
			// Rewritten when outdated, so plugin updates fix the guards of existing installs.
			if ( ! file_exists( $path ) || @file_get_contents( $path ) !== $content ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	private static function probe_token() {
		$token = get_option( self::OPTION_PROBE );
		if ( ! is_string( $token ) || ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) {
			$token = strtolower( wp_generate_password( 32, false, false ) );
			update_option( self::OPTION_PROBE, $token, false );
		}
		return $token;
	}

	/**
	 * Public URL of the storage dir (it must NOT be reachable – see exposure()).
	 */
	public static function url() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['baseurl'] ) . basename( self::dir() );
	}

	/**
	 * Self-test of the web server protection: the site requests probe.txt from
	 * the storage dir over HTTP like any visitor would. Works for every setup
	 * (Apache, nginx, nginx in front of Apache, IIS, CDN) instead of guessing
	 * from SERVER_SOFTWARE.
	 *
	 * @param bool $refresh Ignore the cached result.
	 * @return string 'protected' | 'exposed' | 'unknown' (loopback request failed).
	 */
	public static function exposure( $refresh = false ) {
		$cached = get_transient( self::TRANSIENT_EXPOSURE );
		if ( ! $refresh && in_array( $cached, array( 'protected', 'exposed', 'unknown' ), true ) ) {
			return $cached;
		}
		$result = 'unknown';
		if ( ! is_wp_error( self::ensure_dir() ) ) {
			$response = wp_remote_get(
				self::url() . '/probe.txt',
				array(
					'timeout'     => 5,
					'redirection' => 2,
					'sslverify'   => false, // Loopback on staging/self-signed certificates.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$body   = (string) wp_remote_retrieve_body( $response );
				$result = ( 200 === $code && false !== strpos( $body, self::probe_token() ) ) ? 'exposed' : 'protected';
			}
		}
		set_transient( self::TRANSIENT_EXPOSURE, $result, 'protected' === $result ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $result;
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
