<?php
/**
 * Export AJAX Handler.
 *
 * Handles AJAX requests for the export process chaining.
 *
 * @package SwishMigrateAndBackup\Export
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Export;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * AJAX handler for export operations.
 */
class ExportAjaxHandler {

	/**
	 * Export controller.
	 *
	 * @var ExportController
	 */
	private ExportController $controller;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param ExportController $controller Export controller.
	 * @param Logger           $logger     Logger instance.
	 */
	public function __construct( ExportController $controller, Logger $logger ) {
		$this->controller = $controller;
		$this->logger = $logger;
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		// Start export - requires user capability.
		add_action( 'wp_ajax_swish_export_start', array( $this, 'handle_start' ) );

		// Process export - internal chaining (no capability check, uses nonce).
		add_action( 'wp_ajax_swish_export_process', array( $this, 'handle_process' ) );
		add_action( 'wp_ajax_nopriv_swish_export_process', array( $this, 'handle_process' ) );

		// Get export status.
		add_action( 'wp_ajax_swish_export_status', array( $this, 'handle_status' ) );

		// Cancel export.
		add_action( 'wp_ajax_swish_export_cancel', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Handle export start request.
	 *
	 * @return void
	 */
	public function handle_start(): void {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
			return;
		}

		// Verify nonce.
		if ( ! check_ajax_referer( 'swish_backup_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
			return;
		}

		// Get options from request.
		$options = array(
			'backup_database'   => ! empty( $_POST['backup_database'] ),
			'backup_plugins'    => ! empty( $_POST['backup_plugins'] ),
			'backup_themes'     => ! empty( $_POST['backup_themes'] ),
			'backup_uploads'    => ! empty( $_POST['backup_uploads'] ),
			'backup_mu_plugins' => ! empty( $_POST['backup_mu_plugins'] ),
			'exclude_patterns'  => isset( $_POST['exclude_patterns'] ) ? array_map( 'sanitize_text_field', (array) $_POST['exclude_patterns'] ) : array(),
		);

		// Generate job ID.
		$job_id = wp_generate_uuid4();

		$this->logger->set_job_id( $job_id );
		$this->logger->info( 'Starting export via AJAX', array( 'options' => $options ) );

		// Create job record in database.
		$this->create_job_record( $job_id, 'full' );

		// Start export.
		$state = $this->controller->start_export( $job_id, $options );

		wp_send_json_success( array(
			'job_id'  => $job_id,
			'status'  => 'started',
			'phase'   => $state['phase'],
			'message' => 'Export started. Processing...',
		) );
	}

	/**
	 * Handle export process request (chained).
	 *
	 * @return void
	 */
	public function handle_process(): void {
		// Get job ID.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing job ID' ), 400 );
			return;
		}

		// Verify nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'swish_export_' . $job_id ) ) {
			// Log but don't fail - nonce might have expired during long processing.
			$this->logger->warning( 'Export nonce verification failed', array( 'job_id' => $job_id ) );
		}

		// Process export phase.
		$state = $this->controller->process_export( $job_id );

		// Update job status.
		$this->update_job_progress( $job_id, $state );

		// Return minimal response (non-blocking requests don't care).
		wp_send_json_success( array(
			'phase'     => $state['phase'] ?? 'unknown',
			'completed' => ExportController::PHASE_COMPLETE === ( $state['phase'] ?? '' ),
		) );
	}

	/**
	 * Handle export status request.
	 *
	 * @return void
	 */
	public function handle_status(): void {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
			return;
		}

