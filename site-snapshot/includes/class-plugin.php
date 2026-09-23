<?php
/**
 * Bootstrap: hooks, capability check, cron cleanup.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const CRON_HOOK = 'sitesnap_cleanup';

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( Backup_Job::class, 'cleanup_abandoned' ) );

		if ( is_admin() ) {
			( new Admin() )->register();
			( new Downloader() )->register();
			Backup_Job::register_ajax();
		}
	}

	/**
	 * Only administrators (super admins on multisite) may use the plugin –
	 * it exposes the whole filesystem, the database and credentials.
	 */
	public static function current_user_allowed() {
		if ( is_multisite() ) {
			return is_super_admin();
		}
		return current_user_can( 'manage_options' );
	}

	public static function activate() {
		Storage::ensure_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
