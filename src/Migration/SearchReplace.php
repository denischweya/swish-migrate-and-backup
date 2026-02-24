<?php
/**
 * Search and Replace Handler.
 *
 * @package SwishMigrateAndBackup\Migration
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Migration;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Handles search and replace operations in the database.
 *
 * Supports serialized data safely using recursive unserialization.
 */
final class SearchReplace {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Number of rows processed.
	 *
	 * @var int
	 */
	private int $rows_processed = 0;

	/**
	 * Number of replacements made.
	 *
	 * @var int
	 */
	private int $replacements_made = 0;

	/**
	 * Rows per batch for processing.
	 */
	private const ROWS_PER_BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Run search and replace on the database.
	 *
	 * @param string        $search           Search string.
	 * @param string        $replace          Replace string.
	 * @param array         $tables           Tables to process (empty for all).
	 * @param callable|null $progress_callback Progress callback.
	 * @return array Results with counts.
	 */
	public function run(
		string $search,
		string $replace,
		array $tables = array(),
		?callable $progress_callback = null
	): array {
		$this->rows_processed = 0;
		$this->replacements_made = 0;

		$this->logger->info( 'Starting search and replace', array(
			'search'  => $search,
			'replace' => $replace,
		) );

		if ( empty( $search ) ) {
			return array(
				'success' => false,
				'error'   => 'Search string cannot be empty',
			);
		}

		// Get tables to process.
		if ( empty( $tables ) ) {
			$tables = $this->get_all_tables();
		}

		$total_tables = count( $tables );
		$table_num = 0;
		$table_results = array();

		foreach ( $tables as $table ) {
			++$table_num;

			if ( $progress_callback ) {
				$progress = (int) ( ( $table_num / $total_tables ) * 100 );
				$progress_callback( $progress, $table, $table_num, $total_tables );
			}

			$result = $this->process_table( $table, $search, $replace );
			$table_results[ $table ] = $result;
		}

		$this->logger->info( 'Search and replace completed', array(
			'rows_processed'    => $this->rows_processed,
			'replacements_made' => $this->replacements_made,
		) );

		return array(
			'success'           => true,
			'rows_processed'    => $this->rows_processed,
			'replacements_made' => $this->replacements_made,
			'tables'            => $table_results,
		);
	}

	/**
	 * Run multiple search and replace operations.
	 *
	 * @param array         $replacements      Array of search => replace pairs.
	 * @param array         $tables           Tables to process.
	 * @param callable|null $progress_callback Progress callback.
	 * @return array Results.
	 */
	public function run_multiple(
		array $replacements,
		array $tables = array(),
		?callable $progress_callback = null
	): array {
		$total_replacements = 0;
		$total_rows = 0;
		$results = array();

		foreach ( $replacements as $search => $replace ) {
			$result = $this->run( $search, $replace, $tables, $progress_callback );
			$results[] = array(
				'search'       => $search,
				'replace'      => $replace,
				'replacements' => $result['replacements_made'] ?? 0,
			);
			$total_replacements += $result['replacements_made'] ?? 0;
			$total_rows += $result['rows_processed'] ?? 0;
		}

		return array(
			'success'              => true,
			'total_rows_processed' => $total_rows,
			'total_replacements'   => $total_replacements,
			'operations'           => $results,
		);
	}

	/**
	 * Dry run to preview changes.
	 *
	 * @param string $search  Search string.
	 * @param string $replace Replace string.
	 * @param array  $tables  Tables to check.
	 * @param int    $limit   Maximum matches to return.
	 * @return array Preview results.
	 */
	public function dry_run(
		string $search,
		string $replace,
		array $tables = array(),
		int $limit = 50
	): array {
		if ( empty( $tables ) ) {
			$tables = $this->get_all_tables();
		}

		$matches = array();
		$total_count = 0;

		foreach ( $tables as $table ) {
			$table_matches = $this->find_matches( $table, $search, $limit );
			$total_count += count( $table_matches );

			foreach ( $table_matches as $match ) {
				if ( count( $matches ) >= $limit ) {
					break 2;
				}

				$matches[] = array(
					'table'    => $table,
					'column'   => $match['column'],
					'row_id'   => $match['row_id'],
					'before'   => $this->truncate_string( $match['value'], 200 ),
					'after'    => $this->truncate_string(
						$this->recursive_replace( $match['value'], $search, $replace ),
						200
					),
				);
			}
		}

		return array(
			'total_matches' => $total_count,
			'preview'       => $matches,
			'truncated'     => $total_count > $limit,
		);
	}

