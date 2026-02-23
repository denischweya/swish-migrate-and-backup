<?php
/**
 * REST API Controller.
 *
 * @package SwishMigrateAndBackup\Api
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Api;

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Queue\JobQueue;
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
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param RestoreManager $restore_manager Restore manager.
	 * @param Migrator       $migrator        Migrator.
	 * @param StorageManager $storage_manager Storage manager.
	 * @param JobQueue       $job_queue       Job queue.
	 */
	public function __construct(
		BackupManager $backup_manager,
		RestoreManager $restore_manager,
		Migrator $migrator,
		StorageManager $storage_manager,
		JobQueue $job_queue
	) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->migrator        = $migrator;
		$this->storage_manager = $storage_manager;
		$this->job_queue       = $job_queue;
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
			'/backup/(?P<id>[a-zA-Z0-9-]+)',
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
			'/backup/(?P<id>[a-zA-Z0-9-]+)/download',
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
			'/job/(?P<id>[a-zA-Z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job_status' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
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
	 * Create a backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_backup( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );

		$settings = get_option( 'swish_backup_settings', array() );
		$options = array(
			'backup_database'      => $settings['backup_database'] ?? true,
			'backup_plugins'       => $settings['backup_plugins'] ?? true,
			'backup_themes'        => $settings['backup_themes'] ?? true,
			'backup_uploads'       => $settings['backup_uploads'] ?? true,
			'backup_core_files'    => $settings['backup_core_files'] ?? true,
			'storage_destinations' => $request->get_param( 'destinations' ) ?? array( 'local' ),
		);

		$result = match ( $type ) {
			'database' => $this->backup_manager->create_database_backup( $options ),
			'files'    => $this->backup_manager->create_files_backup( $options ),
			default    => $this->backup_manager->create_full_backup( $options ),
		};

		if ( null === $result ) {
			return new WP_Error(
				'backup_failed',
				__( 'Backup creation failed.', 'swish-migrate-and-backup' ),
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
		$deleted = $this->backup_manager->delete_backup( $request->get_param( 'id' ) );

		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete backup.', 'swish-migrate-and-backup' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'deleted' => true ) );
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
		$url = $adapter->get_download_url( $backup['filename'] );

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
	 * Run migration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_migration( WP_REST_Request $request ) {
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
		$job = $this->backup_manager->get_job_status( $request->get_param( 'id' ) );

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $job );
	}
}
