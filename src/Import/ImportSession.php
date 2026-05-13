<?php
/**
 * Import Session Manager.
 *
 * Handles session-independent authentication for import/migration operations.
 * This allows imports to continue even when the database is being restored,
 * which would normally invalidate WordPress sessions.
 *
 * Inspired by All-in-One WP Migration's approach.
 *
 * @package SwishMigrateAndBackup\Import
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Import;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages import sessions with secret key authentication.
 */
final class ImportSession {

	/**
	 * Option name for the secret key.
	 */
	private const SECRET_KEY_OPTION = 'swish_import_secret_key';

	/**
	 * Option name for preserved options during import.
	 */
	private const PRESERVED_OPTIONS = 'swish_import_preserved_options';

	/**
	 * Option name for import state.
	 */
	private const IMPORT_STATE_OPTION = 'swish_import_state';

	/**
	 * Filename for file-based state storage during database restore.
	 * This survives when the wp_options table is dropped/recreated.
	 */
	private const STATE_FILE_NAME = 'swish-import-state.json';

	/**
	 * Filename for file-based secret key storage during database restore.
	 */
	private const SECRET_KEY_FILE_NAME = 'swish-import-key.txt';

	/**
	 * Filename for file-based preserved options storage during database restore.
	 */
	private const PRESERVED_OPTIONS_FILE_NAME = 'swish-preserved-options.json';

	/**
	 * Option names that must be preserved during database restore.
	 * These are saved before restore and restored immediately after.
	 *
	 * NOTE: active_plugins is NOT included here because we want the backup's
	 * plugin list, not the current site's. Swish plugin activation is handled
	 * separately via '_swish_plugin_file'.
	 */
	private const CRITICAL_OPTIONS = array(
		'siteurl',
		'home',
		'swish_import_secret_key',
		'swish_import_state',
		'swish_import_preserved_options',
		'swish_backup_settings',
	);

