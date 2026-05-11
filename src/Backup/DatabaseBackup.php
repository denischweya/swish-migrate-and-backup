<?php
/**
 * Database Backup Handler.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Handles database backup operations.
 */
final class DatabaseBackup {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Tables to exclude from backup.
	 *
	 * @var array
	 */
	private array $excluded_tables = array();

	/**
	 * Maximum rows per batch for memory efficiency.
	 *
	 * @var int
	 */
	private int $rows_per_batch = 200;

	/**
	 * Start time for timeout tracking.
	 *
	 * @var float
	 */
	private float $start_time = 0;

	/**
	 * Maximum execution time in seconds.
	 *
	 * @var int
	 */
	private int $max_execution_time = 25;

	/**
	 * Default rows per batch.
	 */
	private const DEFAULT_ROWS_PER_BATCH = 200;

	/**
	 * Minimum rows per batch (for memory-constrained environments).
	 */
	private const MIN_ROWS_PER_BATCH = 50;

	/**
	 * Memory threshold for reducing batch size (32MB).
	 */
	private const MEMORY_THRESHOLD = 33554432;

	/**
	 * Default tables to exclude (transient/log tables that regenerate).
	 */
	private const DEFAULT_EXCLUDED_PATTERNS = array(
		'actionscheduler_logs',
		'actionscheduler_claims',
		'wc_admin_note_actions', // WooCommerce admin notes - regenerates.
	);

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Set tables to exclude from backup.
	 *
	 * @param array $tables Table names to exclude.
	 * @return self
	 */
	public function set_excluded_tables( array $tables ): self {
		$this->excluded_tables = $tables;
		return $this;
	}

	/**
	 * Set the number of rows per batch.
	 *
	 * @param int $rows_per_batch Rows per batch (50-2000).
	 * @return self
	 */
	public function set_rows_per_batch( int $rows_per_batch ): self {
		$this->rows_per_batch = max( 50, min( 2000, $rows_per_batch ) );
		return $this;
	}

	/**
	 * Get the current rows per batch setting.
	 *
	 * @return int
	 */
	public function get_rows_per_batch(): int {
		return $this->rows_per_batch;
	}

	/**
	 * Set maximum execution time for the backup.
	 *
	 * @param int $seconds Maximum seconds (5-300).
	 * @return self
	 */
	public function set_max_execution_time( int $seconds ): self {
		$this->max_execution_time = max( 5, min( 300, $seconds ) );
		return $this;
	}

	/**
	 * Check if we're approaching the timeout limit.
	 *
	 * @param int $buffer_seconds Seconds to keep as buffer before timeout.
	 * @return bool True if we should stop soon.
	 */
	private function is_approaching_timeout( int $buffer_seconds = 5 ): bool {
		if ( 0 === $this->start_time ) {
			return false;
		}

		$elapsed = microtime( true ) - $this->start_time;
		return $elapsed >= ( $this->max_execution_time - $buffer_seconds );
	}

