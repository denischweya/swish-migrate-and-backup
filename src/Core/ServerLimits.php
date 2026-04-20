<?php
/**
 * Server Limits Detection and Adaptation.
 *
 * Detects hosting environment limits and provides adaptive settings
 * for backup/migration operations.
 *
 * @package SwishMigrateAndBackup\Core
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects server limits and provides adaptive configuration.
 */
final class ServerLimits {

	/**
	 * Cached limits.
	 *
	 * @var array|null
	 */
	private static ?array $limits = null;

	/**
	 * Request start time.
	 *
	 * @var float
	 */
	private static float $start_time = 0;

	/**
	 * Get all server limits.
	 *
	 * @return array Server limits.
	 */
	public static function get_limits(): array {
		if ( null === self::$limits ) {
			self::$limits = array(
				'max_execution_time'   => self::get_max_execution_time(),
				'memory_limit'         => self::get_memory_limit(),
				'memory_available'     => self::get_available_memory(),
				'upload_max_filesize'  => self::get_upload_max_filesize(),
				'post_max_size'        => self::get_post_max_size(),
				'max_input_time'       => self::get_max_input_time(),
				'is_managed_hosting'   => self::is_managed_hosting(),
				'hosting_environment'  => self::detect_hosting_environment(),
			);
		}

		return self::$limits;
	}

	/**
	 * Initialize request timing.
	 *
	 * Call this at the start of a long-running operation.
	 *
	 * @return void
	 */
	public static function init_timing(): void {
		self::$start_time = microtime( true );
	}

	/**
	 * Get elapsed time since init_timing() was called.
	 *
	 * @return float Elapsed time in seconds.
	 */
	public static function get_elapsed_time(): float {
		if ( 0 === self::$start_time ) {
			self::init_timing();
		}

		return microtime( true ) - self::$start_time;
	}

	/**
	 * Get remaining execution time.
	 *
	 * @param float $safety_margin Seconds to reserve as safety margin (default 5).
	 * @return float Remaining seconds, or -1 if unlimited.
	 */
	public static function get_remaining_time( float $safety_margin = 5.0 ): float {
		$max_time = self::get_max_execution_time();

		if ( 0 === $max_time ) {
			return -1; // Unlimited.
		}

		$elapsed = self::get_elapsed_time();
		$remaining = $max_time - $elapsed - $safety_margin;

		return max( 0, $remaining );
	}

	/**
	 * Check if we're approaching the time limit.
	 *
	 * @param float $threshold_seconds Seconds before limit to trigger (default 10).
	 * @return bool True if approaching limit.
	 */
	public static function is_approaching_time_limit( float $threshold_seconds = 10.0 ): bool {
		$remaining = self::get_remaining_time( 0 );

		if ( -1 === $remaining ) {
			return false; // Unlimited time.
		}

		return $remaining <= $threshold_seconds;
	}

	/**
	 * Check if memory is getting low.
	 *
	 * @param int $threshold_bytes Minimum bytes required (default 32MB).
	 * @return bool True if memory is low.
	 */
	public static function is_memory_low( int $threshold_bytes = 33554432 ): bool {
		return self::get_available_memory() < $threshold_bytes;
	}

	/**
	 * Get max execution time in seconds.
	 *
	 * @return int Max execution time (0 = unlimited).
	 */
	public static function get_max_execution_time(): int {
		$max_time = (int) ini_get( 'max_execution_time' );

		// On some hosts, this might be set very high or unlimited.
		// Cap at a reasonable max for safety.
		if ( $max_time > 300 || 0 === $max_time ) {
			// Check if we're in a CLI environment (typically unlimited).
			if ( php_sapi_name() === 'cli' ) {
				return 0; // Unlimited for CLI.
			}
		}

		return $max_time;
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @return int Memory limit in bytes.
	 */
	public static function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );

		if ( '-1' === $limit ) {
			return PHP_INT_MAX; // Unlimited.
		}

