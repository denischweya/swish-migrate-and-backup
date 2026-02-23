<?php
/**
 * Database Backup Handler.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

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
	 */
	private const ROWS_PER_BATCH = 1000;

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
	 * Create a database backup.
	 *
	 * @param string        $output_path       Output file path.
	 * @param callable|null $progress_callback Progress callback.
	 * @return bool True if successful.
	 */
	public function backup( string $output_path, ?callable $progress_callback = null ): bool {
		global $wpdb;

		$this->logger->info( 'Starting database backup' );

		try {
			// Get all tables.
			$tables = $this->get_tables();
			$total_tables = count( $tables );

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

			// Write header.
			$this->write_header( $handle );

			// Backup each table.
			$table_num = 0;
			foreach ( $tables as $table ) {
				++$table_num;

				if ( $progress_callback ) {
					$progress = (int) ( ( $table_num / $total_tables ) * 100 );
					$progress_callback( $progress, $table, $table_num, $total_tables );
				}

				$this->backup_table( $handle, $table );
			}

			// Write footer.
			$this->write_footer( $handle );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			$this->logger->info( 'Database backup completed', array(
				'tables' => $total_tables,
				'size'   => filesize( $output_path ),
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

		// Filter out excluded tables.
		return array_filter(
			$tables,
			fn( $table ) => ! in_array( $table, $this->excluded_tables, true )
		);
	}

	/**
	 * Get table row count.
	 *
	 * @param string $table Table name.
	 * @return int Row count.
	 */
	public function get_table_row_count( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @return void
	 */
	private function backup_table( $handle, string $table ): void {
		global $wpdb;

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		if ( $create_table && isset( $create_table[1] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, $create_table[1] . ";\n\n" );
		}

		// Get row count.
		$row_count = $this->get_table_row_count( $table );

		if ( 0 === $row_count ) {
			return;
		}

		// Dump data comment.
		$sql = "-- Dumping data for table `{$table}`\n\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $sql );

		// Disable keys for faster import.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n" );

		// Get columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$column_names = array_map( fn( $col ) => $col['Field'], $columns );
		$column_list = '`' . implode( '`, `', $column_names ) . '`';

		// Dump data in batches.
		$offset = 0;
		while ( $offset < $row_count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
					self::ROWS_PER_BATCH,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			$this->write_insert_statements( $handle, $table, $column_list, $rows, $columns );

			$offset += self::ROWS_PER_BATCH;

			// Free up memory.
			unset( $rows );
		}

		// Re-enable keys.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n\n" );
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
}
