<?php
/**
 * File Queue - Database-backed queue for chunked file processing.
 *
 * This implements a proper queue architecture that:
 * - Separates file indexing from processing
 * - Tracks individual file status
 * - Supports retry/recovery
 * - Enables chunked processing with hard time budgets
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-backed file queue for reliable chunked processing.
 */
final class FileQueue {

	/**
	 * Table name (without prefix).
	 */
	private const TABLE_NAME = 'swish_file_queue';

	/**
	 * File statuses.
	 */
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_SKIPPED    = 'skipped';

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the queue table if it doesn't exist.
	 *
	 * @return bool True on success.
	 */
	public static function create_table(): bool {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL,
			file_path VARCHAR(512) NOT NULL,
			relative_path VARCHAR(512) NOT NULL,
			file_size BIGINT UNSIGNED DEFAULT 0,
			status VARCHAR(20) DEFAULT 'pending',
			attempts TINYINT UNSIGNED DEFAULT 0,
			bytes_written BIGINT UNSIGNED DEFAULT 0,
			chunk_index INT UNSIGNED DEFAULT 0,
			error_message VARCHAR(255) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_job_status (job_id, status),
			INDEX idx_job_id (job_id),
			INDEX idx_status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Drop the queue table.
	 *
	 * @return bool
	 */
	public static function drop_table(): bool {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ) !== false;
	}

	/**
	 * Clear all files for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return int Number of rows deleted.
	 */
	public static function clear_job( string $job_id ): int {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( $table_name, array( 'job_id' => $job_id ), array( '%s' ) );
	}

	/**
	 * Add a file to the queue.
	 *
	 * @param string $job_id        Job ID.
	 * @param string $file_path     Absolute file path.
	 * @param string $relative_path Relative path for archive.
	 * @param int    $file_size     File size in bytes.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function add_file( string $job_id, string $file_path, string $relative_path, int $file_size ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'job_id'        => $job_id,
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'file_size'     => $file_size,
				'status'        => self::STATUS_PENDING,
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Add multiple files to the queue in a single batch.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $files  Array of file data with 'path', 'relative', 'size' keys.
	 * @return int Number of files added.
	 */
	public static function add_files_batch( string $job_id, array $files ): int {
		global $wpdb;

		if ( empty( $files ) ) {
			return 0;
		}

		$table_name = self::get_table_name();
		$values     = array();
		$placeholders = array();

		foreach ( $files as $file ) {
			$values[] = $job_id;
			$values[] = $file['path'];
			$values[] = $file['relative'];
			$values[] = $file['size'] ?? 0;
			$values[] = self::STATUS_PENDING;

			$placeholders[] = '(%s, %s, %s, %d, %s)';
		}

		$sql = "INSERT INTO {$table_name} (job_id, file_path, relative_path, file_size, status) VALUES ";
		$sql .= implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return $result !== false ? count( $files ) : 0;
	}

