<?php
/**
 * Backup State Manager.
 *
 * Handles persistent state storage for resumable backups using
 * database and file-based storage instead of transients.
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
 * Manages backup state for resumable operations.
 *
 * Uses a combination of database table for metadata and
 * temp files for large data like file lists.
 */
final class BackupState {

	/**
	 * Database table name (without prefix).
	 */
	private const TABLE_NAME = 'swish_backup_state';

	/**
	 * State file directory.
	 *
	 * @var string
	 */
	private string $state_dir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->state_dir = WP_CONTENT_DIR . '/swish-backups/state';
		$this->ensure_state_directory();
	}

	/**
	 * Ensure state directory exists.
	 *
	 * @return void
	 */
	private function ensure_state_directory(): void {
		if ( ! is_dir( $this->state_dir ) ) {
			wp_mkdir_p( $this->state_dir );

			// Add .htaccess for security.
			$htaccess = $this->state_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $htaccess, "Deny from all\n" );
			}

			// Add index.php for security.
			$index = $this->state_dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			}
		}
	}

	/**
	 * Create the state table if it doesn't exist.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(36) NOT NULL,
			state_key VARCHAR(100) NOT NULL,
			state_value LONGTEXT,
			file_path VARCHAR(500),
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY job_state (job_id, state_key),
			KEY job_id (job_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Save state metadata to database.
	 *
	 * @param string $job_id    Job ID.
	 * @param string $key       State key.
	 * @param mixed  $value     State value (will be JSON encoded).
	 * @param string $file_path Optional associated file path.
	 * @return bool True on success.
	 */
	public function save_meta( string $job_id, string $key, $value, string $file_path = '' ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
		$encoded_value = wp_json_encode( $value );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->replace(
			$table,
			array(
				'job_id'      => $job_id,
				'state_key'   => $key,
				'state_value' => $encoded_value,
				'file_path'   => $file_path,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Get state metadata from database.
	 *
	 * @param string $job_id Job ID.
	 * @param string $key    State key.
	 * @return mixed|null State value or null if not found.
	 */
	public function get_meta( string $job_id, string $key ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT state_value, file_path FROM {$table} WHERE job_id = %s AND state_key = %s",
				$job_id,
				$key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return json_decode( $row['state_value'], true );
	}

	/**
	 * Get all state for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array All state key-value pairs.
	 */
	public function get_all_meta( string $job_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT state_key, state_value, file_path FROM {$table} WHERE job_id = %s",
				$job_id
			),
			ARRAY_A
		);

		$state = array();
		foreach ( $rows as $row ) {
			$state[ $row['state_key'] ] = array(
				'value'     => json_decode( $row['state_value'], true ),
				'file_path' => $row['file_path'],
			);
		}

		return $state;
	}

	/**
	 * Save file list to disk file.
	 *
	 * Uses a compact format: one file path per line.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $files  Array of file data arrays.
	 * @return string|false File path on success, false on failure.
	 */
	public function save_file_list( string $job_id, array $files ) {
		$file_path = $this->get_file_list_path( $job_id );

		// Write files in a compact format: JSON lines (one JSON object per line).
		$handle = fopen( $file_path, 'w' );
		if ( ! $handle ) {
			return false;
		}

		foreach ( $files as $file ) {
			// Write compact JSON - one file per line.
			fwrite( $handle, wp_json_encode( $file ) . "\n" );
		}

		fclose( $handle );

		// Save metadata reference.
		$this->save_meta( $job_id, 'file_list', array(
			'count'     => count( $files ),
			'file_path' => $file_path,
		), $file_path );

		return $file_path;
	}

	/**
	 * Append files to existing file list.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $files  Array of file data arrays to append.
	 * @return bool True on success.
	 */
	public function append_to_file_list( string $job_id, array $files ): bool {
		$file_path = $this->get_file_list_path( $job_id );

		$handle = fopen( $file_path, 'a' );
		if ( ! $handle ) {
			return false;
		}

		foreach ( $files as $file ) {
			fwrite( $handle, wp_json_encode( $file ) . "\n" );
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Read file list from disk with offset and limit.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $offset Starting line offset.
	 * @param int    $limit  Maximum lines to read (0 = all).
	 * @return array Array of file data.
	 */
	public function read_file_list( string $job_id, int $offset = 0, int $limit = 0 ): array {
		$file_path = $this->get_file_list_path( $job_id );

		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$files = array();
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$line_num = 0;
		$read_count = 0;

		while ( ( $line = fgets( $handle ) ) !== false ) {
			// Skip lines before offset.
			if ( $line_num < $offset ) {
				++$line_num;
				continue;
			}

			// Check limit.
			if ( $limit > 0 && $read_count >= $limit ) {
				break;
			}

			$line = trim( $line );
			if ( ! empty( $line ) ) {
				$file_data = json_decode( $line, true );
				if ( $file_data ) {
					$files[] = $file_data;
					++$read_count;
				}
			}

			++$line_num;
		}

		fclose( $handle );

		return $files;
	}

	/**
	 * Count lines in file list.
	 *
	 * @param string $job_id Job ID.
	 * @return int Line count.
	 */
	public function count_file_list( string $job_id ): int {
		$file_path = $this->get_file_list_path( $job_id );

		if ( ! file_exists( $file_path ) ) {
			return 0;
		}

		$count = 0;
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return 0;
		}

		while ( fgets( $handle ) !== false ) {
			++$count;
		}

		fclose( $handle );

		return $count;
	}

	/**
	 * Get file list path for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return string File path.
	 */
	public function get_file_list_path( string $job_id ): string {
		return $this->state_dir . '/files-' . $job_id . '.jsonl';
	}

	/**
	 * Save current processing position.
	 *
	 * @param string $job_id    Job ID.
	 * @param int    $processed Number of files processed.
	 * @param int    $total     Total number of files.
	 * @param string $phase     Current phase (scan, backup, finalize).
	 * @param array  $extra     Extra state data.
	 * @return bool True on success.
	 */
	public function save_progress( string $job_id, int $processed, int $total, string $phase, array $extra = array() ): bool {
		return $this->save_meta( $job_id, 'progress', array_merge( array(
			'processed' => $processed,
			'total'     => $total,
			'phase'     => $phase,
			'timestamp' => time(),
		), $extra ) );
	}

	/**
	 * Get current processing position.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Progress data or null if not found.
	 */
	public function get_progress( string $job_id ): ?array {
		return $this->get_meta( $job_id, 'progress' );
	}

	/**
	 * Save backup options.
	 *
	 * @param string $job_id  Job ID.
	 * @param array  $options Backup options.
	 * @return bool True on success.
	 */
	public function save_options( string $job_id, array $options ): bool {
		return $this->save_meta( $job_id, 'options', $options );
	}

	/**
	 * Get backup options.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Options or null if not found.
	 */
	public function get_options( string $job_id ): ?array {
		return $this->get_meta( $job_id, 'options' );
	}

	/**
	 * Clean up all state for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function cleanup( string $job_id ): void {
		global $wpdb;

		// Delete database records.
		$table = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'job_id' => $job_id ) );

		// Delete state files.
		$file_list_path = $this->get_file_list_path( $job_id );
		if ( file_exists( $file_list_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $file_list_path );
		}

		// Clean up any other job-related files.
		$pattern = $this->state_dir . '/*-' . $job_id . '.*';
		$files = glob( $pattern );
		if ( $files ) {
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/**
	 * Clean up old/stale state data.
	 *
	 * @param int $max_age_hours Maximum age in hours (default 24).
	 * @return int Number of cleaned up jobs.
	 */
	public function cleanup_stale( int $max_age_hours = 24 ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_hours * HOUR_IN_SECONDS ) );

		// Get stale job IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stale_jobs = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT job_id FROM {$table} WHERE updated_at < %s",
				$cutoff
			)
		);

		$cleaned = 0;
		foreach ( $stale_jobs as $job_id ) {
			$this->cleanup( $job_id );
			++$cleaned;
		}

		return $cleaned;
	}

	/**
	 * Check if a job has saved state.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True if state exists.
	 */
	public function has_state( string $job_id ): bool {
		$progress = $this->get_progress( $job_id );
		return null !== $progress;
	}

	/**
	 * Get the state directory path.
	 *
	 * @return string State directory path.
	 */
	public function get_state_dir(): string {
		return $this->state_dir;
	}
}
