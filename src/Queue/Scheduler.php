<?php
/**
 * Backup Scheduler.
 *
 * @package SwishMigrateAndBackup\Queue
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Queue;

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Logger\Logger;

/**
 * Handles scheduled backup operations.
 */
final class Scheduler {

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private BackupManager $backup_manager;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param BackupManager $backup_manager Backup manager.
	 * @param Logger        $logger         Logger instance.
	 */
	public function __construct( BackupManager $backup_manager, Logger $logger ) {
		$this->backup_manager = $backup_manager;
		$this->logger         = $logger;
	}

	/**
	 * Create a new schedule.
	 *
	 * @param array $data Schedule data.
	 * @return int|false Schedule ID or false on failure.
	 */
	public function create_schedule( array $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		$schedule = array(
			'name'                 => sanitize_text_field( $data['name'] ?? '' ),
			'frequency'            => sanitize_text_field( $data['frequency'] ?? 'daily' ),
			'backup_type'          => sanitize_text_field( $data['backup_type'] ?? 'full' ),
			'storage_destinations' => wp_json_encode( $data['storage_destinations'] ?? array( 'local' ) ),
			'retention_count'      => absint( $data['retention_count'] ?? 5 ),
			'is_active'            => 1,
			'options'              => wp_json_encode( $data['options'] ?? array() ),
			'created_at'           => current_time( 'mysql', true ),
		);

		// Calculate next run time.
		$schedule['next_run'] = $this->calculate_next_run( $schedule['frequency'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $schedule );

		if ( ! $result ) {
			return false;
		}

		$schedule_id = $wpdb->insert_id;

		// Schedule the cron event.
		$this->schedule_cron_event( $schedule_id, $schedule['frequency'], strtotime( $schedule['next_run'] ) );

		$this->logger->info( 'Schedule created', array(
			'schedule_id' => $schedule_id,
			'name'        => $schedule['name'],
		) );

		return $schedule_id;
	}

	/**
	 * Update a schedule.
	 *
	 * @param int   $schedule_id Schedule ID.
	 * @param array $data        Schedule data.
	 * @return bool True if updated.
	 */
	public function update_schedule( int $schedule_id, array $data ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		$update = array();

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['frequency'] ) ) {
			$update['frequency'] = sanitize_text_field( $data['frequency'] );
			$update['next_run'] = $this->calculate_next_run( $update['frequency'] );
		}
		if ( isset( $data['backup_type'] ) ) {
			$update['backup_type'] = sanitize_text_field( $data['backup_type'] );
		}
		if ( isset( $data['storage_destinations'] ) ) {
			$update['storage_destinations'] = wp_json_encode( $data['storage_destinations'] );
		}
		if ( isset( $data['retention_count'] ) ) {
			$update['retention_count'] = absint( $data['retention_count'] );
		}
		if ( isset( $data['is_active'] ) ) {
			$update['is_active'] = $data['is_active'] ? 1 : 0;
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $update, array( 'id' => $schedule_id ) );

		if ( false === $result ) {
			return false;
		}

		// Reschedule cron if frequency changed.
		if ( isset( $update['frequency'] ) ) {
			$this->unschedule_cron_event( $schedule_id );
			if ( $update['is_active'] ?? true ) {
				$this->schedule_cron_event( $schedule_id, $update['frequency'], strtotime( $update['next_run'] ) );
			}
		}

