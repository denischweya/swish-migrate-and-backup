<?php
/**
 * Swish Extractor - Reads .swish archives with streaming.
 *
 * Handles file extraction with:
 * - 512KB chunk streaming (minimal memory)
 * - Per-byte offset tracking (resume mid-file)
 * - Iterator pattern for file listing
 *
 * @package SwishMigrateAndBackup\Archive
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Archive;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extractor for reading .swish archives.
 */
class SwishExtractor extends SwishArchiver {

	/**
	 * Open archive for reading.
	 *
	 * @return bool
	 */
	public function open(): bool {
		if ( ! file_exists( $this->file_path ) ) {
			return false;
		}

		$this->file_handle = fopen( $this->file_path, 'rb' );

		return false !== $this->file_handle;
	}

	/**
	 * Read next file header from archive.
	 *
	 * @return array|false File info array or false on EOF/error.
	 */
	public function read_header() {
		if ( ! $this->is_open() ) {
			return false;
		}

		$header_data = fread( $this->file_handle, self::HEADER_SIZE );

		if ( false === $header_data || strlen( $header_data ) < self::HEADER_SIZE ) {
			return false;
		}

		$header = $this->parse_header( $header_data );

		if ( ! $header || ! empty( $header['eof'] ) ) {
			return false;
		}

		// Add current position for extraction.
		$header['content_offset'] = ftell( $this->file_handle );

		return $header;
	}

	/**
	 * Skip to next file in archive.
	 *
	 * @param array $header Current file header.
	 * @return bool
	 */
	public function skip_file( array $header ): bool {
		if ( ! $this->is_open() ) {
			return false;
		}

		return 0 === fseek( $this->file_handle, $header['size'], SEEK_CUR );
	}

	/**
	 * Extract current file to destination.
	 *
	 * @param array  $header           File header from read_header().
	 * @param string $destination_path Full path to write extracted file.
	 * @param int    $file_offset      Byte offset within file (for resume).
	 * @param int    $bytes_written    Output: bytes written in this call.
	 * @param int    $timeout_seconds  Max seconds to run.
	 * @return bool True if complete, false if timed out.
	 */
	public function extract_file(
		array $header,
		string $destination_path,
		int &$file_offset = 0,
		int &$bytes_written = 0,
		int $timeout_seconds = 10
	): bool {
		if ( ! $this->is_open() ) {
			return false;
		}

		$start_time = microtime( true );
		$bytes_written = 0;
		$remaining = $header['size'] - $file_offset;

		// Create destination directory.
		$dest_dir = dirname( $destination_path );
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		// Open destination file.
		$mode = ( 0 === $file_offset ) ? 'wb' : 'ab';
		$dest_handle = fopen( $destination_path, $mode );

		if ( ! $dest_handle ) {
			return false;
		}

		// Seek to content position + offset.
		$content_start = $header['content_offset'] + $file_offset;
		fseek( $this->file_handle, $content_start, SEEK_SET );

		// Stream content in chunks.
		while ( $remaining > 0 ) {
			// Check timeout.
			if ( ( microtime( true ) - $start_time ) > $timeout_seconds ) {
				fclose( $dest_handle );
				return false; // Timed out.
			}

			$chunk_size = min( self::CHUNK_SIZE, $remaining );
			$chunk = fread( $this->file_handle, $chunk_size );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$written = fwrite( $dest_handle, $chunk );
			if ( false === $written ) {
				fclose( $dest_handle );
				return false;
			}

			$file_offset += $written;
			$bytes_written += $written;
			$remaining -= $written;

			unset( $chunk );
		}

		fclose( $dest_handle );

		// Set modification time.
		if ( $remaining <= 0 && ! empty( $header['mtime'] ) ) {
			touch( $destination_path, $header['mtime'] );
		}

		return $remaining <= 0;
	}