		return self::parse_size( $limit );
	}

	/**
	 * Get available memory in bytes.
	 *
	 * @return int Available memory.
	 */
	public static function get_available_memory(): int {
		$limit = self::get_memory_limit();
		$used = memory_get_usage( true );

		return max( 0, $limit - $used );
	}

	/**
	 * Get upload_max_filesize in bytes.
	 *
	 * @return int Upload max filesize.
	 */
	public static function get_upload_max_filesize(): int {
		return self::parse_size( ini_get( 'upload_max_filesize' ) ?: '2M' );
	}

	/**
	 * Get post_max_size in bytes.
	 *
	 * @return int Post max size.
	 */
	public static function get_post_max_size(): int {
		return self::parse_size( ini_get( 'post_max_size' ) ?: '8M' );
	}

	/**
	 * Get max_input_time in seconds.
	 *
	 * @return int Max input time.
	 */
	public static function get_max_input_time(): int {
		return (int) ini_get( 'max_input_time' );
	}

	/**
	 * Check if running on managed hosting (WP Engine, Flywheel, etc.).
	 *
	 * @return bool True if managed hosting detected.
	 */
	public static function is_managed_hosting(): bool {
		$environment = self::detect_hosting_environment();
		return in_array( $environment, array( 'wpengine', 'flywheel', 'kinsta', 'pantheon', 'cloudways' ), true );
	}

	/**
	 * Detect hosting environment.
	 *
	 * @return string Hosting environment identifier.
	 */
	public static function detect_hosting_environment(): string {
		// WP Engine.
		if ( defined( 'WPE_APIKEY' ) || getenv( 'IS_WPE' ) ) {
			return 'wpengine';
		}

		// Flywheel.
		if ( defined( 'FLYWHEEL_CONFIG_DIR' ) || getenv( 'FLYWHEEL_ENV' ) ) {
			return 'flywheel';
		}

		// Kinsta.
		if ( defined( 'KINSTA_CACHE_ZONE' ) || getenv( 'KINSTA_CACHE_ZONE' ) ) {
			return 'kinsta';
		}

		// Pantheon.
		if ( defined( 'PANTHEON_ENVIRONMENT' ) || getenv( 'PANTHEON_ENVIRONMENT' ) ) {
			return 'pantheon';
		}

		// Cloudways.
		if ( getenv( 'CLOUDWAYS_ENV' ) || strpos( gethostname() ?: '', 'cloudways' ) !== false ) {
			return 'cloudways';
		}

		// SiteGround.
		if ( strpos( gethostname() ?: '', 'siteground' ) !== false || file_exists( '/etc/siteground' ) ) {
			return 'siteground';
		}

		// GoDaddy.
		if ( strpos( gethostname() ?: '', 'godaddy' ) !== false || defined( 'GD_SYSTEM_PLUGIN_DIR' ) ) {
			return 'godaddy';
		}

		// Bluehost.
		if ( strpos( gethostname() ?: '', 'bluehost' ) !== false || defined( 'STARTER_PLUGIN_VERSION' ) ) {
			return 'bluehost';
		}

		return 'standard';
	}

	/**
	 * Check if running on WP Engine specifically.
	 *
	 * WP Engine has very strict limits and aggressive process killing.
	 *
	 * @return bool True if WP Engine detected.
	 */
	public static function is_wpengine(): bool {
		return 'wpengine' === self::detect_hosting_environment();
	}

	/**
	 * Check if running on a strict managed host.
	 *
	 * These hosts have aggressive timeouts and need extra care.
	 *
	 * @return bool True if strict hosting detected.
	 */
	public static function is_strict_hosting(): bool {
		$environment = self::detect_hosting_environment();
		return in_array( $environment, array( 'wpengine', 'flywheel', 'kinsta', 'pantheon' ), true );
	}

	/**
	 * Get the safe timeout threshold for this environment.
	 *
	 * Returns how many seconds before max_execution_time we should stop.
	 *
	 * @return float Seconds before timeout to stop processing.
	 */
	public static function get_safe_timeout_threshold(): float {
		$environment = self::detect_hosting_environment();

		// WP Engine and Flywheel are very aggressive - stop much earlier.
		if ( in_array( $environment, array( 'wpengine', 'flywheel' ), true ) ) {
			return 20.0; // Stop 20 seconds before limit.
		}

		// Other managed hosts - still be careful.
		if ( self::is_managed_hosting() ) {
			return 15.0;
		}

		// Standard hosting.
		return 10.0;
	}

	/**
	 * Get the export phase timeout.
	 *
	 * This is the maximum time to spend in a single export phase
	 * before chaining to a new HTTP request. Uses a short timeout
	 * (10 seconds default) like All-in-One WP Migration for reliability.
	 *
	 * @return int Timeout in seconds.
	 */
	public static function get_export_timeout(): int {
		$environment = self::detect_hosting_environment();

		// WP Engine is very aggressive - use shorter timeout.
		if ( 'wpengine' === $environment ) {
			return 8;
		}

		// Other strict hosts.
		if ( self::is_strict_hosting() ) {
			return 10;
		}

		// Standard timeout - still keep it short for reliability.
		return apply_filters( 'swish_export_timeout', 10 );
	}

	/**
	 * Get the streaming chunk size for file operations.
	 *
	 * Uses 512KB chunks like All-in-One WP Migration for
	 * minimal memory usage regardless of file size.
	 *
	 * @return int Chunk size in bytes.
	 */
	public static function get_streaming_chunk_size(): int {
		$available_memory = self::get_available_memory();

		// Very low memory - use smaller chunks.
		if ( $available_memory < 32 * 1024 * 1024 ) { // < 32MB.
			return 256 * 1024; // 256KB.
		}

		// Standard 512KB chunk (same as All-in-One WP Migration).
		return 512 * 1024; // 512KB.
	}

	/**
	 * Get how often to check for timeout (every N files).
	 *
	 * @return int Number of files between timeout checks.
	 */
	public static function get_timeout_check_interval(): int {
		$environment = self::detect_hosting_environment();

		// WP Engine - check very frequently.
		if ( 'wpengine' === $environment ) {
			return 10;
		}

		// Other strict hosts.
		if ( self::is_strict_hosting() ) {
			return 15;
		}

		// Managed hosting.
		if ( self::is_managed_hosting() ) {
			return 25;
		}

		// Standard.
		return 50;
	}

	/**
	 * Get adaptive batch size for file operations.
	 *
	 * @param int $default Default batch size.
	 * @return int Adjusted batch size.
	 */
	public static function get_adaptive_file_batch_size( int $default = 100 ): int {
		$limits = self::get_limits();
		$batch_size = $default;
		$environment = $limits['hosting_environment'];

		// WP Engine - very strict limits.
		if ( 'wpengine' === $environment ) {
			return min( $batch_size, 15 ); // Max 15 files at a time.
		}

		// Other strict hosts.
		if ( in_array( $environment, array( 'flywheel', 'kinsta', 'pantheon' ), true ) ) {
			return min( $batch_size, 25 );
		}

		// Reduce batch size for short execution times.
		$max_time = $limits['max_execution_time'];
		if ( $max_time > 0 && $max_time <= 30 ) {
			$batch_size = min( $batch_size, 20 );
		} elseif ( $max_time > 0 && $max_time <= 60 ) {
			$batch_size = min( $batch_size, 40 );
		}

		// Reduce batch size for low memory.
		$available_memory = $limits['memory_available'];
		if ( $available_memory < 64 * 1024 * 1024 ) { // < 64MB.
			$batch_size = min( $batch_size, 20 );
		} elseif ( $available_memory < 128 * 1024 * 1024 ) { // < 128MB.
			$batch_size = min( $batch_size, 40 );
		}

		// Other managed hosting - still be careful.
		if ( $limits['is_managed_hosting'] ) {
			$batch_size = min( $batch_size, 35 );
		}

		return max( 10, $batch_size ); // Never go below 10.
	}

	/**
	 * Get adaptive batch size for database operations.
	 *
	 * @param int $default Default batch size.
	 * @return int Adjusted batch size.
	 */
	public static function get_adaptive_db_batch_size( int $default = 500 ): int {
		$limits = self::get_limits();
		$batch_size = $default;

		// Reduce batch size for short execution times.
		$max_time = $limits['max_execution_time'];
		if ( $max_time > 0 && $max_time <= 30 ) {
			$batch_size = min( $batch_size, 100 );
		} elseif ( $max_time > 0 && $max_time <= 60 ) {
			$batch_size = min( $batch_size, 250 );
		}

		// Reduce batch size for low memory.
		$available_memory = $limits['memory_available'];
		if ( $available_memory < 64 * 1024 * 1024 ) {
			$batch_size = min( $batch_size, 100 );
		} elseif ( $available_memory < 128 * 1024 * 1024 ) {
			$batch_size = min( $batch_size, 250 );
		}

		return max( 50, $batch_size );
	}

	/**
	 * Get safe operation time per item.
	 *
	 * Returns the estimated safe time to spend on each item
	 * given the total number of items and server limits.
	 *
	 * @param int $total_items Total items to process.
	 * @return float Seconds per item.
	 */
	public static function get_safe_time_per_item( int $total_items ): float {
		$max_time = self::get_max_execution_time();

		if ( 0 === $max_time || $total_items <= 0 ) {
			return 0.1; // Default 100ms per item for unlimited.
		}

		// Reserve 20% as safety margin.
		$available_time = $max_time * 0.8;

		return $available_time / $total_items;
	}

	/**
	 * Get recommended ZIP flush interval.
	 *
	 * How often to close/reopen ZIP to prevent memory buildup.
	 * On strict hosts, we flush much more frequently.
	 *
	 * @return int Files between flushes.
	 */
	public static function get_zip_flush_interval(): int {
		$environment = self::detect_hosting_environment();
		$available_memory = self::get_available_memory();

		// WP Engine - flush very frequently to prevent memory issues.
		if ( 'wpengine' === $environment ) {
			return 15; // Flush every 15 files.
		}

		// Other strict hosts.
		if ( in_array( $environment, array( 'flywheel', 'kinsta', 'pantheon' ), true ) ) {
			return 25;
		}

		// Managed hosting.
		if ( self::is_managed_hosting() ) {
			return 50;
		}

		// Standard hosting - base on memory.
		if ( $available_memory < 64 * 1024 * 1024 ) {
			return 50;
		} elseif ( $available_memory < 128 * 1024 * 1024 ) {
			return 100;
		} elseif ( $available_memory < 256 * 1024 * 1024 ) {
			return 200;
		}

		return 300;
	}

	/**
	 * Get recommended compression level.
	 *
	 * Higher compression = more CPU/memory usage.
	 *
	 * @return int Compression level 0-9.
	 */
	public static function get_recommended_compression(): int {
		$limits = self::get_limits();

		// Use lower compression on constrained environments.
		if ( $limits['max_execution_time'] > 0 && $limits['max_execution_time'] <= 30 ) {
			return 1; // Fastest.
		}

		if ( $limits['memory_available'] < 64 * 1024 * 1024 ) {
			return 3; // Low compression.
		}

		if ( $limits['is_managed_hosting'] ) {
			return 5; // Medium compression.
		}

		return 6; // Default.
	}

	/**
	 * Parse PHP size string to bytes.
	 *
	 * @param string $size Size string (e.g., "128M", "1G").
	 * @return int Size in bytes.
	 */
	private static function parse_size( string $size ): int {
		$size = trim( $size );

		if ( empty( $size ) ) {
			return 0;
		}

		$value = (int) $size;
		$unit = strtoupper( substr( $size, -1 ) );

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

	/**
	 * Format bytes for display.
	 *
	 * @param int $bytes Bytes.
	 * @return string Formatted size.
	 */
	public static function format_bytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$bytes = max( $bytes, 0 );
		$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow = min( $pow, count( $units ) - 1 );

		return round( $bytes / pow( 1024, $pow ), 2 ) . ' ' . $units[ $pow ];
	}

	/**
	 * Get debug info for logging.
	 *
	 * @return array Debug information.
	 */
	public static function get_debug_info(): array {
		$limits = self::get_limits();

		return array(
			'max_execution_time'    => $limits['max_execution_time'] . 's',
			'memory_limit'          => self::format_bytes( $limits['memory_limit'] ),
			'memory_available'      => self::format_bytes( $limits['memory_available'] ),
			'hosting_environment'   => $limits['hosting_environment'],
			'is_managed_hosting'    => $limits['is_managed_hosting'] ? 'yes' : 'no',
			'is_strict_hosting'     => self::is_strict_hosting() ? 'yes' : 'no',
			'adaptive_file_batch'   => self::get_adaptive_file_batch_size(),
			'adaptive_db_batch'     => self::get_adaptive_db_batch_size(),
			'zip_flush_interval'    => self::get_zip_flush_interval(),
			'timeout_check_interval' => self::get_timeout_check_interval(),
			'safe_timeout_threshold' => self::get_safe_timeout_threshold() . 's',
			'compression_level'     => self::get_recommended_compression(),
		);
	}
}
