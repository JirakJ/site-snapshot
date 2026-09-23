<?php
/**
 * Resumable backup job: scan files → dump database → pack files → finalize.
 *
 * The browser drives the job with short AJAX "step" requests, each limited by
 * a time budget, so large sites work even with a 30 s max_execution_time.
 * Every phase (including the file scan) commits its progress to job.json; a
 * step that dies half-way is rolled back to the last commit by the next step
 * (work files are truncated to their committed sizes).
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Backup_Job {

	const ID_PATTERN      = '/^\d{8}-\d{6}-[a-z0-9]{6}$/';
	const ACTIVE_OPTION   = 'sitesnap_active_job';
	const STALE_SECONDS   = 900;
	const COMMIT_SECONDS  = 2;
	const MAX_WARNINGS    = 200;
	const SQL_DEFLATE_MAX = 268435456;

	/** @var array<string, mixed> */
	private $state;
	/** @var string */
	private $dir;

	private function __construct( $dir, array $state ) {
		$this->dir   = $dir;
		$this->state = $state;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function register_ajax() {
		add_action( 'wp_ajax_sitesnap_backup_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_sitesnap_backup_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_sitesnap_backup_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_sitesnap_backup_delete', array( __CLASS__, 'ajax_delete' ) );
	}

	private static function guard() {
		check_ajax_referer( 'sitesnap', 'nonce' );
		if ( ! Plugin::current_user_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'site-snapshot' ) ), 403 );
		}
		global $wpdb;
		$wpdb->suppress_errors( true ); // DB warnings must not corrupt the JSON response.
	}

	private static function request_id() {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		if ( ! preg_match( self::ID_PATTERN, $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Neplatné ID zálohy.', 'site-snapshot' ) ), 400 );
		}
		return $id;
	}

	public static function ajax_start() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified.
		$options = array(
			'files'         => ! empty( $_POST['files'] ),
			'db'            => ! empty( $_POST['db'] ),
			'all_tables'    => ! empty( $_POST['all_tables'] ),
			'exclude_cache' => ! empty( $_POST['exclude_cache'] ),
		);
		// phpcs:enable
		if ( ! $options['files'] && ! $options['db'] ) {
			wp_send_json_error( array( 'message' => __( 'Vyberte soubory, databázi nebo obojí.', 'site-snapshot' ) ), 400 );
		}
		$running = self::running();
		if ( $running ) {
			wp_send_json_error(
				array(
					'message' => __( 'Jiná záloha právě běží – počkejte na její dokončení nebo ji zrušte.', 'site-snapshot' ),
					'job'     => $running->public_state(),
				),
				409
			);
		}
		$job = self::create( $options );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 500 );
		}
		wp_send_json_success( $job->public_state() );
	}

	public static function ajax_step() {
		self::guard();
		$job = self::load( self::request_id() );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Záloha nenalezena.', 'site-snapshot' ) ), 404 );
		}
		if ( 'running' === $job->state['status'] ) {
			// Resuming an interrupted (stale) job is allowed unless another one is active.
			$active = self::running();
			if ( $active && $active->state['id'] !== $job->state['id'] ) {
				wp_send_json_error( array( 'message' => __( 'Jiná záloha právě běží.', 'site-snapshot' ) ), 409 );
			}
			update_option( self::ACTIVE_OPTION, $job->state['id'], false );
		}
		$job->run_step();
		wp_send_json_success( $job->public_state() );
	}

	/**
	 * Cancelling never races a running step: it only raises a flag. Whoever
	 * holds the job lock (this request if the job is idle, otherwise the
	 * running step at its next checkpoint) tears the job down.
	 */
	public static function ajax_cancel() {
		self::guard();
		$job = self::load( self::request_id() );
		if ( $job && 'running' === $job->state['status'] ) {
			touch( $job->dir . '/cancel' );
			$lock = $job->acquire_lock();
			if ( $lock ) {
				$job->teardown_cancelled( $lock );
			}
		}
		wp_send_json_success();
	}

	public static function ajax_delete() {
		self::guard();
		$id  = self::request_id();
		$job = self::load( $id );
		if ( $job && 'running' === $job->state['status'] && ! $job->is_stale() ) {
			wp_send_json_error( array( 'message' => __( 'Běžící zálohu nejdřív zrušte.', 'site-snapshot' ) ), 409 );
		}
		Storage::delete_tree( Storage::dir() . '/' . $id );
		self::release_active( $id );
		Activity_Log::add( 'backup_deleted', $id );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * @return Backup_Job|\WP_Error
	 */
	public static function create( array $options ) {
		$root = Storage::ensure_dir();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$id  = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$dir = $root . '/' . $id;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'sitesnap_mkdir', __( 'Nelze vytvořit pracovní složku zálohy.', 'site-snapshot' ) );
		}
		// Blocks directory listing where .htaccess is ignored (nginx with autoindex).
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$user = wp_get_current_user();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$job  = new self(
			$dir,
			array(
				'id'           => $id,
				'version'      => SITESNAP_VERSION,
				'created'      => time(),
				'updated'      => time(),
				'finished'     => 0,
				'user'         => $user->user_login,
				'status'       => 'running',
				'phase'        => $options['files'] ? 'scan' : 'db',
				'options'      => $options,
				'download'     => sanitize_file_name( ( $host ? $host : 'site' ) . '-' . wp_date( 'Y-m-d-His' ) . '.zip' ),
				'sources'      => $options['files'] ? self::sources() : array(),
				// Scan: queue of directories (queue.txt) and list of files (files.txt).
				'queue_read'   => 0,
				'queue_size'   => 0,
				'list_size'    => 0,
				'files_total'  => 0,
				'bytes_total'  => 0,
				// Pack.
				'files_done'   => 0,
				'bytes_done'   => 0,
				'list_offset'  => 0,
				'zip'          => array( 'archive' => 0, 'central' => 0, 'entries' => 0 ),
				// Database.
				'tables'       => array(),
				'table_index'  => 0,
				'table_cursor' => null,
				'rows_done'    => 0,
				'sql_size'     => 0,
				'zip_size'     => 0,
				'warnings'     => array(),
				'error'        => '',
			)
		);
		$job->save();
		update_option( self::ACTIVE_OPTION, $id, false );
		$what = array();
		if ( $options['files'] ) {
			$what[] = __( 'soubory', 'site-snapshot' );
		}
		if ( $options['db'] ) {
			$what[] = $options['all_tables'] ? __( 'celá databáze', 'site-snapshot' ) : __( 'databáze (tabulky webu)', 'site-snapshot' );
		}
		Activity_Log::add( 'backup_started', $id . ' – ' . implode( ' + ', $what ) );
		return $job;
	}

	/**
	 * @return Backup_Job|null
	 */
	public static function load( $id ) {
		if ( ! preg_match( self::ID_PATTERN, (string) $id ) ) {
			return null;
		}
		$dir  = Storage::dir() . '/' . $id;
		$json = @file_get_contents( $dir . '/job.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false !== $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && isset( $data['id'] ) && $data['id'] === $id ? new self( $dir, $data ) : null;
	}

	/**
	 * All jobs, newest first.
	 *
	 * @return Backup_Job[]
	 */
	public static function all() {
		$jobs = array();
		$dirs = glob( Storage::dir() . '/*', GLOB_ONLYDIR );
		foreach ( $dirs ? $dirs : array() as $dir ) {
			$job = self::load( basename( $dir ) );
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		usort(
			$jobs,
			static function ( $a, $b ) {
				return $b->state['created'] <=> $a->state['created'];
			}
		);
		return $jobs;
	}

	/**
	 * The currently running (non-stale) job, if any.
	 *
	 * @return Backup_Job|null
	 */
	public static function running() {
		$id  = get_option( self::ACTIVE_OPTION );
		$job = $id ? self::load( $id ) : null;
		if ( $job && 'running' === $job->state['status'] && ! $job->is_stale() ) {
			return $job;
		}
		return null;
	}

	/**
	 * Clears the active-job marker only when it still points at $id, so a
	 * finishing old job can never unmark a newer one.
	 */
	private static function release_active( $id ) {
		if ( get_option( self::ACTIVE_OPTION ) === $id ) {
			delete_option( self::ACTIVE_OPTION );
		}
	}

	/**
	 * Daily cron: removes jobs that never finished (browser closed etc.).
	 */
	public static function cleanup_abandoned() {
		foreach ( self::all() as $job ) {
			$abandoned = in_array( $job->state['status'], array( 'running', 'failed', 'cancelled' ), true )
				&& time() - (int) $job->state['updated'] > DAY_IN_SECONDS;
			if ( $abandoned ) {
				Storage::delete_tree( $job->dir );
				self::release_active( $job->state['id'] );
			}
		}
	}

	public function is_stale() {
		return time() - (int) $this->state['updated'] > self::STALE_SECONDS;
	}

	public function get( $key ) {
		return isset( $this->state[ $key ] ) ? $this->state[ $key ] : null;
	}

	public function archive_path() {
		return $this->dir . '/archive.zip';
	}

	/**
	 * State safe to send to the browser.
	 */
	public function public_state() {
		$s     = $this->state;
		$table = isset( $s['tables'][ $s['table_index'] ] ) ? $s['tables'][ $s['table_index'] ]['name'] : '';
		return array(
			'id'          => $s['id'],
			'status'      => $s['status'],
			'phase'       => $s['phase'],
			'progress'    => $this->progress(),
			'message'     => $this->message( $table ),
			'files_done'  => $s['files_done'],
			'files_total' => $s['files_total'],
			'bytes_done'  => $s['bytes_done'],
			'bytes_total' => $s['bytes_total'],
			'rows_done'   => $s['rows_done'],
			'zip_size'    => $s['zip_size'],
			'warnings'    => count( $s['warnings'] ),
			'error'       => $s['error'],
		);
	}

	/**
	 * Rough overall percentage: DB counts ~20 %, files ~75 % (by bytes).
	 */
	private function progress() {
		$s = $this->state;
		if ( 'done' === $s['status'] ) {
			return 100;
		}
		$db_share    = $s['options']['db'] ? ( $s['options']['files'] ? 20 : 95 ) : 0;
		$files_share = $s['options']['files'] ? ( $s['options']['db'] ? 75 : 95 ) : 0;
		$done        = 0.0;
		if ( $s['options']['db'] ) {
			$tables = count( $s['tables'] );
			if ( in_array( $s['phase'], array( 'files', 'finalize' ), true ) ) {
				$done += $db_share;
			} elseif ( 'db' === $s['phase'] && $tables > 0 ) {
				$done += $db_share * $s['table_index'] / $tables;
			}
		}
		if ( $s['options']['files'] && 'finalize' === $s['phase'] ) {
			$done += $files_share;
		} elseif ( $s['options']['files'] && 'files' === $s['phase'] && $s['bytes_total'] > 0 ) {
			$done += $files_share * min( 1, $s['bytes_done'] / $s['bytes_total'] );
		}
		return (int) min( 99, floor( $done ) );
	}

	private function message( $table ) {
		$s = $this->state;
		switch ( $s['status'] ) {
			case 'done':
				return __( 'Hotovo – záloha je připravena ke stažení.', 'site-snapshot' );
			case 'failed':
				return __( 'Záloha selhala: ', 'site-snapshot' ) . $s['error'];
			case 'cancelled':
				return __( 'Záloha zrušena.', 'site-snapshot' );
		}
		switch ( $s['phase'] ) {
			case 'scan':
				/* translators: 1: files found, 2: their size */
				return sprintf( __( 'Procházím soubory webu… (%1$s souborů, %2$s)', 'site-snapshot' ), number_format_i18n( $s['files_total'] ), size_format( $s['bytes_total'], 1 ) );
			case 'db':
				/* translators: 1: table name, 2: number of rows exported so far */
				return sprintf( __( 'Exportuji databázi – tabulka %1$s (%2$s řádků celkem)…', 'site-snapshot' ), $table, number_format_i18n( $s['rows_done'] ) );
			case 'files':
				/* translators: 1: files done, 2: files total, 3: bytes done, 4: bytes total */
				return sprintf(
					__( 'Balím soubory %1$s / %2$s (%3$s / %4$s)…', 'site-snapshot' ),
					number_format_i18n( $s['files_done'] ),
					number_format_i18n( $s['files_total'] ),
					size_format( $s['bytes_done'], 1 ),
					size_format( $s['bytes_total'], 1 )
				);
			case 'finalize':
				return __( 'Dokončuji archiv…', 'site-snapshot' );
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @return resource|null Lock handle, or null when another request holds it.
	 */
	private function acquire_lock() {
		$lock = @fopen( $this->dir . '/job.lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $lock ) {
			return null;
		}
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		return $lock;
	}

	private static function release_lock( $lock ) {
		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	private function cancel_requested() {
		return file_exists( $this->dir . '/cancel' );
	}

	/**
	 * Called with the job lock held: marks the job cancelled and removes its files.
	 */
	private function teardown_cancelled( $lock ) {
		$this->state['status'] = 'cancelled';
		self::release_active( $this->state['id'] );
		Activity_Log::add( 'backup_cancelled', $this->state['id'] );
		self::release_lock( $lock );
		Storage::delete_tree( $this->dir );
	}

	public function run_step() {
		if ( 'running' !== $this->state['status'] ) {
			return;
		}
		$lock = $this->acquire_lock();
		if ( ! $lock ) {
			return; // Another step of this job is still running – the browser will poll again.
		}
		// Re-read under the lock: a previous step may have committed meanwhile.
		$fresh = self::load( $this->state['id'] );
		if ( $fresh ) {
			$this->state = $fresh->state;
		}
		if ( 'running' !== $this->state['status'] ) {
			self::release_lock( $lock );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );
		$deadline = microtime( true ) + self::time_budget();

		try {
			while ( 'running' === $this->state['status'] && microtime( true ) < $deadline && ! $this->cancel_requested() ) {
				switch ( $this->state['phase'] ) {
					case 'scan':
						$this->step_scan( $deadline );
						break;
					case 'db':
						$this->step_db( $deadline );
						break;
					case 'files':
						$this->step_files( $deadline );
						break;
					case 'finalize':
						$this->step_finalize();
						break;
					default:
						throw new \RuntimeException( 'Neznámá fáze ' . $this->state['phase'] );
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail( $e->getMessage() );
		}

		if ( 'running' === $this->state['status'] && $this->cancel_requested() ) {
			$this->teardown_cancelled( $lock );
			return;
		}
		try {
			$this->save();
		} catch ( \Throwable $e ) {
			unset( $e ); // Nothing more to do – the browser sees the last committed state.
		}
		self::release_lock( $lock );
	}

	/**
	 * Seconds of work per request: half of max_execution_time, 5–20 s.
	 */
	private static function time_budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? max( 5, min( 20, (int) floor( $max / 2 ) ) ) : 20;
		return max( 0.01, (float) apply_filters( 'sitesnap_step_seconds', $budget ) );
	}

	/**
	 * What to back up: the WordPress root under files/, plus parts of the
	 * install that live outside it (wp-config.php one level up, wp-content /
	 * plugins / uploads moved elsewhere – e.g. Bedrock) under extra/.
	 *
	 * @return array<int, array{path: string, zip: string, type: string}>
	 */
	public static function sources() {
		$root    = File_Browser::root();
		$sources = array(
			array(
				'path' => $root,
				'zip'  => 'files',
				'type' => 'dir',
			),
		);
		$uploads    = wp_upload_dir( null, false );
		$candidates = array( WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root(), $uploads['basedir'] );
		$used       = array( 'files' => true );
		foreach ( $candidates as $candidate ) {
			$real = realpath( (string) $candidate );
			if ( false === $real || ! is_dir( $real ) ) {
				continue;
			}
			$real = wp_normalize_path( $real );
			foreach ( $sources as $source ) {
				if ( 'dir' === $source['type'] && ( $real === $source['path'] || 0 === strpos( $real, $source['path'] . '/' ) ) ) {
					continue 2; // Already covered.
				}
			}
			$zip = 'extra/' . basename( $real );
			for ( $i = 2; isset( $used[ $zip ] ); $i++ ) {
				$zip = 'extra/' . basename( $real ) . '-' . $i;
			}
			$used[ $zip ] = true;
			$sources[]    = array(
				'path' => $real,
				'zip'  => $zip,
				'type' => 'dir',
			);
		}
		// WordPress also loads wp-config.php from the parent dir if that dir is not another install.
		$parent = dirname( $root );
		if ( ! file_exists( $root . '/wp-config.php' ) && is_file( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
			$sources[] = array(
				'path' => $parent . '/wp-config.php',
				'zip'  => 'extra/wp-config.php',
				'type' => 'file',
			);
		}
		return $sources;
	}

	/**
	 * Breadth-first scan, resumable: queue.txt holds "zip-prefix<TAB>abs-dir"
	 * lines still to read, files.txt receives "zip-name<TAB>abs-path" lines.
	 */
	private function step_scan( $deadline ) {
		$queue_path = $this->dir . '/queue.txt';
		$list_path  = $this->dir . '/files.txt';
		$queue      = self::open_truncated( $queue_path, $this->state['queue_size'] );
		$list       = self::open_truncated( $list_path, $this->state['list_size'] );
		$exclude    = $this->state['options']['exclude_cache'] ? self::cache_dirs() : array();

		try {
			if ( 0 === $this->state['queue_size'] && 0 === $this->state['list_size'] ) {
				foreach ( $this->state['sources'] as $source ) {
					if ( 'dir' === $source['type'] ) {
						self::write_all( $queue, $source['zip'] . "\t" . $source['path'] . "\n" );
					} else {
						$this->add_to_list( $list, $source['zip'], $source['path'] );
					}
				}
				$this->commit_scan( $queue, $list );
			}

			$last_commit = microtime( true );
			while ( microtime( true ) < $deadline ) {
				fseek( $queue, $this->state['queue_read'] );
				$line = fgets( $queue );
				fseek( $queue, 0, SEEK_END );
				if ( false === $line ) {
					$this->state['phase'] = $this->state['options']['db'] ? 'db' : 'files';
					break;
				}
				$next = $this->state['queue_read'] + strlen( $line );
				list( $prefix, $dir ) = explode( "\t", rtrim( $line, "\n" ), 2 );
				$this->scan_dir( $queue, $list, $prefix, $dir, $exclude );
				$this->state['queue_read'] = $next;
				if ( microtime( true ) - $last_commit > self::COMMIT_SECONDS ) {
					$this->commit_scan( $queue, $list );
					$last_commit = microtime( true );
				}
			}
			$this->commit_scan( $queue, $list );
		} finally {
			fclose( $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	private function scan_dir( $queue, $list, $prefix, $dir, array $exclude ) {
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $names ) {
			$this->warn( sprintf( __( 'Složku nelze přečíst: %s', 'site-snapshot' ), $prefix ) );
			return;
		}
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			$zip  = $prefix . '/' . $name;
			if ( false !== strpbrk( $name, "\t\r\n" ) ) {
				$this->warn( sprintf( __( 'Přeskočeno (neplatný název): %s', 'site-snapshot' ), str_replace( array( "\t", "\r", "\n" ), '?', $zip ) ) );
				continue;
			}
			if ( is_dir( $path ) ) {
				// Symlinked dirs are skipped (loops, content outside the site).
				if ( is_link( $path ) || Storage::is_storage_dir( $path ) || in_array( $path, $exclude, true ) ) {
					continue;
				}
				self::write_all( $queue, $zip . "\t" . $path . "\n" );
			} elseif ( is_file( $path ) ) {
				$this->add_to_list( $list, $zip, $path );
			}
		}
	}

	private function add_to_list( $list, $zip, $path ) {
		if ( ! is_readable( $path ) ) {
			$this->warn( sprintf( __( 'Přeskočeno (nelze číst): %s', 'site-snapshot' ), $zip ) );
			return;
		}
		self::write_all( $list, $zip . "\t" . $path . "\n" );
		++$this->state['files_total'];
		$this->state['bytes_total'] += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private function commit_scan( $queue, $list ) {
		fflush( $queue );
		fflush( $list );
		fseek( $queue, 0, SEEK_END );
		$this->state['queue_size'] = (int) ftell( $queue );
		$this->state['list_size']  = (int) ftell( $list );
		$this->save();
	}

	private function step_db( $deadline ) {
		global $wpdb;
		$sql_path = $this->dir . '/database.sql';

		if ( empty( $this->state['tables'] ) && 0 === $this->state['sql_size'] ) {
			$this->state['tables'] = Db_Dumper::tables( $this->state['options']['all_tables'] );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'SHOW TABLES: ' . $wpdb->last_error );
			}
			file_put_contents( $sql_path, Db_Dumper::header() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->state['sql_size'] = (int) filesize( $sql_path );
			$this->save();
		}

		Db_Dumper::prepare_session();
		$fh          = self::open_truncated( $sql_path, $this->state['sql_size'] ); // Drop anything written after the last commit.
		$last_commit = microtime( true );

		try {
			while ( microtime( true ) < $deadline ) {
				$index = $this->state['table_index'];
				if ( $index >= count( $this->state['tables'] ) ) {
					self::write_all( $fh, Db_Dumper::footer() );
					$this->commit_sql( $fh );
					$this->state['phase'] = $this->state['options']['files'] ? 'files' : 'finalize';
					break;
				}
				$table = $this->state['tables'][ $index ];

				if ( null === $this->state['table_cursor'] ) {
					self::write_all( $fh, Db_Dumper::structure( $table['name'], $table['type'] ) );
					if ( 'VIEW' === $table['type'] ) {
						$this->next_table();
						continue;
					}
					$this->state['table_cursor'] = array();
				}

				$chunk = Db_Dumper::rows( $table['name'], $this->state['table_cursor'] );
				if ( '' !== $chunk['error'] ) {
					$this->warn( sprintf( __( 'Tabulka %1$s: %2$s', 'site-snapshot' ), $table['name'], $chunk['error'] ) );
					self::write_all( $fh, "\n" );
					$this->next_table();
					continue;
				}
				self::write_all( $fh, $chunk['sql'] );
				$this->state['rows_done'] += $chunk['rows'];
				if ( $chunk['done'] ) {
					self::write_all( $fh, "\n" );
					$this->next_table();
				} else {
					$this->state['table_cursor'] = $chunk['cursor'];
				}
				if ( microtime( true ) - $last_commit > self::COMMIT_SECONDS ) {
					$this->commit_sql( $fh );
					$last_commit = microtime( true );
				}
			}
			$this->commit_sql( $fh );
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	private function next_table() {
		++$this->state['table_index'];
		$this->state['table_cursor'] = null;
	}

	private function commit_sql( $fh ) {
		fflush( $fh );
		$this->state['sql_size'] = (int) ftell( $fh );
		$this->save();
	}

	private function step_files( $deadline ) {
		$list = fopen( $this->dir . '/files.txt', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $list ) {
			throw new \RuntimeException( __( 'Chybí seznam souborů.', 'site-snapshot' ) );
		}
		$zip = $this->zip_writer();
		fseek( $list, $this->state['list_offset'] );
		$last_commit = microtime( true );

		try {
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $list );
				if ( false === $line || $this->state['list_offset'] >= $this->state['list_size'] ) {
					$this->state['phase'] = 'finalize';
					break;
				}
				list( $name, $path ) = explode( "\t", rtrim( $line, "\n" ), 2 );
				try {
					$this->state['bytes_done'] += $zip->add_file( $path, $name );
				} catch ( Zip_Read_Error $e ) {
					// File vanished or became unreadable since the scan – note it and go on.
					$this->warn( $e->getMessage() );
				}
				++$this->state['files_done'];
				$this->state['list_offset'] = (int) ftell( $list );

				if ( microtime( true ) - $last_commit > self::COMMIT_SECONDS ) {
					$this->state['zip'] = $zip->position();
					$this->save();
					$last_commit = microtime( true );
					if ( $this->cancel_requested() ) {
						break;
					}
				}
			}
			$this->state['zip'] = $zip->position();
			$this->save();
		} finally {
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$zip->close();
		}
	}

	private function step_finalize() {
		$zip = $this->zip_writer();
		try {
			if ( $this->state['options']['db'] ) {
				// Only the committed part of the dump is valid.
				$sql = fopen( $this->dir . '/database.sql', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $sql ) {
					ftruncate( $sql, $this->state['sql_size'] );
					fclose( $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				$zip->add_file( $this->dir . '/database.sql', 'database.sql', self::SQL_DEFLATE_MAX );
			}
			$info = Report::build( $this );
			$zip->add_string( 'SITE-INFO.txt', $info['text'] );
			$zip->add_string( 'site-info.json', $info['json'] );
			$zip->add_string( 'OBNOVA-README.txt', Report::restore_readme( $this->state['options'], (array) $this->state['sources'] ) );
			$this->state['zip_size'] = $zip->finish();
		} finally {
			$zip->close();
		}

		foreach ( array( 'files.txt', 'queue.txt', 'database.sql', 'central.bin' ) as $tmp ) {
			if ( file_exists( $this->dir . '/' . $tmp ) ) {
				@unlink( $this->dir . '/' . $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		$this->state['status']   = 'done';
		$this->state['phase']    = 'done';
		$this->state['finished'] = time();
		self::release_active( $this->state['id'] );
		Activity_Log::add(
			'backup_completed',
			sprintf( '%s – %s, %d souborů', $this->state['id'], size_format( $this->state['zip_size'], 1 ), $this->state['files_done'] )
		);
	}

	private function zip_writer() {
		$z = $this->state['zip'];
		return new Zip_Writer( $this->archive_path(), $this->dir . '/central.bin', $z['archive'], $z['central'], $z['entries'] );
	}

	private function fail( $message ) {
		$this->state['status'] = 'failed';
		$this->state['error']  = $message;
		self::release_active( $this->state['id'] );
		Activity_Log::add( 'backup_failed', $this->state['id'] . ' – ' . $message );
	}

	private function warn( $message ) {
		if ( count( $this->state['warnings'] ) < self::MAX_WARNINGS ) {
			$this->state['warnings'][] = $message;
		}
	}

	private function save() {
		if ( ! is_dir( $this->dir ) ) {
			throw new \RuntimeException( __( 'Pracovní složka zálohy zmizela.', 'site-snapshot' ) );
		}
		$this->state['updated'] = time();
		$tmp                    = $this->dir . '/job.json.tmp';
		if ( false === @file_put_contents( $tmp, wp_json_encode( $this->state ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( __( 'Nelze uložit stav zálohy (plný disk?).', 'site-snapshot' ) );
		}
		rename( $tmp, $this->dir . '/job.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Opens a work file for appending after cutting it back to the committed size.
	 *
	 * @return resource
	 */
	private static function open_truncated( $path, $size ) {
		$fh = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( sprintf( __( 'Nelze zapisovat do %s.', 'site-snapshot' ), basename( $path ) ) );
		}
		ftruncate( $fh, (int) $size );
		fseek( $fh, 0, SEEK_END );
		return $fh;
	}

	private static function write_all( $fh, $data ) {
		if ( '' !== $data && fwrite( $fh, $data ) !== strlen( $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new \RuntimeException( __( 'Zápis selhal (plný disk?).', 'site-snapshot' ) );
		}
	}

	/**
	 * Cache and other backup plugins' storage – regenerable or redundant.
	 *
	 * @return string[]
	 */
	public static function cache_dirs() {
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$dirs    = array(
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/updraft',
			$content . '/ai1wm-backups',
			$content . '/backups-dup-lite',
			$content . '/backups-dup-pro',
			$content . '/wpvividbackups',
			$content . '/et-cache',
			$content . '/litespeed',
		);
		$uploads = wp_upload_dir( null, false );
		foreach ( (array) glob( wp_normalize_path( $uploads['basedir'] ) . '/backwpup*', GLOB_ONLYDIR ) as $dir ) {
			$dirs[] = wp_normalize_path( $dir );
		}
		$dirs = (array) apply_filters( 'sitesnap_excluded_dirs', $dirs );
		// Scanned paths are realpath()-based – compare like with like.
		return array_map(
			static function ( $dir ) {
				$real = realpath( $dir );
				return wp_normalize_path( false !== $real ? $real : $dir );
			},
			$dirs
		);
	}
}
