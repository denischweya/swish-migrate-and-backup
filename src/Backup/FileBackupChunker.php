<?php
/**
 * File Backup Chunker - Handles file backup in resumable chunks.
 *
 * Uses streaming tar.gz creation to avoid memory issues with ZipArchive.
 * Processes files in 10-second time slices for reliable operation.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Chunked file backup processor.
 *
 * Key features:
 * - Streams files directly to tar.gz (no memory-hungry ZipArchive)
 * - Tracks exact file index for resumability
 * - 10-second time slices by default
 * - Handles large files by splitting into chunks
 */
final class FileBackupChunker extends ChunkingManager {

	/**
	 * Maximum bytes to read per file chunk (1MB like Duplicator Pro).
	 */
	private const FILE_CHUNK_SIZE = 1048576;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Output archive path.
	 *
	 * @var string
	 */
	private string $output_path = '';

	/**
	 * File list (loaded from state file).
	 *
	 * @var array
	 */
	private array $files = array();

	/**
	 * Current file index.
	 *
	 * @var int
	 */
	private int $current_index = 0;

	/**
	 * Current file byte offset (for large files).
	 *
	 * @var int
	 */
	private int $current_offset = 0;

	/**
	 * Archive handle.
	 *
	 * @var resource|null
	 */
	private $archive_handle = null;

	/**
	 * GZIP compression handle.
	 *
	 * @var resource|null
	 */
	private $gz_handle = null;

	/**
	 * Progress callback.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Files processed in current chunk.
	 *
	 * @var int
	 */
	private int $files_in_chunk = 0;

	/**
	 * Bytes written in current chunk.
	 *
	 * @var int
	 */
	private int $bytes_in_chunk = 0;

	/**
	 * Constructor.
	 *
	 * @param string   $job_id     Job ID for state persistence.
	 * @param Logger   $logger     Logger instance.
	 * @param int      $time_slice Time slice in seconds.
	 */
	public function __construct( string $job_id, Logger $logger, int $time_slice = self::DEFAULT_TIME_SLICE ) {
		parent::__construct( $job_id, $time_slice );
		$this->logger = $logger;
	}

	/**
	 * Initialize the chunker with file list and output path.
	 *
	 * @param array  $files       Array of file data arrays with 'path' and 'relative' keys.
	 * @param string $output_path Output archive path (will be .tar.gz).
	 * @return self
	 */
	public function initialize( array $files, string $output_path ): self {
		$this->files       = $files;
		$this->output_path = $this->ensure_tar_extension( $output_path );
		$this->total_items = count( $files );

		return $this;
	}

	/**
	 * Set progress callback.
	 *
	 * @param callable|null $callback Progress callback (percent, message, processed, total).
	 * @return self
	 */
	public function set_progress_callback( ?callable $callback ): self {
		$this->progress_callback = $callback;
		return $this;
	}

	/**
	 * Ensure the output path has .tar.gz extension.
	 *
	 * @param string $path Original path.
	 * @return string Path with .tar.gz extension.
	 */
	private function ensure_tar_extension( string $path ): string {
		$ext = pathinfo( $path, PATHINFO_EXTENSION );
		if ( 'gz' === $ext || str_ends_with( $path, '.tar.gz' ) ) {
			return $path;
		}

		// Replace .zip or other extension with .tar.gz.
		return preg_replace( '/\.[^.]+$/', '', $path ) . '.tar.gz';
	}

	/**
	 * Process a single file (or file chunk for large files).
	 *
	 * @param mixed $item File data array.
	 * @return bool True on success.
	 */
	protected function process_item( $item ): bool {
		$file_path = $item['path'] ?? '';
		$relative  = $item['relative'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			$this->logger->warning( 'Skipping unreadable file', array( 'path' => $file_path ) );
			++$this->files_in_chunk;
			return true; // Skip but don't fail.
		}

		$file_size = filesize( $file_path );

		// Write tar header for new files.
		if ( 0 === $this->current_offset ) {
			$this->write_tar_header( $relative, $file_size, filemtime( $file_path ) );
		}

		// Write file content in chunks.
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			$this->logger->warning( 'Failed to open file', array( 'path' => $file_path ) );
			return true; // Skip but don't fail.
		}

		// Seek to offset for resumed files.
		if ( $this->current_offset > 0 ) {
			fseek( $handle, $this->current_offset );
		}

		// Read and write a chunk.
		$bytes_remaining = $file_size - $this->current_offset;
		$to_read         = min( self::FILE_CHUNK_SIZE, $bytes_remaining );
		$content         = fread( $handle, $to_read );

