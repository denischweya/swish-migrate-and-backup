<?php
/**
 * Job Queue Handler.
 *
 * @package SwishMigrateAndBackup\Queue
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Queue;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Handles background job processing.
 */
final class JobQueue {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Option name for storing queue.
	 */
	private const QUEUE_OPTION = 'swish_backup_job_queue';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Add a job to the queue.
	 *
	 * @param string $type Job type.
	 * @param array  $data Job data.
	 * @return string Job ID.
	 */
	public function add_job( string $type, array $data = array() ): string {
		$job_id = wp_generate_uuid4();

		$job = array(
			'id'         => $job_id,
			'type'       => $type,
			'data'       => $data,
			'status'     => 'pending',
			'progress'   => 0,
			'created_at' => current_time( 'mysql', true ),
			'started_at' => null,
			'attempts'   => 0,
		);

		$queue = $this->get_queue();
		$queue[ $job_id ] = $job;
		$this->save_queue( $queue );

		$this->logger->info( 'Job added to queue', array(
			'job_id' => $job_id,
			'type'   => $type,
		) );

		return $job_id;
	}

	/**
	 * Get a job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null if not found.
	 */
	public function get_job( string $job_id ): ?array {
		$queue = $this->get_queue();
		return $queue[ $job_id ] ?? null;
	}

	/**
	 * Update job status.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $status   New status.
	 * @param int    $progress Progress percentage.
	 * @param array  $data     Additional data to merge.
	 * @return bool True if updated.
	 */
	public function update_job( string $job_id, string $status, int $progress = 0, array $data = array() ): bool {
		$queue = $this->get_queue();

		if ( ! isset( $queue[ $job_id ] ) ) {
			return false;
		}

		$queue[ $job_id ]['status'] = $status;
		$queue[ $job_id ]['progress'] = $progress;

		if ( 'processing' === $status && null === $queue[ $job_id ]['started_at'] ) {
			$queue[ $job_id ]['started_at'] = current_time( 'mysql', true );
		}

		if ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
			$queue[ $job_id ]['completed_at'] = current_time( 'mysql', true );
		}

		if ( ! empty( $data ) ) {
			$queue[ $job_id ]['data'] = array_merge( $queue[ $job_id ]['data'], $data );
		}

		$this->save_queue( $queue );

		return true;
	}

	/**
	 * Get the next pending job.
	 *
	 * @return array|null Next job or null.
	 */
	public function get_next_job(): ?array {
		$queue = $this->get_queue();

		foreach ( $queue as $job ) {
			if ( 'pending' === $job['status'] ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * Remove a job from the queue.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True if removed.
	 */
	public function remove_job( string $job_id ): bool {
		$queue = $this->get_queue();

		if ( ! isset( $queue[ $job_id ] ) ) {
			return false;
		}

		unset( $queue[ $job_id ] );
		$this->save_queue( $queue );

		return true;
	}

	/**
	 * Clear completed jobs.
	 *
	 * @param int $max_age Maximum age in seconds.
	 * @return int Number of cleared jobs.
	 */
	public function clear_completed_jobs( int $max_age = 86400 ): int {
		$queue = $this->get_queue();
		$cleared = 0;
		$cutoff = time() - $max_age;

		foreach ( $queue as $job_id => $job ) {
			if ( 'completed' === $job['status'] || 'failed' === $job['status'] ) {
				$completed_at = strtotime( $job['completed_at'] ?? $job['created_at'] );
				if ( $completed_at < $cutoff ) {
					unset( $queue[ $job_id ] );
					++$cleared;
				}
			}
		}

		$this->save_queue( $queue );

		return $cleared;
	}

	/**
	 * Get all jobs.
	 *
	 * @param string|null $status Filter by status.
	 * @return array Jobs.
	 */
	public function get_all_jobs( ?string $status = null ): array {
		$queue = $this->get_queue();

		if ( null === $status ) {
			return array_values( $queue );
		}

		return array_values( array_filter(
			$queue,
			fn( $job ) => $job['status'] === $status
		) );
	}

	/**
	 * Get the queue from storage.
	 *
	 * @return array
	 */
	private function get_queue(): array {
		return get_option( self::QUEUE_OPTION, array() );
	}

	/**
	 * Save the queue to storage.
	 *
	 * @param array $queue Queue data.
	 * @return void
	 */
	private function save_queue( array $queue ): void {
		update_option( self::QUEUE_OPTION, $queue, false );
	}
}
