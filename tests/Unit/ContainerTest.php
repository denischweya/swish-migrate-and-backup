<?php
/**
 * Container Unit Tests.
 *
 * @package SwishMigrateAndBackup\Tests\Unit
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SwishMigrateAndBackup\Core\Container;
use InvalidArgumentException;

/**
 * Tests for the Container class.
 */
class ContainerTest extends TestCase {

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Container::reset_instance();
		$this->container = Container::get_instance();
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tearDown(): void {
		$this->container->reset();
		Container::reset_instance();
		parent::tearDown();
	}

	/**
	 * Test singleton pattern.
	 */
	public function test_singleton_returns_same_instance(): void {
		$instance1 = Container::get_instance();
		$instance2 = Container::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test binding and resolving a service.
	 */
	public function test_bind_and_get(): void {
		$this->container->bind( 'test_service', fn() => new \stdClass() );

		$service = $this->container->get( 'test_service' );

		$this->assertInstanceOf( \stdClass::class, $service );
	}

	/**
	 * Test singleton binding.
	 */
	public function test_singleton_returns_same_instance(): void {
		$this->container->singleton( 'singleton_service', fn() => new \stdClass() );

		$service1 = $this->container->get( 'singleton_service' );
		$service2 = $this->container->get( 'singleton_service' );

		$this->assertSame( $service1, $service2 );
	}

	/**
	 * Test non-singleton creates new instances.
	 */
	public function test_non_singleton_creates_new_instances(): void {
		$this->container->bind( 'transient_service', fn() => new \stdClass() );

		$service1 = $this->container->get( 'transient_service' );
		$service2 = $this->container->get( 'transient_service' );

		$this->assertNotSame( $service1, $service2 );
	}

	/**
	 * Test registering an instance.
	 */
	public function test_instance_registration(): void {
		$instance = new \stdClass();
		$instance->test = 'value';

		$this->container->instance( 'my_instance', $instance );

		$resolved = $this->container->get( 'my_instance' );

		$this->assertSame( $instance, $resolved );
		$this->assertSame( 'value', $resolved->test );
	}

	/**
	 * Test has method.
	 */
	public function test_has_returns_correct_value(): void {
		$this->assertFalse( $this->container->has( 'non_existent' ) );

		$this->container->bind( 'exists', fn() => new \stdClass() );

		$this->assertTrue( $this->container->has( 'exists' ) );
	}

	/**
	 * Test exception for unregistered service.
	 */
	public function test_get_throws_exception_for_unregistered_service(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->container->get( 'non_existent_service' );
	}

	/**
	 * Test dependency injection through container.
	 */
	public function test_dependency_injection(): void {
		$this->container->singleton( 'dependency', fn() => new \stdClass() );
		$this->container->bind( 'service', fn( Container $c ) => (object) array( 'dep' => $c->get( 'dependency' ) ) );

		$service = $this->container->get( 'service' );
		$dependency = $this->container->get( 'dependency' );

		$this->assertSame( $dependency, $service->dep );
	}

	/**
	 * Test reset clears all bindings.
	 */
	public function test_reset_clears_bindings(): void {
		$this->container->bind( 'test', fn() => new \stdClass() );
		$this->assertTrue( $this->container->has( 'test' ) );

		$this->container->reset();

		$this->assertFalse( $this->container->has( 'test' ) );
	}
}
