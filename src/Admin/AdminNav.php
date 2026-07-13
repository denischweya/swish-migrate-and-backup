<?php
/**
 * Admin Navigation Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

use SwishMigrateAndBackup\Admin\Multisite\AdminLayout;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin navigation bar.
 *
 * Emits the same modern "swish-app" chrome (top nav, dark-mode toggle, cards)
 * used by the multisite network-admin UI so both interfaces look identical.
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
				'icon'  => 'dashboard',
			),
			array(
				'slug'  => 'swish-backup-backups',
				'label' => __( 'Backups', 'swish-migrate-and-backup' ),
				'icon'  => 'backup',
			),
			array(
				'slug'  => 'swish-backup-schedules',
				'label' => __( 'Schedules', 'swish-migrate-and-backup' ),
				'icon'  => 'calendar_month',
			),
			array(
				'slug'  => 'swish-backup-migration',
				'label' => __( 'Migration', 'swish-migrate-and-backup' ),
				'icon'  => 'swap_horiz',
			),
			array(
				'slug'  => 'swish-backup-settings',
				'label' => __( 'Settings', 'swish-migrate-and-backup' ),
				'icon'  => 'settings',
				'class' => 'swish-nav-settings',
			),
			array(
				'slug'  => 'swish-backup-logs',
				'label' => __( 'Logs', 'swish-migrate-and-backup' ),
				'icon'  => 'list_alt',
			),
			array(
				'slug'  => 'swish-backup-docs',
				'label' => __( 'Documentation', 'swish-migrate-and-backup' ),
				'icon'  => 'menu_book',
			),
		);

		return $items;
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
	 * Render the opening layout wrapper, top navigation and page header.
	 *
	 * @param string $title       Page title.
	 * @param string $description Optional page description.
	 * @param array  $actions     Optional header action buttons (pre-escaped HTML strings).
	 * @return void
	 */
	public static function render_start( string $title = '', string $description = '', array $actions = array() ): void {
		$theme = AdminLayout::get_theme();
		?>
		<div class="swish-app swish-app-top-nav" data-theme="<?php echo esc_attr( $theme ); ?>">
			<?php self::render_top_nav(); ?>
			<main class="swish-main">
				<div class="swish-content">
					<div class="swish-content-inner">
						<?php if ( '' !== $title ) : ?>
							<div class="swish-page-header">
								<div class="swish-page-header-main">
									<h3><?php echo esc_html( $title ); ?></h3>
									<?php if ( '' !== $description ) : ?>
										<p><?php echo esc_html( $description ); ?></p>
									<?php endif; ?>
								</div>
								<?php if ( ! empty( $actions ) ) : ?>
									<div class="swish-page-header-actions">
										<?php
										foreach ( $actions as $action ) {
											// Action buttons are pre-escaped markup emitted by the calling page.
											echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										}
										?>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
		<?php
	}

	/**
	 * Render the closing layout wrapper.
	 *
	 * @return void
	 */
	public static function render_end(): void {
		?>
					</div>
				</div>
			</main>
		</div>
		<?php
	}

	/**
	 * Render the top navigation bar.
	 *
	 * @return void
	 */
	private static function render_top_nav(): void {
		$items        = self::get_nav_items();
		$current_page = self::get_current_page();
		?>
		<header class="swish-top-nav">
			<div class="swish-top-nav-brand">
				<span class="material-symbols-outlined" style="color: var(--swish-primary-600);">security</span>
				<h1><?php esc_html_e( 'Swish Backup', 'swish-migrate-and-backup' ); ?></h1>
				<span class="swish-pro-tag"><?php echo esc_html( SWISH_BACKUP_VERSION ); ?></span>
			</div>

			<nav class="swish-top-nav-menu">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$is_active   = $current_page === $item['slug'];
					$item_class  = $is_active ? 'swish-top-nav-item active' : 'swish-top-nav-item';
					$item_class .= isset( $item['class'] ) ? ' ' . $item['class'] : '';
					$url         = admin_url( 'admin.php?page=' . $item['slug'] );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $item_class ); ?>">
						<span class="material-symbols-outlined"><?php echo esc_html( $item['icon'] ); ?></span>
						<span><?php echo esc_html( $item['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="swish-top-nav-actions">
				<button type="button" class="swish-theme-toggle" id="swish-theme-toggle" aria-label="Toggle dark mode">
					<span class="material-symbols-outlined swish-icon-sun">light_mode</span>
					<span class="material-symbols-outlined swish-icon-moon">dark_mode</span>
				</button>
			</div>
		</header>
		<?php
	}

	/**
	 * Render the navigation (back-compat shim).
	 *
	 * Older call sites render just the nav bar; the modern chrome is emitted
	 * by render_start(). Kept so any external caller keeps working.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_top_nav();
	}
}
