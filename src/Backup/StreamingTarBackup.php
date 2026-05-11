<?php
/**
 * Streaming Tar Backup - True streaming backup with 10-second chunks.
 *
 * This class creates tar.gz archives by streaming files directly to disk,
 * never loading more than 1MB at a time. Designed for large sites with
 * 50,000+ files without exhausting server resources.
 *
 * Key features:
 * - Pure PHP tar creation (no system commands)
 * - 10-second time slices with state persistence
 * - Maximum 1MB memory per file chunk
 * - Resumable from exact file + byte offset
 * - Final gzip compression after tar is complete
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
 * Streaming tar backup with chunked processing.
 */
final class StreamingTarBackup {

	/**
	 * Time slice in seconds (like Duplicator Pro).
	 */
	private const TIME_SLICE = 10;

	/**
	 * Maximum bytes to read per file chunk (1MB).
	 */
	private const CHUNK_SIZE = 1048576;

	/**
	 * Result: backup completed successfully.
	 */
	public const RESULT_COMPLETE = 'complete';

	/**
	 * Result: backup needs continuation (time slice expired).
	 */
	public const RESULT_CONTINUE = 'continue';

	/**
	 * Result: backup failed with error.
	 */
	public const RESULT_ERROR = 'error';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * State manager.
	 *
	 * @var BackupState
	 */
	private BackupState $state;

	/**
	 * Current job ID.
	 *
	 * @var string
	 */
	private string $job_id = '';

	/**
	 * Output tar file path (uncompressed during creation).
	 *
	 * @var string
	 */
	private string $tar_path = '';

	/**
	 * Output tar.gz file path (final compressed).
	 *
	 * @var string
	 */
	private string $output_path = '';

	/**
	 * Archive file handle.
	 *
	 * @var resource|null
	 */
	private $archive_handle = null;

	/**
	 * Current file index.
	 *
	 * @var int
	 */
	private int $file_index = 0;

	/**
	 * Current byte offset within file (for large files).
	 *
	 * @var int
	 */
	private int $byte_offset = 0;

	/**
	 * Total files to process.
	 *
	 * @var int
	 */
	private int $total_files = 0;

	/**
	 * Files processed so far.
	 *
	 * @var int
	 */
	private int $processed_files = 0;

	/**
	 * Start timestamp for time slice.
	 *
	 * @var float
	 */
	private float $start_time = 0;

	/**
	 * End timestamp (start + TIME_SLICE).
	 *
	 * @var float
	 */
	private float $end_time = 0;