		return true;
	}

	/**
	 * Delete a schedule.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return bool True if deleted.
	 */
	public function delete_schedule( int $schedule_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		// Unschedule cron event.
		$this->unschedule_cron_event( $schedule_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $schedule_id ) );

		return false !== $result;
	}

	/**
	 * Get all schedules.
	 *
	 * @return array Schedules.
	 */
	public function get_schedules(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$schedules = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} ORDER BY created_at DESC",
			ARRAY_A
		);

		foreach ( $schedules as &$schedule ) {
			$schedule['storage_destinations'] = json_decode( $schedule['storage_destinations'], true );
			$schedule['options'] = json_decode( $schedule['options'], true );
			$schedule['is_active'] = (bool) $schedule['is_active'];
		}

		return $schedules ?: array();
	}

	/**
	 * Get a single schedule.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return array|null Schedule data or null.
	 */
	public function get_schedule( int $schedule_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d",
				$schedule_id
			),
			ARRAY_A
		);

		if ( ! $schedule ) {
			return null;
		}

		$schedule['storage_destinations'] = json_decode( $schedule['storage_destinations'], true );
		$schedule['options'] = json_decode( $schedule['options'], true );
		$schedule['is_active'] = (bool) $schedule['is_active'];

		return $schedule;
	}

	/**
	 * Run a scheduled backup.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return bool True if successful.
	 */
	public function run_scheduled_backup( int $schedule_id = 0 ): bool {
		// If no schedule ID provided, get the next due schedule.
		if ( ! $schedule_id ) {
			$schedule = $this->get_next_due_schedule();
		} else {
			$schedule = $this->get_schedule( $schedule_id );
		}

		if ( ! $schedule || ! $schedule['is_active'] ) {
			return false;
		}

		$this->logger->info( 'Running scheduled backup', array(
			'schedule_id'   => $schedule['id'],
			'schedule_name' => $schedule['name'],
		) );

		$options = array(
			'storage_destinations' => $schedule['storage_destinations'],
		);

		// Run the appropriate backup type.
		$result = match ( $schedule['backup_type'] ) {
			'database' => $this->backup_manager->create_database_backup( $options ),
			'files'    => $this->backup_manager->create_files_backup( $options ),
			default    => $this->backup_manager->create_full_backup( $options ),
		};

		// Update last run time and calculate next run.
		$this->update_after_run( (int) $schedule['id'], $schedule['frequency'] );

		// Apply retention policy.
		$this->backup_manager->apply_retention_policy( $schedule['retention_count'] );

		return null !== $result;
	}

	/**
	 * Get the next schedule that is due to run.
	 *
	 * @return array|null Schedule or null.
	 */
	private function get_next_due_schedule(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE is_active = 1 AND next_run <= %s ORDER BY next_run ASC LIMIT 1",
				$now
			),
			ARRAY_A
		);

		if ( ! $schedule ) {
			return null;
		}

		$schedule['storage_destinations'] = json_decode( $schedule['storage_destinations'], true );
		$schedule['options'] = json_decode( $schedule['options'], true );

		return $schedule;
	}

	/**
	 * Update schedule after a run.
	 *
	 * @param int    $schedule_id Schedule ID.
	 * @param string $frequency   Frequency.
	 * @return void
	 */
	private function update_after_run( int $schedule_id, string $frequency ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_schedules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'last_run' => current_time( 'mysql', true ),
				'next_run' => $this->calculate_next_run( $frequency ),
			),
			array( 'id' => $schedule_id )
		);
	}

	/**
	 * Calculate the next run time based on frequency.
	 *
	 * @param string $frequency Frequency.
	 * @return string Next run datetime.
	 */
	private function calculate_next_run( string $frequency ): string {
		$intervals = array(
			'hourly'     => '+1 hour',
			'twicedaily' => '+12 hours',
			'daily'      => '+1 day',
			'weekly'     => '+1 week',
			'monthly'    => '+1 month',
		);

		$interval = $intervals[ $frequency ] ?? '+1 day';
		return gmdate( 'Y-m-d H:i:s', strtotime( $interval ) );
	}

	/**
	 * Schedule a WordPress cron event.
	 *
	 * @param int    $schedule_id Schedule ID.
	 * @param string $frequency   Frequency.
	 * @param int    $timestamp   First run timestamp.
	 * @return void
	 */
	private function schedule_cron_event( int $schedule_id, string $frequency, int $timestamp ): void {
		$recurrence = match ( $frequency ) {
			'hourly'     => 'hourly',
			'twicedaily' => 'twicedaily',
			'daily'      => 'daily',
			'weekly'     => 'swish_backup_weekly',
			'monthly'    => 'swish_backup_monthly',
			default      => 'daily',
		};

		wp_schedule_event( $timestamp, $recurrence, 'swish_backup_scheduled_backup', array( $schedule_id ) );
	}

	/**
	 * Unschedule a WordPress cron event.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return void
	 */
	private function unschedule_cron_event( int $schedule_id ): void {
		$timestamp = wp_next_scheduled( 'swish_backup_scheduled_backup', array( $schedule_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'swish_backup_scheduled_backup', array( $schedule_id ) );
		}
	}

	/**
	 * Toggle schedule active status.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return bool New active status.
	 */
	public function toggle_schedule( int $schedule_id ): bool {
		$schedule = $this->get_schedule( $schedule_id );

		if ( ! $schedule ) {
			return false;
		}

		$new_status = ! $schedule['is_active'];
		$this->update_schedule( $schedule_id, array( 'is_active' => $new_status ) );

		if ( $new_status ) {
			$this->schedule_cron_event(
				$schedule_id,
				$schedule['frequency'],
				strtotime( $schedule['next_run'] )
			);
		} else {
			$this->unschedule_cron_event( $schedule_id );
		}

		return $new_status;
	}
}