	/**
	 * Process a single table.
	 *
	 * @param string $table   Table name.
	 * @param string $search  Search string.
	 * @param string $replace Replace string.
	 * @return array Table results.
	 */
	private function process_table( string $table, string $search, string $replace ): array {
		global $wpdb;

		// Get primary key.
		$primary_key = $this->get_primary_key( $table );
		if ( ! $primary_key ) {
			$this->logger->warning( 'No primary key found for table', array( 'table' => $table ) );
			return array( 'rows' => 0, 'changes' => 0, 'skipped' => true );
		}

		// Get text columns.
		$columns = $this->get_text_columns( $table );
		if ( empty( $columns ) ) {
			return array( 'rows' => 0, 'changes' => 0, 'no_text_columns' => true );
		}

		// Get row count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( 0 === $row_count ) {
			return array( 'rows' => 0, 'changes' => 0 );
		}

		$table_changes = 0;
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

			foreach ( $rows as $row ) {
				$row_id = $row[ $primary_key ] ?? null;
				if ( null === $row_id ) {
					continue;
				}

				$updates = array();

				foreach ( $columns as $column ) {
					if ( ! isset( $row[ $column ] ) || null === $row[ $column ] ) {
						continue;
					}

					$value = $row[ $column ];

					// Check if contains search string.
					if ( strpos( $value, $search ) === false ) {
						continue;
					}

					// Perform replacement.
					$new_value = $this->recursive_replace( $value, $search, $replace );

					if ( $new_value !== $value ) {
						$updates[ $column ] = $new_value;
					}
				}

				if ( ! empty( $updates ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						$updates,
						array( $primary_key => $row_id )
					);

					$table_changes += count( $updates );
					$this->replacements_made += count( $updates );
				}

				++$this->rows_processed;
			}

			$offset += self::ROWS_PER_BATCH;

