<?php
/**
 * Download endpoints (admin-post.php): finished backups, single files and
 * on-the-fly ZIPs of a folder. All require the capability and a nonce.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Downloader {

	const NONCE = 'sitesnap_download';

	public function register() {
		add_action( 'admin_post_sitesnap_download_backup', array( $this, 'backup' ) );
		add_action( 'admin_post_sitesnap_download_file', array( $this, 'file' ) );
		add_action( 'admin_post_sitesnap_download_folder', array( $this, 'folder' ) );
	}

	public static function url( $action, array $args ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	private function guard() {
		if ( ! Plugin::current_user_allowed() ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'site-snapshot' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	public function backup() {
		$this->guard();
		$id  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$job = Backup_Job::load( $id );
		if ( ! $job || 'done' !== $job->get( 'status' ) ) {
			wp_die( esc_html__( 'Záloha nenalezena nebo ještě není dokončená.', 'site-snapshot' ), 404 );
		}
		Activity_Log::add( 'backup_downloaded', $id . ' – ' . size_format( (int) $job->get( 'zip_size' ), 1 ) );
		Storage::send_file( $job->archive_path(), (string) $job->get( 'download' ), 'application/zip' );
	}

	public function file() {
		$this->guard();
		$path = $this->requested_path();
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Soubor nenalezen.', 'site-snapshot' ), 404 );
		}
		Activity_Log::add( 'file_downloaded', File_Browser::relative( $path ) );
		Storage::send_file( $path, basename( $path ) );
	}

	public function folder() {
		$this->guard();
		$path = $this->requested_path();
		if ( ! is_dir( $path ) ) {
			wp_die( esc_html__( 'Složka nenalezena.', 'site-snapshot' ), 404 );
		}
		$root = Storage::ensure_dir();
		if ( is_wp_error( $root ) ) {
			wp_die( esc_html( $root->get_error_message() ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$tmp = $root . '/tmp-' . strtolower( wp_generate_password( 12, false, false ) );
		wp_mkdir_p( $tmp );
		register_shutdown_function( array( Storage::class, 'delete_tree' ), $tmp );

		$rel    = File_Browser::relative( $path );
		$base   = '' === $rel ? basename( File_Browser::root() ) : basename( $path );
		$count  = 0;
		$failed = array();
		try {
			$zip = new Zip_Writer( $tmp . '/archive.zip', $tmp . '/central.bin' );
			foreach ( File_Browser::walk( $path ) as $file ) {
				$inner = substr( $file, strlen( $path ) + 1 );
				try {
					$zip->add_file( $file, $base . '/' . $inner );
					++$count;
				} catch ( Zip_Read_Error $e ) {
					$failed[] = $inner;
				}
			}
			if ( $failed ) {
				$zip->add_string( $base . '/_NEPRECTENE-SOUBORY.txt', implode( "\n", $failed ) . "\n" );
			}
			$zip->finish();
			$zip->close();
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}

		Activity_Log::add( 'folder_downloaded', ( '' === $rel ? '/' : $rel ) . ' – ' . $count . ' souborů' );
		Storage::send_file( $tmp . '/archive.zip', $base . '-' . wp_date( 'Y-m-d-His' ) . '.zip', 'application/zip' );
	}

	private function requested_path() {
		$rel  = isset( $_GET['path'] ) ? wp_unslash( $_GET['path'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by File_Browser::resolve().
		$path = File_Browser::resolve( (string) $rel );
		if ( null === $path || 0 === strpos( $path . '/', Storage::dir() . '/' ) || self::inside_any_storage( $path ) ) {
			wp_die( esc_html__( 'Neplatná cesta.', 'site-snapshot' ), 400 );
		}
		return $path;
	}

	/**
	 * Refuses any path inside a (sub)site's backup storage dir.
	 */
	private static function inside_any_storage( $path ) {
		$root = File_Browser::root();
		for ( $p = $path; strlen( $p ) > strlen( $root ); $p = dirname( $p ) ) {
			if ( is_dir( $p ) && Storage::is_storage_dir( $p ) ) {
				return true;
			}
		}
		return false;
	}
}
