<?php
/**
 * Logger class for backup operations.
 *
 * @package SwishMigrateAndBackup\Logger
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-3 inspired logger for backup operations.
 */
final class Logger {

	/**
	 * Log levels.
	 */
	public const EMERGENCY = 'emergency';
	public const ALERT     = 'alert';
	public const CRITICAL  = 'critical';
	public const ERROR     = 'error';
	public const WARNING   = 'warning';
	public const NOTICE    = 'notice';
	public const INFO      = 'info';
	public const DEBUG     = 'debug';

	/**
	 * Log levels hierarchy.
	 *
	 * @var array<string, int>
	 */
	private const LEVEL_HIERARCHY = array(
		self::EMERGENCY => 800,
		self::ALERT     => 700,
		self::CRITICAL  => 600,
		self::ERROR     => 500,
		self::WARNING   => 400,
		self::NOTICE    => 300,
		self::INFO      => 200,
		self::DEBUG     => 100,
	);

	/**
	 * Current job ID for contextual logging.
	 *
	 * @var string|null
	 */
	private ?string $job_id = null;

	/**
	 * Minimum log level to record.
	 *
	 * @var string
	 */
	private string $min_level;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Constructor.
	 *
	 * @param string $min_level Minimum log level.
	 */
	public function __construct( string $min_level = self::INFO ) {
		$this->min_level = $min_level;
		$this->log_dir   = $this->get_log_directory();
	}

	/**
	 * Set the current job ID for contextual logging.
	 *
	 * @param string|null $job_id Job ID.
	 * @return self
	 */
	public function set_job_id( ?string $job_id ): self {
		$this->job_id = $job_id;
		return $this;
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::EMERGENCY, $message, $context );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::ALERT, $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::CRITICAL, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Log a notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::NOTICE, $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Log a message at any level.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		// Check if we should log this level.
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		// Add job ID to context if set.
		if ( $this->job_id ) {
			$context['job_id'] = $this->job_id;
		}

		// Interpolate message with context.
		$message = $this->interpolate( $message, $context );

		// Create log entry.
		$entry = array(
			'timestamp' => gmdate( 'Y-m-d H:i:s' ),
			'level'     => strtoupper( $level ),
			'message'   => $message,
			'context'   => $context,
		);

		// Write to file.
		$this->write_to_file( $entry );

		// Write to database.
		$this->write_to_database( $level, $message, $context );

		/**
		 * Fires after a log entry is written.
		 *
		 * @param string $level   Log level.
		 * @param string $message Log message.
		 * @param array  $context Log context.
		 */
		do_action( 'swish_backup_logged', $level, $message, $context );
	}

	/**
	 * Get logs for a specific job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $limit  Maximum number of logs.
	 * @return array
	 */
	public function get_job_logs( string $job_id, int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE job_id = %s ORDER BY created_at DESC LIMIT %d",
				$job_id,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Get recent logs.
	 *
	 * @param int    $limit  Maximum number of logs.
	 * @param string $level  Minimum level to retrieve.
	 * @return array
	 */
	public function get_recent_logs( int $limit = 100, string $level = self::INFO ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_logs';
		$levels = $this->get_levels_at_or_above( $level );

		$placeholders = implode( ',', array_fill( 0, count( $levels ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE level IN ({$placeholders}) ORDER BY created_at DESC LIMIT %d",
				array_merge( $levels, array( $limit ) )
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Clear old logs.
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public function clear_old_logs( int $days = 30 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_logs';
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < %s",
				$date
			)
		);

		return $deleted ?: 0;
	}

	/**
	 * Check if a level should be logged.
	 *
	 * @param string $level Level to check.
	 * @return bool
	 */
	private function should_log( string $level ): bool {
		$level_value = self::LEVEL_HIERARCHY[ $level ] ?? 0;
		$min_value   = self::LEVEL_HIERARCHY[ $this->min_level ] ?? 0;

		return $level_value >= $min_value;
	}

	/**
	 * Get all levels at or above a given level.
	 *
	 * @param string $level Minimum level.
	 * @return array
	 */
	private function get_levels_at_or_above( string $level ): array {
		$min_value = self::LEVEL_HIERARCHY[ $level ] ?? 0;
		$levels = array();

		foreach ( self::LEVEL_HIERARCHY as $lvl => $value ) {
			if ( $value >= $min_value ) {
				$levels[] = $lvl;
			}
		}

		return $levels;
	}

	/**
	 * Interpolate message with context values.
	 *
	 * @param string $message Message template.
	 * @param array  $context Context values.
	 * @return string
	 */
	private function interpolate( string $message, array $context ): string {
		$replace = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$replace[ '{' . $key . '}' ] = $value;
			}
		}

		return strtr( $message, $replace );
	}

	/**
	 * Write log entry to file.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function write_to_file( array $entry ): void {
		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		$filename = $this->log_dir . '/backup-' . gmdate( 'Y-m-d' ) . '.log';

		$line = sprintf(
			"[%s] %s: %s\n",
			$entry['timestamp'],
			$entry['level'],
			$entry['message']
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $filename, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Write log entry to database.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 * @return void
	 */
	private function write_to_database( string $level, string $message, array $context ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'swish_backup_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'job_id'     => $context['job_id'] ?? null,
				'level'      => $level,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get the log directory path.
	 *
	 * @return string
	 */
	private function get_log_directory(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/swish-backups/logs';
	}

	/**
	 * Get the current log file path.
	 *
	 * @return string
	 */
	public function get_current_log_file(): string {
		return $this->log_dir . '/backup-' . gmdate( 'Y-m-d' ) . '.log';
	}
}
