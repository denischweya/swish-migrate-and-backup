<?php
/**
 * Export Controller - Non-blocking HTTP request chaining for exports.
 *
 * Orchestrates the backup process using chained HTTP requests
 * instead of WP Cron for faster and more reliable processing
 * on shared hosting.
 *
 * @package SwishMigrateAndBackup\Export
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Export;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Archive\SwishCompressor;
use SwishMigrateAndBackup\Archive\Exception\QuotaExceededException;
use SwishMigrateAndBackup\Archive\Exception\NotWritableException;
use SwishMigrateAndBackup\Logger\Logger;

/**
 * Controls export process with HTTP request chaining.
 */
class ExportController {

	/**
	 * Export phases in order.
	 */
	public const PHASE_INIT = 'init';
	public const PHASE_ENUMERATE = 'enumerate';
	public const PHASE_DATABASE = 'database';
	public const PHASE_CONTENT = 'content';
	public const PHASE_FINALIZE = 'finalize';
	public const PHASE_COMPLETE = 'complete';

	/**
	 * Phase order.
	 *
	 * @var array
	 */
	private const PHASE_ORDER = array(
		self::PHASE_INIT,
		self::PHASE_ENUMERATE,
		self::PHASE_DATABASE,
		self::PHASE_CONTENT,
		self::PHASE_FINALIZE,
		self::PHASE_COMPLETE,
	);

	/**
	 * Default timeout per phase (seconds).
	 */
	public const DEFAULT_TIMEOUT = 10;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Setup environment for long-running operations.
	 *
	 * @return void
	 */
	public static function setup_environment(): void {
		// Prevent client disconnects from stopping script.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		// Remove time limit.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );

		// Disable input time limit.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ini_set( 'max_input_time', '-1' );

		// Increase memory limit if possible.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ini_set( 'memory_limit', '512M' );

		// Disable output buffering issues.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Prevent regex backtrack issues.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ini_set( 'pcre.backtrack_limit', (string) PHP_INT_MAX );
	}

	/**
	 * Start a new export.
	 *
	 * @param string $job_id  Job ID.
	 * @param array  $options Export options.
	 * @return array Initial state.
	 */
	public function start_export( string $job_id, array $options = array() ): array {
		$state = array(
			'job_id'          => $job_id,
			'phase'           => self::PHASE_INIT,
			'options'         => $options,
			'started_at'      => time(),
			'archive_path'    => $this->get_archive_path( $job_id ),
			'filemap_path'    => $this->get_filemap_path( $job_id ),
			'temp_dir'        => $this->get_temp_dir( $job_id ),
			'archive_offset'  => 0,
			'file_offset'     => 0,
			'filemap_offset'  => 0,
			'dir_index'       => 0,
			'dir_offset'      => 0,
			'total_files'     => 0,
			'total_size'      => 0,
			'processed_files' => 0,
			'processed_size'  => 0,
			'db_table_index'  => 0,
			'db_table_offset' => 0,
			'error'           => null,
		);

		// Save initial state.
		$this->save_state( $job_id, $state );

		// Trigger first phase.
		$this->chain_next_request( $job_id );

		return $state;
	}

	/**
	 * Process export phase.
	 *
	 * Called by AJAX handler for each chained request.
	 *
	 * @param string $job_id Job ID.
	 * @return array Updated state.
	 */
	public function process_export( string $job_id ): array {
		self::setup_environment();

		$state = $this->get_state( $job_id );

		if ( ! $state ) {
			return array( 'error' => 'Export state not found' );
		}

		$this->logger->set_job_id( $job_id );

		try {
			$phase = $state['phase'];
			$timeout = apply_filters( 'swish_export_timeout', self::DEFAULT_TIMEOUT );

			$this->logger->debug( 'Processing export phase', array(
				'phase'   => $phase,
				'timeout' => $timeout,
			) );

			$state = match ( $phase ) {
				self::PHASE_INIT      => $this->phase_init( $state ),
				self::PHASE_ENUMERATE => $this->phase_enumerate( $state, $timeout ),
				self::PHASE_DATABASE  => $this->phase_database( $state, $timeout ),
				self::PHASE_CONTENT   => $this->phase_content( $state, $timeout ),
				self::PHASE_FINALIZE  => $this->phase_finalize( $state ),
				self::PHASE_COMPLETE  => $state, // Already done.
				default               => $state,
			};

			// Save updated state.
			$this->save_state( $job_id, $state );

			// Chain next request if not complete.
			if ( self::PHASE_COMPLETE !== $state['phase'] && empty( $state['error'] ) ) {
				$this->chain_next_request( $job_id );
			}

		} catch ( QuotaExceededException $e ) {
			$state['error'] = 'Disk quota exceeded: ' . $e->getMessage();
			$state['phase'] = self::PHASE_COMPLETE;
			$this->save_state( $job_id, $state );
			$this->logger->error( 'Export failed: quota exceeded', array( 'error' => $e->getMessage() ) );

		} catch ( NotWritableException $e ) {
			$state['error'] = 'Cannot write to disk: ' . $e->getMessage();
			$state['phase'] = self::PHASE_COMPLETE;
			$this->save_state( $job_id, $state );
			$this->logger->error( 'Export failed: not writable', array( 'error' => $e->getMessage() ) );

		} catch ( \Exception $e ) {
			$state['error'] = $e->getMessage();
			$state['phase'] = self::PHASE_COMPLETE;
			$this->save_state( $job_id, $state );
			$this->logger->error( 'Export failed', array( 'error' => $e->getMessage() ) );
		}

		return $state;
	}