	/**
	 * Progress callback.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
		$this->state  = new BackupState();
	}

	/**
	 * Run a backup chunk.
	 *
	 * Call this repeatedly until it returns RESULT_COMPLETE or RESULT_ERROR.
	 * Each call processes files for ~10 seconds, then saves state and returns.
	 *
	 * @param string        $job_id            Job ID for state persistence.
	 * @param array         $files             Array of file data ['path' => ..., 'relative' => ...].
	 * @param string        $output_path       Output archive path (.tar.gz).
	 * @param callable|null $progress_callback Progress callback (progress, message, processed, total).
	 * @return array{result: string, progress: float, processed: int, total: int, error?: string, path?: string}
	 */
	public function run_chunk(
		string $job_id,
		array $files,
		string $output_path,
		?callable $progress_callback = null
	): array {
		$this->job_id            = $job_id;
		$this->output_path       = $output_path;
		$this->tar_path          = preg_replace( '/\.gz$/', '', $output_path );
		$this->progress_callback = $progress_callback;
		$this->total_files       = count( $files );
		$this->start_time        = microtime( true );
		$this->end_time          = $this->start_time + self::TIME_SLICE;

		// Restore state from previous chunk.
		$this->restore_state();

		$this->logger->info( 'Starting streaming backup chunk', array(
			'job_id'       => $job_id,
			'file_index'   => $this->file_index,
			'byte_offset'  => $this->byte_offset,
			'total_files'  => $this->total_files,
			'time_slice'   => self::TIME_SLICE,
		) );

		// Open tar file for append.
		if ( ! $this->open_archive() ) {
			return $this->error_result( 'Failed to open archive: ' . $this->tar_path );
		}

		// Process files until time slice expires or all done.
		while ( $this->file_index < $this->total_files ) {
			// Check time limit (with 0.5s buffer for cleanup).
			if ( microtime( true ) >= ( $this->end_time - 0.5 ) ) {
				$this->close_archive( false );
				$this->save_state();

				$this->logger->info( 'Chunk time limit reached, saving state', array(
					'file_index'  => $this->file_index,
					'byte_offset' => $this->byte_offset,
					'processed'   => $this->processed_files,
					'elapsed'     => round( microtime( true ) - $this->start_time, 2 ),
				) );

				return $this->continue_result();
			}

			$file = $files[ $this->file_index ];
			$result = $this->process_file( $file );

			if ( ! $result ) {
				// File processing failed but we continue (skip bad files).
				$this->logger->warning( 'Skipping unprocessable file', array(
					'path' => $file['path'] ?? 'unknown',
				) );
				$this->file_index++;
				$this->byte_offset = 0;
				continue;
			}

			// Check if file is fully written.
			if ( 0 === $this->byte_offset ) {
				$this->processed_files++;
				$this->file_index++;

				// Report progress every 100 files or every second.
				if ( $this->processed_files % 100 === 0 ) {
					$this->report_progress();
				}
			}
		}

		// All files processed - finalize archive.
		$this->finalize_archive();
		$this->cleanup_state();

		$this->logger->info( 'Streaming backup completed', array(
			'total_files' => $this->total_files,
			'output_path' => $this->output_path,
			'elapsed'     => round( microtime( true ) - $this->start_time, 2 ),
		) );

		return array(
			'result'    => self::RESULT_COMPLETE,
			'progress'  => 100.0,
			'processed' => $this->total_files,
			'total'     => $this->total_files,
			'path'      => $this->output_path,
			'size'      => file_exists( $this->output_path ) ? filesize( $this->output_path ) : 0,
		);
	}

