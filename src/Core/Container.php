<?php
/**
 * Service Container for dependency injection.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

use Closure;
use InvalidArgumentException;

/**
 * Simple dependency injection container.
 */
final class Container {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered service factories.
	 *
	 * @var array<string, Closure>
	 */
	private array $factories = array();

	/**
	 * Resolved singleton instances.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Services registered as singletons.
	 *
	 * @var array<string, bool>
	 */
	private array $singletons = array();

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance of the container.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a service factory.
	 *
	 * @param string  $id       Service identifier.
	 * @param Closure $factory  Factory closure that creates the service.
	 * @param bool    $singleton Whether to cache the instance.
	 * @return self
	 */
	public function bind( string $id, Closure $factory, bool $singleton = false ): self {
		$this->factories[ $id ]  = $factory;
		$this->singletons[ $id ] = $singleton;

		return $this;
	}

	/**
	 * Register a singleton service.
	 *
	 * @param string  $id      Service identifier.
	 * @param Closure $factory Factory closure.
	 * @return self
	 */
	public function singleton( string $id, Closure $factory ): self {
		return $this->bind( $id, $factory, true );
	}

	/**
	 * Register an existing instance.
	 *
	 * @param string $id       Service identifier.
	 * @param object $instance The instance to register.
	 * @return self
	 */
	public function instance( string $id, object $instance ): self {
		$this->instances[ $id ]  = $instance;
		$this->singletons[ $id ] = true;

		return $this;
	}

	/**
	 * Resolve a service from the container.
	 *
	 * @param string $id Service identifier.
	 * @return object
	 * @throws InvalidArgumentException If service not found.
	 */
	public function get( string $id ): object {
		// Return cached singleton instance.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Check if factory exists.
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: service identifier */
					esc_html__( 'Service not found: %s', 'swish-migrate-and-backup' ),
					esc_html( $id )
				)
			);
		}

		// Create the instance.
		$instance = $this->factories[ $id ]( $this );

		// Cache if singleton.
		if ( $this->singletons[ $id ] ) {
			$this->instances[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Reset the container (primarily for testing).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->factories  = array();
		$this->instances  = array();
		$this->singletons = array();
	}

	/**
	 * Reset the singleton instance (primarily for testing).
	 *
	 * @return void
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