		fclose( $handle );

		if ( false === $content ) {
			$this->logger->warning( 'Failed to read file content', array( 'path' => $file_path ) );
			return true;
		}

		// Write to archive.
		$this->write_to_archive( $content );
		$this->current_offset += strlen( $content );
		$this->bytes_in_chunk += strlen( $content );

		// Check if file is complete.
		if ( $this->current_offset >= $file_size ) {
			// Pad to 512-byte boundary.
			$padding = ( 512 - ( $file_size % 512 ) ) % 512;
			if ( $padding > 0 ) {
				$this->write_to_archive( str_repeat( "\0", $padding ) );
			}

			++$this->files_in_chunk;
			$this->current_offset = 0;

			// Report progress.
			if ( $this->progress_callback ) {
				$progress = $this->get_progress();
				( $this->progress_callback )( $progress, $relative, $this->processed_items, $this->total_items );
			}
		}

		return true;
	}

	/**
	 * Get the next item to process.
	 *
	 * @return mixed|null Next item or null.
	 */
	protected function get_next_item() {
		// If we're in the middle of a large file, return same file.
		if ( $this->current_offset > 0 && $this->current_index < count( $this->files ) ) {
			return $this->files[ $this->current_index ];
		}

		// Move to next file if current one is done.
		if ( 0 === $this->current_offset && $this->current_index < count( $this->files ) ) {
			return $this->files[ $this->current_index++ ];
		}

		return null;
	}

	/**
	 * Check if there are more items to process.
	 *
	 * @return bool True if more items.
	 */
	protected function has_more_items(): bool {
		// Still processing a large file.
		if ( $this->current_offset > 0 ) {
			return true;
		}

		return $this->current_index < count( $this->files );
	}

	/**
	 * Save current state for resuming.
	 *
	 * @return bool True on success.
	 */
	protected function save_state(): bool {
		$state_data = array(
			'current_index'   => $this->current_index,
			'current_offset'  => $this->current_offset,
			'processed_items' => $this->processed_items,
			'total_items'     => $this->total_items,
			'output_path'     => $this->output_path,
		);

		return $this->state->save_meta( $this->job_id, $this->get_state_key(), $state_data );
	}

	/**
	 * Restore state from previous chunk.
	 *
	 * @return bool True if state was restored.
	 */
	protected function restore_state(): bool {
		$state_data = $this->state->get_meta( $this->job_id, $this->get_state_key() );

		if ( ! $state_data ) {
			return false;
		}

		$this->current_index   = $state_data['current_index'] ?? 0;
		$this->current_offset  = $state_data['current_offset'] ?? 0;
		$this->processed_items = $state_data['processed_items'] ?? 0;
		$this->total_items     = $state_data['total_items'] ?? count( $this->files );
		$this->output_path     = $state_data['output_path'] ?? $this->output_path;

		$this->logger->debug( 'Restored file backup state', $state_data );

		return true;
	}

	/**
	 * Run the backup process.
	 *
	 * @param bool $rewind Start from beginning.
	 * @return int CHUNK_COMPLETE, CHUNK_STOP, or CHUNK_ERROR.
	 */
	public function run( bool $rewind = false ): int {
		// Open archive.
		if ( ! $this->open_archive( $rewind ) ) {
			$this->last_error = 'Failed to open archive for writing';
			return self::CHUNK_ERROR;
		}

		$this->files_in_chunk = 0;
		$this->bytes_in_chunk = 0;

		$this->logger->info( 'Starting file backup chunk', array(
			'job_id'      => $this->job_id,
			'rewind'      => $rewind,
			'file_index'  => $this->current_index,
			'file_offset' => $this->current_offset,
			'total_files' => $this->total_items,
			'time_slice'  => $this->time_slice,
		) );

		// Run the chunking loop.
		$result = parent::run( $rewind );

		// Close archive.
		$this->close_archive( self::CHUNK_COMPLETE === $result );

		$this->logger->info( 'File backup chunk completed', array(
			'result'         => $result,
			'files_in_chunk' => $this->files_in_chunk,
			'bytes_in_chunk' => $this->bytes_in_chunk,
			'processed'      => $this->processed_items,
			'total'          => $this->total_items,
			'elapsed'        => round( $this->get_elapsed_time(), 2 ),
		) );

		return $result;
	}

	/**
	 * Open the archive for writing.
	 *
	 * @param bool $rewind If true, create new archive (overwrite existing).
	 * @return bool True on success.
	 */
	private function open_archive( bool $rewind ): bool {
		// Ensure output directory exists.
		$dir = dirname( $this->output_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$mode = $rewind ? 'wb' : 'ab';

		// For gzip, we need to open raw file first.
		$raw_path = $this->output_path;
		$this->archive_handle = fopen( $raw_path, $mode );

		if ( ! $this->archive_handle ) {
			return false;
		}

		// Use gzopen for compression.
		$gz_path = 'compress.zlib://' . $raw_path;

		// For append mode, we need to be careful with gzip.
		// Actually, let's use raw tar without gzip during creation,
		// and gzip at the end. This allows proper appending.
		// OR use PHP's phar extension if available.

		// For simplicity, let's create an uncompressed tar during backup,
		// then gzip it at the end in finalize_archive().
		$this->output_path = preg_replace( '/\.gz$/', '', $this->output_path );

		// Close the gz path and reopen as raw tar.
		fclose( $this->archive_handle );
		$this->archive_handle = fopen( $this->output_path, $mode );

		return $this->archive_handle !== false;
	}

	/**
	 * Write data to the archive.
	 *
	 * @param string $data Data to write.
	 * @return int Bytes written.
	 */
	private function write_to_archive( string $data ): int {
		if ( ! $this->archive_handle ) {
			return 0;
		}

		return fwrite( $this->archive_handle, $data ) ?: 0;
	}

	/**
	 * Close the archive.
	 *
	 * @param bool $finalize If true, add EOF markers and compress.
	 * @return void
	 */
	private function close_archive( bool $finalize = false ): void {
		if ( ! $this->archive_handle ) {
			return;
		}

		if ( $finalize ) {
			// Write two zero blocks for tar EOF.
			$this->write_to_archive( str_repeat( "\0", 1024 ) );
		}

		fclose( $this->archive_handle );
		$this->archive_handle = null;

		if ( $finalize ) {
			$this->compress_archive();
		}
	}

	/**
	 * Compress the tar archive to tar.gz.
	 *
	 * @return bool True on success.
	 */
	private function compress_archive(): bool {
		$tar_path = $this->output_path;
		$gz_path  = $tar_path . '.gz';

		if ( ! file_exists( $tar_path ) ) {
			return false;
		}

		$this->logger->info( 'Compressing archive', array(
			'tar_size' => filesize( $tar_path ),
		) );

		// Use gzopen for streaming compression.
		$in  = fopen( $tar_path, 'rb' );
		$out = gzopen( $gz_path, 'wb6' ); // Level 6 compression.

		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				gzclose( $out );
			}
			return false;
		}

		// Stream in 1MB chunks.
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, self::FILE_CHUNK_SIZE );
			if ( false !== $chunk ) {
				gzwrite( $out, $chunk );
			}
		}

		fclose( $in );
		gzclose( $out );

		// Remove the uncompressed tar.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $tar_path );

		// Update output path.
		$this->output_path = $gz_path;

		$this->logger->info( 'Archive compressed', array(
			'gz_size' => filesize( $gz_path ),
		) );

		return true;
	}

	/**
	 * Write a tar header for a file.
	 *
	 * @param string $name  File name in archive.
	 * @param int    $size  File size.
	 * @param int    $mtime Modification time.
	 * @return void
	 */
	private function write_tar_header( string $name, int $size, int $mtime ): void {
		// Tar header is 512 bytes.
		$header = str_repeat( "\0", 512 );

		// File name (100 bytes).
		$name_bytes = substr( $name, 0, 100 );
		$header     = substr_replace( $header, $name_bytes, 0, strlen( $name_bytes ) );

		// File mode (8 bytes) - 0644.
		$header = substr_replace( $header, sprintf( '%07o', 0644 ), 100, 7 );

		// UID (8 bytes).
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 108, 7 );

		// GID (8 bytes).
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 116, 7 );

		// File size (12 bytes).
		$header = substr_replace( $header, sprintf( '%011o', $size ), 124, 11 );

		// Modification time (12 bytes).
		$header = substr_replace( $header, sprintf( '%011o', $mtime ), 136, 11 );

		// Checksum placeholder (8 bytes of spaces).
		$header = substr_replace( $header, '        ', 148, 8 );

		// Type flag (1 byte) - '0' for regular file.
		$header[156] = '0';

		// Calculate and set checksum.
		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}
		$header = substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );

		$this->write_to_archive( $header );
	}

	/**
	 * Get the output archive path.
	 *
	 * @return string Archive path.
	 */
	public function get_output_path(): string {
		return $this->output_path;
	}
}
