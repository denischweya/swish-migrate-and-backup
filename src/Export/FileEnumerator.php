<?php
/**
 * File Enumerator - Separate enumeration phase for backup.
 *
 * Creates a filemap file containing all files to backup.
 * This separates directory scanning from archiving for
 * better reliability on shared hosting.
 *
 * @package SwishMigrateAndBackup\Export
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Export;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates files for backup with timeout support.
 */
class FileEnumerator {

	/**
	 * Default exclude patterns.
	 *
	 * @var array
	 */
	private const DEFAULT_EXCLUDES = array(
		'.git',
		'.svn',
		'.hg',
		'node_modules',
		'.DS_Store',
		'Thumbs.db',
		'*.log',
		'*.tmp',
		'*.temp',
		'error_log',
		'debug.log',
		'.htaccess.bak',
	);

	/**
	 * Directories to always exclude.
	 *
	 * @var array
	 */
	private const EXCLUDED_DIRS = array(
		'swish-backups',
		'cache',
		'backup',
		'backups',
		'updraft',
		'ai1wm-backups',
	);

	/**
	 * Filemap file path.
	 *
	 * @var string
	 */
	private string $filemap_path;

	/**
	 * Filemap file handle.
	 *
	 * @var resource|null
	 */
	private $filemap_handle = null;

	/**
	 * Custom exclude patterns.
	 *
	 * @var array
	 */
	private array $exclude_patterns = array();

	/**
	 * Total files found.
	 *
	 * @var int
	 */
	private int $total_files = 0;

	/**
	 * Total size in bytes.
	 *
	 * @var int
	 */
	private int $total_size = 0;

	/**
	 * Constructor.
	 *
	 * @param string $filemap_path Path to store filemap.
	 */
	public function __construct( string $filemap_path ) {
		$this->filemap_path = $filemap_path;
	}

	/**
	 * Set custom exclude patterns.
	 *
	 * @param array $patterns Patterns to exclude.
	 * @return self
	 */
	public function set_exclude_patterns( array $patterns ): self {
		$this->exclude_patterns = $patterns;
		return $this;
	}

	/**
	 * Open filemap for writing.
	 *
	 * @param bool $append Whether to append to existing filemap.
	 * @return bool
	 */
	public function open( bool $append = false ): bool {
		$dir = dirname( $this->filemap_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$mode = $append ? 'ab' : 'wb';
		$this->filemap_handle = fopen( $this->filemap_path, $mode );

		return false !== $this->filemap_handle;
	}

	/**
	 * Close filemap.
	 *
	 * @return bool
	 */
	public function close(): bool {
		if ( $this->filemap_handle ) {
			$result = fclose( $this->filemap_handle );
			$this->filemap_handle = null;
			return $result;
		}
		return true;
	}

	/**
	 * Enumerate directory and write to filemap.
	 *
	 * @param string $directory       Directory to enumerate.
	 * @param string $base_path       Base path for relative paths.
	 * @param int    $dir_offset      Directory offset (for resume).
	 * @param int    $timeout_seconds Max seconds to run.
	 * @return array Result with 'completed', 'dir_offset', 'files_found', 'total_size'.
	 */
	public function enumerate(
		string $directory,
		string $base_path = '',
		int $dir_offset = 0,
		int $timeout_seconds = 10
	): array {
		if ( ! $this->filemap_handle ) {
			if ( ! $this->open( $dir_offset > 0 ) ) {
				return array(
					'completed'   => false,
					'error'       => 'Cannot open filemap for writing',
					'dir_offset'  => $dir_offset,
					'files_found' => 0,
					'total_size'  => 0,
				);
			}
		}

		$start_time = microtime( true );
		$files_found = 0;
		$total_size = 0;
		$current_offset = 0;

		if ( empty( $base_path ) ) {
			$base_path = $directory;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator(
						$directory,
						\RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
					),
					function ( $file, $key, $iterator ) {
						return $this->filter_callback( $file, $iterator );
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD // Skip unreadable directories.
			);

			foreach ( $iterator as $file ) {
				// Skip to resume offset.
				if ( $current_offset < $dir_offset ) {
					++$current_offset;
					continue;
				}

				// Check timeout.
				if ( ( microtime( true ) - $start_time ) > $timeout_seconds ) {
					return array(
						'completed'   => false,
						'timeout'     => true,
						'dir_offset'  => $current_offset,
						'files_found' => $files_found,
						'total_size'  => $total_size,
					);
				}

				if ( $file->isFile() && $file->isReadable() ) {
					$path = $file->getPathname();
					$relative = $this->get_relative_path( $path, $base_path );
					$size = $file->getSize();

					// Write to filemap: path|relative|size.
					$line = $path . '|' . $relative . '|' . $size . "\n";
					fwrite( $this->filemap_handle, $line );

					++$files_found;
					$total_size += $size;
				}

				++$current_offset;
			}
		} catch ( \Exception $e ) {
			return array(
				'completed'   => false,
				'error'       => $e->getMessage(),
				'dir_offset'  => $current_offset,
				'files_found' => $files_found,
				'total_size'  => $total_size,
			);
		}

		$this->total_files = $files_found;
		$this->total_size = $total_size;

		return array(
			'completed'   => true,
			'dir_offset'  => $current_offset,
			'files_found' => $files_found,
			'total_size'  => $total_size,
		);
	}

	/**
	 * Enumerate multiple directories.
	 *
	 * @param array $directories      Directories to enumerate.
	 * @param int   $dir_index        Current directory index (for resume).
	 * @param int   $dir_offset       Offset within current directory.
	 * @param int   $timeout_seconds  Max seconds to run.
	 * @return array Result with enumeration status.
	 */
	public function enumerate_directories(
		array $directories,
		int $dir_index = 0,
		int $dir_offset = 0,
		int $timeout_seconds = 10
	): array {
		$start_time = microtime( true );
		$total_files = 0;
		$total_size = 0;

		for ( $i = $dir_index; $i < count( $directories ); $i++ ) {
			$dir = $directories[ $i ];

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$remaining_time = $timeout_seconds - ( microtime( true ) - $start_time );
			if ( $remaining_time <= 0 ) {
				return array(
					'completed'   => false,
					'timeout'     => true,
					'dir_index'   => $i,
					'dir_offset'  => $dir_offset,
					'files_found' => $total_files,
					'total_size'  => $total_size,
				);
			}

			$result = $this->enumerate(
				$dir,
				$dir,
				$i === $dir_index ? $dir_offset : 0,
				(int) $remaining_time
			);

			$total_files += $result['files_found'];
			$total_size += $result['total_size'];

			if ( ! $result['completed'] ) {
				return array(
					'completed'   => false,
					'timeout'     => $result['timeout'] ?? false,
					'error'       => $result['error'] ?? null,
					'dir_index'   => $i,
					'dir_offset'  => $result['dir_offset'],
					'files_found' => $total_files,
					'total_size'  => $total_size,
				);
			}

			// Reset offset for next directory.
			$dir_offset = 0;
		}

		return array(
			'completed'   => true,
			'dir_index'   => count( $directories ),
			'dir_offset'  => 0,
			'files_found' => $total_files,
			'total_size'  => $total_size,
		);
	}

	/**
	 * Read filemap and return file entries.
	 *
	 * @param int $offset Starting line offset.
	 * @param int $limit  Maximum lines to read (0 = all).
	 * @return array Array of file entries.
	 */
	public function read_filemap( int $offset = 0, int $limit = 0 ): array {
		if ( ! file_exists( $this->filemap_path ) ) {
			return array();
		}

		$handle = fopen( $this->filemap_path, 'rb' );
		if ( ! $handle ) {
			return array();
		}

		$files = array();
		$line_num = 0;
		$read_count = 0;

		while ( ( $line = fgets( $handle ) ) !== false ) {
			if ( $line_num < $offset ) {
				++$line_num;
				continue;
			}

			if ( $limit > 0 && $read_count >= $limit ) {
				break;
			}

			$line = trim( $line );
			if ( ! empty( $line ) ) {
				$parts = explode( '|', $line, 3 );
				if ( count( $parts ) >= 3 ) {
					$files[] = array(
						'path'     => $parts[0],
						'relative' => $parts[1],
						'size'     => (int) $parts[2],
					);
					++$read_count;
				}
			}

			++$line_num;
		}

		fclose( $handle );

		return $files;
	}

	/**
	 * Count lines in filemap.
	 *
	 * @return int
	 */
	public function count_files(): int {
		if ( ! file_exists( $this->filemap_path ) ) {
			return 0;
		}

		$count = 0;
		$handle = fopen( $this->filemap_path, 'rb' );

		if ( $handle ) {
			while ( fgets( $handle ) !== false ) {
				++$count;
			}
			fclose( $handle );
		}

		return $count;
	}

	/**
	 * Get total size from filemap.
	 *
	 * @return int Total size in bytes.
	 */
	public function get_total_size(): int {
		if ( ! file_exists( $this->filemap_path ) ) {
			return 0;
		}

		$total = 0;
		$handle = fopen( $this->filemap_path, 'rb' );

		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$parts = explode( '|', trim( $line ), 3 );
				if ( count( $parts ) >= 3 ) {
					$total += (int) $parts[2];
				}
			}
			fclose( $handle );
		}