	/**
	 * Extract file content to string (for small files like manifests).
	 *
	 * @param array $header File header.
	 * @param int   $max_size Maximum size to read (default 1MB).
	 * @return string|false Content or false on failure.
	 */
	public function extract_content( array $header, int $max_size = 1048576 ) {
		if ( ! $this->is_open() ) {
			return false;
		}

		if ( $header['size'] > $max_size ) {
			return false; // Too large.
		}

		// Seek to content.
		fseek( $this->file_handle, $header['content_offset'], SEEK_SET );

		$content = fread( $this->file_handle, $header['size'] );

		return $content;
	}

	/**
	 * List all files in archive.
	 *
	 * @return array Array of file headers.
	 */
	public function list_files(): array {
		if ( ! $this->is_open() ) {
			if ( ! $this->open() ) {
				return array();
			}
		}

		// Start from beginning.
		fseek( $this->file_handle, 0, SEEK_SET );

		$files = array();

		while ( $header = $this->read_header() ) {
			$files[] = $header;
			$this->skip_file( $header );
		}

		return $files;
	}

	/**
	 * Find file by path in archive.
	 *
	 * @param string $path File path to find.
	 * @return array|false File header or false if not found.
	 */
	public function find_file( string $path ) {
		if ( ! $this->is_open() ) {
			if ( ! $this->open() ) {
				return false;
			}
		}

		// Start from beginning.
		fseek( $this->file_handle, 0, SEEK_SET );

		while ( $header = $this->read_header() ) {
			if ( $header['path'] === $path ) {
				return $header;
			}
			$this->skip_file( $header );
		}

		return false;
	}

	/**
	 * Extract all files to destination directory.
	 *
	 * @param string   $destination_dir   Base directory for extraction.
	 * @param int      $archive_offset    Byte offset in archive (for resume).
	 * @param int      $file_offset       Byte offset in current file (for resume).
	 * @param int      $timeout_seconds   Max seconds to run.
	 * @param callable $progress_callback Optional progress callback.
	 * @return array Result with 'completed', 'archive_offset', 'file_offset', 'files_extracted'.
	 */
	public function extract_all(
		string $destination_dir,
		int $archive_offset = 0,
		int $file_offset = 0,
		int $timeout_seconds = 10,
		?callable $progress_callback = null
	): array {
		if ( ! $this->is_open() ) {
			if ( ! $this->open() ) {
				return array(
					'completed'       => false,
					'error'           => 'Cannot open archive',
					'archive_offset'  => $archive_offset,
					'file_offset'     => $file_offset,
					'files_extracted' => 0,
				);
			}
		}

		$start_time = microtime( true );
		$files_extracted = 0;

		// Seek to resume position.
		fseek( $this->file_handle, $archive_offset, SEEK_SET );

		while ( $header = $this->read_header() ) {
			// Check timeout.
			if ( ( microtime( true ) - $start_time ) > $timeout_seconds ) {
				// Save position at current file header start.
				return array(
					'completed'       => false,
					'timeout'         => true,
					'archive_offset'  => $header['content_offset'] - self::HEADER_SIZE,
					'file_offset'     => $file_offset,
					'files_extracted' => $files_extracted,
				);
			}

			$dest_path = $destination_dir . '/' . $header['path'];

			$bytes = 0;
			$completed = $this->extract_file(
				$header,
				$dest_path,
				$file_offset,
				$bytes,
				$timeout_seconds - ( microtime( true ) - $start_time )
			);

			if ( ! $completed ) {
				// Timed out mid-file.
				return array(
					'completed'       => false,
					'timeout'         => true,
					'archive_offset'  => $header['content_offset'] - self::HEADER_SIZE,
					'file_offset'     => $file_offset,
					'files_extracted' => $files_extracted,
				);
			}

			// Reset file offset for next file.
			$file_offset = 0;
			++$files_extracted;

			// Progress callback.
			if ( $progress_callback ) {
				$progress_callback( $header['path'], $files_extracted );
			}
		}

		return array(
			'completed'       => true,
			'archive_offset'  => ftell( $this->file_handle ),
			'file_offset'     => 0,
			'files_extracted' => $files_extracted,
		);
	}
}
