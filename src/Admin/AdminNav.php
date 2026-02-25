<?php
/**
 * Admin Navigation Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin navigation bar.
 */
final class AdminNav {

	/**
	 * Get the navigation items.
	 *
	 * @return array Navigation items.
	 */
	public static function get_nav_items(): array {
		$items = array(
			array(
				'slug'  => 'swish-backup',
				'label' => __( 'Dashboard', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-dashboard',
			),
			array(
				'slug'  => 'swish-backup-backups',
				'label' => __( 'Backups', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-database',
			),
			array(
				'slug'  => 'swish-backup-schedules',
				'label' => __( 'Schedules', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-calendar-alt',
			),
			array(
				'slug'  => 'swish-backup-migration',
				'label' => __( 'Migration', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-migrate',
			),
			array(
				'slug'  => 'swish-backup-settings',
				'label' => __( 'Settings', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-admin-settings',
				'class' => 'swish-nav-settings',
			),
			array(
				'slug'  => 'swish-backup-docs',
				'label' => __( 'Documentation', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-book',
			),
		);

		// Add Pro page only if Pro is not installed.
		if ( ! self::is_pro_installed() ) {
			$items[] = array(
				'slug'  => 'swish-backup-pro',
				'label' => __( 'Go Pro', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-star-filled',
				'class' => 'swish-nav-pro',
			);
		}

		return $items;
	}

	/**
	 * Check if Pro version is installed.
	 *
	 * @return bool True if Pro is installed.
	 */
	public static function is_pro_installed(): bool {
		return defined( 'SWISH_BACKUP_PRO_VERSION' ) || is_plugin_active( 'swish-migrate-and-backup-pro/swish-migrate-and-backup-pro.php' );
	}

	/**
	 * Get the current page slug.
	 *
	 * @return string Current page slug.
	 */
	public static function get_current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Render the navigation.
	 *
	 * @return void
	 */
	public static function render(): void {
		$items        = self::get_nav_items();
		$current_page = self::get_current_page();
		?>
		<div class="swish-admin-nav">
			<div class="swish-admin-nav-brand">
				<span class="dashicons dashicons-cloud-saved"></span>
				<span class="swish-admin-nav-title"><?php esc_html_e( 'Swish Backup', 'swish-migrate-and-backup' ); ?></span>
				<span class="swish-admin-nav-version"><?php echo esc_html( SWISH_BACKUP_VERSION ); ?></span>
			</div>
			<nav class="swish-admin-nav-links">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$is_active   = $current_page === $item['slug'];
					$item_class  = $is_active ? 'swish-nav-item active' : 'swish-nav-item';
					$item_class .= isset( $item['class'] ) ? ' ' . $item['class'] : '';
					$url         = admin_url( 'admin.php?page=' . $item['slug'] );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $item_class ); ?>">
						<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
						<span class="swish-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}
}