	/**
	 * Phase: Initialize export.
	 *
	 * @param array $state Current state.
	 * @return array Updated state.
	 */
	private function phase_init( array $state ): array {
		// Create temp directory.
		$temp_dir = $state['temp_dir'];
		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Create archive.
		$compressor = new SwishCompressor( $state['archive_path'] );
		if ( ! $compressor->open() ) {
			$state['error'] = 'Cannot create archive file';
			$state['phase'] = self::PHASE_COMPLETE;
			return $state;
		}
		$compressor->close();

		$this->logger->info( 'Export initialized', array(
			'archive' => $state['archive_path'],
		) );

		// Move to next phase.
		$state['phase'] = self::PHASE_ENUMERATE;

		return $state;
	}

	/**
	 * Phase: Enumerate files.
	 *
	 * @param array $state   Current state.
	 * @param int   $timeout Timeout seconds.
	 * @return array Updated state.
	 */
	private function phase_enumerate( array $state, int $timeout ): array {
		$options = $state['options'];

		// Build directory list based on options.
		$directories = $this->get_backup_directories( $options );

		$enumerator = new FileEnumerator( $state['filemap_path'] );

		if ( ! empty( $options['exclude_patterns'] ) ) {
			$enumerator->set_exclude_patterns( $options['exclude_patterns'] );
		}

		$result = $enumerator->enumerate_directories(
			$directories,
			$state['dir_index'],
			$state['dir_offset'],
			$timeout
		);

		$enumerator->close();

		// Update state.
		$state['dir_index'] = $result['dir_index'];
		$state['dir_offset'] = $result['dir_offset'];
		$state['total_files'] += $result['files_found'];
		$state['total_size'] += $result['total_size'];

		if ( $result['completed'] ) {
			$this->logger->info( 'File enumeration complete', array(
				'total_files' => $state['total_files'],
				'total_size'  => size_format( $state['total_size'] ),
			) );

			// Move to database phase.
			$state['phase'] = self::PHASE_DATABASE;
		}

		return $state;
	}

	/**
	 * Phase: Export database.
	 *
	 * @param array $state   Current state.
	 * @param int   $timeout Timeout seconds.
	 * @return array Updated state.
	 */
	private function phase_database( array $state, int $timeout ): array {
		$options = $state['options'];

		// Skip database if not requested.
		if ( isset( $options['backup_database'] ) && false === $options['backup_database'] ) {
			$state['phase'] = self::PHASE_CONTENT;
			return $state;
		}

		$db_file = $state['temp_dir'] . '/database.sql';
		$start_time = microtime( true );

		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$table_count = count( $tables );

		// Open or append to database file.
		$mode = ( 0 === $state['db_table_index'] && 0 === $state['db_table_offset'] ) ? 'wb' : 'ab';
		$handle = fopen( $db_file, $mode );

		if ( ! $handle ) {
			$state['error'] = 'Cannot create database dump file';
			$state['phase'] = self::PHASE_COMPLETE;
			return $state;
		}

		// Write header on first run.
		if ( 0 === $state['db_table_index'] && 0 === $state['db_table_offset'] ) {
			fwrite( $handle, "-- Swish Backup Database Dump\n" );
			fwrite( $handle, "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
			fwrite( $handle, "-- Server: " . DB_HOST . "\n" );
			fwrite( $handle, "-- Database: " . DB_NAME . "\n\n" );
			fwrite( $handle, "SET NAMES utf8mb4;\n" );
			fwrite( $handle, "SET foreign_key_checks = 0;\n\n" );
		}

		// Process tables.
		for ( $i = $state['db_table_index']; $i < $table_count; $i++ ) {
			$table = $tables[ $i ];

			// Check timeout.
			if ( ( microtime( true ) - $start_time ) > $timeout ) {
				fclose( $handle );
				$state['db_table_index'] = $i;
				return $state;
			}

			// Write table structure.
			if ( 0 === $state['db_table_offset'] ) {
				fwrite( $handle, "-- Table: {$table}\n" );
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
				if ( $create ) {
					fwrite( $handle, $create[1] . ";\n\n" );
				}
			}

			// Export data in chunks.
			$batch_size = 500;
			$offset = $state['db_table_offset'];

			while ( true ) {
				// Check timeout.
				if ( ( microtime( true ) - $start_time ) > $timeout ) {
					fclose( $handle );
					$state['db_table_index'] = $i;
					$state['db_table_offset'] = $offset;
					return $state;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				), ARRAY_A );

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$values = array_map( function ( $v ) use ( $wpdb ) {
						if ( null === $v ) {
							return 'NULL';
						}
						return "'" . $wpdb->_real_escape( $v ) . "'";
					}, $row );

					$columns = '`' . implode( '`, `', array_keys( $row ) ) . '`';
					fwrite( $handle, "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n" );
				}

				$offset += $batch_size;

				// Free memory.
				unset( $rows );
			}

			// Reset offset for next table.
			$state['db_table_offset'] = 0;

			fwrite( $handle, "\n" );
		}

