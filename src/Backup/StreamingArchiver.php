<?php
/**
 * Streaming Archiver - Resumable ZIP creation for large sites.
 *
 * Inspired by All-in-One Migration's approach:
 * - Time-based chunking (breaks after timeout, resumes next request)
 * - File list stored on disk (not in memory)
 * - Tracks multiple offsets for resumability
 * - Streaming writes in small chunks
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming archiver that creates ZIP files incrementally with resumability.
 */
final class StreamingArchiver {

	/**
	 * Default timeout in seconds before yielding control.
	 */
	private const DEFAULT_TIMEOUT = 10;

	/**
	 * Chunk size for reading files (512KB).
	 */
	private const CHUNK_SIZE = 524288;

	/**
	 * Archive file path.
	 *
	 * @var string
	 */
	private string $archive_path;

	/**
	 * ZipArchive instance.
	 *
	 * @var \ZipArchive|null
	 */
	private ?\ZipArchive $zip = null;

	/**
	 * State file path for tracking progress.
	 *
	 * @var string
	 */
	private string $state_file;

	/**
	 * Constructor.
	 *
	 * @param string $archive_path Path to the ZIP archive.
	 * @param string $state_file   Path to state file for tracking progress.
	 */
	public function __construct( string $archive_path, string $state_file ) {
		$this->archive_path = $archive_path;
		$this->state_file   = $state_file;
	}

	/**
	 * Create or continue creating an archive from a file list.
	 *
	 * @param string        $file_list_path Path to file containing list of files (one per line).
	 * @param string        $base_path      Base path to strip from file paths in archive.
	 * @param callable|null $progress_cb    Progress callback(int $processed, int $total, string $current_file).
	 * @param int           $timeout        Timeout in seconds before returning.
	 * @return array{completed: bool, processed: int, total: int, error?: string}
	 */
	public function create_from_file_list(
		string $file_list_path,
		string $base_path,
		?callable $progress_cb = null,
		int $timeout = self::DEFAULT_TIMEOUT
	): array {
		$start_time = microtime( true );

		// Load or initialize state.
		$state = $this->load_state();

		// Count total files if not already done.
		if ( 0 === $state['total'] ) {
			$state['total'] = $this->count_lines( $file_list_path );
			$this->save_state( $state );
		}

		// Open the file list.
		$file_list_handle = fopen( $file_list_path, 'r' );
		if ( false === $file_list_handle ) {
			return array(
				'completed' => false,
				'processed' => $state['processed'],
				'total'     => $state['total'],
				'error'     => 'Failed to open file list',
			);
		}

		// Seek to where we left off.
		if ( $state['file_list_offset'] > 0 ) {
			fseek( $file_list_handle, $state['file_list_offset'] );
		}

		// Open ZIP archive.
		$this->zip = new \ZipArchive();
		$zip_flags = file_exists( $this->archive_path ) ? \ZipArchive::CHECKCONS : \ZipArchive::CREATE;

		$result = $this->zip->open( $this->archive_path, $zip_flags );
		if ( true !== $result ) {
			fclose( $file_list_handle );
			return array(
				'completed' => false,
				'processed' => $state['processed'],
				'total'     => $state['total'],
				'error'     => 'Failed to open ZIP archive: ' . $result,
			);
		}

		$completed = true;
		$base_path = rtrim( $base_path, '/' ) . '/';
		$base_len  = strlen( $base_path );

		// Process files.
		while ( ( $line = fgets( $file_list_handle ) ) !== false ) {
			$file_path = trim( $line );

			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				// Skip empty lines or missing files.
				$state['file_list_offset'] = ftell( $file_list_handle );
				continue;
			}

			// Calculate relative path for archive.
			$relative_path = $file_path;
			if ( strpos( $file_path, $base_path ) === 0 ) {
				$relative_path = substr( $file_path, $base_len );
			}

			// Add file to archive.
			if ( is_file( $file_path ) && is_readable( $file_path ) ) {
				// For large files, we add them directly (ZipArchive handles streaming internally).
				$added = $this->zip->addFile( $file_path, $relative_path );

				if ( ! $added ) {
					// Try adding as string for problematic files.
					$content = file_get_contents( $file_path );
					if ( false !== $content ) {
						$this->zip->addFromString( $relative_path, $content );
					}
				}
			}

			$state['processed']++;
			$state['file_list_offset'] = ftell( $file_list_handle );

			// Report progress.
			if ( null !== $progress_cb ) {
				$progress_cb( $state['processed'], $state['total'], $relative_path );
			}

			// Check timeout.
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $timeout ) {
				$completed = false;
				break;
			}