		return $total;
	}

	/**
	 * Filter callback for iterator.
	 *
	 * @param \SplFileInfo                       $file     Current file.
	 * @param \RecursiveCallbackFilterIterator $iterator Iterator.
	 * @return bool True to include, false to exclude.
	 */
	private function filter_callback( \SplFileInfo $file, \RecursiveCallbackFilterIterator $iterator ): bool {
		$filename = $file->getFilename();

		// Exclude hidden files (except .htaccess).
		if ( '.' === $filename[0] && '.htaccess' !== $filename ) {
			return false;
		}

		// Exclude directories.
		if ( $file->isDir() ) {
			if ( in_array( $filename, self::EXCLUDED_DIRS, true ) ) {
				return false;
			}
		}

		// Check default excludes.
		foreach ( self::DEFAULT_EXCLUDES as $pattern ) {
			if ( fnmatch( $pattern, $filename ) ) {
				return false;
			}
		}

		// Check custom excludes.
		foreach ( $this->exclude_patterns as $pattern ) {
			if ( fnmatch( $pattern, $filename ) ) {
				return false;
			}
			// Also check full path.
			if ( fnmatch( $pattern, $file->getPathname() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get relative path from base.
	 *
	 * @param string $path      Full path.
	 * @param string $base_path Base path.
	 * @return string Relative path.
	 */
	private function get_relative_path( string $path, string $base_path ): string {
		$path = str_replace( '\\', '/', $path );
		$base_path = str_replace( '\\', '/', rtrim( $base_path, '/' ) );

		if ( strpos( $path, $base_path ) === 0 ) {
			return ltrim( substr( $path, strlen( $base_path ) ), '/' );
		}

		return basename( $path );
	}

	/**
	 * Get filemap path.
	 *
	 * @return string
	 */
	public function get_filemap_path(): string {
		return $this->filemap_path;
	}

	/**
	 * Delete filemap.
	 *
	 * @return bool
	 */
	public function cleanup(): bool {
		$this->close();

		if ( file_exists( $this->filemap_path ) ) {
			return unlink( $this->filemap_path );
		}

		return true;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}
}