		// Finalize database dump.
		fwrite( $handle, "SET foreign_key_checks = 1;\n" );
		fclose( $handle );

		$this->logger->info( 'Database export complete', array(
			'tables' => $table_count,
			'size'   => size_format( filesize( $db_file ) ),
		) );

		// Move to content phase.
		$state['phase'] = self::PHASE_CONTENT;
		$state['db_table_index'] = 0;
		$state['db_table_offset'] = 0;

		return $state;
	}

	/**
	 * Phase: Archive content.
	 *
	 * @param array $state   Current state.
	 * @param int   $timeout Timeout seconds.
	 * @return array Updated state.
	 */
	private function phase_content( array $state, int $timeout ): array {
		$start_time = microtime( true );

		// Open archive at current offset.
		$compressor = new SwishCompressor( $state['archive_path'] );
		if ( ! $compressor->open_at_offset( $state['archive_offset'] ) ) {
			$state['error'] = 'Cannot open archive for writing';
			$state['phase'] = self::PHASE_COMPLETE;
			return $state;
		}

		// First, add database dump if it exists.
		$db_file = $state['temp_dir'] . '/database.sql';
		if ( file_exists( $db_file ) && 0 === $state['filemap_offset'] && 0 === $state['file_offset'] ) {
			$db_offset = 0;
			$db_bytes = 0;
			$completed = $compressor->add_file( $db_file, 'database.sql', $db_offset, $db_bytes, $timeout );

			$state['archive_offset'] = $compressor->get_bytes_written();

			if ( ! $completed ) {
				// Database file still processing.
				$compressor->flush();
				$compressor->close();
				return $state;
			}
		}

		// Read files from filemap.
		$enumerator = new FileEnumerator( $state['filemap_path'] );
		$batch_size = 50;

		while ( true ) {
			// Check timeout.
			$remaining_time = $timeout - ( microtime( true ) - $start_time );
			if ( $remaining_time <= 1 ) {
				break;
			}

			$files = $enumerator->read_filemap( $state['filemap_offset'], $batch_size );

			if ( empty( $files ) ) {
				// All files processed.
				$state['phase'] = self::PHASE_FINALIZE;
				break;
			}

			foreach ( $files as $file ) {
				// Check timeout.
				$remaining_time = $timeout - ( microtime( true ) - $start_time );
				if ( $remaining_time <= 1 ) {
					break 2;
				}

				$bytes_written = 0;
				$completed = $compressor->add_file(
					$file['path'],
					$file['relative'],
					$state['file_offset'],
					$bytes_written,
					(int) $remaining_time
				);

				$state['processed_size'] += $bytes_written;

				if ( ! $completed ) {
					// File still processing (mid-file timeout).
					$state['archive_offset'] = $compressor->get_bytes_written();
					$compressor->flush();
					$compressor->close();
					return $state;
				}

				// File completed.
				$state['file_offset'] = 0;
				++$state['filemap_offset'];
				++$state['processed_files'];
			}
		}

		$state['archive_offset'] = $compressor->get_bytes_written();
		$compressor->flush();
		$compressor->close();

		return $state;
	}

	/**
	 * Phase: Finalize archive.
	 *
	 * @param array $state Current state.
	 * @return array Updated state.
	 */
	private function phase_finalize( array $state ): array {
		// Add manifest.
		$manifest = array(
			'version'         => SWISH_BACKUP_VERSION,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'site_url'        => get_site_url(),
			'home_url'        => get_home_url(),
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'total_files'     => $state['total_files'],
			'total_size'      => $state['total_size'],
			'processed_files' => $state['processed_files'],
			'options'         => $state['options'],
		);

		$compressor = new SwishCompressor( $state['archive_path'] );
		if ( ! $compressor->open_at_offset( $state['archive_offset'] ) ) {
			$state['error'] = 'Cannot open archive to finalize';
			$state['phase'] = self::PHASE_COMPLETE;
			return $state;
		}

		// Add manifest.
		$compressor->add_content( wp_json_encode( $manifest, JSON_PRETTY_PRINT ), 'manifest.json' );

		// Finalize with EOF marker.
		$compressor->finalize();
		$compressor->close();

		// Cleanup temp files.
		$this->cleanup_temp( $state['temp_dir'] );
		$this->cleanup_temp( $state['filemap_path'] );

		$state['phase'] = self::PHASE_COMPLETE;
		$state['completed_at'] = time();
		$state['archive_size'] = filesize( $state['archive_path'] );

		$this->logger->info( 'Export complete', array(
			'archive_path' => $state['archive_path'],
			'archive_size' => size_format( $state['archive_size'] ),
			'total_files'  => $state['processed_files'],
		) );

		return $state;
	}

	/**
	 * Chain next HTTP request.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function chain_next_request( string $job_id ): void {
		$url = admin_url( 'admin-ajax.php' );

		$args = array(
			'timeout'   => 5,
			'blocking'  => false, // Non-blocking - don't wait for response.
			'sslverify' => apply_filters( 'swish_export_sslverify', false ),
			'body'      => array(
				'action' => 'swish_export_process',
				'job_id' => $job_id,
				'nonce'  => wp_create_nonce( 'swish_export_' . $job_id ),
			),
		);

		wp_remote_post( $url, $args );
	}

	/**
	 * Get backup directories based on options.
	 *
	 * @param array $options Backup options.
	 * @return array Directories to backup.
	 */
	private function get_backup_directories( array $options ): array {
		$directories = array();

		if ( $options['backup_plugins'] ?? true ) {
			$directories[] = WP_PLUGIN_DIR;
		}

		if ( $options['backup_themes'] ?? true ) {
			$directories[] = get_theme_root();
		}

		if ( $options['backup_uploads'] ?? true ) {
			$upload_dir = wp_upload_dir();
			$directories[] = $upload_dir['basedir'];
		}

		if ( $options['backup_mu_plugins'] ?? false ) {
			if ( is_dir( WPMU_PLUGIN_DIR ) ) {
				$directories[] = WPMU_PLUGIN_DIR;
			}
		}

		return array_filter( $directories, 'is_dir' );
	}

	/**
	 * Get archive path for job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private function get_archive_path( string $job_id ): string {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		$site_name = sanitize_file_name( wp_parse_url( get_site_url(), PHP_URL_HOST ) );
		$timestamp = gmdate( 'Y-m-d-His' );

		return $backup_dir . "/{$site_name}-{$timestamp}.swish";
	}

	/**
	 * Get filemap path for job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private function get_filemap_path( string $job_id ): string {
		return WP_CONTENT_DIR . '/swish-backups/temp/' . $job_id . '/filemap.list';
	}

	/**
	 * Get temp directory for job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private function get_temp_dir( string $job_id ): string {
		return WP_CONTENT_DIR . '/swish-backups/temp/' . $job_id;
	}

	/**
	 * Save export state.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $state  State data.
	 * @return bool
	 */
	private function save_state( string $job_id, array $state ): bool {
		$state_file = $this->get_temp_dir( $job_id ) . '/state.json';
		$dir = dirname( $state_file );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$result = file_put_contents( $state_file, wp_json_encode( $state ) );

		return false !== $result;
	}

	/**
	 * Get export state.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_state( string $job_id ): ?array {
		$state_file = $this->get_temp_dir( $job_id ) . '/state.json';

		if ( ! file_exists( $state_file ) ) {
			return null;
		}

		$content = file_get_contents( $state_file );
		if ( ! $content ) {
			return null;
		}

		return json_decode( $content, true );
	}

	/**
	 * Cleanup temporary files.
	 *
	 * @param string $path Path to cleanup.
	 * @return void
	 */
	private function cleanup_temp( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $file->getRealPath() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $path );
	}
}
