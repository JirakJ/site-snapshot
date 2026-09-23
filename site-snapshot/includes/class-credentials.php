<?php
/**
 * Access credentials: what WordPress knows (wp-config.php) plus FTP/hosting
 * access entered by the admin. Manually entered values are stored encrypted
 * (libsodium, key derived from the site's AUTH salt) so a DB-only leak does
 * not reveal them in plain text.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Credentials {

	const OPTION = 'sitesnap_access';

	/**
	 * Manual fields: key => [label, is_secret].
	 */
	public static function fields() {
		return array(
			'ftp_protocol'  => array( __( 'FTP protokol', 'site-snapshot' ), false ),
			'ftp_host'      => array( __( 'FTP server', 'site-snapshot' ), false ),
			'ftp_port'      => array( __( 'FTP port', 'site-snapshot' ), false ),
			'ftp_user'      => array( __( 'FTP uživatel', 'site-snapshot' ), false ),
			'ftp_pass'      => array( __( 'FTP heslo', 'site-snapshot' ), true ),
			'ftp_path'      => array( __( 'FTP cesta k webu', 'site-snapshot' ), false ),
			'hosting_url'   => array( __( 'Administrace hostingu (URL)', 'site-snapshot' ), false ),
			'hosting_user'  => array( __( 'Hosting – uživatel', 'site-snapshot' ), false ),
			'hosting_pass'  => array( __( 'Hosting – heslo', 'site-snapshot' ), true ),
			'db_admin_url'  => array( __( 'phpMyAdmin / Adminer (URL)', 'site-snapshot' ), false ),
			'note'          => array( __( 'Poznámka', 'site-snapshot' ), false ),
		);
	}

	/**
	 * @return array<int, array{label: string, value: string, secret: bool}>
	 */
	public static function from_config() {
		global $wpdb;
		$rows = array(
			array( 'label' => 'DB_HOST', 'value' => DB_HOST, 'secret' => false ),
			array( 'label' => 'DB_NAME', 'value' => DB_NAME, 'secret' => false ),
			array( 'label' => 'DB_USER', 'value' => DB_USER, 'secret' => false ),
			array( 'label' => 'DB_PASSWORD', 'value' => DB_PASSWORD, 'secret' => true ),
			array( 'label' => 'DB_CHARSET', 'value' => defined( 'DB_CHARSET' ) ? DB_CHARSET : '', 'secret' => false ),
			array( 'label' => '$table_prefix', 'value' => $wpdb->base_prefix, 'secret' => false ),
		);
		$optional = array(
			'FS_METHOD'       => false,
			'FTP_HOST'        => false,
			'FTP_USER'        => false,
			'FTP_PASS'        => true,
			'FTP_BASE'        => false,
			'FTP_SSL'         => false,
			'FTP_PUBKEY'      => false,
			'FTP_PRIKEY'      => false,
		);
		foreach ( $optional as $const => $secret ) {
			if ( defined( $const ) ) {
				$value  = constant( $const );
				$rows[] = array(
					'label'  => $const,
					'value'  => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value,
					'secret' => $secret,
				);
			}
		}
		return $rows;
	}

	/**
	 * @return array<int, array{label: string, value: string, secret: bool}>
	 */
	public static function administrators() {
		$args = array(
			'role'    => 'administrator',
			'orderby' => 'login',
			'fields'  => array( 'user_login', 'user_email' ),
		);
		if ( is_multisite() ) {
			$args['blog_id'] = 0;
			$args['login__in'] = get_super_admins();
			unset( $args['role'] );
		}
		$rows = array();
		foreach ( get_users( $args ) as $user ) {
			$rows[] = array(
				'label'  => $user->user_login,
				'value'  => $user->user_email,
				'secret' => false,
			);
		}
		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	public static function manual() {
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$json = self::decrypt( $stored );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? array_map( 'strval', $data ) : array();
	}

	/**
	 * True when something is stored but cannot be decrypted (salts changed).
	 */
	public static function manual_unreadable() {
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && '' !== $stored && false === self::decrypt( $stored );
	}

	public static function save_manual( array $input ) {
		$data = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$value = 'note' === $key ? sanitize_textarea_field( $input[ $key ] ) : trim( (string) $input[ $key ] );
			if ( 'note' !== $key ) {
				// Passwords may contain any printable characters – only strip control chars.
				$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
			}
			if ( '' !== $value ) {
				$data[ $key ] = $value;
			}
		}
		if ( empty( $data ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, self::encrypt( wp_json_encode( $data ) ), false );
	}

	/**
	 * Manual values as display rows (for the UI and the backup info file).
	 */
	public static function manual_rows() {
		$rows   = array();
		$values = self::manual();
		foreach ( self::fields() as $key => $meta ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$rows[] = array(
					'label'  => $meta[0],
					'value'  => $values[ $key ],
					'secret' => $meta[1],
				);
			}
		}
		return $rows;
	}

	private static function key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|site-snapshot', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|false
	 */
	private static function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'v1:' ) ) {
			return false;
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		try {
			return sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