	/**
	 * Get next batch of pending files.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $limit  Maximum files to fetch.
	 * @return array Array of file records.
	 */
	public static function get_pending_batch( string $job_id, int $limit = 20 ): array {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				WHERE job_id = %s AND status = %s
				ORDER BY id ASC
				LIMIT %d",
				$job_id,
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Get files that need retry (failed with attempts < max).
	 *
	 * @param string $job_id      Job ID.
	 * @param int    $max_retries Maximum retry attempts.
	 * @param int    $limit       Maximum files to fetch.
	 * @return array Array of file records.
	 */
	public static function get_retryable( string $job_id, int $max_retries = 3, int $limit = 10 ): array {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				WHERE job_id = %s AND status = %s AND attempts < %d
				ORDER BY attempts ASC, id ASC
				LIMIT %d",
				$job_id,
				self::STATUS_FAILED,
				$max_retries,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Mark a file as processing.
	 *
	 * @param int $file_id File queue ID.
	 * @return bool
	 */
	public static function mark_processing( int $file_id ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array(
				'status'   => self::STATUS_PROCESSING,
				'attempts' => $wpdb->prepare( 'attempts + 1' ),
			),
			array( 'id' => $file_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Increment attempts separately since update() doesn't support expressions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET attempts = attempts + 1 WHERE id = %d",
				$file_id
			)
		);

		return $result !== false;
	}

	/**
	 * Mark a file as completed.
	 *
	 * @param int $file_id       File queue ID.
	 * @param int $bytes_written Bytes written to archive.
	 * @return bool
	 */
	public static function mark_completed( int $file_id, int $bytes_written = 0 ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$table_name,
			array(
				'status'        => self::STATUS_COMPLETED,
				'bytes_written' => $bytes_written,
			),
			array( 'id' => $file_id ),
			array( '%s', '%d' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Mark a file as failed.
	 *
	 * @param int    $file_id       File queue ID.
	 * @param string $error_message Error message.
	 * @param int    $bytes_written Partial bytes written (for resume).
	 * @return bool
	 */
	public static function mark_failed( int $file_id, string $error_message = '', int $bytes_written = 0 ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$table_name,
			array(
				'status'        => self::STATUS_FAILED,
				'error_message' => substr( $error_message, 0, 255 ),
				'bytes_written' => $bytes_written,
			),
			array( 'id' => $file_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Mark a file as skipped.
	 *
	 * @param int    $file_id File queue ID.
	 * @param string $reason  Skip reason.
	 * @return bool
	 */
	public static function mark_skipped( int $file_id, string $reason = '' ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$table_name,
			array(
				'status'        => self::STATUS_SKIPPED,
				'error_message' => substr( $reason, 0, 255 ),
			),
			array( 'id' => $file_id ),
			array( '%s', '%s' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Update file progress (for large files processed in chunks).
	 *
	 * @param int $file_id       File queue ID.
	 * @param int $bytes_written Total bytes written so far.
	 * @param int $chunk_index   Current chunk index.
	 * @return bool
	 */
	public static function update_progress( int $file_id, int $bytes_written, int $chunk_index ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$table_name,
			array(
				'bytes_written' => $bytes_written,
				'chunk_index'   => $chunk_index,
			),
			array( 'id' => $file_id ),
			array( '%d', '%d' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Reset a file to pending status (for partial writes that need retry).
	 *
	 * @param int $file_id File queue ID.
	 * @return bool
	 */
	public static function reset_to_pending( int $file_id ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$table_name,
			array( 'status' => self::STATUS_PENDING ),
			array( 'id' => $file_id ),
			array( '%s' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Reset processing files back to pending (for stale processing).
	 *
	 * @param string $job_id          Job ID.
	 * @param int    $stale_threshold Seconds before a processing file is considered stale.
	 * @return int Number of files reset.
	 */
	public static function reset_stale_processing( string $job_id, int $stale_threshold = 300 ): int {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET status = %s
				WHERE job_id = %s
				AND status = %s
				AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
				self::STATUS_PENDING,
				$job_id,
				self::STATUS_PROCESSING,
				$stale_threshold
			)
		);

		return $result !== false ? (int) $result : 0;
	}

	/**
	 * Get job statistics.
	 *
	 * @param string $job_id Job ID.
	 * @return array Statistics with counts and sizes.
	 */
	public static function get_job_stats( string $job_id ): array {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					status,
					COUNT(*) as count,
					SUM(file_size) as total_size,
					SUM(bytes_written) as bytes_written
				FROM {$table_name}
				WHERE job_id = %s
				GROUP BY status",
				$job_id
			),
			ARRAY_A
		);

		$stats = array(
			'total'       => 0,
			'pending'     => 0,
			'processing'  => 0,
			'completed'   => 0,
			'failed'      => 0,
			'skipped'     => 0,
			'total_size'  => 0,
			'written'     => 0,
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				$status = $row['status'];
				$count  = (int) $row['count'];
				$size   = (int) $row['total_size'];
				$written = (int) $row['bytes_written'];

				$stats[ $status ] = $count;
				$stats['total'] += $count;
				$stats['total_size'] += $size;
				$stats['written'] += $written;
			}
		}

		// Calculate progress percentage.
		$stats['progress'] = $stats['total'] > 0
			? round( ( $stats['completed'] + $stats['skipped'] ) / $stats['total'] * 100, 1 )
			: 0;

		return $stats;
	}

	/**
	 * Check if job has pending files.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	public static function has_pending( string $job_id ): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE job_id = %s AND status IN (%s, %s)",
				$job_id,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Check if job is complete.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	public static function is_job_complete( string $job_id ): bool {
		$stats = self::get_job_stats( $job_id );
		return $stats['pending'] === 0 && $stats['processing'] === 0;
	}

	/**
	 * Get total file count for job.
	 *
	 * @param string $job_id Job ID.
	 * @return int
	 */
	public static function get_total_count( string $job_id ): int {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE job_id = %s",
				$job_id
			)
		);

		return (int) $count;
	}

	/**
	 * Get failed files for reporting.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $limit  Maximum files to return.
	 * @return array
	 */
	public static function get_failed_files( string $job_id, int $limit = 100 ): array {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_path, relative_path, file_size, attempts, error_message
				FROM {$table_name}
				WHERE job_id = %s AND status = %s
				ORDER BY id ASC
				LIMIT %d",
				$job_id,
				self::STATUS_FAILED,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}
}
