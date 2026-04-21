<?php
/**
 * Swish Archiver - Custom binary format for resumable backups.
 *
 * Format inspired by All-in-One Migration's .wpress format:
 * - Simple binary format with file headers
 * - No central directory (unlike ZIP) - allows true append
 * - Time-based chunking with full resumability
 * - Tracks multiple offsets: archive position, file offset, filemap offset
 *
 * File Format:
 * [Header Block: 4377 bytes]
 *   - filename: 255 bytes (null-padded)
 *   - filesize: 14 bytes (decimal string, null-padded)
 *   - mtime: 12 bytes (decimal string, null-padded)
 *   - filepath: 4096 bytes (null-padded, forward slashes)
 * [File Content: variable]
 * ... repeat for each file ...
 * [EOF Block: 4377 bytes of nulls]
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swish archive format handler for creating and extracting .swish files.
 */
final class SwishArchiver {

	/**
	 * Header block size in bytes.
	 */
	private const HEADER_SIZE = 4377;

	/**
	 * Filename field size.
	 */
	private const FILENAME_SIZE = 255;

	/**
	 * Filesize field size.
	 */
	private const FILESIZE_SIZE = 14;

	/**
	 * Modification time field size.
	 */
	private const MTIME_SIZE = 12;

	/**
	 * Path field size.
	 */
	private const PATH_SIZE = 4096;

	/**
	 * Chunk size for reading/writing file content (512KB).
	 */
	private const CHUNK_SIZE = 524288;

	/**
	 * Default timeout in seconds.
	 */
	private const DEFAULT_TIMEOUT = 10;

	/**
	 * Archive file path.
	 *
	 * @var string
	 */
	private string $archive_path;

	/**
	 * Archive file handle.
	 *
	 * @var resource|null
	 */
	private $file_handle = null;

	/**
	 * EOF block (4377 null bytes).
	 *
	 * @var string
	 */
	private string $eof_block;

	/**
	 * Constructor.
	 *
	 * @param string $archive_path Path to the archive file.
	 */
	public function __construct( string $archive_path ) {
		$this->archive_path = $archive_path;
		$this->eof_block    = pack( 'a' . self::HEADER_SIZE, '' );
	}

	/**
	 * Open archive for writing (append mode).
	 *
	 * @return bool True on success.
	 */
	public function open_for_write(): bool {
		// Open in append binary mode, create if doesn't exist.
		$this->file_handle = @fopen( $this->archive_path, 'ab' );

		if ( false === $this->file_handle ) {
			return false;
		}

		return true;
	}

	/**
	 * Open archive for reading.
	 *
	 * @return bool True on success.
	 */
	public function open_for_read(): bool {
		$this->file_handle = @fopen( $this->archive_path, 'rb' );

		if ( false === $this->file_handle ) {
			return false;
		}

		return true;
	}

	/**
	 * Close the archive.
	 *
	 * @param bool $finalize Whether to write EOF marker.
	 * @return void
	 */
	public function close( bool $finalize = false ): void {
		if ( null === $this->file_handle ) {
			return;
		}

		if ( $finalize ) {
			// Write EOF marker.
			@fwrite( $this->file_handle, $this->eof_block );
		}

		@fclose( $this->file_handle );
		$this->file_handle = null;
	}

	/**
	 * Get current archive position.
	 *
	 * @return int Position in bytes.
	 */
	public function get_position(): int {
		if ( null === $this->file_handle ) {
			return 0;
		}

		$pos = @ftell( $this->file_handle );
		return false === $pos ? 0 : $pos;
	}

	/**
	 * Set archive position.
	 *
	 * @param int $position Position in bytes.
	 * @return bool True on success.
	 */
	public function set_position( int $position ): bool {
		if ( null === $this->file_handle ) {
			return false;
		}

		return @fseek( $this->file_handle, $position, SEEK_SET ) !== -1;
	}

