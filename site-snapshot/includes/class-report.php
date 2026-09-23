<?php
/**
 * SITE-INFO.txt / site-info.json / OBNOVA-README.txt written into every backup:
 * environment versions and access credentials at the time of the backup.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Report {

	/**
	 * @return array{text: string, json: string}
	 */
	public static function build( Backup_Job $job ) {
		$system = System_Info::collect();
		$access = array(
			'wp_config'      => Credentials::from_config(),
			'administrators' => Credentials::administrators(),
			'manual'         => Credentials::manual_rows(),
		);
		$meta   = array(
			'backup_id'   => $job->get( 'id' ),
			'created'     => wp_date( 'Y-m-d H:i:s T', (int) $job->get( 'created' ) ),
			'created_by'  => $job->get( 'user' ),
			'plugin'      => 'Site Snapshot ' . SITESNAP_VERSION,
			'options'     => $job->get( 'options' ),
			'files'       => (int) $job->get( 'files_done' ),
			'files_bytes' => (int) $job->get( 'bytes_done' ),
			'db_tables'   => count( (array) $job->get( 'tables' ) ),
			'db_rows'     => (int) $job->get( 'rows_done' ),
		);

		$t   = array();
		$t[] = '=====================================================================';
		$t[] = ' SITE SNAPSHOT – ' . home_url();
		$t[] = '=====================================================================';
		$t[] = '';
		$t[] = '!!! Tento soubor obsahuje přístupové údaje. Uchovávejte zálohu v bezpečí. !!!';
		$t[] = '';
		$t[] = '[Záloha]';
		$t[] = self::line( 'ID', $meta['backup_id'] );
		$t[] = self::line( 'Vytvořeno', $meta['created'] );
		$t[] = self::line( 'Vytvořil', $meta['created_by'] );
		$t[] = self::line( 'Soubory', $meta['files'] . ' (' . size_format( $meta['files_bytes'], 1 ) . ')' );
		$t[] = self::line( 'Databáze', $meta['db_tables'] . ' tabulek, ' . $meta['db_rows'] . ' řádků' );
		$t[] = '';
		foreach ( $system as $section ) {
			$t[] = '[' . $section['title'] . ']';
			foreach ( $section['rows'] as $label => $value ) {
				$t[] = self::line( $label, $value );
			}
			$t[] = '';
		}
		$t[] = '[Přístupy – wp-config.php]';
		foreach ( $access['wp_config'] as $row ) {
			$t[] = self::line( $row['label'], $row['value'] );
		}
		$t[] = '';
		$t[] = '[Administrátoři WordPressu (login → e-mail)]';
		foreach ( $access['administrators'] as $row ) {
			$t[] = self::line( $row['label'], $row['value'] );
		}
		$t[] = '';
		$t[] = '[Přístupy – FTP / hosting (zadáno ručně)]';
		if ( $access['manual'] ) {
			foreach ( $access['manual'] as $row ) {
				$t[] = self::line( $row['label'], $row['value'] );
			}
		} else {
			$t[] = '  (nevyplněno)';
		}
		$warnings = (array) $job->get( 'warnings' );
		if ( $warnings ) {
			$t[] = '';
			$t[] = '[Upozornění při zálohování]';
			foreach ( $warnings as $w ) {
				$t[] = '  - ' . $w;
			}
		}
		$t[] = '';

		$json = wp_json_encode(
			array(
				'backup'   => $meta,
				'system'   => $system,
				'access'   => $access,
				'warnings' => $warnings,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return array(
			'text' => implode( "\n", $t ),
			'json' => (string) $json,
		);
	}

	public static function restore_readme( array $options, array $sources = array() ) {
		global $wpdb;
		$lines = array(
			'OBNOVA ZE ZÁLOHY (Site Snapshot)',
			'================================',
			'',
			'Obsah archivu:',
		);
		$extra = array();
		foreach ( $sources as $source ) {
			if ( 'files' !== $source['zip'] ) {
				$extra[] = $source;
			}
		}
		if ( ! empty( $options['files'] ) ) {
			$lines[] = '  files/          – kořen WordPressu (' . File_Browser::root() . ')';
			foreach ( $extra as $source ) {
				$lines[] = sprintf( '  %-15s – leží mimo kořen webu, původně %s', $source['zip'] . ( 'dir' === $source['type'] ? '/' : '' ), $source['path'] );
			}
		}
		if ( ! empty( $options['db'] ) ) {
			$lines[] = '  database.sql    – export databáze (DROP + CREATE + INSERT)';
		}
		$lines[] = '  SITE-INFO.txt   – verze PHP / DB / WordPressu a přístupové údaje v době zálohy';
		$lines[] = '  site-info.json  – totéž strojově čitelné';
		$lines[] = '';
		$lines[] = 'Postup obnovy:';
		$n       = 1;
		if ( ! empty( $options['files'] ) ) {
			$lines[] = ( $n++ ) . '. Nahrajte obsah složky files/ přes FTP do kořene webu (přepíše změněné soubory).';
			if ( $extra ) {
				$lines[] = '   Položky z extra/ vraťte na původní umístění uvedená výše.';
			}
		}
		if ( ! empty( $options['db'] ) ) {
			$lines[] = ( $n++ ) . '. Importujte database.sql do databáze z wp-config.php:';
			$lines[] = '     phpMyAdmin/Adminer → Import, nebo:';
			$lines[] = '     mysql -h HOST -u USER -p ' . DB_NAME . ' < database.sql';
			$lines[] = '   Import přepíše tabulky s prefixem ' . $wpdb->base_prefix . ' (DROP TABLE IF EXISTS).';
		}
		$lines[] = ( $n++ ) . '. Pokud se mění doména, upravte siteurl/home v tabulce ' . $wpdb->base_prefix . 'options';
		$lines[] = '   (a použijte nástroj na search-replace, který umí serializovaná data, např. WP-CLI).';
		$lines[] = ( $n++ ) . '. Po obnově se přihlaste do administrace a uložte Nastavení → Trvalé odkazy.';
		$lines[] = '';
		return implode( "\n", $lines );
	}

	private static function line( $label, $value ) {
		$label .= ':';
		$pad    = max( 1, 30 - ( function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label ) ) );
		return '  ' . $label . str_repeat( ' ', $pad ) . $value;
	}
}
