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
	public const HEADER_SIZE = 4377;

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
	 * Exactly $declared_size bytes (default: the file's current size) are
	 * written after the header, regardless of how the source changes while
	 * the backup runs: a file that grew is cut at the declared size and a
	 * file that shrank or vanished is padded with null bytes. The header
	 * and the content therefore always agree, which is what keeps every
	 * following entry readable.
	 *
	 * @param string $source_path   Full path to source file.
	 * @param string $archive_path  Path to use inside archive (relative).
	 * @param int    $file_offset   Offset within file to resume from.
	 * @param int    $bytes_written Output: bytes written in this call.
	 * @param int    $timeout       Timeout in seconds (0 = no timeout).
	 * @param int    $declared_size Size to record in the header (-1 = current file size).
	 *                              Pass the size captured when the file was indexed, and
	 *                              pass the same value again when resuming.
	 * @return bool True if file completely written, false if timed out.
	 */
	public function add_file(
		string $source_path,
		string $archive_path,
		int $file_offset = 0,
		int &$bytes_written = 0,
		int $timeout = self::DEFAULT_TIMEOUT,
		int $declared_size = -1
	): bool {
		$bytes_written = 0;
		$start_time    = microtime( true );

		$stat          = @stat( $source_path );
		$source_handle = false !== $stat ? @fopen( $source_path, 'rb' ) : false;

		// Nothing written yet and the source is missing/unreadable: skip the entry.
		if ( false === $source_handle && 0 === $file_offset ) {
			return true;
		}

		$file_size = $declared_size >= 0 ? $declared_size : (int) $stat['size'];
		$mtime     = false !== $stat ? (int) $stat['mtime'] : time();

		// Write header only on first chunk (offset = 0).
		if ( 0 === $file_offset ) {
			$header  = $this->create_header( $archive_path, $file_size, $mtime );
			$written = @fwrite( $this->file_handle, $header );

			if ( false === $written || strlen( $header ) !== $written ) {
				if ( $source_handle ) {
					@fclose( $source_handle );
				}
				throw new ArchiveWriteException( $this->describe_write_failure() );
			}
		}

		$remaining = $file_size - $file_offset;

		if ( $source_handle && $file_offset > 0 ) {
			@fseek( $source_handle, $file_offset, SEEK_SET );
		}

		// Write exactly $remaining bytes: source content first, null padding if the source ran short.
		$completed = true;

		while ( $remaining > 0 ) {
			$chunk = '';
			if ( $source_handle ) {
				$chunk = @fread( $source_handle, min( self::CHUNK_SIZE, $remaining ) );
				if ( false === $chunk ) {
					$chunk = '';
				}
			}
			if ( '' === $chunk ) {
				$chunk = str_repeat( "\0", min( self::CHUNK_SIZE, $remaining ) );
			}

			$written = @fwrite( $this->file_handle, $chunk );

			if ( false === $written || strlen( $chunk ) !== $written ) {
				// A write failure (disk full, quota) is not a timeout: the caller must
				// not retry it forever as if more time would help.
				if ( $source_handle ) {
					@fclose( $source_handle );
				}
				throw new ArchiveWriteException( $this->describe_write_failure() );
			}

			$bytes_written += $written;
			$remaining     -= $written;

			// Check timeout.
			if ( $remaining > 0 && $timeout > 0 ) {
				$elapsed = microtime( true ) - $start_time;
				if ( $elapsed >= $timeout ) {
					$completed = false;
					break;
				}
			}
		}

		if ( $source_handle ) {
			@fclose( $source_handle );
		}

		return $completed;
	}

	/**
	 * Human-readable reason for a failed archive write.
	 *
	 * @return string
	 */
	private function describe_write_failure(): string {
		$last = error_get_last();
		$why  = $last['message'] ?? '';
		if ( '' === $why ) {
			$free = @disk_free_space( dirname( $this->archive_path ) );
			$why  = ( false !== $free && $free < 10485760 ) ? 'disk full' : 'write error';
		}

		return sprintf( 'Failed to write to backup archive %s (%s)', basename( $this->archive_path ), $why );
	}

	/**
	 * Walk the entries that start at $from and return the end of the last one
	 * that is complete (header plus exactly its declared size).
	 *
	 * Used after a request died without recording what it appended: every
	 * complete entry is kept, only the trailing incomplete one is cut.
	 *
	 * @param int $from Byte offset of an entry boundary to start from.
	 * @return int Offset just after the last complete entry (>= $from).
	 */
	public function find_last_complete_entry_end( int $from ): int {
		$size   = $this->get_end_position();
		$handle = @fopen( $this->archive_path, 'rb' );
		if ( false === $handle ) {
			return $from;
		}

		$pos = max( 0, $from );
		while ( $pos + self::HEADER_SIZE <= $size ) {
			if ( 0 !== @fseek( $handle, $pos, SEEK_SET ) ) {
				break;
			}
			$header = @fread( $handle, self::HEADER_SIZE );
			if ( false === $header || strlen( $header ) < self::HEADER_SIZE || $header === $this->eof_block ) {
				break;
			}
			$parsed     = $this->parse_header( $header );
			$size_field = rtrim( substr( $header, self::FILENAME_SIZE, self::FILESIZE_SIZE ), "\0" );
			if ( null === $parsed || '' === $parsed['filename'] || '' === $size_field || ! ctype_digit( $size_field ) ) {
				break;
			}
			$end = $pos + self::HEADER_SIZE + $parsed['size'];
			if ( $end > $size ) {
				break; // Incomplete trailing entry.
			}
			$pos = $end;
		}
		@fclose( $handle );

		return $pos;
	}

	/**
	 * Get the current end of the archive in bytes.
	 *
	 * ftell() is unreliable on a handle opened in append mode before the first
	 * write, so the size is taken from the file descriptor instead.
	 *
	 * @return int Archive size in bytes.
	 */
	public function get_end_position(): int {
		if ( null === $this->file_handle ) {
			return $this->get_size();
		}

		@fflush( $this->file_handle );
		$stat = @fstat( $this->file_handle );

		return $stat ? (int) $stat['size'] : $this->get_size();
	}

	/**
	 * Cut the archive back to $offset bytes.
	 *
	 * Used to discard the tail of an entry that a previous request wrote only
	 * partially (and could not record), so the next write starts at a clean
	 * entry boundary.
	 *
	 * @param int $offset Byte offset to truncate at.
	 * @return bool True on success.
	 */
	public function truncate_to( int $offset ): bool {
		if ( null === $this->file_handle ) {
			return false;
		}

		@fflush( $this->file_handle );

		return @ftruncate( $this->file_handle, max( 0, $offset ) );
	}

	/**
	 * Walk the archive header by header and check that every entry is complete
	 * and that the EOF marker is the last block.
	 *
	 * This is a structural check (headers and sizes), not a content checksum,
	 * so it costs one seek per entry even on multi-gigabyte archives. With a
	 * time budget the walk can be resumed: pass back 'pos' and 'entries' from a
	 * result whose 'completed' is false.
	 *
	 * @param int   $start_pos   Byte offset to resume from (an entry boundary).
	 * @param int   $entries     Entries already verified before $start_pos.
	 * @param float $time_budget Seconds to work before yielding (0 = no limit).
	 * @return array{valid: bool, completed: bool, entries: int, pos: int, error: string}
	 */
	public function verify_structure( int $start_pos = 0, int $entries = 0, float $time_budget = 0 ): array {
		if ( ! file_exists( $this->archive_path ) ) {
			return array(
				'valid'     => false,
				'completed' => true,
				'entries'   => 0,
				'pos'       => 0,
				'error'     => 'Archive file not found',
			);
		}

		$size   = (int) filesize( $this->archive_path );
		$handle = @fopen( $this->archive_path, 'rb' );

		if ( false === $handle ) {
			return array(
				'valid'     => false,
				'completed' => true,
				'entries'   => 0,
				'pos'       => 0,
				'error'     => 'Archive cannot be opened',
			);
		}

		$pos    = max( 0, $start_pos );
		$result = null;
		$start  = microtime( true );

		if ( $pos > 0 && 0 !== @fseek( $handle, $pos, SEEK_SET ) ) {
			@fclose( $handle );
			return array(
				'valid'     => false,
				'completed' => true,
				'entries'   => $entries,
				'pos'       => $pos,
				'error'     => sprintf( 'Cannot seek to byte %d', $pos ),
			);
		}

		while ( null === $result ) {
			if ( $time_budget > 0 && ( microtime( true ) - $start ) >= $time_budget ) {
				@fclose( $handle );
				return array(
					'valid'     => false,
					'completed' => false,
					'entries'   => $entries,
					'pos'       => $pos,
					'error'     => '',
				);
			}

			$header = @fread( $handle, self::HEADER_SIZE );

			if ( false === $header || strlen( $header ) < self::HEADER_SIZE ) {
				$result = array(
					'valid'   => false,
					'entries' => $entries,
					'error'   => sprintf( 'Archive ends without an EOF marker at byte %d', $pos ),
				);
				break;
			}

			if ( $header === $this->eof_block ) {
				$valid  = ( $pos + self::HEADER_SIZE === $size );
				$result = array(
					'valid'   => $valid,
					'entries' => $entries,
					'error'   => $valid ? '' : 'Unexpected data after the EOF marker',
				);
				break;
			}

			$parsed     = $this->parse_header( $header );
			$size_field = rtrim( substr( $header, self::FILENAME_SIZE, self::FILESIZE_SIZE ), "\0" );

			if (
				null === $parsed ||
				'' === $parsed['filename'] ||
				'' === $size_field ||
				! ctype_digit( $size_field ) ||
				$pos + self::HEADER_SIZE + $parsed['size'] > $size
			) {
				$result = array(
					'valid'   => false,
					'entries' => $entries,
					'error'   => sprintf( 'Corrupt entry header at byte %d', $pos ),
				);
				break;
			}

			$pos += self::HEADER_SIZE + $parsed['size'];
			++$entries;

			if ( 0 !== @fseek( $handle, $pos, SEEK_SET ) ) {
				$result = array(
					'valid'   => false,
					'entries' => $entries,
					'error'   => sprintf( 'Cannot seek to byte %d', $pos ),
				);
				break;
			}
		}

		@fclose( $handle );

		$result['completed'] = true;
		$result['pos']       = $pos;

		return $result;
	}

	/**
	 * Compute the archive's MD5 in time-bounded, resumable steps.
	 *
	 * Hashing a multi-gigabyte archive in one request outlives proxy timeouts,
	 * so the hash context is serialised between calls (PHP 8.1+). Pass back
	 * 'offset' and 'context' until 'completed' is true.
	 *
	 * @param int    $offset      Byte offset to resume from.
	 * @param string $context     Serialised hash context from the previous call ('' to start).
	 * @param float  $time_budget Seconds to work before yielding (0 = no limit).
	 * @return array{completed: bool, offset: int, context: string, checksum: string, error: string}
	 */
	public function checksum_chunked( int $offset = 0, string $context = '', float $time_budget = 0 ): array {
		$fail = static function ( string $error ) use ( $offset ): array {
			return array(
				'completed' => true,
				'offset'    => $offset,
				'context'   => '',
				'checksum'  => '',
				'error'     => $error,
			);
		};

		$handle = @fopen( $this->archive_path, 'rb' );
		if ( false === $handle ) {
			return $fail( 'Archive cannot be opened' );
		}

		$ctx = null;
		if ( '' !== $context && $offset > 0 ) {
			try {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$restored = unserialize( base64_decode( $context ), array( 'allowed_classes' => array( \HashContext::class ) ) );
				if ( $restored instanceof \HashContext ) {
					$ctx = $restored;
				}
			} catch ( \Throwable $e ) {
				$ctx = null;
			}
		}
		if ( null === $ctx ) {
			$ctx    = hash_init( 'md5' );
			$offset = 0;
		}

		if ( $offset > 0 && 0 !== @fseek( $handle, $offset, SEEK_SET ) ) {
			@fclose( $handle );
			return $fail( sprintf( 'Cannot seek to byte %d', $offset ) );
		}

		$start = microtime( true );
		while ( ! feof( $handle ) ) {
			$chunk = @fread( $handle, 1048576 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			hash_update( $ctx, $chunk );
			$offset += strlen( $chunk );

			if ( $time_budget > 0 && ! feof( $handle ) && ( microtime( true ) - $start ) >= $time_budget ) {
				@fclose( $handle );
				try {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					$saved = base64_encode( serialize( $ctx ) );
				} catch ( \Throwable $e ) {
					// Context cannot be serialised on this PHP build: finish in one go.
					return $this->checksum_chunked( 0, '', 0 );
				}
				return array(
					'completed' => false,
					'offset'    => $offset,
					'context'   => $saved,
					'checksum'  => '',
					'error'     => '',
				);
			}
		}
		@fclose( $handle );

		return array(
			'completed' => true,
			'offset'    => $offset,
			'context'   => '',
			'checksum'  => hash_final( $ctx ),
			'error'     => '',
		);
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