	/**
	 * Add a file to the archive.
	 *
	 * This method supports resuming - if a file was partially written,
	 * pass the file_offset to continue from that position.
	 *
	 * @param string $source_path   Full path to source file.
	 * @param string $archive_path  Path to use inside archive (relative).
	 * @param int    $file_offset   Offset within file to resume from.
	 * @param int    $bytes_written Output: bytes written in this call.
	 * @param int    $timeout       Timeout in seconds (0 = no timeout).
	 * @return bool True if file completely written, false if timed out.
	 */
	public function add_file(
		string $source_path,
		string $archive_path,
		int $file_offset = 0,
		int &$bytes_written = 0,
		int $timeout = self::DEFAULT_TIMEOUT
	): bool {
		$bytes_written = 0;
		$start_time    = microtime( true );

		// Get file info.
		$stat = @stat( $source_path );
		if ( false === $stat ) {
			return true; // Skip missing files.
		}

		$file_size = $stat['size'];
		$mtime     = $stat['mtime'];

		// Open source file.
		$source_handle = @fopen( $source_path, 'rb' );
		if ( false === $source_handle ) {
			return true; // Skip unreadable files.
		}

		// Write header only on first chunk (offset = 0).
		if ( 0 === $file_offset ) {
			$header = $this->create_header( $archive_path, $file_size, $mtime );
			$written = @fwrite( $this->file_handle, $header );

			if ( false === $written || strlen( $header ) !== $written ) {
				@fclose( $source_handle );
				return false;
			}
		}

		// Seek to offset in source file.
		if ( $file_offset > 0 ) {
			@fseek( $source_handle, $file_offset, SEEK_SET );
		}

		// Read and write file content in chunks.
		$completed = true;

		while ( ! @feof( $source_handle ) ) {
			$chunk = @fread( $source_handle, self::CHUNK_SIZE );

			if ( false === $chunk ) {
				break;
			}

			$chunk_len = strlen( $chunk );
			if ( 0 === $chunk_len ) {
				break;
			}

			$written = @fwrite( $this->file_handle, $chunk );

			if ( false === $written || $chunk_len !== $written ) {
				$completed = false;
				break;
			}

			$bytes_written += $written;

			// Check timeout.
			if ( $timeout > 0 ) {
				$elapsed = microtime( true ) - $start_time;
				if ( $elapsed >= $timeout ) {
					$completed = false;
					break;
				}
			}
		}

		@fclose( $source_handle );

		return $completed;
	}

	/**
	 * Add content directly to archive (for generated files like database.sql).
	 *
	 * @param string $archive_path Path to use inside archive.
	 * @param string $content      Content to add.
	 * @return bool True on success.
	 */
	public function add_content( string $archive_path, string $content ): bool {
		$file_size = strlen( $content );
		$mtime     = time();

		// Write header.
		$header = $this->create_header( $archive_path, $file_size, $mtime );
		$written = @fwrite( $this->file_handle, $header );

		if ( false === $written || strlen( $header ) !== $written ) {
			return false;
		}

		// Write content.
		$written = @fwrite( $this->file_handle, $content );

		if ( false === $written || $file_size !== $written ) {
			return false;
		}

		return true;
	}

	/**
	 * Add a file from path to archive (for small files like manifest.json).
	 *
	 * @param string $source_path  Full path to source file.
	 * @param string $archive_path Path to use inside archive.
	 * @return bool True on success.
	 */
	public function add_file_direct( string $source_path, string $archive_path ): bool {
		$content = @file_get_contents( $source_path );

		if ( false === $content ) {
			return false;
		}

		return $this->add_content( $archive_path, $content );
	}