	/**
	 * Process a single file (or chunk of large file).
	 *
	 * @param array $file File data with 'path' and 'relative' keys.
	 * @return bool True if processed (even partially), false to skip.
	 */
	private function process_file( array $file ): bool {
		$path     = $file['path'] ?? '';
		$relative = $file['relative'] ?? '';

		if ( empty( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// Skip directories (we only archive files).
		if ( is_dir( $path ) ) {
			return false;
		}

		$file_size = filesize( $path );

		// Write tar header for new files (byte_offset = 0).
		if ( 0 === $this->byte_offset ) {
			$this->write_tar_header( $relative, $file_size, filemtime( $path ) ?: time() );
		}

		// Handle empty files (0 bytes) - header already written, no content needed.
		if ( 0 === $file_size ) {
			// No content or padding needed for empty files (0 % 512 = 0).
			$this->byte_offset = 0;
			return true;
		}

		// Open file for reading.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		// Seek to current offset for resumed files.
		if ( $this->byte_offset > 0 ) {
			fseek( $handle, $this->byte_offset );
		}

		// Read and write one chunk.
		$bytes_remaining = $file_size - $this->byte_offset;
		$to_read         = min( self::CHUNK_SIZE, $bytes_remaining );

		// Safety check: ensure we have bytes to read.
		if ( $to_read <= 0 ) {
			fclose( $handle );
			$this->byte_offset = 0;
			return true;
		}

		$content = fread( $handle, $to_read );

		fclose( $handle );

		if ( false === $content ) {
			return false;
		}

		// Write to archive.
		fwrite( $this->archive_handle, $content );
		$this->byte_offset += strlen( $content );

		// Check if file is complete.
		if ( $this->byte_offset >= $file_size ) {
			// Pad to 512-byte boundary.
			$padding = ( 512 - ( $file_size % 512 ) ) % 512;
			if ( $padding > 0 ) {
				fwrite( $this->archive_handle, str_repeat( "\0", $padding ) );
			}

			// Reset offset for next file.
			$this->byte_offset = 0;
		}

		return true;
	}

	/**
	 * Write a tar header.
	 *
	 * @param string $name  File name in archive.
	 * @param int    $size  File size in bytes.
	 * @param int    $mtime Modification timestamp.
	 * @return void
	 */
	private function write_tar_header( string $name, int $size, int $mtime ): void {
		// Handle long filenames (>100 chars) with extended header.
		if ( strlen( $name ) > 100 ) {
			$this->write_long_name_header( $name );
		}

		// Build 512-byte header.
		$header = str_repeat( "\0", 512 );

		// File name (100 bytes) - truncate if needed.
		$name_bytes = substr( $name, 0, 100 );
		$header = substr_replace( $header, $name_bytes, 0, strlen( $name_bytes ) );

		// File mode (8 bytes) - 0644.
		$header = substr_replace( $header, sprintf( '%07o', 0644 ), 100, 7 );

		// UID (8 bytes) - 0.
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 108, 7 );

		// GID (8 bytes) - 0.
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 116, 7 );

		// File size (12 bytes).
		$header = substr_replace( $header, sprintf( '%011o', $size ), 124, 11 );

		// Modification time (12 bytes).
		$header = substr_replace( $header, sprintf( '%011o', $mtime ), 136, 11 );

		// Checksum placeholder (8 spaces).
		$header = substr_replace( $header, '        ', 148, 8 );

		// Type flag - '0' for regular file.
		$header[156] = '0';

		// USTAR magic.
		$header = substr_replace( $header, 'ustar', 257, 5 );
		$header[262] = "\0";
		$header = substr_replace( $header, '00', 263, 2 );

		// Calculate and set checksum.
		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}
		$header = substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );

		fwrite( $this->archive_handle, $header );
	}

	/**
	 * Write extended header for long filenames.
	 *
	 * @param string $name Full filename.
	 * @return void
	 */
	private function write_long_name_header( string $name ): void {
		$name_data = $name . "\0";
		$name_size = strlen( $name_data );

		// Build PAX extended header.
		$header = str_repeat( "\0", 512 );

		// Special name for extended header.
		$header = substr_replace( $header, '././@LongLink', 0, 13 );

		// File mode.
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 100, 7 );

		// UID/GID.
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 108, 7 );
		$header = substr_replace( $header, sprintf( '%07o', 0 ), 116, 7 );

		// Size of name data.
		$header = substr_replace( $header, sprintf( '%011o', $name_size ), 124, 11 );

		// Mtime.
		$header = substr_replace( $header, sprintf( '%011o', 0 ), 136, 11 );

		// Checksum placeholder.
		$header = substr_replace( $header, '        ', 148, 8 );

		// Type 'L' for long name.
		$header[156] = 'L';

		// Calculate checksum.
		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}
		$header = substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );

		fwrite( $this->archive_handle, $header );

		// Write name data padded to 512 bytes.
		fwrite( $this->archive_handle, $name_data );
		$padding = ( 512 - ( $name_size % 512 ) ) % 512;
		if ( $padding > 0 ) {
			fwrite( $this->archive_handle, str_repeat( "\0", $padding ) );
		}
	}

	/**
	 * Open archive file for writing/appending.
	 *
	 * @return bool True on success.
	 */
	private function open_archive(): bool {
		// Ensure output directory exists.
		$dir = dirname( $this->tar_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Append if file exists and we're resuming, otherwise create new.
		$mode = ( $this->file_index > 0 || $this->byte_offset > 0 ) ? 'ab' : 'wb';

		$this->archive_handle = fopen( $this->tar_path, $mode );

		return false !== $this->archive_handle;
	}

	/**
	 * Close archive file.
	 *
	 * @param bool $finalize Whether to finalize (add EOF markers).
	 * @return void
	 */
	private function close_archive( bool $finalize ): void {
		if ( ! $this->archive_handle ) {
			return;
		}

		if ( $finalize ) {
			// Write two 512-byte null blocks for tar EOF.
			fwrite( $this->archive_handle, str_repeat( "\0", 1024 ) );
		}

		fclose( $this->archive_handle );
		$this->archive_handle = null;
	}

	/**
	 * Finalize archive (add EOF and compress).
	 *
	 * @return void
	 */
	private function finalize_archive(): void {
		$this->close_archive( true );

		// Compress the tar file to tar.gz.
		$this->compress_archive();
	}

	/**
	 * Compress tar to tar.gz using streaming.
	 *
	 * @return bool True on success.
	 */
	private function compress_archive(): bool {
		if ( ! file_exists( $this->tar_path ) ) {
			return false;
		}

		$tar_size = filesize( $this->tar_path );

		$this->logger->info( 'Compressing archive', array(
			'tar_path' => $this->tar_path,
			'tar_size' => $tar_size,
		) );

		// Stream compress to gzip.
		$in  = fopen( $this->tar_path, 'rb' );
		$out = gzopen( $this->output_path, 'wb6' ); // Level 6 compression.

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
			$chunk = fread( $in, self::CHUNK_SIZE );
			if ( false !== $chunk ) {
				gzwrite( $out, $chunk );
			}
		}

		fclose( $in );
		gzclose( $out );

		// Remove uncompressed tar.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $this->tar_path );

		$gz_size = filesize( $this->output_path );

		$this->logger->info( 'Archive compressed', array(
			'output_path' => $this->output_path,
			'gz_size'     => $gz_size,
			'ratio'       => $tar_size > 0 ? round( ( 1 - $gz_size / $tar_size ) * 100, 1 ) . '%' : 'N/A',
		) );

		return true;
	}

	/**
	 * Save current state for resuming.
	 *
	 * @return void
	 */
	private function save_state(): void {
		$state_data = array(
			'file_index'      => $this->file_index,
			'byte_offset'     => $this->byte_offset,
			'processed_files' => $this->processed_files,
			'tar_path'        => $this->tar_path,
			'output_path'     => $this->output_path,
		);

		$this->state->save_meta( $this->job_id, 'streaming_tar_state', $state_data );
	}

	/**
	 * Restore state from previous chunk.
	 *
	 * @return void
	 */
	private function restore_state(): void {
		$state_data = $this->state->get_meta( $this->job_id, 'streaming_tar_state' );

		if ( $state_data ) {
			$this->file_index      = $state_data['file_index'] ?? 0;
			$this->byte_offset     = $state_data['byte_offset'] ?? 0;
			$this->processed_files = $state_data['processed_files'] ?? 0;

			$this->logger->debug( 'Restored streaming backup state', $state_data );
		}
	}

	/**
	 * Clean up state after completion.
	 *
	 * @return void
	 */
	private function cleanup_state(): void {
		$this->state->delete_meta( $this->job_id, 'streaming_tar_state' );
	}

	/**
	 * Report progress via callback.
	 *
	 * @return void
	 */
	private function report_progress(): void {
		if ( ! $this->progress_callback ) {
			return;
		}

		$progress = $this->total_files > 0
			? ( $this->processed_files / $this->total_files ) * 100
			: 0;

		$message = sprintf(
			'Backing up files... %d/%d (%.2f%%)',
			$this->processed_files,
			$this->total_files,
			$progress
		);

		( $this->progress_callback )( $progress, $message, $this->processed_files, $this->total_files );
	}

	/**
	 * Build error result array.
	 *
	 * @param string $error Error message.
	 * @return array Result array.
	 */
	private function error_result( string $error ): array {
		$this->last_error = $error;
		$this->logger->error( 'Streaming backup error', array( 'error' => $error ) );

		return array(
			'result'    => self::RESULT_ERROR,
			'progress'  => $this->get_progress(),
			'processed' => $this->processed_files,
			'total'     => $this->total_files,
			'error'     => $error,
		);
	}

	/**
	 * Build continue result array.
	 *
	 * @return array Result array.
	 */
	private function continue_result(): array {
		return array(
			'result'      => self::RESULT_CONTINUE,
			'progress'    => $this->get_progress(),
			'processed'   => $this->processed_files,
			'total'       => $this->total_files,
			'file_index'  => $this->file_index,
			'byte_offset' => $this->byte_offset,
		);
	}

	/**
	 * Get current progress percentage.
	 *
	 * @return float Progress 0-100.
	 */
	private function get_progress(): float {
		if ( $this->total_files <= 0 ) {
			return 0.0;
		}

		return ( $this->processed_files / $this->total_files ) * 100;
	}

	/**
	 * Get last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Reset state for a fresh backup.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function reset( string $job_id ): void {
		$this->state->delete_meta( $job_id, 'streaming_tar_state' );
	}
}
