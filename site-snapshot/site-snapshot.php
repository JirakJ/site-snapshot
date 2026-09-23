<?php
/**
 * Plugin Name:       Site Snapshot
 * Description:       Prohlížeč souborů webu (jako FTP klient) a kompletní záloha souborů + databáze do jednoho ZIPu – rychlý backup před úpravami webu. Včetně přehledu přístupových údajů a verzí PHP / databáze.
 * Version:           0.2.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Jakub Jirák
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-snapshot
 *
 * @package SiteSnapshot
 */

defined( 'ABSPATH' ) || exit;

define( 'SITESNAP_VERSION', '0.2.1' );
define( 'SITESNAP_FILE', __FILE__ );
define( 'SITESNAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITESNAP_URL', plugin_dir_url( __FILE__ ) );

require_once SITESNAP_DIR . 'includes/class-storage.php';
require_once SITESNAP_DIR . 'includes/class-activity-log.php';
require_once SITESNAP_DIR . 'includes/class-system-info.php';
require_once SITESNAP_DIR . 'includes/class-credentials.php';
require_once SITESNAP_DIR . 'includes/class-file-browser.php';
require_once SITESNAP_DIR . 'includes/class-db-dumper.php';
require_once SITESNAP_DIR . 'includes/class-zip-writer.php';
require_once SITESNAP_DIR . 'includes/class-report.php';
require_once SITESNAP_DIR . 'includes/class-backup-job.php';
require_once SITESNAP_DIR . 'includes/class-downloader.php';
require_once SITESNAP_DIR . 'includes/class-admin.php';
require_once SITESNAP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'SiteSnapshot\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SiteSnapshot\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SiteSnapshot\\Plugin', 'instance' ) );