	/**
	 * Create archive from file list with time-based chunking.
	 *
	 * @param string        $file_list_path    Path to file containing list of files.
	 * @param string        $base_path         Base path to strip from paths.
	 * @param int           $filemap_offset    Offset in filemap to resume from.
	 * @param int           $file_offset       Offset within current file.
	 * @param int           $archive_offset    Offset in archive to resume from.
	 * @param callable|null $progress_callback Progress callback(processed, total, current_file).
	 * @param int           $timeout           Timeout in seconds.
	 * @return array{completed: bool, filemap_offset: int, file_offset: int, archive_offset: int, processed: int, total: int}
	 */
	public function create_from_file_list(
		string $file_list_path,
		string $base_path,
		int $filemap_offset = 0,
		int $file_offset = 0,
		int $archive_offset = 0,
		?callable $progress_callback = null,
		int $timeout = self::DEFAULT_TIMEOUT
	): array {
		$start_time = microtime( true );
		$processed  = 0;
		$total      = $this->count_lines( $file_list_path );

		// Open file list.
		$filemap = @fopen( $file_list_path, 'r' );
		if ( false === $filemap ) {
			return array(
				'completed'      => false,
				'error'          => 'Failed to open file list',
				'filemap_offset' => $filemap_offset,
				'file_offset'    => $file_offset,
				'archive_offset' => $archive_offset,
				'processed'      => 0,
				'total'          => $total,
			);
		}

		// Seek to filemap offset.
		if ( $filemap_offset > 0 ) {
			@fseek( $filemap, $filemap_offset, SEEK_SET );

			// Count processed files (lines before offset).
			$temp = @fopen( $file_list_path, 'r' );
			if ( $temp ) {
				while ( ! feof( $temp ) && ftell( $temp ) < $filemap_offset ) {
					fgets( $temp );
					$processed++;
				}
				@fclose( $temp );
			}
		}

		// Open archive.
		if ( ! $this->open_for_write() ) {
			@fclose( $filemap );
			return array(
				'completed'      => false,
				'error'          => 'Failed to open archive',
				'filemap_offset' => $filemap_offset,
				'file_offset'    => $file_offset,
				'archive_offset' => $archive_offset,
				'processed'      => $processed,
				'total'          => $total,
			);
		}

		// Set archive position if resuming.
		if ( $archive_offset > 0 ) {
			// Need to open in r+b mode to seek and write.
			$this->close();
			$this->file_handle = @fopen( $this->archive_path, 'r+b' );
			if ( false === $this->file_handle ) {
				@fclose( $filemap );
				return array(
					'completed'      => false,
					'error'          => 'Failed to reopen archive for resume',
					'filemap_offset' => $filemap_offset,
					'file_offset'    => $file_offset,
					'archive_offset' => $archive_offset,
					'processed'      => $processed,
					'total'          => $total,
				);
			}
			$this->set_position( $archive_offset );
		}

		$base_path     = rtrim( $base_path, '/' ) . '/';
		$base_len      = strlen( $base_path );
		$completed     = true;
		$current_file_offset = $file_offset;

		// Process files.
		while ( ( $line = fgets( $filemap ) ) !== false ) {
			$source_path = trim( $line );

			if ( empty( $source_path ) ) {
				$filemap_offset = ftell( $filemap );
				continue;
			}

			// Handle null-terminated paths (from tar --null).
			$source_path = rtrim( $source_path, "\0" );

			if ( ! file_exists( $source_path ) || ! is_file( $source_path ) ) {
				$filemap_offset = ftell( $filemap );
				$current_file_offset = 0;
				$processed++;
				continue;
			}

			// Calculate relative path.
			$relative_path = $source_path;
			if ( strpos( $source_path, $base_path ) === 0 ) {
				$relative_path = substr( $source_path, $base_len );
			}

			// Normalize path separators.
			$relative_path = str_replace( '\\', '/', $relative_path );

			// Add file to archive.
			$bytes_written = 0;
			$file_complete = $this->add_file(
				$source_path,
				$relative_path,
				$current_file_offset,
				$bytes_written,
				$timeout
			);

			if ( $file_complete ) {
				// File completely written.
				$filemap_offset = ftell( $filemap );
				$current_file_offset = 0;
				$processed++;

				// Report progress.
				if ( null !== $progress_callback ) {
					$progress_callback( $processed, $total, $relative_path );
				}
			} else {
				// File partially written or timeout.
				$current_file_offset += $bytes_written;
				$completed = false;
				break;
			}

			// Check timeout.
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $timeout ) {
				$completed = false;
				break;
			}
		}

