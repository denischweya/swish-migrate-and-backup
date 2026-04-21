<?php
/**
 * Swish Compressor - Creates .swish archives with streaming writes.
 *
 * Handles file addition with:
 * - 512KB chunk streaming (minimal memory)
 * - Per-byte offset tracking (resume mid-file)
 * - Disk quota detection
 * - Atomic writes with validation
 *
 * @package SwishMigrateAndBackup\Archive
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Archive;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Archive\Exception\QuotaExceededException;
use SwishMigrateAndBackup\Archive\Exception\NotWritableException;

/**
 * Compressor for creating .swish archives.
 */
class SwishCompressor extends SwishArchiver {

	/**
	 * Total bytes written to archive.
	 *
	 * @var int
	 */
	protected int $bytes_written = 0;

	/**
	 * Open archive for writing.
	 *
	 * @param bool $append Whether to append to existing archive.
	 * @return bool
	 */
	public function open( bool $append = false ): bool {
		$mode = $append ? 'ab' : 'wb';

		// Create directory if needed.
		$dir = dirname( $this->file_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$this->file_handle = fopen( $this->file_path, $mode );

		if ( ! $this->file_handle ) {
			return false;
		}

		// Track current position.
		$this->bytes_written = (int) ftell( $this->file_handle );

		return true;
	}

	/**
	 * Open archive for appending at specific offset.
	 *
	 * Used for resuming interrupted writes.
	 *
	 * @param int $offset Byte offset to resume from.
	 * @return bool
	 */
	public function open_at_offset( int $offset ): bool {
		// Open in read-write mode.
		$this->file_handle = fopen( $this->file_path, 'r+b' );

		if ( ! $this->file_handle ) {
			// File doesn't exist, create it.
			return $this->open( false );
		}

		// Truncate to offset (discard any partial writes).
		ftruncate( $this->file_handle, $offset );

		// Seek to end.
		fseek( $this->file_handle, 0, SEEK_END );

		$this->bytes_written = $offset;

		return true;
	}

	/**
	 * Add file to archive with streaming.
	 *
	 * Supports resuming mid-file via $file_offset parameter.
	 *
	 * @param string $source_path     Source file path.
	 * @param string $archive_path    Path within archive.
	 * @param int    $file_offset     Byte offset within source file (for resume).
	 * @param int    $bytes_written   Output: bytes written in this call.
	 * @param int    $timeout_seconds Max seconds to run before returning.
	 * @return bool True if file fully added, false if timed out (needs resume).
	 * @throws QuotaExceededException If disk space exhausted.
	 * @throws NotWritableException If write fails.
	 */
	public function add_file(
		string $source_path,
		string $archive_path,
		int &$file_offset = 0,
		int &$bytes_written = 0,
		int $timeout_seconds = 10
	): bool {
		if ( ! $this->is_open() ) {
			throw new NotWritableException( 'Archive not open for writing.' );
		}

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			// Skip unreadable files silently.
			return true;
		}

		$start_time = microtime( true );
		$file_size = (int) filesize( $source_path );
		$file_mtime = (int) filemtime( $source_path );
		$bytes_written = 0;

		// Parse archive path into name and prefix.
		$name = basename( $archive_path );
		$prefix = dirname( $archive_path );
		if ( '.' === $prefix ) {
			$prefix = '';
		}

		// If starting fresh (offset = 0), write header first.
		if ( 0 === $file_offset ) {
			$header = $this->create_header( $name, $file_size, $file_mtime, $prefix );

			$header_written = $this->write_data( $header );
			if ( $header_written !== self::HEADER_SIZE ) {
				throw new QuotaExceededException( 'Failed to write file header. Disk may be full.' );
			}

			$bytes_written += $header_written;
			$this->bytes_written += $header_written;
		}

		// Open source file.
		$source_handle = fopen( $source_path, 'rb' );
		if ( ! $source_handle ) {
			// Can't read file, but header is written. Write empty content.
			return true;
		}

		// Seek to resume position.
		if ( $file_offset > 0 ) {
			fseek( $source_handle, $file_offset, SEEK_SET );
		}

		// Stream file content in chunks.
		while ( ! feof( $source_handle ) ) {
			// Check timeout.
			if ( ( microtime( true ) - $start_time ) > $timeout_seconds ) {
				fclose( $source_handle );
				return false; // Timed out, needs resume.
			}

			$chunk = fread( $source_handle, self::CHUNK_SIZE );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$chunk_size = strlen( $chunk );
			$written = $this->write_data( $chunk );

			if ( $written !== $chunk_size ) {
				fclose( $source_handle );
				throw new QuotaExceededException(
					sprintf( 'Partial write: %d of %d bytes. Disk may be full.', $written, $chunk_size )
				);
			}

			$file_offset += $written;
			$bytes_written += $written;
			$this->bytes_written += $written;

			// Free memory.
			unset( $chunk );
		}

		fclose( $source_handle );

		// File completed.
		return true;
	}

