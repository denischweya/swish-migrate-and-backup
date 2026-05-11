<?php
/**
 * Chunking Manager - Abstract base class for resumable operations.
 *
 * Based on Duplicator Pro's chunking pattern for reliable, resumable
 * backup operations that work within server timeout limits.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class for chunked/resumable operations.
 *
 * Key features:
 * - 10-second worker time slices (configurable)
 * - State persistence for resumability
 * - Throttling support to reduce server load
 * - Progress tracking
 */
abstract class ChunkingManager {

	/**
	 * Chunk result: operation completed successfully.
	 */
	public const CHUNK_COMPLETE = 0;

	/**
	 * Chunk result: stopped due to timeout, can resume.
	 */
	public const CHUNK_STOP = 1;

	/**
	 * Chunk result: error occurred.
	 */
	public const CHUNK_ERROR = -1;

	/**
	 * Default time slice in seconds (10 seconds like Duplicator Pro).
	 */
	public const DEFAULT_TIME_SLICE = 10;

	/**
	 * Maximum iterations before stopping (0 = no limit).
	 *
	 * @var int
	 */
	protected int $max_iterations = 0;

	/**
	 * Time slice in seconds before stopping.
	 *
	 * @var int
	 */
	protected int $time_slice = self::DEFAULT_TIME_SLICE;

	/**
	 * Throttling delay in microseconds between iterations.
	 *
	 * @var int
	 */
	protected int $throttle_delay = 0;

	/**
	 * Timestamp when the current chunk started.
	 *
	 * @var float
	 */
	protected float $start_time = 0;

	/**
	 * Timestamp when we should stop (timeout).
	 *
	 * @var int
	 */
	protected int $timeout_timestamp = 0;

	/**
	 * Number of iterations in current chunk.
	 *
	 * @var int
	 */
	protected int $iteration_count = 0;

	/**
	 * Total items to process.
	 *
	 * @var int
	 */
	protected int $total_items = 0;

	/**
	 * Items processed so far (across all chunks).
	 *
	 * @var int
	 */
	protected int $processed_items = 0;

	/**
	 * Current position for resuming (implementation-specific).
	 *
	 * @var array
	 */
	protected array $position = array();

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	protected string $last_error = '';

	/**
	 * Job ID for state persistence.
	 *
	 * @var string
	 */
	protected string $job_id = '';

	/**
	 * State manager for persistence.
	 *
	 * @var BackupState|null
	 */
	protected ?BackupState $state = null;

	/**
	 * Constructor.
	 *
	 * @param string $job_id         Job ID for state persistence.
	 * @param int    $time_slice     Time slice in seconds (default 10).
	 * @param int    $max_iterations Maximum iterations per chunk (0 = no limit).
	 * @param int    $throttle_delay Microseconds to sleep between iterations.
	 */
	public function __construct(
		string $job_id,
		int $time_slice = self::DEFAULT_TIME_SLICE,
		int $max_iterations = 0,
		int $throttle_delay = 0
	) {
		$this->job_id         = $job_id;
		$this->time_slice     = max( 1, $time_slice );
		$this->max_iterations = $max_iterations;
		$this->throttle_delay = $throttle_delay;
		$this->state          = new BackupState();
	}

	/**
	 * Process a single item.
	 *
	 * Implement this method to define what happens for each item.
	 *
	 * @param mixed $item Current item to process.
	 * @return bool True on success, false on failure.
	 */
	abstract protected function process_item( $item ): bool;

	/**
	 * Get the next item to process.
	 *
	 * Implement this method to provide items to process.
	 *
	 * @return mixed|null Next item or null if no more items.
	 */
	abstract protected function get_next_item();

	/**
	 * Check if there are more items to process.
	 *
	 * @return bool True if more items exist.
	 */
	abstract protected function has_more_items(): bool;

	/**
	 * Save current state for resuming.
	 *
	 * @return bool True on success.
	 */
	abstract protected function save_state(): bool;

	/**
	 * Restore state from previous chunk.
	 *
	 * @return bool True if state was restored.
	 */
	abstract protected function restore_state(): bool;

	/**
	 * Run a chunk of processing.
	 *
	 * @param bool $rewind If true, start from beginning (ignore saved state).
	 * @return int CHUNK_COMPLETE, CHUNK_STOP, or CHUNK_ERROR.
	 */
	public function run( bool $rewind = false ): int {
		$this->iteration_count = 0;
		$this->last_error      = '';

		// Initialize or restore state.
		if ( $rewind ) {
			$this->reset_state();
		} else {
			$this->restore_state();
		}

		// Start the timer.
		$this->start_timer();

		// Process items until done, timeout, or error.
		while ( $this->has_more_items() ) {
			$item = $this->get_next_item();
			if ( null === $item ) {
				break;
			}

			++$this->iteration_count;

			try {
				if ( ! $this->process_item( $item ) ) {
					$this->last_error = 'Failed to process item';
					$this->save_state();
					return self::CHUNK_ERROR;
				}
			} catch ( \Exception $e ) {
				$this->last_error = $e->getMessage();
				$this->save_state();
				return self::CHUNK_ERROR;
			}

			++$this->processed_items;

			// Apply throttling if configured.
			if ( $this->throttle_delay > 0 ) {
				usleep( $this->throttle_delay );
			}

			// Check if we need to stop (timeout or max iterations).
			if ( $this->should_stop() ) {
				$this->save_state();
				return self::CHUNK_STOP;
			}
		}

		// All items processed.
		$this->cleanup_state();
		return self::CHUNK_COMPLETE;
	}