		// Check if we've reached the end.
		if ( feof( $filemap ) ) {
			$completed = true;
		}

		$archive_offset = $this->get_position();

		// Close archive (finalize only if completed).
		$this->close( $completed );
		@fclose( $filemap );

		return array(
			'completed'      => $completed,
			'filemap_offset' => $filemap_offset,
			'file_offset'    => $current_file_offset,
			'archive_offset' => $archive_offset,
			'processed'      => $processed,
			'total'          => $total,
		);
	}

	/**
	 * Create a file header block.
	 *
	 * @param string $path     File path in archive.
	 * @param int    $size     File size in bytes.
	 * @param int    $mtime    Modification time.
	 * @return string Binary header block.
	 */
	private function create_header( string $path, int $size, int $mtime ): string {
		// Normalize path separators.
		$path = str_replace( '\\', '/', $path );

		// Split into filename and directory.
		$filename = basename( $path );
		$dirname  = dirname( $path );

		if ( '.' === $dirname ) {
			$dirname = '';
		}

		// Pack header.
		$format = sprintf(
			'a%da%da%da%d',
			self::FILENAME_SIZE,
			self::FILESIZE_SIZE,
			self::MTIME_SIZE,
			self::PATH_SIZE
		);

		return pack(
			$format,
			$filename,
			(string) $size,
			(string) $mtime,
			$dirname
		);
	}

	/**
	 * Parse a header block.
	 *
	 * @param string $header Binary header block.
	 * @return array|null Parsed header or null if invalid/EOF.
	 */
	public function parse_header( string $header ): ?array {
		if ( strlen( $header ) !== self::HEADER_SIZE ) {
			return null;
		}

		// Check for EOF (all nulls).
		if ( $header === $this->eof_block ) {
			return null;
		}

		$format = sprintf(
			'a%dfilename/a%dsize/a%dmtime/a%dpath',
			self::FILENAME_SIZE,
			self::FILESIZE_SIZE,
			self::MTIME_SIZE,
			self::PATH_SIZE
		);

		$data = unpack( $format, $header );

		if ( false === $data ) {
			return null;
		}

		// Trim null padding.
		$filename = rtrim( $data['filename'], "\0" );
		$size     = (int) rtrim( $data['size'], "\0" );
		$mtime    = (int) rtrim( $data['mtime'], "\0" );
		$path     = rtrim( $data['path'], "\0" );

		// Reconstruct full path.
		$full_path = empty( $path ) ? $filename : $path . '/' . $filename;

		return array(
			'filename' => $filename,
			'size'     => $size,
			'mtime'    => $mtime,
			'path'     => $path,
			'full_path' => $full_path,
		);
	}

	/**
	 * Extract archive to directory.
	 *
	 * @param string        $destination       Destination directory.
	 * @param int           $archive_offset    Offset to resume from.
	 * @param callable|null $progress_callback Progress callback.
	 * @param int           $timeout           Timeout in seconds.
	 * @return array{completed: bool, archive_offset: int, files_extracted: int}
	 */
	public function extract_to(
		string $destination,
		int $archive_offset = 0,
		?callable $progress_callback = null,
		int $timeout = self::DEFAULT_TIMEOUT
	): array {
		$start_time      = microtime( true );
		$files_extracted = 0;

		if ( ! $this->open_for_read() ) {
			return array(
				'completed'       => false,
				'error'           => 'Failed to open archive',
				'archive_offset'  => $archive_offset,
				'files_extracted' => 0,
			);
		}

		// Seek to offset.
		if ( $archive_offset > 0 ) {
			$this->set_position( $archive_offset );
		}

		$destination = rtrim( $destination, '/' );
		$completed   = true;

		while ( true ) {
			// Read header.
			$header_data = @fread( $this->file_handle, self::HEADER_SIZE );

			if ( false === $header_data || strlen( $header_data ) < self::HEADER_SIZE ) {
				break;
			}

			$header = $this->parse_header( $header_data );

			if ( null === $header ) {
				// EOF or invalid header.
				break;
			}

			// Create destination path.
			$dest_path = $destination . '/' . $header['full_path'];
			$dest_dir  = dirname( $dest_path );

			// Create directory if needed.
			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			// Extract file content.
			$remaining = $header['size'];
			$dest_handle = @fopen( $dest_path, 'wb' );

			if ( false === $dest_handle ) {
				// Skip this file.
				@fseek( $this->file_handle, $remaining, SEEK_CUR );
				continue;
			}

			while ( $remaining > 0 ) {
				$chunk_size = min( $remaining, self::CHUNK_SIZE );
				$chunk      = @fread( $this->file_handle, $chunk_size );

				if ( false === $chunk ) {
					break;
				}

				@fwrite( $dest_handle, $chunk );
				$remaining -= strlen( $chunk );

				// Check timeout.
				$elapsed = microtime( true ) - $start_time;
				if ( $elapsed >= $timeout && $remaining > 0 ) {
					@fclose( $dest_handle );
					$archive_offset = $this->get_position();
					$this->close();

					return array(
						'completed'       => false,
						'archive_offset'  => $archive_offset,
						'files_extracted' => $files_extracted,
					);
				}
			}

			@fclose( $dest_handle );

			// Set modification time.
			if ( $header['mtime'] > 0 ) {
				@touch( $dest_path, $header['mtime'] );
			}

			$files_extracted++;

			if ( null !== $progress_callback ) {
				$progress_callback( $files_extracted, $header['full_path'] );
			}

			// Check timeout.
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $timeout ) {
				$completed = false;
				break;
			}
		}

		$archive_offset = $this->get_position();
		$this->close();

		return array(
			'completed'       => $completed,
			'archive_offset'  => $archive_offset,
			'files_extracted' => $files_extracted,
		);
	}

	/**
	 * List files in archive.
	 *
	 * @return array List of file info arrays.
	 */
	public function list_files(): array {
		$files = array();

		if ( ! $this->open_for_read() ) {
			return $files;
		}

		while ( true ) {
			$header_data = @fread( $this->file_handle, self::HEADER_SIZE );

			if ( false === $header_data || strlen( $header_data ) < self::HEADER_SIZE ) {
				break;
			}

			$header = $this->parse_header( $header_data );

			if ( null === $header ) {
				break;
			}

			$files[] = $header;

			// Skip file content.
			@fseek( $this->file_handle, $header['size'], SEEK_CUR );
		}

		$this->close();

		return $files;
	}

	/**
	 * Validate archive.
	 *
	 * @return bool True if valid.
	 */
	public function is_valid(): bool {
		if ( ! file_exists( $this->archive_path ) ) {
			return false;
		}

		$handle = @fopen( $this->archive_path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		// Check for EOF marker at end of file.
		if ( @fseek( $handle, -self::HEADER_SIZE, SEEK_END ) === -1 ) {
			@fclose( $handle );
			return false;
		}

		$eof_data = @fread( $handle, self::HEADER_SIZE );
		@fclose( $handle );

		return $eof_data === $this->eof_block;
	}

	/**
	 * Count lines in a file.
	 *
	 * @param string $file_path Path to file.
	 * @return int Line count.
	 */
	private function count_lines( string $file_path ): int {
		$count  = 0;
		$handle = @fopen( $file_path, 'r' );

		if ( false === $handle ) {
			return 0;
		}

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, 1048576 );
			if ( false !== $buffer ) {
				$count += substr_count( $buffer, "\n" );
			}
		}

		@fclose( $handle );

		return $count;
	}

	/**
	 * Get archive file size.
	 *
	 * @return int File size in bytes.
	 */
	public function get_size(): int {
		if ( ! file_exists( $this->archive_path ) ) {
			return 0;
		}

		return (int) filesize( $this->archive_path );
	}
}