			// Free memory.
			unset( $rows );
		}

		return array(
			'rows'    => $row_count,
			'changes' => $table_changes,
		);
	}

	/**
	 * Recursively replace in potentially serialized data.
	 *
	 * @param mixed  $data    Data to process.
	 * @param string $search  Search string.
	 * @param string $replace Replace string.
	 * @return mixed Processed data.
	 */
	private function recursive_replace( $data, string $search, string $replace ) {
		// Handle serialized data.
		if ( is_string( $data ) && $this->is_serialized( $data ) ) {
			$unserialized = @unserialize( $data );
			if ( false !== $unserialized || 'b:0;' === $data ) {
				$replaced = $this->recursive_replace( $unserialized, $search, $replace );
				return serialize( $replaced );
			}
		}

		// Handle arrays.
		if ( is_array( $data ) ) {
			return array_map(
				fn( $item ) => $this->recursive_replace( $item, $search, $replace ),
				$data
			);
		}

		// Handle objects.
		if ( is_object( $data ) ) {
			$class = get_class( $data );

			// Handle stdClass.
			if ( 'stdClass' === $class ) {
				foreach ( get_object_vars( $data ) as $key => $value ) {
					$data->$key = $this->recursive_replace( $value, $search, $replace );
				}
				return $data;
			}

			// Handle other objects carefully.
			return $data;
		}

		// Handle strings.
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}

		// Return other types unchanged.
		return $data;
	}

	/**
	 * Check if a string is serialized.
	 *
	 * @param string $data String to check.
	 * @return bool True if serialized.
	 */
	private function is_serialized( string $data ): bool {
		if ( strlen( $data ) < 4 ) {
			return false;
		}

		if ( ':' !== $data[1] ) {
			return false;
		}

		$token = $data[0];
		switch ( $token ) {
			case 's':
				return '"' === $data[ strlen( $data ) - 2 ] && ';' === $data[ strlen( $data ) - 1 ];
			case 'a':
			case 'O':
				return '}' === $data[ strlen( $data ) - 1 ];
			case 'b':
			case 'i':
			case 'd':
				return ';' === $data[ strlen( $data ) - 1 ];
			case 'N':
				return 'N;' === $data;
		}

		return false;
	}

	/**
	 * Find matches in a table (for dry run).
	 *
	 * @param string $table  Table name.
	 * @param string $search Search string.
	 * @param int    $limit  Maximum matches.
	 * @return array Matches.
	 */
	private function find_matches( string $table, string $search, int $limit ): array {
		global $wpdb;

		$primary_key = $this->get_primary_key( $table );
		if ( ! $primary_key ) {
			return array();
		}

		$columns = $this->get_text_columns( $table );
		if ( empty( $columns ) ) {
			return array();
		}

		$matches = array();

		foreach ( $columns as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT `{$primary_key}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE %s LIMIT %d",
					'%' . $wpdb->esc_like( $search ) . '%',
					$limit - count( $matches )
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$matches[] = array(
					'column' => $column,
					'row_id' => $row[ $primary_key ],
					'value'  => $row[ $column ],
				);

				if ( count( $matches ) >= $limit ) {
					break 2;
				}
			}
		}

		return $matches;
	}

	/**
	 * Get all tables in the database.
	 *
	 * @return array Table names.
	 */
	private function get_all_tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col( 'SHOW TABLES' );
	}

	/**
	 * Get the primary key column for a table.
	 *
	 * @param string $table Table name.
	 * @return string|null Primary key column or null.
	 */
	private function get_primary_key( string $table ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );

		return $keys[0]['Column_name'] ?? null;
	}

	/**
	 * Get text columns from a table.
	 *
	 * @param string $table Table name.
	 * @return array Column names.
	 */
	private function get_text_columns( string $table ): array {
		global $wpdb;

		$text_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

		$text_columns = array();
		foreach ( $columns as $column ) {
			$type = strtolower( preg_replace( '/\(.*\)/', '', $column['Type'] ) );
			if ( in_array( $type, $text_types, true ) ) {
				$text_columns[] = $column['Field'];
			}
		}

		return $text_columns;
	}

	/**
	 * Truncate a string for display.
	 *
	 * @param string $string String to truncate.
	 * @param int    $length Maximum length.
	 * @return string Truncated string.
	 */
	private function truncate_string( string $string, int $length ): string {
		if ( strlen( $string ) <= $length ) {
			return $string;
		}

		return substr( $string, 0, $length - 3 ) . '...';
	}

	/**
	 * Generate URL migration replacements.
	 *
	 * @param string $old_url Old site URL.
	 * @param string $new_url New site URL.
	 * @return array Array of search => replace pairs.
	 */
	public function generate_url_replacements( string $old_url, string $new_url ): array {
		$replacements = array();

		// Standard URL.
		$replacements[ $old_url ] = $new_url;

		// URL without protocol.
		$old_no_proto = preg_replace( '#^https?://#', '', $old_url );
		$new_no_proto = preg_replace( '#^https?://#', '', $new_url );
		$replacements[ $old_no_proto ] = $new_no_proto;

		// Escaped URLs (for JSON).
		$replacements[ str_replace( '/', '\/', $old_url ) ] = str_replace( '/', '\/', $new_url );

		// URL encoded.
		$replacements[ urlencode( $old_url ) ] = urlencode( $new_url );

		// With www/without www variations.
		$old_with_www = preg_replace( '#^(https?://)(?!www\.)#', '$1www.', $old_url );
		$old_without_www = preg_replace( '#^(https?://)www\.#', '$1', $old_url );
		$new_with_www = preg_replace( '#^(https?://)(?!www\.)#', '$1www.', $new_url );
		$new_without_www = preg_replace( '#^(https?://)www\.#', '$1', $new_url );

		if ( $old_with_www !== $old_url ) {
			$replacements[ $old_with_www ] = $new_with_www;
		}
		if ( $old_without_www !== $old_url ) {
			$replacements[ $old_without_www ] = $new_without_www;
		}

		return $replacements;
	}
}
