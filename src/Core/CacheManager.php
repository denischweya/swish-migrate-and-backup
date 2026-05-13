<?php
/**
 * Cache Manager - Shared cache flushing utilities.
 *
 * Consolidates cache flushing logic to avoid duplication and provides
 * safeguards against infinite loops during transient deletion.
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
 * Manages cache flushing operations.
 */
final class CacheManager {

	/**
	 * Maximum iterations for transient deletion to prevent infinite loops.
	 */
	private const MAX_TRANSIENT_ITERATIONS = 100;

	/**
	 * Batch size for transient deletion queries.
	 */
	private const TRANSIENT_BATCH_SIZE = 1000;

	/**
	 * Flush all caches after restore/migration.
	 *
	 * This includes:
	 * - Rewrite rules
	 * - Object cache
	 * - Transients (with iteration limit)
	 * - Opcache
	 *
	 * @return array Statistics about the flush operation.
	 */
	public static function flush_all(): array {
		$stats = array(
			'rewrite_rules_flushed' => false,
			'object_cache_flushed'  => false,
			'transients_deleted'    => 0,
			'opcache_reset'         => false,
		);

		// Flush rewrite rules.
		flush_rewrite_rules();
		$stats['rewrite_rules_flushed'] = true;

		// Flush object cache.
		wp_cache_flush();
		$stats['object_cache_flushed'] = true;

		// Clear transients with iteration limit.
		$stats['transients_deleted'] = self::clear_transients();

		// Clear opcache if available.
		if ( function_exists( 'opcache_reset' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@opcache_reset();
			$stats['opcache_reset'] = true;
		}

		return $stats;
	}

	/**
	 * Clear transients in batches with iteration limit.
	 *
	 * Prevents infinite loops on sites with continuously regenerating transients
	 * by limiting the number of deletion iterations.
	 *
	 * @return int Total number of transients deleted.
	 */
	public static function clear_transients(): int {
		global $wpdb;

		$total_deleted = 0;
		$iterations = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
					'_transient_%',
					self::TRANSIENT_BATCH_SIZE
				)
			);

			$deleted_count = ( false !== $deleted ) ? (int) $deleted : 0;
			$total_deleted += $deleted_count;
			++$iterations;

			// Free memory between batches.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Safety limit to prevent infinite loops.
			// 100 iterations * 1000 batch = 100,000 transients max.
			if ( $iterations >= self::MAX_TRANSIENT_ITERATIONS ) {
				break;
			}
		} while ( $deleted_count > 0 );

		return $total_deleted;
	}

	/**
	 * Clear specific cache types.
	 *
	 * @param array $types Cache types to clear: 'rewrite', 'object', 'transients', 'opcache'.
	 * @return array Statistics about the flush operation.
	 */
	public static function flush_specific( array $types ): array {
		$stats = array();

		if ( in_array( 'rewrite', $types, true ) ) {
			flush_rewrite_rules();
			$stats['rewrite_rules_flushed'] = true;
		}

		if ( in_array( 'object', $types, true ) ) {
			wp_cache_flush();
			$stats['object_cache_flushed'] = true;
		}

		if ( in_array( 'transients', $types, true ) ) {
			$stats['transients_deleted'] = self::clear_transients();
		}

		if ( in_array( 'opcache', $types, true ) && function_exists( 'opcache_reset' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@opcache_reset();
			$stats['opcache_reset'] = true;
		}

		return $stats;
	}
}
