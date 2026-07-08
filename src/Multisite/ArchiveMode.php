<?php
/**
 * Archive Mode Handler.
 *
 * @package SwishMigrateAndBackup\Multisite
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles archive mode selection and logic.
 */
final class ArchiveMode {

	/**
	 * Single archive mode constant.
	 */
	public const MODE_SINGLE = 'single';

	/**
	 * Separate archives mode constant.
	 */
	public const MODE_SEPARATE = 'separate';

	/**
	 * Validate archive mode.
	 *
	 * @param string $mode Archive mode.
	 * @return bool True if valid.
	 */
	public static function is_valid_mode( string $mode ): bool {
		return in_array( $mode, array( self::MODE_SINGLE, self::MODE_SEPARATE ), true );
	}

	/**
	 * Get default archive mode.
	 *
	 * @return string Default mode.
	 */
	public static function get_default_mode(): string {
		return self::MODE_SINGLE;
	}

	/**
	 * Get archive mode from options.
	 *
	 * @param array $options Backup options.
	 * @return string Valid archive mode.
	 */
	public static function get_mode_from_options( array $options ): string {
		$mode = $options['archive_mode'] ?? self::get_default_mode();

		if ( ! self::is_valid_mode( $mode ) ) {
			return self::get_default_mode();
		}

		return $mode;
	}

	/**
	 * Get archive mode description.
	 *
	 * @param string $mode Archive mode.
	 * @return string Description.
	 */
	public static function get_mode_description( string $mode ): string {
		$descriptions = array(
			self::MODE_SINGLE   => __( 'All sites in one archive - Easier to manage, single backup file', 'swish-migrate-and-backup' ),
			self::MODE_SEPARATE => __( 'Separate archive per site - Individual site restore, multiple files', 'swish-migrate-and-backup' ),
		);

		return $descriptions[ $mode ] ?? '';
	}

	/**
	 * Get available archive modes.
	 *
	 * @return array Array of mode => description.
	 */
	public static function get_available_modes(): array {
		return array(
			self::MODE_SINGLE   => array(
				'value'       => self::MODE_SINGLE,
				'label'       => __( 'Single Archive', 'swish-migrate-and-backup' ),
				'description' => self::get_mode_description( self::MODE_SINGLE ),
				'icon'        => 'archive',
			),
			self::MODE_SEPARATE => array(
				'value'       => self::MODE_SEPARATE,
				'label'       => __( 'Separate Archives', 'swish-migrate-and-backup' ),
				'description' => self::get_mode_description( self::MODE_SEPARATE ),
				'icon'        => 'media-archive',
			),
		);
	}

	/**
	 * Get recommended mode based on site count.
	 *
	 * @param int $site_count Number of sites.
	 * @return string Recommended mode.
	 */
	public static function get_recommended_mode( int $site_count ): string {
		// For many sites, separate archives might be better.
		if ( $site_count > 10 ) {
			return self::MODE_SEPARATE;
		}

		// For fewer sites, single archive is easier.
		return self::MODE_SINGLE;
	}
}