			// Periodically save state and close/reopen ZIP to flush.
			if ( $state['processed'] % 100 === 0 ) {
				$this->save_state( $state );

				// Close and reopen to flush to disk.
				$this->zip->close();
				$this->zip = new \ZipArchive();
				$this->zip->open( $this->archive_path );
			}
		}

		// Check if we've reached the end.
		if ( feof( $file_list_handle ) ) {
			$completed = true;
		}

		// Save final state.
		$this->save_state( $state );

		// Close handles.
		fclose( $file_list_handle );
		$this->zip->close();
		$this->zip = null;

		// Clean up state file if completed.
		if ( $completed && file_exists( $this->state_file ) ) {
			unlink( $this->state_file );
		}

		return array(
			'completed' => $completed,
			'processed' => $state['processed'],
			'total'     => $state['total'],
		);
	}

	/**
	 * Add files incrementally to an existing archive.
	 *
	 * This method processes files in batches with timeout control.
	 *
	 * @param array         $files       Array of file paths to add.
	 * @param string        $base_path   Base path to strip from file paths.
	 * @param int           $start_index Starting index in the files array.
	 * @param callable|null $progress_cb Progress callback.
	 * @param int           $timeout     Timeout in seconds.
	 * @return array{completed: bool, last_index: int, processed: int}
	 */
	public function add_files_batch(
		array $files,
		string $base_path,
		int $start_index = 0,
		?callable $progress_cb = null,
		int $timeout = self::DEFAULT_TIMEOUT
	): array {
		$start_time = microtime( true );
		$total      = count( $files );
		$processed  = 0;
		$last_index = $start_index;

		// Open ZIP.
		$this->zip = new \ZipArchive();
		$zip_flags = file_exists( $this->archive_path ) ? 0 : \ZipArchive::CREATE;

		if ( true !== $this->zip->open( $this->archive_path, $zip_flags ) ) {
			return array(
				'completed'  => false,
				'last_index' => $start_index,
				'processed'  => 0,
				'error'      => 'Failed to open ZIP archive',
			);
		}

		$base_path = rtrim( $base_path, '/' ) . '/';
		$base_len  = strlen( $base_path );

		for ( $i = $start_index; $i < $total; $i++ ) {
			$file_path = $files[ $i ];

			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				$last_index = $i + 1;
				continue;
			}

			// Calculate relative path.
			$relative_path = $file_path;
			if ( strpos( $file_path, $base_path ) === 0 ) {
				$relative_path = substr( $file_path, $base_len );
			}

			// Add to archive.
			if ( is_file( $file_path ) ) {
				$this->zip->addFile( $file_path, $relative_path );
			}

			$processed++;
			$last_index = $i + 1;

			if ( null !== $progress_cb ) {
				$progress_cb( $last_index, $total, $relative_path );
			}

			// Check timeout.
			if ( ( microtime( true ) - $start_time ) >= $timeout ) {
				$this->zip->close();
				$this->zip = null;

				return array(
					'completed'  => false,
					'last_index' => $last_index,
					'processed'  => $processed,
				);
			}

			// Flush periodically.
			if ( $processed % 50 === 0 ) {
				$this->zip->close();
				$this->zip = new \ZipArchive();
				$this->zip->open( $this->archive_path );
			}
		}

		$this->zip->close();
		$this->zip = null;

		return array(
			'completed'  => true,
			'last_index' => $last_index,
			'processed'  => $processed,
		);
	}

	/**
	 * Load state from file.
	 *
	 * @return array{processed: int, total: int, file_list_offset: int}
	 */
	private function load_state(): array {
		$default = array(
			'processed'        => 0,
			'total'            => 0,
			'file_list_offset' => 0,
		);

		if ( ! file_exists( $this->state_file ) ) {
			return $default;
		}

		$content = file_get_contents( $this->state_file );
		if ( false === $content ) {
			return $default;
		}

		$state = json_decode( $content, true );
		if ( ! is_array( $state ) ) {
			return $default;
		}

		return array_merge( $default, $state );
	}

	/**
	 * Save state to file.
	 *
	 * @param array $state State to save.
	 * @return bool True on success.
	 */
	private function save_state( array $state ): bool {
		$content = wp_json_encode( $state );
		return false !== file_put_contents( $this->state_file, $content, LOCK_EX );
	}

	/**
	 * Count lines in a file efficiently.
	 *
	 * @param string $file_path Path to file.
	 * @return int Number of lines.
	 */
	private function count_lines( string $file_path ): int {
		$count  = 0;
		$handle = fopen( $file_path, 'r' );

		if ( false === $handle ) {
			return 0;
		}

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, 1048576 ); // 1MB chunks.
			if ( false !== $buffer ) {
				$count += substr_count( $buffer, "\n" );
			}
		}

		fclose( $handle );

		return $count;
	}

	/**
	 * Get current progress percentage.
	 *
	 * @return int Progress percentage (0-100).
	 */
	public function get_progress(): int {
		$state = $this->load_state();

		if ( 0 === $state['total'] ) {
			return 0;
		}

		return (int) min( ( $state['processed'] / $state['total'] ) * 100, 100 );
	}

	/**
	 * Reset archiver state.
	 *
	 * @return void
	 */
	public function reset(): void {
		if ( file_exists( $this->state_file ) ) {
			unlink( $this->state_file );
		}

		if ( file_exists( $this->archive_path ) ) {
			unlink( $this->archive_path );
		}
	}
}
