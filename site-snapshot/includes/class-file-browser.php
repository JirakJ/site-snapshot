<?php
/**
 * Read-only file browser rooted at the WordPress install directory.
 *
 * Every path coming from the request is resolved with realpath() and must stay
 * inside the root – "..", absolute paths and symlinks pointing outside are
 * rejected.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class File_Browser {

	public static function root() {
		$root = realpath( (string) apply_filters( 'sitesnap_root', ABSPATH ) );
		return wp_normalize_path( untrailingslashit( false !== $root ? $root : ABSPATH ) );
	}

	/**
	 * Resolves a root-relative path. Returns the absolute normalized path or null.
	 */
	public static function resolve( $relative ) {
		$root     = self::root();
		$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
		$real     = realpath( '' === $relative ? $root : $root . '/' . $relative );
		if ( false === $real ) {
			return null;
		}
		$real = wp_normalize_path( $real );
		if ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) {
			return null;
		}
		return $real;
	}

	public static function relative( $absolute ) {
		$root = self::root();
		$abs  = wp_normalize_path( $absolute );
		return $abs === $root ? '' : ltrim( substr( $abs, strlen( $root ) ), '/' );
	}

	/**
	 * @return array<int, array{name: string, rel: string, dir: bool, link: bool, size: int|null, mtime: int, perms: string, readable: bool}>
	 */
	public static function list_dir( $absolute ) {
		$entries = array();
		$storage = Storage::dir();
		$names   = @scandir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $names ) {
			return $entries;
		}
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $absolute . '/' . $name;
			if ( wp_normalize_path( $path ) === $storage ) {
				continue; // Never expose our own backup storage in the listing.
			}
			$is_dir    = is_dir( $path );
			$stat      = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$entries[] = array(
				'name'     => $name,
				'rel'      => self::relative( $path ),
				'dir'      => $is_dir,
				'link'     => is_link( $path ),
				'size'     => $is_dir ? null : ( false !== $stat ? (int) @filesize( $path ) : null ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'mtime'    => false !== $stat ? (int) $stat['mtime'] : 0,
				'perms'    => false !== $stat ? substr( sprintf( '%o', $stat['mode'] ), -4 ) : '',
				'readable' => is_readable( $path ),
			);
		}
		usort(
			$entries,
			static function ( $a, $b ) {
				if ( $a['dir'] !== $b['dir'] ) {
					return $a['dir'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $entries;
	}

	/**
	 * Yields every readable file under $absolute (depth-first), skipping the
	 * backup storage, symlinked directories and optional excluded dirs.
	 *
	 * @param string   $absolute Directory to walk.
	 * @param string[] $exclude  Absolute normalized directory paths to skip.
	 * @return \Generator<int, string>
	 */
	public static function walk( $absolute, array $exclude = array() ) {
		$exclude[] = Storage::dir();
		$dir_it    = new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO );
		$filter    = new \RecursiveCallbackFilterIterator(
			$dir_it,
			static function ( \SplFileInfo $item ) use ( $exclude ) {
				if ( $item->isDir() ) {
					if ( $item->isLink() ) {
						return false;
					}
					return ! in_array( wp_normalize_path( $item->getPathname() ), $exclude, true );
				}
				return true;
			}
		);
		$it = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $item ) {
			/** @var \SplFileInfo $item */
			if ( $item->isFile() ) {
				yield wp_normalize_path( $item->getPathname() );
			}
		}
	}

	/**
	 * Breadcrumb trail for a relative path: [ [label, rel], ... ].
	 */
	public static function breadcrumbs( $relative ) {
		$crumbs = array( array( basename( self::root() ) ? basename( self::root() ) : '/', '' ) );
		$acc    = '';
		foreach ( array_filter( explode( '/', $relative ), 'strlen' ) as $part ) {
			$acc      = '' === $acc ? $part : $acc . '/' . $part;
			$crumbs[] = array( $part, $acc );
		}
		return $crumbs;
	}

	/**
	 * Files that hold credentials or are otherwise worth highlighting.
	 */
	public static function is_sensitive( $name ) {
		return in_array( strtolower( $name ), array( 'wp-config.php', '.htaccess', '.env', '.user.ini', 'php.ini', 'web.config', '.htpasswd' ), true );
	}
}
