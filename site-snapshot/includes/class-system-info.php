<?php
/**
 * Environment report: WordPress, PHP, database, server.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class System_Info {

	/**
	 * @return array<string, array{title: string, rows: array<string, string>}>
	 */
	public static function collect() {
		return array(
			'wordpress' => array(
				'title' => __( 'WordPress', 'site-snapshot' ),
				'rows'  => self::wordpress(),
			),
			'php'       => array(
				'title' => __( 'PHP', 'site-snapshot' ),
				'rows'  => self::php(),
			),
			'database'  => array(
				'title' => __( 'Databáze', 'site-snapshot' ),
				'rows'  => self::database(),
			),
			'server'    => array(
				'title' => __( 'Server', 'site-snapshot' ),
				'rows'  => self::server(),
			),
		);
	}

	private static function wordpress() {
		global $wp_version;
		$theme   = wp_get_theme();
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all_plugins[ $file ] ) ) {
				$plugins[] = $all_plugins[ $file ]['Name'] . ' ' . $all_plugins[ $file ]['Version'];
			}
		}
		return array(
			__( 'Verze', 'site-snapshot' )              => $wp_version,
			__( 'Adresa webu (home)', 'site-snapshot' ) => home_url(),
			__( 'Adresa WP (siteurl)', 'site-snapshot' ) => site_url(),
			__( 'Kořenová složka (ABSPATH)', 'site-snapshot' ) => wp_normalize_path( ABSPATH ),
			__( 'wp-content', 'site-snapshot' )         => wp_normalize_path( WP_CONTENT_DIR ),
			__( 'Multisite', 'site-snapshot' )          => is_multisite() ? __( 'ano', 'site-snapshot' ) : __( 'ne', 'site-snapshot' ),
			__( 'Jazyk', 'site-snapshot' )              => get_locale(),
			__( 'Časové pásmo', 'site-snapshot' )       => wp_timezone_string(),
			__( 'Aktivní šablona', 'site-snapshot' )    => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' ),
			/* translators: %d: number of active plugins */
			sprintf( __( 'Aktivní pluginy (%d)', 'site-snapshot' ), count( $plugins ) ) => implode( ', ', $plugins ),
			'WP_DEBUG'                                  => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false',
			'WP_MEMORY_LIMIT'                           => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
		);
	}

	private static function php() {
		$extensions = array();
		foreach ( array( 'zlib', 'sodium', 'mysqli', 'zip', 'curl', 'openssl', 'mbstring', 'intl', 'gd', 'imagick', 'exif', 'fileinfo', 'opcache' ) as $ext ) {
			$extensions[] = $ext . ( extension_loaded( $ext ) || extension_loaded( 'Zend ' . ucfirst( $ext ) ) ? ' ✓' : ' ✗' );
		}
		return array(
			__( 'Verze', 'site-snapshot' )     => PHP_VERSION . ' (' . ( 8 === PHP_INT_SIZE ? '64bit' : '32bit – ' . __( 'nepodporováno', 'site-snapshot' ) ) . ')',
			'SAPI'                             => PHP_SAPI,
			'memory_limit'                     => (string) ini_get( 'memory_limit' ),
			'max_execution_time'               => (string) ini_get( 'max_execution_time' ),
			'upload_max_filesize'              => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'                    => (string) ini_get( 'post_max_size' ),
			'max_input_vars'                   => (string) ini_get( 'max_input_vars' ),
			__( 'Rozšíření', 'site-snapshot' ) => implode( ', ', $extensions ),
		);
	}

	private static function database() {
		global $wpdb;
		$server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$ver    = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$type   = false !== stripos( $ver . $server, 'mariadb' ) ? 'MariaDB' : 'MySQL';

		$size = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS tables_count, COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);
		$prefixed = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->base_prefix ) . '%'
			)
		);

		return array(
			__( 'Server', 'site-snapshot' )          => $type . ' ' . $ver,
			__( 'Klient (PHP)', 'site-snapshot' )    => $wpdb->use_mysqli && $wpdb->dbh instanceof \mysqli ? 'mysqli ' . mysqli_get_client_info() : 'mysql',
			__( 'Znaková sada', 'site-snapshot' )    => $wpdb->charset . ( $wpdb->collate ? ' / ' . $wpdb->collate : '' ),
			__( 'Prefix tabulek', 'site-snapshot' )  => $wpdb->base_prefix,
			__( 'Tabulek celkem', 'site-snapshot' )  => $size ? (string) $size->tables_count : '',
			__( 'Tabulek s prefixem', 'site-snapshot' ) => (string) $prefixed,
			__( 'Velikost databáze', 'site-snapshot' ) => $size ? size_format( (float) $size->bytes, 1 ) : '',
		);
	}

	private static function server() {
		$root  = wp_normalize_path( ABSPATH );
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$user  = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() ) : null;

		return array(
			__( 'Webový server', 'site-snapshot' )      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			__( 'Operační systém', 'site-snapshot' )    => PHP_OS_FAMILY . ' ' . php_uname( 'r' ) . ' (' . php_uname( 'm' ) . ')',
			__( 'Hostname', 'site-snapshot' )           => (string) gethostname(),
			__( 'IP serveru', 'site-snapshot' )         => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '',
			'DOCUMENT_ROOT'                             => isset( $_SERVER['DOCUMENT_ROOT'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) : '',
			__( 'PHP běží jako uživatel', 'site-snapshot' ) => is_array( $user ) ? $user['name'] : (string) get_current_user(),
			'HTTPS'                                     => is_ssl() ? __( 'ano', 'site-snapshot' ) : __( 'ne', 'site-snapshot' ),
			__( 'Volné místo na disku', 'site-snapshot' ) => false !== $free ? size_format( $free, 1 ) . ( $total ? ' / ' . size_format( $total, 1 ) : '' ) : __( 'nezjištěno', 'site-snapshot' ),
			__( 'Čas serveru', 'site-snapshot' )        => wp_date( 'Y-m-d H:i:s T' ),
		);
	}
}
