<?php
/**
 * REST API Controller.
 *
 * @package SwishMigrateAndBackup\Api
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Api;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Import\ImportPipeline;
use SwishMigrateAndBackup\Import\ImportSession;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Queue\JobQueue;
use SwishMigrateAndBackup\Queue\Scheduler;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Storage\StorageManager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API controller for backup operations.
 */
final class RestController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'swish-backup/v1';

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private BackupManager $backup_manager;

	/**
	 * Restore manager.
	 *
	 * @var RestoreManager
	 */
	private RestoreManager $restore_manager;

	/**
	 * Migrator.
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Job queue.
	 *
	 * @var JobQueue
	 */
	private JobQueue $job_queue;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param RestoreManager $restore_manager Restore manager.
	 * @param Migrator       $migrator        Migrator.
	 * @param StorageManager $storage_manager Storage manager.
	 * @param JobQueue       $job_queue       Job queue.
	 * @param Scheduler      $scheduler       Scheduler.
	 */
	public function __construct(
		BackupManager $backup_manager,
		RestoreManager $restore_manager,
		Migrator $migrator,
		StorageManager $storage_manager,
		JobQueue $job_queue,
		Scheduler $scheduler
	) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->migrator        = $migrator;
		$this->storage_manager = $storage_manager;
		$this->job_queue       = $job_queue;
		$this->scheduler       = $scheduler;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Backup routes.
		register_rest_route(
			$this->namespace,
			'/backup',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_backup' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'type'            => array(
							'type'    => 'string',
							'enum'    => array( 'full', 'database', 'files' ),
							'default' => 'full',
						),
						'async'           => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'db_batch_size'   => array(
							'type'              => 'integer',
							'default'           => 200,
							'minimum'           => 50,
							'maximum'           => 2000,
							'sanitize_callback' => 'absint',
						),
						'file_batch_size' => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 25,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/backups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backups' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/backup/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/backup/(?P<id>[a-zA-Z0-9_-]+)/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_download_url' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Restore routes.
		register_rest_route(
			$this->namespace,
			'/restore',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_backup' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'backup_id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// Migration routes.
		register_rest_route(
			$this->namespace,
			'/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_backup' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/list',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_importable_backups' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/migrate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_migration' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/search-replace',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'search_replace' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'search'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'replace' => array(
							'type'     => 'string',
							'required' => true,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		// Storage routes.
		register_rest_route(
			$this->namespace,
			'/storage/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_storage' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'adapter' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// Job status route.
		register_rest_route(
			$this->namespace,
			'/job/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job_status' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Process pending job (fallback for hosts where WP Cron doesn't trigger immediately).
		register_rest_route(
			$this->namespace,
			'/job/(?P<id>[a-zA-Z0-9_-]+)/process',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_pending_job' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Cancel a running or pending job.
		register_rest_route(
			$this->namespace,
			'/job/(?P<id>[a-zA-Z0-9_-]+)/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_job' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Settings routes.
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Dashboard stats route.
		register_rest_route(
			$this->namespace,
			'/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_dashboard_stats' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Folder structure route for granular backup selection.
		register_rest_route(
			$this->namespace,
			'/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_folder_structure' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Pipeline-based backup routes (queue-based, chunked processing).
		register_rest_route(
			$this->namespace,
			'/pipeline/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pipeline_start' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'type' => array(
							'type'    => 'string',
							'enum'    => array( 'full', 'database', 'files' ),
							'default' => 'full',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pipeline/continue/(?P<job_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pipeline_continue' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pipeline/status/(?P<job_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'pipeline_status' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Schedule routes.
		register_rest_route(
			$this->namespace,
			'/schedule/(?P<id>\d+)/toggle',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_schedule' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/schedule/(?P<id>\d+)/run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_schedule' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/schedule/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_schedule' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Import pipeline routes (chunked, session-based imports).
		register_rest_route(
			$this->namespace,
			'/import/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_pipeline_start' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'backup_path' => array(
							'type'     => 'string',
							'required' => true,
						),
						'old_url'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'new_url'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'restore_database' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'restore_files'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/continue',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_pipeline_continue' ),
					'permission_callback' => array( $this, 'check_import_secret_key' ),
					'args'                => array(
						'secret_key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'import_pipeline_status' ),
					'permission_callback' => array( $this, 'check_import_secret_key_or_admin' ),
					'args'                => array(
						'secret_key' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Check admin permission.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'swish-migrate-and-backup' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check import secret key permission.
	 *
	 * This allows import operations to continue even after database restore
	 * when WordPress sessions are invalidated.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_import_secret_key( WP_REST_Request $request ) {
		$secret_key = $request->get_param( 'secret_key' );

		if ( empty( $secret_key ) ) {
			return new WP_Error(
				'missing_secret_key',
				__( 'Import secret key is required.', 'swish-migrate-and-backup' ),
				array( 'status' => 401 )
			);
		}

		if ( ! ImportSession::verify_secret_key( $secret_key ) ) {
			return new WP_Error(
				'invalid_secret_key',
				__( 'Invalid or expired import session.', 'swish-migrate-and-backup' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check import secret key OR admin permission.
	 *
	 * Allows both authenticated admins and valid import sessions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_import_secret_key_or_admin( WP_REST_Request $request ) {
		// First try admin permission.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Then try secret key.
		$secret_key = $request->get_param( 'secret_key' );
		if ( ! empty( $secret_key ) && ImportSession::verify_secret_key( $secret_key ) ) {
			return true;
		}

		return new WP_Error(
			'unauthorized',
			__( 'You must be an admin or have a valid import session.', 'swish-migrate-and-backup' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Create a backup.
	 *
	 * Uses async processing by default to avoid timeout issues on shared hosting.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_backup( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		$async = $request->get_param( 'async' ) ?? true; // Default to async.

		$settings = get_option( 'swish_backup_settings', array() );
		$options = array(
			'type'                 => $type ?? 'full',
			'backup_database'      => $settings['backup_database'] ?? true,
			'backup_plugins'       => $settings['backup_plugins'] ?? true,
			'backup_themes'        => $settings['backup_themes'] ?? true,
			'backup_uploads'       => $settings['backup_uploads'] ?? true,
			'backup_core_files'    => $settings['backup_core_files'] ?? true,
			'storage_destinations' => $request->get_param( 'destinations' ) ?? array( 'local' ),
			'db_batch_size'        => $request->get_param( 'db_batch_size' ) ?? 200,
			'file_batch_size'      => $request->get_param( 'file_batch_size' ) ?? 50,
			// Granular exclusions.
			'exclude_plugins'      => $settings['exclude_plugins'] ?? array(),
			'exclude_themes'       => $settings['exclude_themes'] ?? array(),
			'exclude_uploads'      => $settings['exclude_uploads'] ?? array(),
		);

		// Use async processing to avoid timeouts on shared/managed hosting.
		if ( $async ) {
			$result = $this->backup_manager->start_async_backup( $options );
			return rest_ensure_response( $result );
		}

		// Synchronous backup (legacy, not recommended for large sites).
		switch ( $type ) {
			case 'database':
				$result = $this->backup_manager->create_database_backup( $options );
				break;
			case 'files':
				$result = $this->backup_manager->create_files_backup( $options );
				break;
			default:
				$result = $this->backup_manager->create_full_backup( $options );
				break;
		}

		// Check if backup failed.
		if ( isset( $result['error'] ) ) {
			// Check if it's a size limit error.
			if ( strpos( $result['error'], '4GB limit' ) !== false ) {
				return new WP_Error(
					'backup_size_limit_exceeded',
					$result['error'],
					array(
						'status'      => 402,
						'upgrade_url' => SWISH_BACKUP_PRO_URL,
					)
				);
			}

			// Generic backup failure.
			return new WP_Error(
				'backup_failed',
				$result['error'],
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get list of backups.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_backups( WP_REST_Request $request ): WP_REST_Response {
		$backups = $this->backup_manager->get_backups( 50 );
		return rest_ensure_response( $backups );
	}

	/**
	 * Get a single backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_backup( WP_REST_Request $request ) {
		$backup = $this->backup_manager->get_backup( $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $backup );
	}

	/**
	 * Delete a backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( WP_REST_Request $request ) {
		try {
			$deleted = $this->backup_manager->delete_backup( $request->get_param( 'id' ) );

			if ( ! $deleted ) {
				return new WP_Error(
					'delete_failed',
					__( 'Failed to delete backup.', 'swish-migrate-and-backup' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( array( 'deleted' => true ) );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'delete_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get download URL for a backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_download_url( WP_REST_Request $request ) {
		$backup = $this->backup_manager->get_backup( $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		$adapter = $this->storage_manager->get_default_adapter();
		// 24-hour expiry for large file downloads that may need retries.
		$url = $adapter->get_download_url( $backup['filename'], DAY_IN_SECONDS );

		return rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * Restore a backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_backup( WP_REST_Request $request ) {
		$backup = $this->backup_manager->get_backup( $request->get_param( 'backup_id' ) );

		if ( ! $backup ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		$options = array(
			'restore_database' => $request->get_param( 'restore_database' ) ?? true,
			'restore_files'    => $request->get_param( 'restore_files' ) ?? true,
		);

		$result = $this->restore_manager->restore( $backup['path'], $options );

		if ( ! $result ) {
			return new WP_Error(
				'restore_failed',
				__( 'Restore failed.', 'swish-migrate-and-backup' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Import a backup file for migration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_backup( WP_REST_Request $request ) {
		// Check for previous fatal error stored in transient.
		$last_error = get_transient( 'swish_import_fatal_error' );
		if ( $last_error ) {
			delete_transient( 'swish_import_fatal_error' );
			return new WP_Error(
				'previous_error',
				$last_error,
				array( 'status' => 500 )
			);
		}

		// Register shutdown handler to catch fatal errors.
		$this->register_import_shutdown_handler();

		// Check available memory before proceeding.
		$memory_check = $this->check_memory_for_import();
		if ( is_wp_error( $memory_check ) ) {
			return $memory_check;
		}

		// Check for server path import (for large files uploaded via FTP/SFTP).
		$server_path = $request->get_param( 'server_path' );
		if ( ! empty( $server_path ) ) {
			return $this->import_from_server_path( $server_path );
		}

		$files = $request->get_file_params();

		if ( empty( $files['backup_file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No backup file provided.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['backup_file'];

		// Check for upload errors.
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			$error_messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'File exceeds upload_max_filesize directive.', 'swish-migrate-and-backup' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds MAX_FILE_SIZE directive.', 'swish-migrate-and-backup' ),
				UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'swish-migrate-and-backup' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'swish-migrate-and-backup' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Missing temporary folder.', 'swish-migrate-and-backup' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'swish-migrate-and-backup' ),
			);

			$message = $error_messages[ $file['error'] ] ?? __( 'Unknown upload error.', 'swish-migrate-and-backup' );

			return new WP_Error(
				'upload_error',
				$message,
				array( 'status' => 400 )
			);
		}

		// Validate file type - allow ZIP, tar.gz, and .swish (our custom format).
		$filename = strtolower( $file['name'] );
		$valid_extension = (
			str_ends_with( $filename, '.zip' ) ||
			str_ends_with( $filename, '.tar.gz' ) ||
			str_ends_with( $filename, '.tgz' ) ||
			str_ends_with( $filename, '.gz' ) ||
			str_ends_with( $filename, '.swish' )
		);

		if ( ! $valid_extension ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'Invalid file type. Allowed types: ZIP, TAR.GZ, SWISH.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		// Set up custom upload directory for backup imports.
		$backup_dir = WP_CONTENT_DIR . '/swish-backups/imports';

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		// Custom upload directory filter.
		$upload_dir_filter = function ( $uploads ) use ( $backup_dir ) {
			$uploads['path']   = $backup_dir;
			$uploads['url']    = content_url( 'swish-backups/imports' );
			$uploads['subdir'] = '';
			return $uploads;
		};

		// Allow backup archive mime types (tar.gz, zip, swish).
		$upload_mimes_filter = function ( $mimes ) {
			$mimes['zip']    = 'application/zip';
			$mimes['gz']     = 'application/gzip';
			$mimes['tar.gz'] = 'application/gzip';
			$mimes['tgz']    = 'application/gzip';
			$mimes['swish']  = 'application/gzip';
			$mimes['tar']    = 'application/x-tar';
			return $mimes;
		};

		// Handle .tar.gz double extension that WordPress doesn't handle well.
		$filetype_filter = function ( $data, $file, $filename ) {
			if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
				return $data;
			}

			// Check for .tar.gz double extension.
			if ( preg_match( '/\.tar\.gz$/i', $filename ) ) {
				$data['ext']  = 'tar.gz';
				$data['type'] = 'application/gzip';
			} elseif ( preg_match( '/\.swish$/i', $filename ) ) {
				$data['ext']  = 'swish';
				$data['type'] = 'application/gzip';
			}

			return $data;
		};

		// Add filters temporarily.
		add_filter( 'upload_dir', $upload_dir_filter );
		add_filter( 'upload_mimes', $upload_mimes_filter );
		add_filter( 'wp_check_filetype_and_ext', $filetype_filter, 10, 3 );

		// Allow backup archive files for this upload.
		$upload_overrides = array(
			'test_form' => false,
			'test_type' => false, // Disable type check since we handle it ourselves.
		);

		// Include the file with wp_handle_upload function.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Use WordPress file upload handler.
		$upload_result = \wp_handle_upload( $file, $upload_overrides );

		// Remove filters after upload.
		remove_filter( 'upload_dir', $upload_dir_filter );
		remove_filter( 'upload_mimes', $upload_mimes_filter );
		remove_filter( 'wp_check_filetype_and_ext', $filetype_filter );

		// Check for upload errors.
		if ( isset( $upload_result['error'] ) ) {
			$error_message = $upload_result['error'];

			// Add helpful suggestions for common large file issues.
			if ( strpos( $error_message, 'could not be moved' ) !== false ) {
				$upload_limit = size_format( wp_max_upload_size() );
				$error_message .= "\n\n" . sprintf(
					/* translators: %s: upload limit */
					__( 'Current upload limit: %s. For large backups (1GB+), upload via FTP/SFTP to wp-content/swish-backups/ then use the "Import from Server Path" option.', 'swish-migrate-and-backup' ),
					$upload_limit
				);
			}

			return new WP_Error(
				'upload_failed',
				$error_message,
				array( 'status' => 500 )
			);
		}

		$destination = $upload_result['file'];
		$filename    = basename( $destination );

		// Analyze the backup.
		$analysis = $this->migrator->analyze_backup( $destination );

		if ( null === $analysis ) {
			// Clean up the uploaded file.
			wp_delete_file( $destination );
			return new WP_Error(
				'analysis_failed',
				__( 'Failed to analyze backup. The file may be corrupted or not a valid Swish backup.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'backup_path'  => $destination,
			'filename'     => $filename,
			'size'         => filesize( $destination ),
			'analysis'     => $analysis,
		) );
	}

	/**
	 * Import backup from a server path (for large files uploaded via FTP/SFTP).
	 *
	 * @param string $server_path Path to the backup file on the server.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	private function import_from_server_path( string $server_path ) {
		// Sanitize and validate path.
		$server_path = wp_normalize_path( $server_path );

		// Security: Only allow paths within specific directories.
		$allowed_dirs = array(
			wp_normalize_path( WP_CONTENT_DIR . '/swish-backups' ),
			wp_normalize_path( WP_CONTENT_DIR . '/uploads' ),
			wp_normalize_path( ABSPATH . 'wp-content' ),
		);

		$is_allowed = false;
		foreach ( $allowed_dirs as $allowed_dir ) {
			if ( strpos( $server_path, $allowed_dir ) === 0 ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			return new WP_Error(
				'invalid_path',
				__( 'Server path must be within wp-content directory for security.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		// Check if file exists.
		if ( ! file_exists( $server_path ) ) {
			return new WP_Error(
				'file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'File not found at path: %s', 'swish-migrate-and-backup' ),
					$server_path
				),
				array( 'status' => 404 )
			);
		}

		// Validate file extension.
		$filename = strtolower( basename( $server_path ) );
		$valid_extension = (
			str_ends_with( $filename, '.zip' ) ||
			str_ends_with( $filename, '.tar.gz' ) ||
			str_ends_with( $filename, '.tgz' ) ||
			str_ends_with( $filename, '.swish' )
		);

		if ( ! $valid_extension ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid file type. Only ZIP, TAR.GZ, and SWISH files are supported.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		// Analyze the backup.
		$analysis = $this->migrator->analyze_backup( $server_path );

		if ( null === $analysis ) {
			return new WP_Error(
				'analysis_failed',
				__( 'Failed to analyze backup. The file may be corrupted or not a valid Swish backup.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'backup_path'  => $server_path,
			'filename'     => basename( $server_path ),
			'size'         => filesize( $server_path ),
			'analysis'     => $analysis,
			'source'       => 'server_path',
		) );
	}

	/**
	 * List importable backup files from swish-backups directory.
	 *
	 * @return WP_REST_Response List of backup files.
	 */
	public function list_importable_backups(): WP_REST_Response {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups';
		$files = array();

		// Scan main backups directory.
		$files = array_merge( $files, $this->scan_backup_directory( $backup_dir ) );

		// Also scan imports subdirectory.
		$imports_dir = $backup_dir . '/imports';
		if ( is_dir( $imports_dir ) ) {
			$files = array_merge( $files, $this->scan_backup_directory( $imports_dir ) );
		}

		// Sort by modification time (newest first).
		usort( $files, function ( $a, $b ) {
			return $b['modified'] - $a['modified'];
		} );

		return rest_ensure_response( array(
			'success' => true,
			'files'   => $files,
		) );
	}

	/**
	 * Scan a directory for backup files.
	 *
	 * @param string $directory Directory to scan.
	 * @return array List of backup files with metadata.
	 */
	private function scan_backup_directory( string $directory ): array {
		$files = array();

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$valid_extensions = array( 'zip', 'gz', 'tgz', 'swish' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$dir_handle = @opendir( $directory );
		if ( ! $dir_handle ) {
			return $files;
		}

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $filename = readdir( $dir_handle ) ) ) {
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}

			$filepath = $directory . '/' . $filename;

			// Skip directories.
			if ( is_dir( $filepath ) ) {
				continue;
			}

			// Check extension.
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

			// Handle .tar.gz double extension.
			if ( 'gz' === $extension && preg_match( '/\.tar\.gz$/i', $filename ) ) {
				$extension = 'tar.gz';
			}

			if ( ! in_array( $extension, $valid_extensions, true ) && 'tar.gz' !== $extension ) {
				continue;
			}

			// Skip temp files.
			if ( strpos( $filename, '.part' ) !== false || strpos( $filename, '.tmp' ) !== false ) {
				continue;
			}

			$files[] = array(
				'filename' => $filename,
				'path'     => $filepath,
				'size'     => filesize( $filepath ),
				'modified' => filemtime( $filepath ),
				'type'     => $this->guess_backup_type( $filename ),
			);
		}

		closedir( $dir_handle );

		return $files;
	}

	/**
	 * Guess backup type from filename.
	 *
	 * @param string $filename Filename.
	 * @return string Backup type (full, database, files, unknown).
	 */
	private function guess_backup_type( string $filename ): string {
		$filename_lower = strtolower( $filename );

		if ( strpos( $filename_lower, '-full-' ) !== false ) {
			return 'full';
		}
		if ( strpos( $filename_lower, '-database-' ) !== false || strpos( $filename_lower, '-db-' ) !== false ) {
			return 'database';
		}
		if ( strpos( $filename_lower, '-files-' ) !== false ) {
			return 'files';
		}

		return 'unknown';
	}

	/**
	 * Run migration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_migration( WP_REST_Request $request ) {
		// Check for previous fatal error stored in transient.
		$last_error = get_transient( 'swish_migration_fatal_error' );
		if ( $last_error ) {
			delete_transient( 'swish_migration_fatal_error' );
			return new WP_Error(
				'previous_error',
				$last_error,
				array( 'status' => 500 )
			);
		}

		// Register shutdown handler to catch fatal errors.
		$this->register_migration_shutdown_handler();

		// Check available memory before proceeding.
		$memory_check = $this->check_memory_for_import();
		if ( is_wp_error( $memory_check ) ) {
			return $memory_check;
		}

		$options = array(
			'old_url'          => $request->get_param( 'old_url' ),
			'new_url'          => $request->get_param( 'new_url' ),
			'restore_database' => $request->get_param( 'restore_database' ) ?? true,
			'restore_files'    => $request->get_param( 'restore_files' ) ?? true,
		);

		$backup_path = $request->get_param( 'backup_path' );

		if ( $backup_path ) {
			$result = $this->migrator->import_and_migrate( $backup_path, $options );
		} else {
			// Just URL replacement.
			$result = $this->migrator->replace_urls(
				$options['old_url'],
				$options['new_url']
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Search and replace.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function search_replace( WP_REST_Request $request ): WP_REST_Response {
		$search = $request->get_param( 'search' );
		$replace = $request->get_param( 'replace' );
		$dry_run = $request->get_param( 'dry_run' );

		if ( $dry_run ) {
			$result = $this->migrator->preview_url_replacement( $search, $replace );
		} else {
			$result = $this->migrator->custom_search_replace( $search, $replace );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Test storage connection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function test_storage( WP_REST_Request $request ): WP_REST_Response {
		$adapter_id = $request->get_param( 'adapter' );

		if ( ! $this->storage_manager->has_adapter( $adapter_id ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'Storage adapter not found.', 'swish-migrate-and-backup' ),
			) );
		}

		$adapter = $this->storage_manager->get_adapter( $adapter_id );

		if ( ! $adapter->is_configured() ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'Storage adapter not configured.', 'swish-migrate-and-backup' ),
			) );
		}

		$connected = $adapter->connect();

		return rest_ensure_response( array(
			'success' => $connected,
			'message' => $connected
				? __( 'Connection successful!', 'swish-migrate-and-backup' )
				: __( 'Connection failed.', 'swish-migrate-and-backup' ),
		) );
	}

	/**
	 * Get job status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_status( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'id' );
		$job = $this->backup_manager->get_job_status( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Fallback: If job is processing and has a checkpoint, trigger continuation.
		// This helps when WP-Cron is unreliable.
		if ( 'processing' === $job['status'] ) {
			$this->backup_manager->maybe_trigger_continuation( $job_id );
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Process a pending job directly.
	 *
	 * This is a fallback for hosts where WP Cron doesn't trigger immediately.
	 * The frontend can call this if the job is still "pending" after a few seconds.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_pending_job( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'id' );
		$job = $this->backup_manager->get_job_status( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Only process if the job is still pending.
		if ( $job['status'] !== 'pending' ) {
			return rest_ensure_response( array(
				'status'  => $job['status'],
				'message' => 'Job is already ' . $job['status'],
			) );
		}

		// Spawn a non-blocking loopback request to process the backup.
		// This allows the frontend to continue polling for updates.
		$this->spawn_backup_process( $job_id );

		// Return immediately so frontend can poll for updates.
		return rest_ensure_response( array(
			'status'  => 'processing',
			'message' => 'Backup processing started',
			'job_id'  => $job_id,
		) );
	}

	/**
	 * Spawn a non-blocking request to process a backup job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function spawn_backup_process( string $job_id ): void {
		// Build the URL to our internal processing endpoint.
		$process_url = add_query_arg(
			array(
				'action'       => 'swish_process_backup',
				'job_id'       => $job_id,
				'process_key'  => wp_create_nonce( 'swish_process_' . $job_id ),
			),
			admin_url( 'admin-ajax.php' )
		);

		// Send a non-blocking request.
		wp_remote_post(
			$process_url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => $_COOKIE,
			)
		);
	}

	/**
	 * Cancel a running or pending job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'id' );
		$job    = $this->backup_manager->get_job_status( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Only cancel if the job is pending or processing.
		if ( ! in_array( $job['status'], array( 'pending', 'processing' ), true ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: job status */
						__( 'Cannot cancel job with status: %s', 'swish-migrate-and-backup' ),
						$job['status']
					),
				)
			);
		}

		// Cancel the job.
		$result = $this->backup_manager->cancel_job( $job_id );

		if ( $result ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Job cancelled successfully.', 'swish-migrate-and-backup' ),
				)
			);
		}

		return new WP_Error(
			'cancel_failed',
			__( 'Failed to cancel job.', 'swish-migrate-and-backup' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Get settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'swish_backup_settings', array() );

		$defaults = array(
			'db_batch_size'        => 200,
			'file_batch_size'      => 50,
			'pipeline_batch_size'  => 150,  // Files per pipeline request (10-500).
			'backup_database'      => true,
			'backup_plugins'       => true,
			'backup_themes'        => true,
			'backup_uploads'       => true,
			'backup_core_files'    => true,
			'compression_level'    => 6,
			'archive_format'       => 'swish',
			'default_storage'      => 'local',
			'exclude_files'        => array(),
			'exclude_plugins'      => array(),  // Plugin slugs to exclude.
			'exclude_themes'       => array(),  // Theme slugs to exclude.
			'exclude_uploads'      => array(),  // Upload folder paths to exclude.
			'email_notifications'  => false,
			'notification_email'   => get_option( 'admin_email' ),
			'tar_available'        => \SwishMigrateAndBackup\Core\ServerLimits::is_tar_available(),
		);

		return rest_ensure_response( wp_parse_args( $settings, $defaults ) );
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'swish_backup_settings', array() );
		$params = $request->get_json_params();

		// Sanitize and validate batch sizes.
		if ( isset( $params['db_batch_size'] ) ) {
			$settings['db_batch_size'] = max( 50, min( 2000, absint( $params['db_batch_size'] ) ) );
		}

		if ( isset( $params['file_batch_size'] ) ) {
			$settings['file_batch_size'] = max( 25, min( 500, absint( $params['file_batch_size'] ) ) );
		}

		if ( isset( $params['pipeline_batch_size'] ) ) {
			$settings['pipeline_batch_size'] = max( 10, min( 500, absint( $params['pipeline_batch_size'] ) ) );
		}

		// Other settings.
		$boolean_settings = array(
			'backup_database',
			'backup_plugins',
			'backup_themes',
			'backup_uploads',
			'backup_core_files',
			'email_notifications',
		);

		foreach ( $boolean_settings as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$settings[ $key ] = (bool) $params[ $key ];
			}
		}

		if ( isset( $params['compression_level'] ) ) {
			$settings['compression_level'] = max( 0, min( 9, absint( $params['compression_level'] ) ) );
		}

		if ( isset( $params['archive_format'] ) ) {
			$allowed_formats = array( 'auto', 'zip', 'tar', 'swish' );
			$format = sanitize_text_field( $params['archive_format'] );
			$settings['archive_format'] = in_array( $format, $allowed_formats, true ) ? $format : 'swish';
		}

		if ( isset( $params['default_storage'] ) ) {
			$settings['default_storage'] = sanitize_text_field( $params['default_storage'] );
		}

		if ( isset( $params['notification_email'] ) ) {
			$settings['notification_email'] = sanitize_email( $params['notification_email'] );
		}

		// Handle exclusion arrays.
		$array_settings = array( 'exclude_plugins', 'exclude_themes', 'exclude_uploads' );
		foreach ( $array_settings as $key ) {
			if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
				$settings[ $key ] = array_map( 'sanitize_text_field', $params[ $key ] );
			}
		}

		update_option( 'swish_backup_settings', $settings );

		return rest_ensure_response( array(
			'success'  => true,
			'settings' => $settings,
		) );
	}

	/**
	 * Get dashboard stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_dashboard_stats( WP_REST_Request $request ): WP_REST_Response {
		$backups = $this->backup_manager->get_backups( 50 );
		$last_backup = ! empty( $backups ) ? $backups[0] : null;

		// Get storage adapters status.
		$adapters = array();
		foreach ( $this->storage_manager->get_all_adapters() as $id => $adapter ) {
			$adapters[ $id ] = array(
				'name'       => $adapter->get_name(),
				'configured' => $adapter->is_configured(),
			);
		}

		// Calculate total storage used.
		$total_size = array_sum( array_column( $backups, 'size' ) );

		return rest_ensure_response( array(
			'total_backups' => count( $backups ),
			'total_size'    => $total_size,
			'last_backup'   => $last_backup,
			'storage'       => $adapters,
			'site_url'      => get_site_url(),
		) );
	}

	/**
	 * Get folder structure for granular backup selection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_folder_structure( WP_REST_Request $request ): WP_REST_Response {
		$structure = array(
			'plugins' => $this->get_plugins_list(),
			'themes'  => $this->get_themes_list(),
			'uploads' => $this->get_uploads_folders(),
		);

		return rest_ensure_response( $structure );
	}

	/**
	 * Get list of all plugins with metadata.
	 *
	 * @return array
	 */
	private function get_plugins_list(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = dirname( $plugin_file );
			if ( '.' === $plugin_dir ) {
				$plugin_dir = basename( $plugin_file, '.php' );
			}

			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_dir;
			$size = 0;

			if ( is_dir( $plugin_path ) ) {
				$size = $this->get_directory_size( $plugin_path );
			} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$size = filesize( WP_PLUGIN_DIR . '/' . $plugin_file );
			}

			$plugins[] = array(
				'slug'    => $plugin_dir,
				'name'    => $plugin_data['Name'],
				'version' => $plugin_data['Version'],
				'active'  => in_array( $plugin_file, $active_plugins, true ),
				'size'    => $size,
				'path'    => $plugin_dir,
			);
		}

		// Sort by name.
		usort( $plugins, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $plugins;
	}

	/**
	 * Get list of all themes with metadata.
	 *
	 * @return array
	 */
	private function get_themes_list(): array {
		$themes = array();
		$all_themes = wp_get_themes();
		$active_theme = get_template();
		$active_child = get_stylesheet();

		foreach ( $all_themes as $slug => $theme ) {
			$theme_path = $theme->get_stylesheet_directory();
			$size = $this->get_directory_size( $theme_path );

			$themes[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $slug === $active_theme || $slug === $active_child ),
				'size'    => $size,
				'path'    => $slug,
			);
		}

		// Sort: active first, then by name.
		usort( $themes, function( $a, $b ) {
			if ( $a['active'] !== $b['active'] ) {
				return $a['active'] ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $themes;
	}

	/**
	 * Get top-level folders in uploads directory.
	 *
	 * @return array
	 */
	private function get_uploads_folders(): array {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		$folders = array();

		if ( ! is_dir( $base_dir ) ) {
			return $folders;
		}

		$iterator = new \DirectoryIterator( $base_dir );

		foreach ( $iterator as $item ) {
			if ( $item->isDot() || ! $item->isDir() ) {
				continue;
			}

			$folder_name = $item->getFilename();

			// Skip backup folders.
			if ( in_array( $folder_name, array( 'swish-backups', 'backups' ), true ) ) {
				continue;
			}

			$folder_path = $item->getPathname();
			$size = $this->get_directory_size( $folder_path );

			// Check if it's a year folder (contains month subfolders).
			$is_year = preg_match( '/^\d{4}$/', $folder_name );

			$folders[] = array(
				'name'     => $folder_name,
				'path'     => $folder_name,
				'size'     => $size,
				'is_year'  => $is_year,
				'children' => $is_year ? $this->get_year_subfolders( $folder_path ) : array(),
			);
		}

		// Sort by name.
		usort( $folders, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $folders;
	}

	/**
	 * Get month subfolders for a year folder in uploads.
	 *
	 * @param string $year_path Path to year folder.
	 * @return array
	 */
	private function get_year_subfolders( string $year_path ): array {
		$subfolders = array();

		if ( ! is_dir( $year_path ) ) {
			return $subfolders;
		}

		$iterator = new \DirectoryIterator( $year_path );

		foreach ( $iterator as $item ) {
			if ( $item->isDot() || ! $item->isDir() ) {
				continue;
			}

			$folder_name = $item->getFilename();
			$size = $this->get_directory_size( $item->getPathname() );

			$subfolders[] = array(
				'name' => $folder_name,
				'path' => basename( $year_path ) . '/' . $folder_name,
				'size' => $size,
			);
		}

		// Sort by name.
		usort( $subfolders, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $subfolders;
	}

	/**
	 * Get directory size (limited depth for performance).
	 *
	 * @param string $path Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( string $path ): int {
		$size = 0;

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			// Limit iteration to prevent timeout.
			$count = 0;
			$max_files = 10000;

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
				$count++;
				if ( $count >= $max_files ) {
					break;
				}
			}
		} catch ( \Exception $e ) {
			// Ignore errors.
		}

		return $size;
	}

	/**
	 * Register shutdown handler to catch fatal errors during import.
	 *
	 * @return void
	 */
	private function register_import_shutdown_handler(): void {
		register_shutdown_function( function () {
			$error = error_get_last();

			if ( null === $error ) {
				return;
			}

			// Only handle fatal errors.
			$fatal_errors = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
			if ( ! in_array( $error['type'], $fatal_errors, true ) ) {
				return;
			}

			// Create user-friendly error message.
			$message = $this->get_friendly_error_message( $error );

			// Store error in transient for next request to display.
			set_transient( 'swish_import_fatal_error', $message, 5 * MINUTE_IN_SECONDS );
		} );
	}

	/**
	 * Register shutdown handler to catch fatal errors during migration.
	 *
	 * @return void
	 */
	private function register_migration_shutdown_handler(): void {
		register_shutdown_function( function () {
			$error = error_get_last();

			if ( null === $error ) {
				return;
			}

			// Only handle fatal errors.
			$fatal_errors = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
			if ( ! in_array( $error['type'], $fatal_errors, true ) ) {
				return;
			}

			// Create user-friendly error message.
			$message = $this->get_friendly_error_message( $error );

			// Store error in transient for next request to display.
			set_transient( 'swish_migration_fatal_error', $message, 5 * MINUTE_IN_SECONDS );
		} );
	}

	/**
	 * Get user-friendly error message from PHP error.
	 *
	 * @param array $error PHP error array from error_get_last().
	 * @return string User-friendly error message.
	 */
	private function get_friendly_error_message( array $error ): string {
		$raw_message = $error['message'] ?? '';

		// Memory exhaustion.
		if ( strpos( $raw_message, 'Allowed memory size' ) !== false ) {
			// Extract memory limit from error message.
			preg_match( '/Allowed memory size of (\d+) bytes/', $raw_message, $matches );
			$memory_limit = isset( $matches[1] ) ? size_format( (int) $matches[1] ) : 'unknown';

			return sprintf(
				/* translators: %s: memory limit */
				__( 'Import failed: Server memory limit (%s) exceeded. Please contact your hosting provider to increase the PHP memory_limit, or try importing a smaller backup.', 'swish-migrate-and-backup' ),
				$memory_limit
			);
		}

		// Maximum execution time.
		if ( strpos( $raw_message, 'Maximum execution time' ) !== false ) {
			return __( 'Import failed: Server timeout reached. Please contact your hosting provider to increase the max_execution_time limit, or try importing a smaller backup.', 'swish-migrate-and-backup' );
		}

		// Upload size limit.
		if ( strpos( $raw_message, 'upload_max_filesize' ) !== false || strpos( $raw_message, 'post_max_size' ) !== false ) {
			return __( 'Import failed: File size exceeds server upload limit. Please contact your hosting provider to increase upload_max_filesize and post_max_size limits.', 'swish-migrate-and-backup' );
		}

		// Generic fatal error.
		return sprintf(
			/* translators: %s: error message */
			__( 'Import failed: A server error occurred. Error: %s', 'swish-migrate-and-backup' ),
			$raw_message
		);
	}

	/**
	 * Check if there's enough memory available for import.
	 *
	 * @return true|WP_Error True if OK, WP_Error if not enough memory.
	 */
	private function check_memory_for_import() {
		$memory_limit = $this->get_memory_limit();
		$memory_used = memory_get_usage( true );
		$memory_available = $memory_limit - $memory_used;

		// We need at least 64MB available for safe import processing.
		$minimum_required = 64 * 1024 * 1024;

		if ( $memory_available < $minimum_required ) {
			return new WP_Error(
				'insufficient_memory',
				sprintf(
					/* translators: 1: available memory, 2: required memory, 3: current limit */
					__( 'Insufficient memory for import. Available: %1$s, Required: %2$s. Your server\'s PHP memory_limit is set to %3$s. Please contact your hosting provider to increase this limit.', 'swish-migrate-and-backup' ),
					size_format( $memory_available ),
					size_format( $minimum_required ),
					size_format( $memory_limit )
				),
				array( 'status' => 507 ) // 507 Insufficient Storage.
			);
		}

		// Warn if memory is low but proceed.
		if ( $memory_available < 128 * 1024 * 1024 ) {
			// Log warning but continue.
			error_log( sprintf(
				'Swish Backup: Low memory warning during import. Available: %s, Limit: %s',
				size_format( $memory_available ),
				size_format( $memory_limit )
			) );
		}

		return true;
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @return int Memory limit in bytes.
	 */
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );

		if ( '-1' === $limit ) {
			// No limit set, assume 512MB.
			return 512 * 1024 * 1024;
		}

		$value = (int) $limit;
		$unit = strtoupper( substr( $limit, -1 ) );

		switch ( $unit ) {
			case 'G':
				$value *= 1024;
				// Fall through.
			case 'M':
				$value *= 1024;
				// Fall through.
			case 'K':
				$value *= 1024;
		}

		return $value;
	}

	/**
	 * Start a pipeline-based backup.
	 *
	 * This uses the new queue-based architecture for reliable chunked processing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pipeline_start( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' ) ?? 'full';
		$settings = get_option( 'swish_backup_settings', array() );

		// Generate job ID.
		$job_id = 'backup_' . wp_generate_uuid4();

		// Calculate adaptive settings based on server capabilities.
		$max_exec_time = \SwishMigrateAndBackup\Core\ServerLimits::get_max_execution_time();
		$safe_threshold = \SwishMigrateAndBackup\Core\ServerLimits::get_safe_timeout_threshold();

		// Dynamic time budget: use 60% of available time, minimum 10s, max 25s.
		$time_budget = 15; // Default.
		if ( $max_exec_time > 0 ) {
			$time_budget = max( 10, min( 25, (int) ( ( $max_exec_time - $safe_threshold ) * 0.6 ) ) );
		}

		// Use adaptive batch size if no user setting, otherwise respect user choice.
		$adaptive_batch = \SwishMigrateAndBackup\Core\ServerLimits::get_adaptive_file_batch_size( 150 );
		$batch_size = $settings['pipeline_batch_size'] ?? $adaptive_batch;

		// Build options.
		$options = array(
			'type'                 => $type,
			'backup_database'      => $settings['backup_database'] ?? true,
			'backup_plugins'       => $settings['backup_plugins'] ?? true,
			'backup_themes'        => $settings['backup_themes'] ?? true,
			'backup_uploads'       => $settings['backup_uploads'] ?? true,
			'backup_core_files'    => $settings['backup_core_files'] ?? false,
			'exclude_plugins'      => $settings['exclude_plugins'] ?? array(),
			'exclude_themes'       => $settings['exclude_themes'] ?? array(),
			'exclude_uploads'      => $settings['exclude_uploads'] ?? array(),
			'time_budget'          => $time_budget,
			'pipeline_batch_size'  => $batch_size,
		);

		// Create backup directory.
		$backup_dir = WP_CONTENT_DIR . '/swish-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		// Generate archive filename.
		$site_name = sanitize_file_name( wp_parse_url( get_site_url(), PHP_URL_HOST ) );
		$timestamp = gmdate( 'Y-m-d-His' );
		$archive_filename = "{$site_name}-{$type}-{$timestamp}.swish";
		$archive_path = $backup_dir . '/' . $archive_filename;

		// Initialize pipeline.
		$pipeline = new \SwishMigrateAndBackup\Backup\BackupPipeline(
			new \SwishMigrateAndBackup\Logger\Logger()
		);
		$pipeline->configure( $options );

		// Get directories to backup.
		$directories = $pipeline->get_backup_directories( $options );

		// Ensure file queue table exists.
		\SwishMigrateAndBackup\Backup\FileQueue::create_table();

		// Clear any previous data for this job.
		\SwishMigrateAndBackup\Backup\FileQueue::clear_job( $job_id );

		// Store job state.
		$job_state = array(
			'job_id'       => $job_id,
			'type'         => $type,
			'phase'        => 'indexing',
			'archive_path' => $archive_path,
			'directories'  => $directories,
			'options'      => $options,
			'index_offset' => 0,
			'started_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( 'swish_pipeline_job_' . $job_id, $job_state );

		// Start indexing phase.
		$result = $pipeline->index_files( $job_id, $directories, 0 );

		// Update job state.
		$job_state['index_offset'] = $result['offset'];
		if ( $result['completed'] ) {
			$job_state['phase'] = 'processing';
		}
		update_option( 'swish_pipeline_job_' . $job_id, $job_state );

		// Get stats.
		$stats = \SwishMigrateAndBackup\Backup\FileQueue::get_job_stats( $job_id );

		// Calculate initial progress: indexing phase = 0-10%.
		$overall_progress = $result['completed'] ? 10 : min( 9, $stats['total'] > 0 ? 5 : 2 );

		return rest_ensure_response( array(
			'success'   => true,
			'job_id'    => $job_id,
			'phase'     => $job_state['phase'],
			'completed' => $result['completed'] && $stats['total'] === 0,
			'indexed'   => $result['indexed'],
			'stats'     => $stats,
			'progress'  => $overall_progress,
			'message'   => $result['completed']
				? 'Indexing complete, ready to process ' . $stats['total'] . ' files'
				: 'Indexing in progress (' . $result['indexed'] . ' files found so far)',
		) );
	}

	/**
	 * Continue a pipeline-based backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pipeline_continue( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Get job state.
		$job_state = get_option( 'swish_pipeline_job_' . $job_id );
		if ( ! $job_state ) {
			return new WP_Error(
				'job_not_found',
				__( 'Backup job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Initialize pipeline.
		$pipeline = new \SwishMigrateAndBackup\Backup\BackupPipeline(
			new \SwishMigrateAndBackup\Logger\Logger()
		);
		$pipeline->configure( $job_state['options'] );

		$phase = $job_state['phase'];
		$result = array();

		if ( 'indexing' === $phase ) {
			// Continue indexing.
			$result = $pipeline->index_files(
				$job_id,
				$job_state['directories'],
				$job_state['index_offset']
			);

			$job_state['index_offset'] = $result['offset'];
			if ( $result['completed'] ) {
				$job_state['phase'] = 'processing';
			}

			$stats = \SwishMigrateAndBackup\Backup\FileQueue::get_job_stats( $job_id );

			update_option( 'swish_pipeline_job_' . $job_id, $job_state );

			// Calculate overall progress: indexing = 0-10%.
			// Since we don't know total files until indexing completes, estimate based on completion.
			$overall_progress = $result['completed'] ? 10 : min( 9, $stats['total'] > 0 ? 5 : 2 );

			return rest_ensure_response( array(
				'success'   => true,
				'job_id'    => $job_id,
				'phase'     => $job_state['phase'],
				'completed' => false,
				'indexed'   => $result['indexed'],
				'stats'     => $stats,
				'progress'  => $overall_progress,
				'message'   => $result['completed']
					? 'Indexing complete, ' . $stats['total'] . ' files to process'
					: 'Indexing: ' . $stats['total'] . ' files found',
			) );
		}

		if ( 'processing' === $phase ) {
			// Prepare archive if not done yet (adds manifest and database).
			if ( empty( $job_state['archive_prepared'] ) ) {
				$prepare_result = $pipeline->prepare_archive(
					$job_id,
					$job_state['archive_path'],
					$job_state['options']
				);

				if ( ! $prepare_result['success'] ) {
					return new WP_Error(
						'prepare_failed',
						$prepare_result['error'] ?? 'Failed to prepare archive',
						array( 'status' => 500 )
					);
				}

				$job_state['archive_prepared'] = true;
				update_option( 'swish_pipeline_job_' . $job_id, $job_state );
			}

			// Continue processing (archiving files).
			$result = $pipeline->process_files( $job_id, $job_state['archive_path'] );

			if ( $result['completed'] ) {
				$job_state['phase'] = 'finalizing';
			}

			update_option( 'swish_pipeline_job_' . $job_id, $job_state );

			// Calculate overall progress: processing = 10-95%.
			// stats['progress'] is 0-100 of processing phase only.
			$processing_progress = $result['stats']['progress'] ?? 0;
			$overall_progress = 10 + (int) ( $processing_progress * 0.85 );
			if ( $result['completed'] ) {
				$overall_progress = 95;
			}

			$completed_files = ( $result['stats']['completed'] ?? 0 ) + ( $result['stats']['skipped'] ?? 0 );
			$total_files = $result['stats']['total'] ?? 0;

			return rest_ensure_response( array(
				'success'   => true,
				'job_id'    => $job_id,
				'phase'     => $job_state['phase'],
				'completed' => false,
				'processed' => $result['processed'],
				'failed'    => $result['failed'],
				'skipped'   => $result['skipped'],
				'stats'     => $result['stats'],
				'progress'  => $overall_progress,
				'message'   => sprintf(
					'Processing: %d/%d files (%d%%)',
					$completed_files,
					$total_files,
					$processing_progress
				),
			) );
		}

		if ( 'finalizing' === $phase ) {
			// Finalize archive.
			$result = $pipeline->finalize( $job_id, $job_state['archive_path'] );

			if ( $result['success'] ) {
				$job_state['phase'] = 'complete';
				$job_state['completed_at'] = gmdate( 'Y-m-d H:i:s' );
				$job_state['file_size'] = $result['size'];
				$job_state['checksum'] = $result['checksum'];

				update_option( 'swish_pipeline_job_' . $job_id, $job_state );

				// Register backup in the manager.
				$this->backup_manager->register_backup( array(
					'job_id'    => $job_id,
					'type'      => $job_state['type'],
					'file_path' => $job_state['archive_path'],
					'file_size' => $result['size'],
					'checksum'  => $result['checksum'],
				) );

				return rest_ensure_response( array(
					'success'   => true,
					'job_id'    => $job_id,
					'phase'     => 'complete',
					'completed' => true,
					'progress'  => 100,
					'file_path' => $job_state['archive_path'],
					'file_size' => $result['size'],
					'checksum'  => $result['checksum'],
					'stats'     => $result['stats'],
					'message'   => 'Backup completed successfully',
				) );
			}

			return new WP_Error(
				'finalize_failed',
				$result['error'] ?? __( 'Failed to finalize backup.', 'swish-migrate-and-backup' ),
				array( 'status' => 500 )
			);
		}

		// Already complete.
		return rest_ensure_response( array(
			'success'   => true,
			'job_id'    => $job_id,
			'phase'     => 'complete',
			'completed' => true,
			'message'   => 'Backup already completed',
		) );
	}

	/**
	 * Get pipeline backup status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pipeline_status( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Get job state.
		$job_state = get_option( 'swish_pipeline_job_' . $job_id );
		if ( ! $job_state ) {
			return new WP_Error(
				'job_not_found',
				__( 'Backup job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Get queue stats.
		$stats = \SwishMigrateAndBackup\Backup\FileQueue::get_job_stats( $job_id );

		return rest_ensure_response( array(
			'job_id'       => $job_id,
			'phase'        => $job_state['phase'],
			'type'         => $job_state['type'],
			'archive_path' => $job_state['archive_path'] ?? null,
			'started_at'   => $job_state['started_at'] ?? null,
			'completed_at' => $job_state['completed_at'] ?? null,
			'file_size'    => $job_state['file_size'] ?? null,
			'checksum'     => $job_state['checksum'] ?? null,
			'stats'        => $stats,
			'progress'     => $stats['progress'],
			'completed'    => 'complete' === $job_state['phase'],
		) );
	}

	/**
	 * Toggle schedule active status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_schedule( WP_REST_Request $request ) {
		$schedule_id = (int) $request->get_param( 'id' );

		$schedule = $this->scheduler->get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		$new_status = $this->scheduler->toggle_schedule( $schedule_id );

		return rest_ensure_response( array(
			'success'   => true,
			'is_active' => $new_status,
			'message'   => $new_status
				? __( 'Schedule activated.', 'swish-migrate-and-backup' )
				: __( 'Schedule paused.', 'swish-migrate-and-backup' ),
		) );
	}

	/**
	 * Run a scheduled backup immediately.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_schedule( WP_REST_Request $request ) {
		$schedule_id = (int) $request->get_param( 'id' );

		$schedule = $this->scheduler->get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->scheduler->run_scheduled_backup( $schedule_id );

		return rest_ensure_response( array(
			'success' => $result,
			'message' => $result
				? __( 'Scheduled backup started.', 'swish-migrate-and-backup' )
				: __( 'Failed to start backup.', 'swish-migrate-and-backup' ),
		) );
	}

	/**
	 * Delete a schedule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_schedule( WP_REST_Request $request ) {
		$schedule_id = (int) $request->get_param( 'id' );

		$schedule = $this->scheduler->get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->scheduler->delete_schedule( $schedule_id );

		return rest_ensure_response( array(
			'success' => $result,
			'message' => $result
				? __( 'Schedule deleted.', 'swish-migrate-and-backup' )
				: __( 'Failed to delete schedule.', 'swish-migrate-and-backup' ),
		) );
	}

	/**
	 * Start an import pipeline.
	 *
	 * This initiates a chunked import operation that can span multiple HTTP requests.
	 * Returns a secret key for subsequent requests.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_pipeline_start( WP_REST_Request $request ) {
		$force = $request->get_param( 'force' ) ?? false;

		// Check if an import is already in progress.
		if ( ImportSession::is_import_active() ) {
			if ( $force ) {
				// Force clear the stale session.
				ImportSession::force_clear();
				$this->logger->info( 'Force cleared stale import session' );
			} else {
				$state = ImportSession::get_state();
				return new WP_Error(
					'import_in_progress',
					__( 'An import is already in progress. Use force=true to clear and restart.', 'swish-migrate-and-backup' ),
					array(
						'status' => 409,
						'phase'  => $state['phase'] ?? 'unknown',
					)
				);
			}
		}

		$backup_path = $request->get_param( 'backup_path' );

		// Validate backup file exists.
		if ( ! file_exists( $backup_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'Backup file not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Create import session.
		$options = array(
			'old_url'          => $request->get_param( 'old_url' ) ?? '',
			'new_url'          => $request->get_param( 'new_url' ) ?? '',
			'restore_database' => $request->get_param( 'restore_database' ) ?? true,
			'restore_files'    => $request->get_param( 'restore_files' ) ?? true,
		);

		// Debug logging to trace URL values at session creation.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'Swish Import Session Creating: old_url="%s", new_url="%s", backup_path="%s"',
			$options['old_url'],
			$options['new_url'],
			$backup_path
		) );

		$session = ImportSession::create_session( $backup_path, $options );

		// Create the import pipeline.
		$logger = new \SwishMigrateAndBackup\Logger\Logger();
		$pipeline = new ImportPipeline( $logger, $this->restore_manager, $this->migrator );

		// Execute the first chunk.
		$result = $pipeline->execute( $session['secret_key'] );

		return rest_ensure_response( array(
			'success'    => $result['success'] ?? true,
			'secret_key' => $session['secret_key'],
			'phase'      => $result['phase'] ?? 'validate',
			'progress'   => $result['progress'] ?? 0,
			'message'    => $result['message'] ?? 'Import started',
			'completed'  => $result['completed'] ?? false,
		) );
	}

	/**
	 * Continue an import pipeline.
	 *
	 * This executes the next chunk of an import operation.
	 * Uses secret key authentication instead of WordPress sessions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_pipeline_continue( WP_REST_Request $request ) {
		$secret_key = $request->get_param( 'secret_key' );

		// Get current state.
		$state = ImportSession::get_state();
		if ( ! $state ) {
			return new WP_Error(
				'no_active_import',
				__( 'No active import session.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Check if already completed.
		if ( in_array( $state['phase'] ?? '', array( 'completed', 'failed' ), true ) ) {
			return rest_ensure_response( array(
				'success'   => 'completed' === $state['phase'],
				'phase'     => $state['phase'],
				'progress'  => 100,
				'message'   => 'Import already ' . $state['phase'],
				'completed' => true,
			) );
		}

		// Create the import pipeline.
		$logger = new \SwishMigrateAndBackup\Logger\Logger();
		$pipeline = new ImportPipeline( $logger, $this->restore_manager, $this->migrator );

		// Execute the next chunk.
		$result = $pipeline->execute( $secret_key );

		// If not completed, spawn a non-blocking continuation request.
		// This ensures the import continues even if the browser connection is lost.
		if ( ! ( $result['completed'] ?? false ) && ( $result['success'] ?? true ) ) {
			$pipeline->spawn_continuation( $secret_key );
		}

		return rest_ensure_response( array(
			'success'   => $result['success'] ?? true,
			'phase'     => $result['phase'] ?? $state['phase'],
			'progress'  => $result['progress'] ?? 0,
			'message'   => $result['message'] ?? 'Processing...',
			'completed' => $result['completed'] ?? false,
			'error'     => $result['error'] ?? null,
		) );
	}

	/**
	 * Get import pipeline status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function import_pipeline_status( WP_REST_Request $request ): WP_REST_Response {
		$state = ImportSession::get_state();

		if ( ! $state ) {
			return rest_ensure_response( array(
				'active'    => false,
				'phase'     => null,
				'progress'  => 0,
				'message'   => 'No active import',
				'completed' => false,
			) );
		}

		$completed = in_array( $state['phase'] ?? '', array( 'completed', 'failed' ), true );

		return rest_ensure_response( array(
			'active'     => ! $completed,
			'phase'      => $state['phase'] ?? 'unknown',
			'progress'   => $state['progress'] ?? 0,
			'started_at' => $state['started_at'] ?? null,
			'completed'  => $completed,
			'success'    => 'completed' === ( $state['phase'] ?? '' ),
			'error'      => $state['result']['error'] ?? null,
		) );
	}
}
