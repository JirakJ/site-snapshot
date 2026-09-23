<?php
/**
 * Audit log – who did what and when (backups, downloads, viewing credentials).
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Activity_Log {

	const OPTION   = 'sitesnap_log';
	const MAX_ROWS = 500;

	public static function add( $action, $details = '' ) {
		$user = wp_get_current_user();
		$log  = self::all();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'user'    => $user && $user->exists() ? $user->user_login : '(system)',
				'ip'      => self::client_ip(),
				'action'  => (string) $action,
				'details' => (string) $details,
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX_ROWS ), false );
	}

	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	public static function label( $action ) {
		$labels = array(
			'backup_started'     => __( 'Záloha spuštěna', 'site-snapshot' ),
			'backup_completed'   => __( 'Záloha dokončena', 'site-snapshot' ),
			'backup_failed'      => __( 'Záloha selhala', 'site-snapshot' ),
			'backup_cancelled'   => __( 'Záloha zrušena', 'site-snapshot' ),
			'backup_downloaded'  => __( 'Záloha stažena', 'site-snapshot' ),
			'backup_deleted'     => __( 'Záloha smazána', 'site-snapshot' ),
			'file_downloaded'    => __( 'Stažen soubor', 'site-snapshot' ),
			'folder_downloaded'  => __( 'Stažena složka', 'site-snapshot' ),
			'credentials_viewed' => __( 'Zobrazeny přístupové údaje', 'site-snapshot' ),
			'credentials_saved'  => __( 'Uloženy přístupové údaje', 'site-snapshot' ),
			'log_cleared'        => __( 'Historie vymazána', 'site-snapshot' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * REMOTE_ADDR only – forwarded headers are trivially spoofable and this is an audit log.
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
