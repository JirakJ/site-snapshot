<?php
/**
 * Append-only streaming ZIP writer (ZIP64-capable).
 *
 * Why not ZipArchive: libzip rewrites the whole archive on every close(), so
 * building a multi-GB backup across many short HTTP requests would be
 * quadratic. This writer appends entries to the archive and their central
 * directory records to a side file; both can be truncated back to the last
 * committed size when a request dies mid-file, and the central directory is
 * written once at the end.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * A source file could not be read – the entry was rolled back, the archive is intact.
 */
final class Zip_Read_Error extends \RuntimeException {}

final class Zip_Writer {

	const DEFLATE_MAX_BYTES = 52428800; // Larger files are stored – keeps each request short.
	const CHUNK             = 1048576;
	const U32               = 0xFFFFFFFF;

	/** Extensions that are already compressed – deflating them is wasted CPU. */
	const STORED_EXTENSIONS = array( 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'm4a', 'ogg', 'oga', 'flac', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'jar', 'wpress' );

	/** @var resource */
	private $archive;
	/** @var resource */
	private $central;
	/** @var int */
	private $entries;

	/**
	 * @param string $archive_path Archive file (created when missing).
	 * @param string $central_path Side file with central directory records.
	 * @param int    $archive_size Committed archive size – anything after it is discarded.
	 * @param int    $central_size Committed central directory size.
	 * @param int    $entries      Committed number of entries.
	 * @throws \RuntimeException When the files cannot be opened.
	 */
	public function __construct( $archive_path, $central_path, $archive_size = 0, $central_size = 0, $entries = 0 ) {
		$this->archive = self::open( $archive_path, (int) $archive_size );
		$this->central = self::open( $central_path, (int) $central_size );
		$this->entries = (int) $entries;
	}