		// Get job ID.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing job ID' ), 400 );
			return;
		}

		// Get state from controller.
		$state = $this->controller->get_state( $job_id );

		if ( ! $state ) {
			// Try to get from database.
			$job = $this->get_job_record( $job_id );

			if ( ! $job ) {
				wp_send_json_error( array( 'message' => 'Export not found' ), 404 );
				return;
			}

			wp_send_json_success( array(
				'job_id'   => $job_id,
				'status'   => $job['status'],
				'progress' => (int) $job['progress'],
				'message'  => $job['error_message'] ?? '',
				'phase'    => 'complete',
			) );
			return;
		}

		// Calculate progress.
		$progress = $this->calculate_progress( $state );

		wp_send_json_success( array(
			'job_id'          => $job_id,
			'status'          => empty( $state['error'] ) ? 'processing' : 'failed',
			'phase'           => $state['phase'],
			'progress'        => $progress,
			'total_files'     => $state['total_files'],
			'processed_files' => $state['processed_files'],
			'total_size'      => size_format( $state['total_size'] ),
			'processed_size'  => size_format( $state['processed_size'] ),
			'error'           => $state['error'] ?? null,
			'archive_path'    => $state['archive_path'] ?? null,
			'archive_size'    => isset( $state['archive_size'] ) ? size_format( $state['archive_size'] ) : null,
			'completed'       => ExportController::PHASE_COMPLETE === $state['phase'],
		) );
	}

	/**
	 * Handle export cancel request.
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
			return;
		}

		// Verify nonce.
		if ( ! check_ajax_referer( 'swish_backup_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
			return;
		}

		// Get job ID.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing job ID' ), 400 );
			return;
		}

		// Get state and cleanup.
		$state = $this->controller->get_state( $job_id );

		if ( $state ) {
			// Delete temp files.
			if ( ! empty( $state['temp_dir'] ) && is_dir( $state['temp_dir'] ) ) {
				$this->cleanup_directory( $state['temp_dir'] );
			}

			// Delete partial archive.
			if ( ! empty( $state['archive_path'] ) && file_exists( $state['archive_path'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $state['archive_path'] );
			}
		}

		// Update job as cancelled.
		$this->fail_job( $job_id, 'Export cancelled by user' );

		$this->logger->info( 'Export cancelled', array( 'job_id' => $job_id ) );

		wp_send_json_success( array(
			'message' => 'Export cancelled',
		) );
	}

	/**
	 * Calculate progress percentage.
	 *
	 * @param array $state Export state.
	 * @return int Progress 0-100.
	 */
	private function calculate_progress( array $state ): int {
		$phase = $state['phase'] ?? '';

		switch ( $phase ) {
			case ExportController::PHASE_INIT:
				return 0;

			case ExportController::PHASE_ENUMERATE:
				return 5;

			case ExportController::PHASE_DATABASE:
				return 10;

			case ExportController::PHASE_CONTENT:
				if ( $state['total_files'] > 0 ) {
					$file_progress = $state['processed_files'] / $state['total_files'];
					return 15 + (int) ( $file_progress * 80 );
				}
				return 15;

			case ExportController::PHASE_FINALIZE:
				return 95;

			case ExportController::PHASE_COMPLETE:
				return empty( $state['error'] ) ? 100 : 0;

			default:
				return 0;
		}
	}

	/**
	 * Create job record in database.
	 *
	 * @param string $job_id Job ID.
	 * @param string $type   Backup type.
	 * @return void
	 */
	private function create_job_record( string $job_id, string $type ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'job_id'     => $job_id,
				'type'       => $type,
				'status'     => 'pending',
				'progress'   => 0,
				'started_at' => current_time( 'mysql', true ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Get job record from database.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	private function get_job_record( string $job_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		return $job ?: null;
	}

	/**
	 * Update job progress.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $state  Export state.
	 * @return void
	 */
	private function update_job_progress( string $job_id, array $state ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';
		$progress = $this->calculate_progress( $state );

		$data = array(
			'status'   => empty( $state['error'] ) ? 'processing' : 'failed',
			'progress' => $progress,
		);

		if ( ! empty( $state['error'] ) ) {
			$data['error_message'] = $state['error'];
		}

		if ( ExportController::PHASE_COMPLETE === $state['phase'] && empty( $state['error'] ) ) {
			$data['status'] = 'completed';
			$data['progress'] = 100;
			$data['completed_at'] = current_time( 'mysql', true );
			$data['file_path'] = $state['archive_path'] ?? '';
			$data['file_size'] = $state['archive_size'] ?? 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'job_id' => $job_id ) );
	}

	/**
	 * Fail job.
	 *
	 * @param string $job_id  Job ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private function fail_job( string $job_id, string $message ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'        => 'failed',
				'completed_at'  => current_time( 'mysql', true ),
				'error_message' => $message,
			),
			array( 'job_id' => $job_id )
		);
	}

	/**
	 * Cleanup directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function cleanup_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
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
		@rmdir( $dir );
	}
}
