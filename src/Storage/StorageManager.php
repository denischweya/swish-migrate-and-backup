<?php
/**
 * Storage Manager.
 *
 * @package SwishMigrateAndBackup\Storage
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage;

use SwishMigrateAndBackup\Core\Container;
use SwishMigrateAndBackup\Storage\Contracts\StorageAdapterInterface;
use InvalidArgumentException;

/**
 * Manages storage adapters and provides a unified interface.
 */
final class StorageManager {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Registered storage adapters.
	 *
	 * @var array<string, StorageAdapterInterface>
	 */
	private array $adapters = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register a storage adapter.
	 *
	 * @param string                  $id      Unique adapter identifier.
	 * @param StorageAdapterInterface $adapter Adapter instance.
	 * @return self
	 */
	public function register_adapter( string $id, StorageAdapterInterface $adapter ): self {
		$this->adapters[ $id ] = $adapter;
		return $this;
	}

	/**
	 * Get a storage adapter by ID.
	 *
	 * @param string $id Adapter identifier.
	 * @return StorageAdapterInterface
	 * @throws InvalidArgumentException If adapter not found.
	 */
	public function get_adapter( string $id ): StorageAdapterInterface {
		if ( ! isset( $this->adapters[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: adapter identifier */
					esc_html__( 'Storage adapter not found: %s', 'swish-migrate-and-backup' ),
					esc_html( $id )
				)
			);
		}

		return $this->adapters[ $id ];
	}

	/**
	 * Check if an adapter is registered.
	 *
	 * @param string $id Adapter identifier.
	 * @return bool
	 */
	public function has_adapter( string $id ): bool {
		return isset( $this->adapters[ $id ] );
	}

	/**
	 * Get all registered adapters.
	 *
	 * @return array<string, StorageAdapterInterface>
	 */
	public function get_all_adapters(): array {
		return $this->adapters;
	}

	/**
	 * Get all configured (ready to use) adapters.
	 *
	 * @return array<string, StorageAdapterInterface>
	 */
	public function get_configured_adapters(): array {
		return array_filter(
			$this->adapters,
			fn( StorageAdapterInterface $adapter ) => $adapter->is_configured()
		);
	}

	/**
	 * Get the default storage adapter.
	 *
	 * @return StorageAdapterInterface
	 */
	public function get_default_adapter(): StorageAdapterInterface {
		$settings = get_option( 'swish_backup_settings', array() );
		$default  = $settings['default_storage'] ?? 'local';

		if ( $this->has_adapter( $default ) && $this->adapters[ $default ]->is_configured() ) {
			return $this->adapters[ $default ];
		}

		// Fall back to local adapter.
		return $this->get_adapter( 'local' );
	}

	/**
	 * Upload a file to specified storage destinations.
	 *
	 * @param string $local_path    Local file path.
	 * @param string $remote_path   Remote file path.
	 * @param array  $destinations  Array of adapter IDs.
	 * @return array Results keyed by adapter ID.
	 */
	public function upload_to_destinations(
		string $local_path,
		string $remote_path,
		array $destinations
	): array {
		$results = array();

		foreach ( $destinations as $destination ) {
			if ( ! $this->has_adapter( $destination ) ) {
				$results[ $destination ] = array(
					'success' => false,
					'error'   => __( 'Adapter not found', 'swish-migrate-and-backup' ),
				);
				continue;
			}

			$adapter = $this->get_adapter( $destination );

			if ( ! $adapter->is_configured() ) {
				$results[ $destination ] = array(
					'success' => false,
					'error'   => __( 'Adapter not configured', 'swish-migrate-and-backup' ),
				);
				continue;
			}

			try {
				$success = $adapter->upload( $local_path, $remote_path );
				$results[ $destination ] = array(
					'success' => $success,
					'error'   => $success ? null : __( 'Upload failed', 'swish-migrate-and-backup' ),
				);
			} catch ( \Exception $e ) {
				$results[ $destination ] = array(
					'success' => false,
					'error'   => $e->getMessage(),
				);
			}
		}

		return $results;
	}

	/**
	 * Delete a file from specified storage destinations.
	 *
	 * @param string $remote_path  Remote file path.
	 * @param array  $destinations Array of adapter IDs.
	 * @return array Results keyed by adapter ID.
	 */
	public function delete_from_destinations( string $remote_path, array $destinations ): array {
		$results = array();

		foreach ( $destinations as $destination ) {
			if ( ! $this->has_adapter( $destination ) ) {
				$results[ $destination ] = false;
				continue;
			}

			$adapter = $this->get_adapter( $destination );

			try {
				$results[ $destination ] = $adapter->delete( $remote_path );
			} catch ( \Exception $e ) {
				$results[ $destination ] = false;
			}
		}

		return $results;
	}

	/**
	 * Get adapter info for display.
	 *
	 * @return array Array of adapter info.
	 */
	public function get_adapters_info(): array {
		$info = array();

		foreach ( $this->adapters as $id => $adapter ) {
			$info[ $id ] = array(
				'id'          => $id,
				'name'        => $adapter->get_name(),
				'configured'  => $adapter->is_configured(),
				'connected'   => $adapter->is_configured() ? $adapter->connect() : false,
				'storage'     => $adapter->get_storage_info(),
			);
		}

		return $info;
	}

	/**
	 * Get total storage usage across all adapters.
	 *
	 * @return array Storage usage info.
	 */
	public function get_total_storage_usage(): array {
		$total_used = 0;
		$by_adapter = array();

		foreach ( $this->get_configured_adapters() as $id => $adapter ) {
			$info = $adapter->get_storage_info();
			if ( isset( $info['used'] ) && is_numeric( $info['used'] ) ) {
				$total_used += $info['used'];
				$by_adapter[ $id ] = $info['used'];
			}
		}

		return array(
			'total'      => $total_used,
			'by_adapter' => $by_adapter,
		);
	}
}
