<?php
/**
 * Pure-PHP SQL dump through $wpdb (no mysqldump / exec needed, works on
 * shared hosting). Tables are exported in chunks so a backup job can spread
 * large tables across several short HTTP requests.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Db_Dumper {

	const ROWS_PER_SELECT  = 1000;
	const INSERT_MAX_BYTES = 1048576;

	/** @var array<string, array{columns: string[], select: string[], kinds: string[], order: string}> */
	private static $meta = array();

	/**
	 * Tables of this site (by prefix) or of the whole database. Base tables
	 * first, views last (views depend on tables when restoring).
	 *
	 * @return array<int, array{name: string, type: string}>
	 */
	public static function tables( $all_tables = false ) {
		global $wpdb;
		if ( $all_tables ) {
			$rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_N );
		}
		$tables = array();
		$views  = array();
		foreach ( (array) $rows as $row ) {
			$entry = array(
				'name' => (string) $row[0],
				'type' => isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ? 'VIEW' : 'BASE TABLE',
			);
			if ( 'VIEW' === $entry['type'] ) {
				$views[] = $entry;
			} else {
				$tables[] = $entry;
			}
		}
		return array_merge( $tables, $views );
	}

	public static function header() {
		global $wpdb, $wp_version;
		return '-- Site Snapshot ' . SITESNAP_VERSION . " SQL dump\n"
			. '-- Web: ' . home_url() . "\n"
			. '-- Databáze: ' . DB_NAME . ' @ ' . DB_HOST . "\n"
			. '-- Server: ' . $wpdb->get_var( 'SELECT VERSION()' ) . ', PHP ' . PHP_VERSION . ', WordPress ' . $wp_version . "\n"
			. '-- Vytvořeno: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n"
			. "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
			. "/*!40101 SET NAMES utf8mb4 */;\n"
			. "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
			. "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
			. "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00' */;\n\n";
	}

	public static function footer() {
		return "\n/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
			. "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
			. "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
			. "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
			. '-- Konec dumpu ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
	}

	/**
	 * Ensures the session runs in UTC so TIMESTAMP columns round-trip exactly
	 * with the TIME_ZONE='+00:00' in the dump header.
	 */
	public static function prepare_session() {
		global $wpdb;
		$wpdb->query( "SET SESSION time_zone = '+00:00'" );
	}

	/**
	 * DROP + CREATE statement for a table or view.
	 */
	public static function structure( $table, $type ) {
		global $wpdb;
		$q = self::quote_identifier( $table );
		if ( 'VIEW' === $type ) {
			$row = $wpdb->get_row( "SHOW CREATE VIEW {$q}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return "-- Pohled {$q} nelze exportovat: " . $wpdb->last_error . "\n\n";
			}
			// DEFINER would break the restore under a different DB user.
			$create = preg_replace( '/\sDEFINER=`[^`]*`@`[^`]*`/', '', $row[1] );
			return "--\n-- Pohled {$q}\n--\n\nDROP VIEW IF EXISTS {$q};\n{$create};\n\n";
		}
		$row = $wpdb->get_row( "SHOW CREATE TABLE {$q}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return "-- Tabulku {$q} nelze exportovat: " . $wpdb->last_error . "\n\n";
		}
		return "--\n-- Tabulka {$q}\n--\n\nDROP TABLE IF EXISTS {$q};\n{$row[1]};\n\n";
	}

	/**
	 * One chunk of INSERT statements.
	 *
	 * @return array{sql: string, rows: int, error: string}
	 */
	public static function rows( $table, $offset ) {
		global $wpdb;
		$meta = self::meta( $table );
		if ( empty( $meta['columns'] ) ) {
			return array( 'sql' => '', 'rows' => 0, 'error' => '' );
		}
		$q       = self::quote_identifier( $table );
		$cols    = implode( ', ', array_map( array( __CLASS__, 'quote_identifier' ), $meta['columns'] ) );
		$sql     = sprintf( 'SELECT %s FROM %s%s LIMIT %d, %d', implode( ', ', $meta['select'] ), $q, $meta['order'], (int) $offset, self::ROWS_PER_SELECT );
		$results = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $results || '' !== $wpdb->last_error ) {
			return array( 'sql' => '', 'rows' => 0, 'error' => (string) $wpdb->last_error );
		}
		if ( empty( $results ) ) {
			return array( 'sql' => '', 'rows' => 0, 'error' => '' );
		}

		$prefix = "INSERT INTO {$q} ({$cols}) VALUES\n";
		$out    = '';
		$batch  = array();
		$bytes  = 0;
		foreach ( $results as $row ) {
			$values = array();
			foreach ( $row as $i => $value ) {
				$values[] = self::literal( $value, $meta['kinds'][ $i ] );
			}
			$tuple   = '(' . implode( ',', $values ) . ')';
			$batch[] = $tuple;
			$bytes  += strlen( $tuple );
			if ( $bytes >= self::INSERT_MAX_BYTES ) {
				$out  .= $prefix . implode( ",\n", $batch ) . ";\n";
				$batch = array();
				$bytes = 0;
			}
		}
		if ( $batch ) {
			$out .= $prefix . implode( ",\n", $batch ) . ";\n";
		}
		return array( 'sql' => $out, 'rows' => count( $results ), 'error' => '' );
	}

	/**
	 * Column list (without generated columns), value kinds and a stable ORDER BY.
	 */
	private static function meta( $table ) {
		global $wpdb;
		if ( isset( self::$meta[ $table ] ) ) {
			return self::$meta[ $table ];
		}
		$q       = self::quote_identifier( $table );
		$columns = array();
		$select  = array();
		$kinds   = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$q}", ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( isset( $col['Extra'] ) && false !== stripos( $col['Extra'], 'GENERATED' ) ) {
				continue; // Generated columns cannot be inserted into.
			}
			$type      = strtolower( (string) $col['Type'] );
			$columns[] = (string) $col['Field'];
			$select[]  = self::quote_identifier( $col['Field'] );
			if ( 0 === strpos( $type, 'bit' ) ) {
				// The text protocol returns BIT inconsistently (raw bytes or decimal digits) – export the number.
				$select[ count( $select ) - 1 ] = 'CAST(' . self::quote_identifier( $col['Field'] ) . ' AS UNSIGNED)';
				$kinds[]                        = 'number';
			} elseif ( preg_match( '/blob|binary|geometry|point|linestring|polygon/', $type ) ) {
				$kinds[] = 'binary';
			} elseif ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/', $type ) ) {
				$kinds[] = 'number';
			} else {
				$kinds[] = 'string';
			}
		}
		$pk = array();
		foreach ( (array) $wpdb->get_results( "SHOW KEYS FROM {$q} WHERE Key_name = 'PRIMARY'", ARRAY_A ) as $key ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pk[ (int) $key['Seq_in_index'] ] = self::quote_identifier( $key['Column_name'] );
		}
		ksort( $pk );
		self::$meta[ $table ] = array(
			'columns' => $columns,
			'select'  => $select,
			'kinds'   => $kinds,
			'order'   => $pk ? ' ORDER BY ' . implode( ', ', $pk ) : '',
		);
		return self::$meta[ $table ];
	}

	public static function literal( $value, $kind ) {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		if ( 'binary' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( 'number' === $kind && is_numeric( $value ) ) {
			return $value;
		}
		return "'" . self::escape( $value ) . "'";
	}

	/**
	 * mysql_real_escape_string equivalent. Safe for utf8/utf8mb4 (no multibyte
	 * sequence contains 0x5C or 0x27). Deliberately not esc_sql(): since WP 4.8.3
	 * it replaces "%" with a placeholder hash.
	 */
	public static function escape( $value ) {
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	public static function quote_identifier( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}
}
