<?php
/**
 * Admin screen: Nástroje → Site Snapshot (backup, files, system & access, log).
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'site-snapshot';

	/** @var string */
	private $hook = '';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_sitesnap_save_access', array( $this, 'save_access' ) );
		add_action( 'admin_post_sitesnap_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_sitesnap_exposure', array( $this, 'ajax_exposure' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITESNAP_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		if ( ! Plugin::current_user_allowed() ) {
			return;
		}
		$this->hook = (string) add_management_page(
			__( 'Site Snapshot', 'site-snapshot' ),
			__( 'Site Snapshot', 'site-snapshot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Otevřít', 'site-snapshot' ) . '</a>' );
		return $links;
	}

	public static function url( $tab = 'backup', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'tools.php' ) );
	}

	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'sitesnap-admin', SITESNAP_URL . 'assets/admin.css', array(), SITESNAP_VERSION );
		wp_enqueue_script( 'sitesnap-admin', SITESNAP_URL . 'assets/admin.js', array(), SITESNAP_VERSION, true );
		wp_localize_script(
			'sitesnap-admin',
			'SiteSnap',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sitesnap' ),
				'i18n'    => array(
					'starting'      => __( 'Spouštím zálohu…', 'site-snapshot' ),
					'confirmCancel' => __( 'Opravdu zrušit běžící zálohu?', 'site-snapshot' ),
					'confirmDelete' => __( 'Smazat tuto zálohu ze serveru?', 'site-snapshot' ),
					'networkError'  => __( 'Chyba spojení, zkouším znovu…', 'site-snapshot' ),
					'failed'        => __( 'Záloha selhala.', 'site-snapshot' ),
					'show'          => __( 'Zobrazit', 'site-snapshot' ),
					'hide'          => __( 'Skrýt', 'site-snapshot' ),
					'copied'        => __( 'Zkopírováno', 'site-snapshot' ),
					'leaveWarning'  => __( 'Záloha ještě běží. Opuštěním stránky ji pozastavíte.', 'site-snapshot' ),
				),
			)
		);
	}

	public function render() {
		if ( ! Plugin::current_user_allowed() ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'site-snapshot' ), 403 );
		}
		$tabs = array(
			'backup' => __( 'Záloha', 'site-snapshot' ),
			'files'  => __( 'Soubory', 'site-snapshot' ),
			'system' => __( 'Systém a přístupy', 'site-snapshot' ),
			'log'    => __( 'Historie', 'site-snapshot' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backup'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'backup';
		?>
		<div class="wrap sitesnap">
			<h1><?php esc_html_e( 'Site Snapshot', 'site-snapshot' ); ?> <span class="sitesnap-version"><?php echo esc_html( SITESNAP_VERSION ); ?></span></h1>
			<?php $this->notices(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="sitesnap-body">
				<?php
				switch ( $tab ) {
					case 'files':
						$this->render_files();
						break;
					case 'system':
						$this->render_system();
						break;
					case 'log':
						$this->render_log();
						break;
					default:
						$this->render_backup();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$messages = array(
			'saved'   => __( 'Přístupové údaje uloženy (šifrovaně).', 'site-snapshot' ),
			'cleared' => __( 'Historie vymazána.', 'site-snapshot' ),
		);
		$key = isset( $_GET['sitesnap_msg'] ) ? sanitize_key( wp_unslash( $_GET['sitesnap_msg'] ) ) : '';
		// phpcs:enable
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */

	private function render_backup() {
		$running = Backup_Job::running();
		$jobs    = Backup_Job::all();
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( Storage::dir() ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		?>
		<div class="sitesnap-card">
			<h2><?php esc_html_e( 'Nová záloha', 'site-snapshot' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Vytvoří jeden ZIP se všemi soubory webu, exportem databáze a přehledem verzí a přístupových údajů. Ideální těsně před aktualizací nebo úpravou webu.', 'site-snapshot' ); ?></p>
			<form id="sitesnap-start" class="sitesnap-options">
				<label><input type="checkbox" name="files" value="1" checked> <?php esc_html_e( 'Soubory webu', 'site-snapshot' ); ?> <code><?php echo esc_html( File_Browser::root() ); ?></code></label>
				<label><input type="checkbox" name="db" value="1" checked> <?php esc_html_e( 'Databáze', 'site-snapshot' ); ?></label>
				<label class="sitesnap-sub"><input type="checkbox" name="all_tables" value="1"> <?php esc_html_e( 'Všechny tabulky v databázi (i bez prefixu tohoto webu)', 'site-snapshot' ); ?></label>
				<label><input type="checkbox" name="exclude_cache" value="1" checked> <?php esc_html_e( 'Vynechat cache a zálohy jiných pluginů (wp-content/cache, updraft, ai1wm-backups…)', 'site-snapshot' ); ?></label>
				<p>
					<button type="submit" class="button button-primary button-hero" <?php disabled( null !== $running ); ?>><?php esc_html_e( 'Vytvořit zálohu', 'site-snapshot' ); ?></button>
				</p>
				<?php if ( false !== $free ) : ?>
					<p class="description">
						<?php
						/* translators: %s: free disk space */
						echo esc_html( sprintf( __( 'Volné místo na disku: %s. Záloha se dočasně ukládá na server – po stažení ji smažte.', 'site-snapshot' ), size_format( $free, 1 ) ) );
						?>
					</p>
				<?php endif; ?>
			</form>

			<div id="sitesnap-progress" class="sitesnap-progress" hidden data-running="<?php echo esc_attr( $running ? $running->get( 'id' ) : '' ); ?>">
				<div class="sitesnap-bar"><span style="width:0%"></span></div>
				<p class="sitesnap-status" aria-live="polite"></p>
				<p><button type="button" class="button" id="sitesnap-cancel"><?php esc_html_e( 'Zrušit zálohu', 'site-snapshot' ); ?></button></p>
			</div>
		</div>

		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'Záloha obsahuje citlivé údaje', 'site-snapshot' ); ?></strong> – <?php esc_html_e( 'wp-config.php, hesla k databázi a zadané FTP/hostingové přístupy. Uchovávejte ji v bezpečí a po stažení ji ze serveru smažte.', 'site-snapshot' ); ?></p>
		</div>

		<div id="sitesnap-exposure" data-status="<?php echo esc_attr( (string) get_transient( Storage::TRANSIENT_EXPOSURE ) ); ?>">
			<?php self::render_exposure( (string) get_transient( Storage::TRANSIENT_EXPOSURE ) ); ?>
		</div>

		<h2><?php esc_html_e( 'Zálohy na serveru', 'site-snapshot' ); ?></h2>
		<table class="widefat striped sitesnap-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Vytvořeno', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Kdo', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Obsah', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Velikost', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Stav', 'site-snapshot' ); ?></th>
					<th class="sitesnap-actions"><?php esc_html_e( 'Akce', 'site-snapshot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $jobs ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Zatím žádné zálohy.', 'site-snapshot' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $jobs as $job ) : ?>
				<?php
				$options  = (array) $job->get( 'options' );
				$contents = array();
				if ( ! empty( $options['files'] ) ) {
					/* translators: %s: number of files */
					$contents[] = sprintf( __( 'soubory (%s)', 'site-snapshot' ), number_format_i18n( (int) $job->get( 'files_done' ) ) );
				}
				if ( ! empty( $options['db'] ) ) {
					/* translators: %s: number of tables */
					$contents[] = sprintf( __( 'databáze (%s tabulek)', 'site-snapshot' ), number_format_i18n( count( (array) $job->get( 'tables' ) ) ) );
				}
				$status   = (string) $job->get( 'status' );
				$warnings = (array) $job->get( 'warnings' );
				?>
				<tr data-id="<?php echo esc_attr( $job->get( 'id' ) ); ?>">
					<td><?php echo esc_html( wp_date( 'j. n. Y H:i', (int) $job->get( 'created' ) ) ); ?></td>
					<td><?php echo esc_html( (string) $job->get( 'user' ) ); ?></td>
					<td><?php echo esc_html( implode( ' + ', $contents ) ); ?></td>
					<td><?php echo 'done' === $status ? esc_html( size_format( (int) $job->get( 'zip_size' ), 1 ) ) : '—'; ?></td>
					<td>
						<?php echo esc_html( $this->status_label( $job ) ); ?>
						<?php if ( 'failed' === $status ) : ?>
							<br><small class="sitesnap-error"><?php echo esc_html( (string) $job->get( 'error' ) ); ?></small>
						<?php endif; ?>
						<?php if ( $warnings ) : ?>
							<details class="sitesnap-warnings">
								<summary>
									<?php
									/* translators: %d: number of warnings */
									echo esc_html( sprintf( _n( '%d upozornění', '%d upozornění', count( $warnings ), 'site-snapshot' ), count( $warnings ) ) );
									?>
								</summary>
								<ul><?php foreach ( $warnings as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul>
							</details>
						<?php endif; ?>
					</td>
					<td class="sitesnap-actions">
						<?php if ( 'done' === $status ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( Downloader::url( 'sitesnap_download_backup', array( 'id' => $job->get( 'id' ) ) ) ); ?>"><?php esc_html_e( 'Stáhnout ZIP', 'site-snapshot' ); ?></a>
						<?php endif; ?>
						<?php if ( 'running' === $status && $job->is_stale() && null === $running ) : ?>
							<button type="button" class="button button-primary sitesnap-resume" data-id="<?php echo esc_attr( $job->get( 'id' ) ); ?>"><?php esc_html_e( 'Pokračovat', 'site-snapshot' ); ?></button>
						<?php endif; ?>
						<?php if ( 'running' !== $status || $job->is_stale() ) : ?>
							<button type="button" class="button sitesnap-delete" data-id="<?php echo esc_attr( $job->get( 'id' ) ); ?>"><?php esc_html_e( 'Smazat', 'site-snapshot' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Result of the storage self-test (Storage::exposure()). An empty status
	 * renders nothing – the browser then runs the test via AJAX.
	 */
	public static function render_exposure( $status ) {
		if ( 'exposed' === $status ) {
			?>
			<div class="notice notice-error inline sitesnap-nginx">
				<p><strong><?php esc_html_e( 'Zálohy jsou z internetu přímo dostupné!', 'site-snapshot' ); ?></strong>
				<?php esc_html_e( 'Test stáhl kontrolní soubor ze složky záloh bez přihlášení – webový server ignoruje ochranný .htaccess (typicky nginx, nebo nginx před Apachem). Dokud to neopravíte, zálohy po stažení ihned mažte.', 'site-snapshot' ); ?></p>
				<p><?php esc_html_e( 'Oprava pro nginx – vložte do bloku server { … } webu (nebo pošlete hostingu) a nginx znovu načtěte:', 'site-snapshot' ); ?></p>
				<pre><code><?php echo esc_html( self::nginx_rule() ); ?></code></pre>
				<p><?php esc_html_e( 'Apache: povolte pro web AllowOverride (alespoň AuthConfig/Limit), aby platil .htaccess.', 'site-snapshot' ); ?>
				<button type="button" class="button button-small sitesnap-recheck"><?php esc_html_e( 'Otestovat znovu', 'site-snapshot' ); ?></button></p>
			</div>
			<?php
		} elseif ( 'unknown' === $status ) {
			?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Ochranu složky záloh se nepodařilo otestovat (web nedokáže volat sám sebe). Ověřte ji podle návodu, kapitola 4.', 'site-snapshot' ); ?>
				<button type="button" class="button button-small sitesnap-recheck"><?php esc_html_e( 'Otestovat znovu', 'site-snapshot' ); ?></button></p>
			</div>
			<?php
		}
	}

	public function ajax_exposure() {
		check_ajax_referer( 'sitesnap', 'nonce' );
		if ( ! Plugin::current_user_allowed() ) {
			wp_send_json_error( null, 403 );
		}
		$status = Storage::exposure( ! empty( $_POST['refresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		ob_start();
		self::render_exposure( $status );
		wp_send_json_success(
			array(
				'status' => $status,
				'html'   => ob_get_clean(),
			)
		);
	}

	/**
	 * nginx rule denying the backup storage under this site's uploads URL path.
	 *
	 * A "^~" prefix location wins over every regex location regardless of
	 * order – a plain regex rule would lose to an earlier static-files block
	 * such as `location ~* \.(zip|txt)$`. Multisite needs a regex covering all
	 * subsites and URL aliases, so there the rule must precede other regex blocks.
	 */
	public static function nginx_rule() {
		if ( ! is_multisite() ) {
			$uploads = wp_upload_dir( null, false );
			$path    = untrailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
			return 'location ^~ ' . $path . "/site-snapshot- {\n    deny all;\n    return 404;\n}";
		}
		// Subdirectory subsites expose uploads under several URL paths (/shop/wp-content/… is
		// rewritten to /wp-content/…), so anchor on the unique storage dir name, not on a path.
		return "# Vložte PŘED ostatní bloky \"location ~\" (regexy se vyhodnocují v pořadí).\n"
			. "location ~ \"/site-snapshot-[a-z0-9]{16,}(/|$)\" {\n    deny all;\n    return 404;\n}"; // Quoted: nginx would parse "{" as a block.
	}

	private function status_label( Backup_Job $job ) {
		switch ( $job->get( 'status' ) ) {
			case 'done':
				return __( 'Hotovo', 'site-snapshot' );
			case 'failed':
				return __( 'Selhalo', 'site-snapshot' );
			case 'cancelled':
				return __( 'Zrušeno', 'site-snapshot' );
			default:
				return $job->is_stale() ? __( 'Přerušeno', 'site-snapshot' ) : __( 'Probíhá…', 'site-snapshot' );
		}
	}

	/* ------------------------------------------------------------------ */

	private function render_files() {
		$rel  = isset( $_GET['path'] ) ? (string) wp_unslash( $_GET['path'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by resolve().
		$path = File_Browser::resolve( $rel );
		if ( null === $path || ! is_dir( $path ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Složka neexistuje nebo leží mimo web.', 'site-snapshot' ) . '</p></div>';
			$path = File_Browser::root();
		}
		$rel     = File_Browser::relative( $path );
		$entries = File_Browser::list_dir( $path );
		$parent  = '' === $rel ? null : ( false !== strpos( $rel, '/' ) ? dirname( $rel ) : '' );
		?>
		<div class="sitesnap-toolbar">
			<nav class="sitesnap-breadcrumbs" aria-label="<?php esc_attr_e( 'Cesta', 'site-snapshot' ); ?>">
				<?php foreach ( File_Browser::breadcrumbs( $rel ) as $i => $crumb ) : ?>
					<?php echo $i > 0 ? '<span class="sep">/</span>' : ''; ?>
					<a href="<?php echo esc_url( self::url( 'files', array( 'path' => $crumb[1] ) ) ); ?>"><?php echo esc_html( $crumb[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<a class="button" href="<?php echo esc_url( Downloader::url( 'sitesnap_download_folder', array( 'path' => $rel ) ) ); ?>"><?php esc_html_e( 'Stáhnout tuto složku jako ZIP', 'site-snapshot' ); ?></a>
		</div>
		<p class="description"><?php echo esc_html( $path ); ?> · <?php esc_html_e( 'Pro celý web s databází použijte kartu Záloha – zvládne i velké weby po částech.', 'site-snapshot' ); ?></p>

		<table class="widefat striped sitesnap-table sitesnap-files">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Název', 'site-snapshot' ); ?></th>
					<th class="num"><?php esc_html_e( 'Velikost', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Změněno', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Práva', 'site-snapshot' ); ?></th>
					<th class="sitesnap-actions"><?php esc_html_e( 'Akce', 'site-snapshot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( null !== $parent ) : ?>
				<tr>
					<td colspan="5"><a href="<?php echo esc_url( self::url( 'files', array( 'path' => $parent ) ) ); ?>" class="sitesnap-up">↑ ..</a></td>
				</tr>
			<?php endif; ?>
			<?php if ( ! $entries ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Prázdná složka (nebo ji nelze přečíst).', 'site-snapshot' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $e ) : ?>
				<tr class="<?php echo $e['readable'] ? '' : 'sitesnap-unreadable'; ?>">
					<td class="sitesnap-name">
						<span class="dashicons <?php echo $e['dir'] ? 'dashicons-category' : 'dashicons-media-default'; ?>" aria-hidden="true"></span>
						<?php if ( $e['dir'] && $e['readable'] ) : ?>
							<a href="<?php echo esc_url( self::url( 'files', array( 'path' => $e['rel'] ) ) ); ?>"><?php echo esc_html( $e['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $e['name'] ); ?>
						<?php endif; ?>
						<?php if ( $e['link'] ) : ?>
							<span class="sitesnap-badge"><?php esc_html_e( 'odkaz', 'site-snapshot' ); ?></span>
						<?php endif; ?>
						<?php if ( ! $e['dir'] && File_Browser::is_sensitive( $e['name'] ) ) : ?>
							<span class="sitesnap-badge sitesnap-badge-warn" title="<?php esc_attr_e( 'Obsahuje konfiguraci nebo přístupové údaje', 'site-snapshot' ); ?>"><?php esc_html_e( 'citlivé', 'site-snapshot' ); ?></span>
						<?php endif; ?>
						<?php if ( ! $e['readable'] ) : ?>
							<span class="sitesnap-badge"><?php esc_html_e( 'nelze číst', 'site-snapshot' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="num"><?php echo null === $e['size'] ? '—' : esc_html( size_format( $e['size'], 1 ) ); ?></td>
					<td><?php echo $e['mtime'] ? esc_html( wp_date( 'j. n. Y H:i', $e['mtime'] ) ) : '—'; ?></td>
					<td><code><?php echo esc_html( $e['perms'] ); ?></code></td>
					<td class="sitesnap-actions">
						<?php if ( $e['readable'] ) : ?>
							<?php if ( $e['dir'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Downloader::url( 'sitesnap_download_folder', array( 'path' => $e['rel'] ) ) ); ?>"><?php esc_html_e( 'ZIP', 'site-snapshot' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( Downloader::url( 'sitesnap_download_file', array( 'path' => $e['rel'] ) ) ); ?>"><?php esc_html_e( 'Stáhnout', 'site-snapshot' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private function render_system() {
		// Viewing credentials is audited – at most once per 10 minutes per user.
		$throttle = 'sitesnap_viewed_' . get_current_user_id();
		if ( ! get_transient( $throttle ) ) {
			Activity_Log::add( 'credentials_viewed' );
			set_transient( $throttle, 1, 10 * MINUTE_IN_SECONDS );
		}
		$manual = Credentials::manual();
		?>
		<div class="sitesnap-grid">
			<?php foreach ( System_Info::collect() as $section ) : ?>
				<div class="sitesnap-card">
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
					<table class="widefat striped sitesnap-kv">
						<?php foreach ( $section['rows'] as $label => $value ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endforeach; ?>
		</div>

		<h2 class="sitesnap-h"><?php esc_html_e( 'Přístupové údaje', 'site-snapshot' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Zobrazení této stránky se zapisuje do historie. Stejné údaje se ukládají do souboru SITE-INFO.txt v každé záloze.', 'site-snapshot' ); ?></p>

		<div class="sitesnap-grid">
			<div class="sitesnap-card">
				<h2><?php esc_html_e( 'Databáze a konfigurace (wp-config.php)', 'site-snapshot' ); ?></h2>
				<?php $this->kv_table( Credentials::from_config() ); ?>
				<?php if ( ! defined( 'FTP_HOST' ) ) : ?>
					<p class="description"><?php esc_html_e( 'FTP údaje nejsou ve wp-config.php definované – WordPress je nezná. Doplňte je níže, uloží se šifrovaně a přidají se do každé zálohy.', 'site-snapshot' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="sitesnap-card">
				<h2><?php esc_html_e( 'Administrátoři WordPressu', 'site-snapshot' ); ?></h2>
				<?php $this->kv_table( Credentials::administrators() ); ?>
				<p class="description"><?php esc_html_e( 'Hesla uživatelů WordPress ukládá jen jako hash – nelze je zobrazit.', 'site-snapshot' ); ?></p>
			</div>
		</div>

		<div class="sitesnap-card">
			<h2><?php esc_html_e( 'FTP a hosting (doplňte ručně)', 'site-snapshot' ); ?></h2>
			<?php if ( Credentials::manual_unreadable() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Uložené údaje nejde dešifrovat – pravděpodobně se změnily bezpečnostní klíče (salts) ve wp-config.php. Zadejte je znovu.', 'site-snapshot' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sitesnap-form" autocomplete="off">
				<input type="hidden" name="action" value="sitesnap_save_access">
				<?php wp_nonce_field( 'sitesnap_save_access' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( Credentials::fields() as $key => $meta ) : ?>
						<?php $value = isset( $manual[ $key ] ) ? $manual[ $key ] : ''; ?>
						<tr>
							<th scope="row"><label for="sitesnap-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td>
								<?php if ( 'note' === $key ) : ?>
									<textarea id="sitesnap-<?php echo esc_attr( $key ); ?>" name="access[<?php echo esc_attr( $key ); ?>]" rows="3" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
								<?php elseif ( 'ftp_protocol' === $key ) : ?>
									<select id="sitesnap-<?php echo esc_attr( $key ); ?>" name="access[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( array( '', 'FTP', 'FTPS (explicit TLS)', 'SFTP (SSH)' ) as $opt ) : ?>
											<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $value, $opt ); ?>><?php echo esc_html( '' === $opt ? '—' : $opt ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<span class="sitesnap-secret-input">
										<input id="sitesnap-<?php echo esc_attr( $key ); ?>" name="access[<?php echo esc_attr( $key ); ?>]" type="<?php echo $meta[1] ? 'password' : 'text'; ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="<?php echo $meta[1] ? 'new-password' : 'off'; ?>" spellcheck="false">
										<?php if ( $meta[1] ) : ?>
											<button type="button" class="button sitesnap-toggle-input" data-target="sitesnap-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Zobrazit', 'site-snapshot' ); ?></button>
										<?php endif; ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Uložit přístupy', 'site-snapshot' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{label: string, value: string, secret: bool}> $rows
	 */
	private function kv_table( array $rows ) {
		if ( ! $rows ) {
			echo '<p>—</p>';
			return;
		}
		echo '<table class="widefat striped sitesnap-kv">';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>';
			if ( $row['secret'] && '' !== $row['value'] ) {
				echo '<span class="sitesnap-secret" data-value="' . esc_attr( $row['value'] ) . '"><code class="sitesnap-mask">••••••••</code></span> ';
				echo '<button type="button" class="button button-small sitesnap-reveal">' . esc_html__( 'Zobrazit', 'site-snapshot' ) . '</button> ';
				echo '<button type="button" class="button button-small sitesnap-copy">' . esc_html__( 'Kopírovat', 'site-snapshot' ) . '</button>';
			} else {
				echo '<code>' . esc_html( $row['value'] ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public function save_access() {
		if ( ! Plugin::current_user_allowed() ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'site-snapshot' ), 403 );
		}
		check_admin_referer( 'sitesnap_save_access' );
		$input = isset( $_POST['access'] ) && is_array( $_POST['access'] ) ? wp_unslash( $_POST['access'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in Credentials::save_manual().
		Credentials::save_manual( array_map( 'strval', $input ) );
		Activity_Log::add( 'credentials_saved' );
		wp_safe_redirect( self::url( 'system', array( 'sitesnap_msg' => 'saved' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */

	private function render_log() {
		$log = Activity_Log::all();
		?>
		<p class="description"><?php esc_html_e( 'Kdo a kdy vytvořil, stáhl nebo smazal zálohu, stáhl soubory nebo zobrazil přístupové údaje.', 'site-snapshot' ); ?></p>
		<table class="widefat striped sitesnap-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Čas', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Uživatel', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'IP', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Akce', 'site-snapshot' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'site-snapshot' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $log ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Historie je prázdná.', 'site-snapshot' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $log as $row ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'j. n. Y H:i:s', (int) $row['time'] ) ); ?></td>
					<td><?php echo esc_html( $row['user'] ); ?></td>
					<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
					<td><?php echo esc_html( Activity_Log::label( $row['action'] ) ); ?></td>
					<td><?php echo esc_html( $row['details'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $log ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sitesnap-clear-log">
				<input type="hidden" name="action" value="sitesnap_clear_log">
				<?php wp_nonce_field( 'sitesnap_clear_log' ); ?>
				<?php submit_button( __( 'Vymazat historii', 'site-snapshot' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public function clear_log() {
		if ( ! Plugin::current_user_allowed() ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'site-snapshot' ), 403 );
		}
		check_admin_referer( 'sitesnap_clear_log' );
		Activity_Log::clear();
		Activity_Log::add( 'log_cleared' ); // The clearing itself stays on record.
		wp_safe_redirect( self::url( 'log', array( 'sitesnap_msg' => 'cleared' ) ) );
		exit;
	}
}