	/**
	 * Add content directly to archive (for database dumps, manifests, etc).
	 *
	 * @param string $content      Content to add.
	 * @param string $archive_path Path within archive.
	 * @return bool
	 * @throws QuotaExceededException If disk space exhausted.
	 * @throws NotWritableException If write fails.
	 */
	public function add_content( string $content, string $archive_path ): bool {
		if ( ! $this->is_open() ) {
			throw new NotWritableException( 'Archive not open for writing.' );
		}

		$size = strlen( $content );
		$mtime = time();

		// Parse archive path.
		$name = basename( $archive_path );
		$prefix = dirname( $archive_path );
		if ( '.' === $prefix ) {
			$prefix = '';
		}

		// Write header.
		$header = $this->create_header( $name, $size, $mtime, $prefix );
		$header_written = $this->write_data( $header );

		if ( $header_written !== self::HEADER_SIZE ) {
			throw new QuotaExceededException( 'Failed to write content header. Disk may be full.' );
		}

		$this->bytes_written += $header_written;

		// Write content in chunks.
		$offset = 0;
		while ( $offset < $size ) {
			$chunk = substr( $content, $offset, self::CHUNK_SIZE );
			$chunk_size = strlen( $chunk );
			$written = $this->write_data( $chunk );

			if ( $written !== $chunk_size ) {
				throw new QuotaExceededException( 'Partial content write. Disk may be full.' );
			}

			$offset += $written;
			$this->bytes_written += $written;
		}

		return true;
	}

	/**
	 * Finalize archive by writing EOF marker.
	 *
	 * @return bool
	 * @throws QuotaExceededException If disk space exhausted.
	 */
	public function finalize(): bool {
		if ( ! $this->is_open() ) {
			return false;
		}

		$written = $this->write_data( $this->eof_marker );

		if ( $written !== self::HEADER_SIZE ) {
			throw new QuotaExceededException( 'Failed to write EOF marker. Disk may be full.' );
		}

		$this->bytes_written += $written;

		return true;
	}

	/**
	 * Get total bytes written to archive.
	 *
	 * @return int
	 */
	public function get_bytes_written(): int {
		return $this->bytes_written;
	}

	/**
	 * Write data to archive with validation.
	 *
	 * @param string $data Data to write.
	 * @return int Bytes actually written.
	 * @throws NotWritableException If write fails completely.
	 */
	protected function write_data( string $data ): int {
		$written = fwrite( $this->file_handle, $data );

		if ( false === $written ) {
			throw new NotWritableException( 'Unable to write to archive file.' );
		}

		return $written;
	}

	/**
	 * Flush any buffered data to disk.
	 *
	 * @return bool
	 */
	public function flush(): bool {
		if ( ! $this->is_open() ) {
			return false;
		}

		return fflush( $this->file_handle );
	}
}