	/**
	 * Start the timeout timer.
	 *
	 * @return void
	 */
	protected function start_timer(): void {
		$this->start_time        = microtime( true );
		$this->timeout_timestamp = time() + $this->time_slice;
	}

	/**
	 * Check if we should stop processing.
	 *
	 * @return bool True if we should stop.
	 */
	protected function should_stop(): bool {
		// Check timeout.
		if ( time() >= $this->timeout_timestamp ) {
			return true;
		}

		// Check max iterations.
		if ( $this->max_iterations > 0 && $this->iteration_count >= $this->max_iterations ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if we're approaching the timeout.
	 *
	 * @param int $buffer_seconds Seconds of buffer before timeout.
	 * @return bool True if within buffer of timeout.
	 */
	protected function is_approaching_timeout( int $buffer_seconds = 2 ): bool {
		return time() >= ( $this->timeout_timestamp - $buffer_seconds );
	}

	/**
	 * Get elapsed time in seconds.
	 *
	 * @return float Elapsed time.
	 */
	public function get_elapsed_time(): float {
		return microtime( true ) - $this->start_time;
	}

	/**
	 * Get progress percentage.
	 *
	 * @return float Progress percentage (0-100).
	 */
	public function get_progress(): float {
		if ( $this->total_items <= 0 ) {
			return 0.0;
		}

		$progress = ( $this->processed_items / $this->total_items ) * 100;
		return min( 100.0, max( 0.0, $progress ) );
	}

	/**
	 * Get the number of items processed in this chunk.
	 *
	 * @return int Iteration count.
	 */
	public function get_iteration_count(): int {
		return $this->iteration_count;
	}

	/**
	 * Get total processed items (across all chunks).
	 *
	 * @return int Processed items count.
	 */
	public function get_processed_items(): int {
		return $this->processed_items;
	}

	/**
	 * Get the last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Get current position (for state saving).
	 *
	 * @return array Position data.
	 */
	public function get_position(): array {
		return $this->position;
	}

	/**
	 * Set the current position (for state restoring).
	 *
	 * @param array $position Position data.
	 * @return void
	 */
	public function set_position( array $position ): void {
		$this->position = $position;
	}

	/**
	 * Reset state for a fresh start.
	 *
	 * @return void
	 */
	protected function reset_state(): void {
		$this->position        = array();
		$this->processed_items = 0;
		$this->iteration_count = 0;
		$this->last_error      = '';

		// Clean up any saved state.
		$this->cleanup_state();
	}

	/**
	 * Clean up saved state after completion.
	 *
	 * @return void
	 */
	protected function cleanup_state(): void {
		if ( $this->state && ! empty( $this->job_id ) ) {
			// State cleanup is handled by BackupState::cleanup() when job completes.
		}
	}

	/**
	 * Set time slice.
	 *
	 * @param int $seconds Time slice in seconds.
	 * @return self
	 */
	public function set_time_slice( int $seconds ): self {
		$this->time_slice = max( 1, $seconds );
		return $this;
	}

	/**
	 * Set maximum iterations per chunk.
	 *
	 * @param int $max Maximum iterations (0 = no limit).
	 * @return self
	 */
	public function set_max_iterations( int $max ): self {
		$this->max_iterations = max( 0, $max );
		return $this;
	}

	/**
	 * Set throttle delay.
	 *
	 * @param int $microseconds Microseconds to sleep between iterations.
	 * @return self
	 */
	public function set_throttle_delay( int $microseconds ): self {
		$this->throttle_delay = max( 0, $microseconds );
		return $this;
	}

	/**
	 * Set total items count for progress calculation.
	 *
	 * @param int $total Total items.
	 * @return self
	 */
	public function set_total_items( int $total ): self {
		$this->total_items = $total;
		return $this;
	}

	/**
	 * Get state key for persistence.
	 *
	 * @param string $suffix Optional suffix for the key.
	 * @return string State key.
	 */
	protected function get_state_key( string $suffix = '' ): string {
		$class_name = ( new \ReflectionClass( $this ) )->getShortName();
		$key        = 'chunking_' . strtolower( $class_name );

		if ( ! empty( $suffix ) ) {
			$key .= '_' . $suffix;
		}

		return $key;
	}
}
