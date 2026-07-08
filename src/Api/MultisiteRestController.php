<?php
/**
 * Multisite REST API Controller.
 *
 * @package SwishMigrateAndBackup\Api
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Api;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use SwishMigrateAndBackup\Multisite\MultisiteManager;
use SwishMigrateAndBackup\Multisite\MultisiteDetector;

/**
 * REST API endpoints for multisite features.
 */
final class MultisiteRestController extends WP_REST_Controller {

	/**
	 * Multisite manager.
	 *
	 * @var MultisiteManager
	 */
	private MultisiteManager $multisite_manager;

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $multisite_detector;

	/**
	 * Constructor.
	 *
	 * @param MultisiteManager  $multisite_manager  Multisite manager.
	 * @param MultisiteDetector $multisite_detector Multisite detector.
	 */
	public function __construct( MultisiteManager $multisite_manager, MultisiteDetector $multisite_detector ) {
		$this->namespace          = 'swish-backup/v1';
		$this->multisite_manager  = $multisite_manager;
		$this->multisite_detector = $multisite_detector;
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Get multisite sites.
		register_rest_route(
			$this->namespace,
			'/pro/multisite/sites',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_multisite_sites' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
			)
		);

		// Create multisite backup.
		register_rest_route(
			$this->namespace,
			'/pro/multisite/backup',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_multisite_backup' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'site_ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'sanitize_callback' => function ( $value ) {
								return array_map( 'absint', (array) $value );
							},
						),
						'archive_mode' => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'single',
							'enum'     => array( 'single', 'separate' ),
						),
						'options' => array(
							'required' => false,
							'type'     => 'object',
							'default'  => array(),
						),
					),
				),
			)
		);

		// Get multisite backup status.
		register_rest_route(
			$this->namespace,
			'/pro/multisite/backup/(?P<job_id>[a-zA-Z0-9-]+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_multisite_backup_status' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'job_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Estimate backup size.
		register_rest_route(
			$this->namespace,
			'/pro/multisite/estimate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'estimate_multisite_backup_size' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'site_ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'sanitize_callback' => function ( $value ) {
								return array_map( 'absint', (array) $value );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Check admin permissions.
	 *
	 * Network-wide operations require a super admin on multisite; on single
	 * site installs manage_options is sufficient.
	 *
	 * @return bool|WP_Error True if user has permission.
	 */
	public function check_admin_permissions() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'swish-migrate-and-backup' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get multisite sites.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_multisite_sites( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->multisite_detector->is_multisite() ) {
			return rest_ensure_response(
				array(
					'is_multisite' => false,
					'sites'        => array(),
				)
			);
		}

		$sites        = $this->multisite_manager->get_sites_for_selection();
		$network_info = $this->multisite_detector->get_network_info();

		return rest_ensure_response(
			array(
				'is_multisite' => true,
				'network'      => $network_info,
				'sites'        => $sites,
				'total_sites'  => count( $sites ),
			)
		);
	}

	/**
	 * Create multisite backup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function create_multisite_backup( WP_REST_Request $request ) {
		$site_ids     = $request->get_param( 'site_ids' );
		$archive_mode = $request->get_param( 'archive_mode' );
		$options      = $request->get_param( 'options' );

		if ( empty( $site_ids ) ) {
			return new WP_Error(
				'invalid_site_ids',
				__( 'No sites selected for backup.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		$options['archive_mode'] = $archive_mode;

		$result = $this->multisite_manager->backup_sites( $site_ids, $options );

		if ( null === $result ) {
			return new WP_Error(
				'backup_failed',
				__( 'Multisite backup creation failed.', 'swish-migrate-and-backup' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get multisite backup status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_multisite_backup_status( WP_REST_Request $request ) {
		global $wpdb;

		$job_id = $request->get_param( 'job_id' );
		$table  = $wpdb->base_prefix . 'swish_backup_multisite_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Backup job not found.', 'swish-migrate-and-backup' ),
				array( 'status' => 404 )
			);
		}

		// Decode site_ids.
		$job['site_ids'] = json_decode( $job['site_ids'], true );

		// Get individual site statuses if separate archives.
		if ( $job['archive_mode'] === 'separate' ) {
			$sites_table = $wpdb->base_prefix . 'swish_backup_site_backups';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$site_backups = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$sites_table} WHERE parent_job_id = %s",
					$job_id
				),
				ARRAY_A
			);

			$job['site_backups'] = $site_backups;
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Estimate multisite backup size.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function estimate_multisite_backup_size( WP_REST_Request $request ) {
		$site_ids = $request->get_param( 'site_ids' );

		if ( empty( $site_ids ) ) {
			return new WP_Error(
				'invalid_site_ids',
				__( 'No sites selected for estimation.', 'swish-migrate-and-backup' ),
				array( 'status' => 400 )
			);
		}

		$estimation = $this->multisite_manager->estimate_backup_size( $site_ids );

		// Format sizes for display.
		$estimation['formatted'] = array(
			'total_database' => size_format( $estimation['total_database'] ),
			'total_uploads'  => size_format( $estimation['total_uploads'] ),
			'total_size'     => size_format( $estimation['total_size'] ),
		);

		foreach ( $estimation['site_sizes'] as $site_id => &$size ) {
			$size['formatted_total'] = size_format( $size['total_size'] );
		}

		return rest_ensure_response( $estimation );
	}
}