	private static function open( $path, $size ) {
		$fh = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( sprintf( 'Nelze otevřít %s pro zápis.', $path ) );
		}
		ftruncate( $fh, $size );
		fseek( $fh, 0, SEEK_END );
		return $fh;
	}

	/**
	 * Committed sizes to persist between requests.
	 *
	 * @return array{archive: int, central: int, entries: int}
	 */
	public function position() {
		fflush( $this->archive );
		fflush( $this->central );
		return array(
			'archive' => (int) ftell( $this->archive ),
			'central' => (int) ftell( $this->central ),
			'entries' => $this->entries,
		);
	}

	/**
	 * Adds a file from disk. Returns the number of source bytes read.
	 *
	 * @throws \RuntimeException On read/write failure.
	 */
	public function add_file( $source, $name, $deflate_max = self::DEFLATE_MAX_BYTES ) {
		clearstatcache( true, $source );
		$size = @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$in   = @fopen( $source, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $size || ! $in ) {
			throw new Zip_Read_Error( 'Soubor nelze přečíst: ' . $name );
		}
		$ext     = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$deflate = function_exists( 'deflate_init' ) && $size > 0 && $size <= $deflate_max && ! in_array( $ext, self::STORED_EXTENSIONS, true );
		$mtime   = (int) @filemtime( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mode    = @fileperms( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$zip64  = $size >= self::U32 - self::CHUNK; // Stored, so compressed size == size.
		$offset = $this->begin_entry( $name, $deflate ? 8 : 0, $mtime, $zip64 );

		$crc  = hash_init( 'crc32b' );
		$ctx  = $deflate ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		$read = 0;
		$out  = 0;
		while ( $read < $size && ! feof( $in ) ) {
			$chunk = fread( $in, (int) min( self::CHUNK, $size - $read ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				ftruncate( $this->archive, $offset ); // Roll back the half-written entry.
				fseek( $this->archive, $offset );
				throw new Zip_Read_Error( 'Chyba čtení: ' . $name );
			}
			if ( '' === $chunk ) {
				break;
			}
			$read += strlen( $chunk );
			hash_update( $crc, $chunk );
			$out += $this->write( $this->archive, $ctx ? deflate_add( $ctx, $chunk, ZLIB_NO_FLUSH ) : $chunk );
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $ctx ) {
			$out += $this->write( $this->archive, deflate_add( $ctx, '', ZLIB_FINISH ) );
		}

		$this->end_entry( $offset, $name, $deflate ? 8 : 0, $mtime, $zip64, self::crc_value( $crc ), $out, $read, false !== $mode ? $mode : 0100644 );
		return $read;
	}

	/**
	 * Adds an in-memory string (info files).
	 */
	public function add_string( $name, $data ) {
		$deflate = function_exists( 'gzdeflate' ) && strlen( $data ) > 0;
		$body    = $deflate ? gzdeflate( $data, 6 ) : $data;
		$mtime   = time();
		$offset  = $this->begin_entry( $name, $deflate ? 8 : 0, $mtime, false );
		$this->write( $this->archive, $body );
		$crc = hash_init( 'crc32b' );
		hash_update( $crc, $data );
		$this->end_entry( $offset, $name, $deflate ? 8 : 0, $mtime, false, self::crc_value( $crc ), strlen( $body ), strlen( $data ), 0100644 );
	}

	/**
	 * Writes the central directory and end records. The side file is consumed.
	 */
	public function finish() {
		$cd_offset = (int) ftell( $this->archive );
		rewind( $this->central );
		$cd_size = (int) stream_copy_to_stream( $this->central, $this->archive );
		fseek( $this->central, 0, SEEK_END );

		$need64 = $this->entries >= 0xFFFF || $cd_size >= self::U32 || $cd_offset >= self::U32;
		if ( $need64 ) {
			$eocd64 = (int) ftell( $this->archive );
			$this->write(
				$this->archive,
				pack( 'VPvvVVPPPP', 0x06064b50, 44, ( 3 << 8 ) | 45, 45, 0, 0, $this->entries, $this->entries, $cd_size, $cd_offset )
				. pack( 'VVPV', 0x07064b50, 0, $eocd64, 1 )
			);
		}
		$this->write(
			$this->archive,
			pack(
				'VvvvvVVv',
				0x06054b50,
				0,
				0,
				min( $this->entries, 0xFFFF ),
				min( $this->entries, 0xFFFF ),
				min( $cd_size, self::U32 ),
				min( $cd_offset, self::U32 ),
				0
			)
		);
		fflush( $this->archive );
		return (int) ftell( $this->archive );
	}

	public function close() {
		if ( is_resource( $this->archive ) ) {
			fclose( $this->archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( is_resource( $this->central ) ) {
			fclose( $this->central ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	private function begin_entry( $name, $method, $mtime, $zip64 ) {
		$offset = (int) ftell( $this->archive );
		$extra  = $zip64 ? pack( 'vvPP', 0x0001, 16, 0, 0 ) : '';
		list( $dos_time, $dos_date ) = self::dos_time( $mtime );
		$this->write(
			$this->archive,
			pack( 'VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, $method, $dos_time, $dos_date, 0, $zip64 ? self::U32 : 0, $zip64 ? self::U32 : 0, strlen( $name ), strlen( $extra ) )
			. $name . $extra
		);
		return $offset;
	}

	private function end_entry( $offset, $name, $method, $mtime, $zip64, $crc, $csize, $usize, $mode ) {
		// Patch CRC and sizes into the local header.
		$end = (int) ftell( $this->archive );
		fseek( $this->archive, $offset + 14 );
		if ( $zip64 ) {
			fwrite( $this->archive, pack( 'V', $crc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fseek( $this->archive, $offset + 30 + strlen( $name ) + 4 );
			fwrite( $this->archive, pack( 'PP', $usize, $csize ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		} else {
			fwrite( $this->archive, pack( 'VVV', $crc, $csize, $usize ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fseek( $this->archive, $end );

		$big   = $zip64 || $csize >= self::U32 || $usize >= self::U32 || $offset >= self::U32;
		$extra = $big ? pack( 'vvPPP', 0x0001, 24, $usize, $csize, $offset ) : '';
		list( $dos_time, $dos_date ) = self::dos_time( $mtime );
		$this->write(
			$this->central,
			pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,
				( 3 << 8 ) | 45, // Made by: UNIX, spec 4.5.
				$big ? 45 : 20,
				0x0800, // UTF-8 names.
				$method,
				$dos_time,
				$dos_date,
				$crc,
				$big ? self::U32 : $csize,
				$big ? self::U32 : $usize,
				strlen( $name ),
				strlen( $extra ),
				0,
				0,
				0,
				( ( $mode & 0xFFFF ) << 16 ) & 0xFFFFFFFF,
				$big ? self::U32 : $offset
			) . $name . $extra
		);
		++$this->entries;
	}

	private function write( $fh, $data ) {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return 0;
		}
		$written = fwrite( $fh, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( $written !== $len ) {
			throw new \RuntimeException( 'Zápis do archivu selhal (plný disk?).' );
		}
		return $len;
	}

	private static function crc_value( $ctx ) {
		$v = unpack( 'N', hash_final( $ctx, true ) );
		return $v[1];
	}

	private static function dos_time( $timestamp ) {
		$t = getdate( $timestamp > 315532800 ? $timestamp : 315532800 ); // DOS epoch starts 1980.
		return array(
			( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | intdiv( $t['seconds'], 2 ),
			( ( $t['year'] - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'],
		);
	}
}