	/**
	 * Check if a table should be excluded based on default patterns.
	 *
	 * @param string $table Table name.
	 * @return bool True if table should be excluded.
	 */
	private function should_exclude_table( string $table ): bool {
		// Check explicit exclusions first.
		if ( in_array( $table, $this->excluded_tables, true ) ) {
			return true;
		}

		// Check default exclusion patterns.
		foreach ( self::DEFAULT_EXCLUDED_PATTERNS as $pattern ) {
			if ( false !== strpos( $table, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a database backup.
	 *
	 * @param string        $output_path       Output file path.
	 * @param callable|null $progress_callback Progress callback.
	 * @return bool True if successful.
	 */
	public function backup( string $output_path, ?callable $progress_callback = null ): bool {
		global $wpdb;

		// Initialize timeout tracking.
		$this->start_time = microtime( true );

		// Determine max execution time from server settings.
		$server_timeout = (int) ini_get( 'max_execution_time' );
		if ( $server_timeout > 0 ) {
			// Leave 10 seconds buffer for cleanup.
			$this->max_execution_time = max( 10, $server_timeout - 10 );
		}

		$this->logger->info( 'Starting database backup', array(
			'output_path'        => $output_path,
			'max_execution_time' => $this->max_execution_time,
		) );

		try {
			// Get all tables.
			$tables = $this->get_tables();
			$total_tables = count( $tables );

			$this->logger->info( 'Found tables to backup', array( 'count' => $total_tables ) );

			if ( empty( $tables ) ) {
				$this->logger->warning( 'No tables found to backup' );
				return false;
			}

			// Open output file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $output_path, 'w' );
			if ( ! $handle ) {
				$this->logger->error( 'Failed to open output file', array( 'path' => $output_path ) );
				return false;
			}

			$this->logger->info( 'Output file opened successfully' );

			// Write header.
			$this->write_header( $handle );

			// Backup each table.
			$table_num = 0;
			$skipped_tables = array();
			$timed_out = false;

			foreach ( $tables as $table ) {
				++$table_num;

				// Check if we're approaching timeout before starting a new table.
				if ( $this->is_approaching_timeout( 10 ) ) {
					$this->logger->warning( 'Approaching timeout, skipping remaining tables', array(
						'elapsed'          => round( microtime( true ) - $this->start_time, 2 ),
						'remaining_tables' => $total_tables - $table_num + 1,
					) );
					$skipped_tables = array_slice( $tables, $table_num - 1 );
					$timed_out = true;
					break;
				}

				$this->logger->info( 'Backing up table', array(
					'table'    => $table,
					'progress' => sprintf( '%d/%d', $table_num, $total_tables ),
					'elapsed'  => round( microtime( true ) - $this->start_time, 2 ),
				) );

				if ( $progress_callback ) {
					$progress = (int) ( ( $table_num / $total_tables ) * 100 );
					$progress_callback( $progress, $table, $table_num, $total_tables );
				}

				$table_result = $this->backup_table( $handle, $table );

				// Check if table backup was truncated due to timeout.
				if ( isset( $table_result['truncated'] ) && $table_result['truncated'] ) {
					$this->logger->warning( 'Table backup truncated due to timeout', array(
						'table'        => $table,
						'rows_written' => $table_result['rows_written'] ?? 0,
						'total_rows'   => $table_result['total_rows'] ?? 0,
					) );
				}
			}

			// Log summary of skipped tables.
			if ( ! empty( $skipped_tables ) ) {
				$this->logger->warning( 'Tables skipped due to timeout', array(
					'count'  => count( $skipped_tables ),
					'tables' => $skipped_tables,
				) );
			}

			// Write footer.
			$this->write_footer( $handle );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			$elapsed = round( microtime( true ) - $this->start_time, 2 );
			$this->logger->info( 'Database backup completed', array(
				'tables'         => $total_tables,
				'tables_backed'  => $table_num - count( $skipped_tables ),
				'tables_skipped' => count( $skipped_tables ),
				'size'           => filesize( $output_path ),
				'elapsed'        => $elapsed,
				'timed_out'      => $timed_out,
			) );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Database backup failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get all tables to backup.
	 *
	 * @return array Table names.
	 */
	public function get_tables(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		// Filter out excluded tables (both explicit and default patterns).
		$filtered = array_filter(
			$tables,
			fn( $table ) => ! $this->should_exclude_table( $table )
		);

		// Log excluded tables for debugging.
		$excluded = array_diff( $tables, $filtered );
		if ( ! empty( $excluded ) ) {
			$this->logger->info( 'Excluding tables from backup', array( 'tables' => array_values( $excluded ) ) );
		}

		return array_values( $filtered );
	}

	/**
	 * Get table row count.
	 *
	 * @param string $table Table name.
	 * @return int Row count.
	 */
	public function get_table_row_count( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->get_col() is safe.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return (int) $count;
	}

	/**
	 * Write SQL header to file.
	 *
	 * @param resource $handle File handle.
	 * @return void
	 */
	private function write_header( $handle ): void {
		global $wpdb;

		$header = "-- Swish Migrate and Backup - Database Backup\n";
		$header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$header .= '-- WordPress Version: ' . get_bloginfo( 'version' ) . "\n";
		$header .= '-- Site URL: ' . get_site_url() . "\n";
		$header .= '-- PHP Version: ' . PHP_VERSION . "\n";
		$header .= '-- MySQL Version: ' . $wpdb->db_version() . "\n";
		$header .= '-- Table Prefix: ' . $wpdb->prefix . "\n";
		$header .= "\n";
		$header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
		$header .= "SET AUTOCOMMIT = 0;\n";
		$header .= "START TRANSACTION;\n";
		$header .= "SET time_zone = \"+00:00\";\n";
		$header .= "SET NAMES utf8mb4;\n";
		$header .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $header );
	}

	/**
	 * Write SQL footer to file.
	 *
	 * @param resource $handle File handle.
	 * @return void
	 */
	private function write_footer( $handle ): void {
		$footer = "\nCOMMIT;\n";
		$footer .= "-- End of backup\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $footer );
	}

	/**
	 * Backup a single table.
	 *
	 * @param resource $handle File handle.
	 * @param string   $table  Table name.
	 * @return array Result with 'truncated', 'rows_written', 'total_rows' keys.
	 */
	private function backup_table( $handle, string $table ): array {
		global $wpdb;

		$result = array(
			'truncated'    => false,
			'rows_written' => 0,
			'total_rows'   => 0,
		);

		$this->logger->debug( 'Backing up table', array( 'table' => $table ) );

		// Table comment.
		$sql = "\n-- --------------------------------------------------------\n";
		$sql .= "-- Table structure for table `{$table}`\n";
		$sql .= "-- --------------------------------------------------------\n\n";

		// Drop table if exists.
		$sql .= "DROP TABLE IF EXISTS `{$table}`;\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $sql );

		// Get CREATE TABLE statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->get_col() is safe.
		$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		if ( $create_table && isset( $create_table[1] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, $create_table[1] . ";\n\n" );
		}

		// Get row count.
		$row_count = $this->get_table_row_count( $table );
		$result['total_rows'] = $row_count;

		$this->logger->debug( 'Table row count', array( 'table' => $table, 'rows' => $row_count ) );

		if ( 0 === $row_count ) {
			return $result;
		}

		// Dump data comment.
		$sql = "-- Dumping data for table `{$table}`\n\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $sql );

		// Disable keys for faster import.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n" );

		// Get columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->get_col() is safe.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$column_names = array_map( fn( $col ) => $col['Field'], $columns );
		$column_list = '`' . implode( '`, `', $column_names ) . '`';

		// Dump data in batches with adaptive batch sizing.
		$offset = 0;
		$current_batch_size = $this->rows_per_batch;
		$batch_count = 0;

		while ( $offset < $row_count ) {
			// Check for timeout every 5 batches (to avoid overhead).
			++$batch_count;
			if ( $batch_count % 5 === 0 && $this->is_approaching_timeout( 5 ) ) {
				$this->logger->warning( 'Table backup truncated due to timeout', array(
					'table'        => $table,
					'rows_written' => $result['rows_written'],
					'total_rows'   => $row_count,
					'elapsed'      => round( microtime( true ) - $this->start_time, 2 ),
				) );
				$result['truncated'] = true;

				// Add comment about truncation.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $handle, "-- WARNING: Table data truncated due to timeout. Rows written: {$result['rows_written']} of {$row_count}\n" );
				break;
			}

			// Check memory and reduce batch size if needed.
			$current_batch_size = $this->get_adaptive_batch_size( $current_batch_size );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->get_col() is safe.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
					$current_batch_size,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			$this->write_insert_statements( $handle, $table, $column_list, $rows, $columns );
			$result['rows_written'] += count( $rows );

			$offset += $current_batch_size;

			// Free up memory aggressively.
			unset( $rows );

			// Trigger garbage collection if available and memory is getting low.
			if ( $this->is_memory_low() && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Re-enable keys.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n\n" );

		return $result;
	}

	/**
	 * Write INSERT statements for rows.
	 *
	 * @param resource $handle      File handle.
	 * @param string   $table       Table name.
	 * @param string   $column_list Column list SQL.
	 * @param array    $rows        Rows to insert.
	 * @param array    $columns     Column definitions.
	 * @return void
	 */
	private function write_insert_statements(
		$handle,
		string $table,
		string $column_list,
		array $rows,
		array $columns
	): void {
		global $wpdb;

		// Build column type map.
		$numeric_types = array( 'int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'float', 'double', 'decimal' );
		$type_map = array();

		foreach ( $columns as $col ) {
			$type = strtolower( preg_replace( '/\(.*\)/', '', $col['Type'] ) );
			$type_map[ $col['Field'] ] = in_array( $type, $numeric_types, true ) ? 'numeric' : 'string';
		}

		// Write INSERT statement.
		$sql = "INSERT INTO `{$table}` ({$column_list}) VALUES\n";

		$value_rows = array();
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $column => $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( 'numeric' === $type_map[ $column ] && is_numeric( $value ) ) {
					$values[] = $value;
				} else {
					// Escape special characters.
					$escaped = $wpdb->_real_escape( $value );
					$values[] = "'{$escaped}'";
				}
			}
			$value_rows[] = '(' . implode( ', ', $values ) . ')';
		}

		$sql .= implode( ",\n", $value_rows ) . ";\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $sql );
	}

	/**
	 * Verify backup file integrity.
	 *
	 * @param string $file_path Path to backup file.
	 * @return bool True if valid.
	 */
	public function verify_backup( string $file_path ): bool {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return false;
		}

		// Check header.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$header = fread( $handle, 100 );
		if ( strpos( $header, '-- Swish Migrate and Backup' ) === false ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			return false;
		}

		// Check for COMMIT at the end.
		fseek( $handle, -50, SEEK_END );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$footer = fread( $handle, 50 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return strpos( $footer, 'COMMIT' ) !== false;
	}

	/**
	 * Get database size in bytes.
	 *
	 * @return int Database size.
	 */
	public function get_database_size(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) as size
				FROM information_schema.tables
				WHERE table_schema = %s",
				DB_NAME
			)
		);

		return (int) ( $result->size ?? 0 );
	}

	/**
	 * Get table sizes.
	 *
	 * @return array Array of table name => size.
	 */
	public function get_table_sizes(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name, (data_length + index_length) as size
				FROM information_schema.tables
				WHERE table_schema = %s
				ORDER BY size DESC",
				DB_NAME
			),
			ARRAY_A
		);

		$sizes = array();
		foreach ( $results as $row ) {
			$sizes[ $row['table_name'] ] = (int) $row['size'];
		}

		return $sizes;
	}

	/**
	 * Get adaptive batch size based on available memory.
	 *
	 * @param int $current_batch_size Current batch size.
	 * @return int Adjusted batch size.
	 */
	private function get_adaptive_batch_size( int $current_batch_size ): int {
		if ( ! $this->is_memory_low() ) {
			return $current_batch_size;
		}

		// Reduce batch size by half, but not below minimum.
		$new_size = max( self::MIN_ROWS_PER_BATCH, (int) ( $current_batch_size / 2 ) );

		if ( $new_size < $current_batch_size ) {
			$this->logger->debug( 'Reducing batch size due to memory pressure', array(
				'old_size' => $current_batch_size,
				'new_size' => $new_size,
			) );
		}

		return $new_size;
	}

	/**
	 * Check if available memory is below threshold.
	 *
	 * @return bool True if memory is low.
	 */
	private function is_memory_low(): bool {
		$available = $this->get_available_memory();
		return $available < self::MEMORY_THRESHOLD;
	}

	/**
	 * Get available memory in bytes.
	 *
	 * @return int Available memory.
	 */
	private function get_available_memory(): int {
		$memory_limit = $this->get_memory_limit();
		$memory_used = memory_get_usage( true );

		return max( 0, $memory_limit - $memory_used );
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @return int Memory limit.
	 */
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );

		if ( '-1' === $limit ) {
			// No limit set, assume 512MB.
			return 512 * 1024 * 1024;
		}

		$value = (int) $limit;
		$unit = strtoupper( substr( $limit, -1 ) );

		switch ( $unit ) {
			case 'G':
				$value *= 1024;
				// Fall through.
			case 'M':
				$value *= 1024;
				// Fall through.
			case 'K':
				$value *= 1024;
		}

		return $value;
	}
}