	/**
	 * Get the path to the secret key file.
	 *
	 * @return string Path to the secret key file.
	 */
	private static function get_secret_key_file_path(): string {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups/temp';
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $backup_dir, 0755, true );
		}
		return $backup_dir . '/' . self::SECRET_KEY_FILE_NAME;
	}

	/**
	 * Get the path to the preserved options file.
	 *
	 * @return string Path to the preserved options file.
	 */
	private static function get_preserved_options_file_path(): string {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups/temp';
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $backup_dir, 0755, true );
		}
		return $backup_dir . '/' . self::PRESERVED_OPTIONS_FILE_NAME;
	}

	/**
	 * Generate a new secret key for an import session.
	 *
	 * @return string The generated secret key.
	 */
	public static function generate_secret_key(): string {
		$secret_key = wp_generate_password( 64, true, true );
		update_option( self::SECRET_KEY_OPTION, $secret_key, false );

		// Also save to file - this survives when wp_options is dropped/recreated.
		$key_file = self::get_secret_key_file_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $key_file, $secret_key, LOCK_EX );

		return $secret_key;
	}

	/**
	 * Get the current secret key.
	 *
	 * Prioritizes file-based key over database to survive database restore.
	 *
	 * @return string|null The secret key or null if not set.
	 */
	public static function get_secret_key(): ?string {
		// First, try to read from file (survives database restore).
		$key_file = self::get_secret_key_file_path();
		if ( file_exists( $key_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$key = file_get_contents( $key_file );
			if ( false !== $key && ! empty( trim( $key ) ) ) {
				return trim( $key );
			}
		}

		// Fall back to database.
		$key = get_option( self::SECRET_KEY_OPTION );
		return ! empty( $key ) ? $key : null;
	}

	/**
	 * Verify a secret key.
	 *
	 * @param string $provided_key The key to verify.
	 * @return bool True if valid.
	 */
	public static function verify_secret_key( string $provided_key ): bool {
		$stored_key = self::get_secret_key();
		if ( empty( $stored_key ) || empty( $provided_key ) ) {
			return false;
		}
		return hash_equals( $stored_key, $provided_key );
	}

	/**
	 * Clear the secret key (end session).
	 *
	 * @return void
	 */
	public static function clear_secret_key(): void {
		delete_option( self::SECRET_KEY_OPTION );

		// Remove secret key file.
		$key_file = self::get_secret_key_file_path();
		if ( file_exists( $key_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $key_file );
		}
	}

	/**
	 * Preserve critical options before database restore.
	 *
	 * This saves options that must survive the database restore,
	 * including authentication data and import state.
	 *
	 * CRITICAL: This also preserves the current user's session tokens.
	 * Without this, importing wp_usermeta will overwrite session_tokens
	 * from the backup, invalidating the current user's WordPress session.
	 *
	 * @return array The preserved options.
	 */
	public static function preserve_critical_options(): array {
		global $wpdb;
		$preserved = array();

		foreach ( self::CRITICAL_OPTIONS as $option_name ) {
			$value = get_option( $option_name );
			if ( false !== $value ) {
				$preserved[ $option_name ] = $value;
			}
		}

		// CRITICAL: Preserve the current user's session tokens.
		// This is what AI1WM does - without this, importing wp_usermeta
		// will overwrite the user's session_tokens, logging them out.
		$current_user = wp_get_current_user();
		if ( $current_user->ID > 0 ) {
			$preserved['_import_user_id'] = $current_user->ID;
			$preserved['_import_user_login'] = $current_user->user_login;

			// Get the current user's session tokens from usermeta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$session_tokens = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens' LIMIT 1",
				$current_user->ID
			) );

			if ( $session_tokens ) {
				$preserved['_user_session_tokens'] = $session_tokens;
			}

			// Also preserve wp_capabilities in case user meta is overwritten.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$capabilities = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$current_user->ID,
				$wpdb->prefix . 'capabilities'
			) );

			if ( $capabilities ) {
				$preserved['_user_capabilities'] = $capabilities;
				$preserved['_user_capabilities_key'] = $wpdb->prefix . 'capabilities';
			}

			error_log( 'Swish Import: Preserved session tokens for user ' . $current_user->ID );
		}

		// Preserve the Swish plugin in active_plugins to ensure it stays active.
		$active_plugins = get_option( 'active_plugins', array() );
		$swish_plugin = null;
		foreach ( $active_plugins as $plugin ) {
			if ( str_contains( $plugin, 'swish-migrate-and-backup' ) ) {
				$swish_plugin = $plugin;
				break;
			}
		}
		if ( $swish_plugin ) {
			$preserved['_swish_plugin_file'] = $swish_plugin;
		}

		// Save to file FIRST - this survives when wp_options is dropped/recreated.
		$options_file = self::get_preserved_options_file_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes_written = @file_put_contents( $options_file, wp_json_encode( $preserved ), LOCK_EX );

		if ( false === $bytes_written ) {
			error_log( 'Swish Import: CRITICAL - Failed to write preserved options file: ' . $options_file );
		}

		// Also store in database for redundancy.
		update_option( self::PRESERVED_OPTIONS, $preserved, false );

		return $preserved;
	}

	/**
	 * Restore critical options after database restore.
	 *
	 * This must be called immediately after the database SQL is executed,
	 * before any other WordPress functions that might need these options.
	 *
	 * @return bool True if options were restored.
	 */
	public static function restore_critical_options(): bool {
		global $wpdb;

		// Try to get preserved options from file first (survives database restore).
		$preserved = null;
		$options_file = self::get_preserved_options_file_path();

		clearstatcache( true, $options_file );
		if ( file_exists( $options_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = @file_get_contents( $options_file );
			if ( false !== $json && '' !== $json ) {
				$preserved = json_decode( $json, true );
			}
		}

		// Fall back to database option if file not available.
		if ( empty( $preserved ) || ! is_array( $preserved ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$preserved = @get_option( self::PRESERVED_OPTIONS );
		}

		if ( empty( $preserved ) || ! is_array( $preserved ) ) {
			error_log( 'Swish Import: No preserved options found to restore' );
			return false;
		}

		error_log( 'Swish Import: Restoring ' . count( $preserved ) . ' preserved options' );

		// Verify options table exists before attempting to write.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->options )
		);

		if ( ! $table_exists ) {
			error_log( 'Swish Import: Options table does not exist: ' . $wpdb->options );
			error_log( 'Swish Import: This likely means table prefix replacement failed during database restore' );
			return false;
		}

		// Restore each critical option.
		$restored_count = 0;
		foreach ( self::CRITICAL_OPTIONS as $option_name ) {
			if ( isset( $preserved[ $option_name ] ) ) {
				// Use direct query to avoid any caching issues.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
						$option_name
					)
				);

				$value = maybe_serialize( $preserved[ $option_name ] );

				if ( $exists ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->update(
						$wpdb->options,
						array( 'option_value' => $value ),
						array( 'option_name' => $option_name ),
						array( '%s' ),
						array( '%s' )
					);
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->insert(
						$wpdb->options,
						array(
							'option_name'  => $option_name,
							'option_value' => $value,
							'autoload'     => 'yes',
						),
						array( '%s', '%s', '%s' )
					);
				}

				if ( false !== $result ) {
					++$restored_count;
				} else {
					error_log( 'Swish Import: Failed to restore option: ' . $option_name . ' - ' . $wpdb->last_error );
				}
			}
		}

		error_log( 'Swish Import: Restored ' . $restored_count . ' critical options' );

		// Ensure Swish plugin stays active.
		if ( ! empty( $preserved['_swish_plugin_file'] ) ) {
			$active_plugins = get_option( 'active_plugins', array() );
			if ( ! in_array( $preserved['_swish_plugin_file'], $active_plugins, true ) ) {
				$active_plugins[] = $preserved['_swish_plugin_file'];
				update_option( 'active_plugins', $active_plugins );
			}
		}

		// CRITICAL: Restore the user's session tokens.
		// This prevents the user from being logged out after database restore.
		if ( ! empty( $preserved['_import_user_id'] ) && ! empty( $preserved['_user_session_tokens'] ) ) {
			$user_id = (int) $preserved['_import_user_id'];

			// Check if usermeta table exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$usermeta_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->usermeta )
			);

			if ( $usermeta_exists ) {
				// Check if session_tokens row exists for this user.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens' LIMIT 1",
					$user_id
				) );

				if ( $meta_exists ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->usermeta,
						array( 'meta_value' => $preserved['_user_session_tokens'] ),
						array( 'umeta_id' => $meta_exists ),
						array( '%s' ),
						array( '%d' )
					);
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->insert(
						$wpdb->usermeta,
						array(
							'user_id'    => $user_id,
							'meta_key'   => 'session_tokens',
							'meta_value' => $preserved['_user_session_tokens'],
						),
						array( '%d', '%s', '%s' )
					);
				}

				error_log( 'Swish Import: Restored session tokens for user ' . $user_id );

				// Also restore capabilities if preserved.
				if ( ! empty( $preserved['_user_capabilities'] ) && ! empty( $preserved['_user_capabilities_key'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$cap_meta_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
						$user_id,
						$preserved['_user_capabilities_key']
					) );

					if ( $cap_meta_id ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$wpdb->usermeta,
							array( 'meta_value' => $preserved['_user_capabilities'] ),
							array( 'umeta_id' => $cap_meta_id ),
							array( '%s' ),
							array( '%d' )
						);
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->insert(
							$wpdb->usermeta,
							array(
								'user_id'    => $user_id,
								'meta_key'   => $preserved['_user_capabilities_key'],
								'meta_value' => $preserved['_user_capabilities'],
							),
							array( '%d', '%s', '%s' )
						);
					}
					error_log( 'Swish Import: Restored capabilities for user ' . $user_id );
				}
			}
		}

		// Clear WordPress internal caches for options.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// Clear user meta cache.
		if ( ! empty( $preserved['_import_user_id'] ) ) {
			wp_cache_delete( $preserved['_import_user_id'], 'user_meta' );
		}

		return true;
	}

	/**
	 * Get the path to the state file.
	 *
	 * @return string Path to the state file.
	 */
	private static function get_state_file_path(): string {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups/temp';
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $backup_dir, 0755, true );
		}
		return $backup_dir . '/' . self::STATE_FILE_NAME;
	}

	/**
	 * Ensure the state directory exists and is writable.
	 *
	 * @return bool True if directory is ready for writing.
	 */
	private static function ensure_state_directory(): bool {
		$backup_dir = WP_CONTENT_DIR . '/swish-backups/temp';

		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! @mkdir( $backup_dir, 0755, true ) ) {
				// Try parent directory.
				$parent = dirname( $backup_dir );
				if ( ! is_dir( $parent ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
					@mkdir( $parent, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				@mkdir( $backup_dir, 0755, true );
			}
		}

		return is_dir( $backup_dir ) && is_writable( $backup_dir );
	}

	/**
	 * Save import state.
	 *
	 * This tracks the current phase and progress of an import operation.
	 * Saves to BOTH database and file to survive database restore.
	 * File storage is CRITICAL - if file write fails, we log an error.
	 *
	 * @param array $state The import state.
	 * @return void
	 */
	public static function save_state( array $state ): void {
		// Update last_activity timestamp.
		$state['last_activity'] = gmdate( 'Y-m-d H:i:s' );

		// Ensure directory exists.
		if ( ! self::ensure_state_directory() ) {
			// Log error but continue - we'll try to write anyway.
			error_log( 'Swish Import: State directory not writable: ' . dirname( self::get_state_file_path() ) );
		}

		// Save to file FIRST (critical for surviving database restore).
		$state_file = self::get_state_file_path();
		$json = wp_json_encode( $state, JSON_PRETTY_PRINT );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes_written = @file_put_contents( $state_file, $json, LOCK_EX );

		if ( false === $bytes_written || $bytes_written !== strlen( $json ) ) {
			// File write failed! This is critical.
			error_log( 'Swish Import: CRITICAL - Failed to write state file: ' . $state_file );
			error_log( 'Swish Import: State that failed to save: ' . $json );
		}

		// Also save to database (may fail during database restore, but that's OK).
		// Suppress errors during database restore when wp_options might not exist.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@update_option( self::IMPORT_STATE_OPTION, $state, false );
	}

	/**
	 * Get import state.
	 *
	 * Prioritizes file-based state over database to survive database restore.
	 *
	 * @return array|null The import state or null if not set.
	 */
	public static function get_state(): ?array {
		// First, try to read from file (survives database restore).
		$state_file = self::get_state_file_path();

		// Clear file stat cache to ensure we read fresh data.
		clearstatcache( true, $state_file );

		if ( file_exists( $state_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = @file_get_contents( $state_file );
			if ( false !== $json && '' !== $json ) {
				$state = json_decode( $json, true );
				if ( is_array( $state ) && ! empty( $state ) ) {
					return $state;
				}
			}
		}

		// Fall back to database.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$state = @get_option( self::IMPORT_STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Clear import state.
	 *
	 * @return void
	 */
	public static function clear_state(): void {
		delete_option( self::IMPORT_STATE_OPTION );
		delete_option( self::PRESERVED_OPTIONS );

		// Remove state file.
		$state_file = self::get_state_file_path();
		if ( file_exists( $state_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $state_file );
		}

		// Remove preserved options file.
		$options_file = self::get_preserved_options_file_path();
		if ( file_exists( $options_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $options_file );
		}
	}

	/**
	 * Create a complete import session.
	 *
	 * @param string $backup_path Path to the backup file.
	 * @param array  $options     Import options.
	 * @return array Session data including secret key.
	 */
	public static function create_session( string $backup_path, array $options = array() ): array {
		$secret_key = self::generate_secret_key();

		$state = array(
			'backup_path'    => $backup_path,
			'options'        => $options,
			'phase'          => 'initialized',
			'phase_priority' => 0,
			'started_at'     => gmdate( 'Y-m-d H:i:s' ),
			'last_activity'  => gmdate( 'Y-m-d H:i:s' ),
			// Offsets for resumable operations.
			'archive_bytes_offset' => 0,
			'file_bytes_offset'    => 0,
			'query_offset'         => 0,
			'total_queries_size'   => 0,
			'total_files_count'    => 0,
			'total_files_size'     => 0,
			'processed_files_size' => 0,
			// Progress tracking.
			'progress'             => 0,
			'current_table'        => null,
		);

		self::save_state( $state );

		return array(
			'secret_key' => $secret_key,
			'state'      => $state,
		);
	}

	/**
	 * Update import phase and progress.
	 *
	 * @param string $phase    The current phase name.
	 * @param int    $priority The phase priority (for ordering).
	 * @param array  $data     Additional state data to merge.
	 * @return void
	 */
	public static function update_phase( string $phase, int $priority, array $data = array() ): void {
		$state = self::get_state() ?? array();

		$state['phase']          = $phase;
		$state['phase_priority'] = $priority;
		$state['last_activity']  = gmdate( 'Y-m-d H:i:s' );

		// Merge additional data.
		foreach ( $data as $key => $value ) {
			$state[ $key ] = $value;
		}

		self::save_state( $state );
	}

	/**
	 * Mark import as completed.
	 *
	 * @param bool  $success Whether the import succeeded.
	 * @param array $result  Result data.
	 * @return void
	 */
	public static function complete( bool $success, array $result = array() ): void {
		$state = self::get_state() ?? array();

		$state['phase']        = $success ? 'completed' : 'failed';
		$state['completed_at'] = gmdate( 'Y-m-d H:i:s' );
		$state['success']      = $success;
		$state['result']       = $result;

		self::save_state( $state );

		// Clear secret key after completion.
		self::clear_secret_key();
	}

	/**
	 * Check if an import is in progress.
	 *
	 * @return bool True if import is active.
	 */
	public static function is_import_active(): bool {
		$state = self::get_state();
		if ( empty( $state ) ) {
			return false;
		}

		// Check if completed or failed.
		if ( in_array( $state['phase'] ?? '', array( 'completed', 'failed' ), true ) ) {
			return false;
		}

		// Check if stale (no activity for 10 minutes).
		// Large database imports on slow servers can have long-running chunks.
		// 10 minutes provides enough buffer while still detecting truly stale sessions.
		$last_activity = strtotime( $state['last_activity'] ?? '' );
		if ( $last_activity && ( time() - $last_activity ) > 600 ) {
			return false;
		}

		return true;
	}

	/**
	 * Force clear any existing import session.
	 *
	 * @return void
	 */
	public static function force_clear(): void {
		self::clear_state();
		self::clear_secret_key();
	}
}
