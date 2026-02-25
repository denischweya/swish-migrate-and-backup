<?php
/**
 * Pro Upgrade Page.
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
 * Pro upgrade/upsell page controller.
 */
final class ProPage {

	/**
	 * Pro sales page URL.
	 *
	 * @var string
	 */
	private const PRO_URL = 'https://swishbackup.swishfolio.com';

	/**
	 * Get feature comparison data.
	 *
	 * @return array Feature comparison data.
	 */
	private function get_features(): array {
		return array(
			array(
				'feature'     => __( 'Full Site Backups', 'swish-migrate-and-backup' ),
				'description' => __( 'Backup your entire WordPress site', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Database Backups', 'swish-migrate-and-backup' ),
				'description' => __( 'Backup only the database', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Files Backups', 'swish-migrate-and-backup' ),
				'description' => __( 'Backup themes, plugins, and uploads', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Cloud Storage', 'swish-migrate-and-backup' ),
				'description' => __( 'Amazon S3, Dropbox, Google Drive', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Scheduled Backups', 'swish-migrate-and-backup' ),
				'description' => __( 'Automatic backups on a schedule', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Site Migration', 'swish-migrate-and-backup' ),
				'description' => __( 'Migrate your site to a new domain', 'swish-migrate-and-backup' ),
				'free'        => true,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Backup Size Limit', 'swish-migrate-and-backup' ),
				'description' => __( 'Maximum backup archive size', 'swish-migrate-and-backup' ),
				'free'        => __( '1 GB', 'swish-migrate-and-backup' ),
				'pro'         => __( 'Unlimited', 'swish-migrate-and-backup' ),
				'highlight'   => true,
			),
			array(
				'feature'     => __( 'Multisite Support', 'swish-migrate-and-backup' ),
				'description' => __( 'Backup entire WordPress multisite networks', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
				'highlight'   => true,
			),
			array(
				'feature'     => __( 'Network-wide Backups', 'swish-migrate-and-backup' ),
				'description' => __( 'Backup all sites or select specific sites', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Flexible Archive Modes', 'swish-migrate-and-backup' ),
				'description' => __( 'Single archive or separate per-site archives', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'WordPress Core Files', 'swish-migrate-and-backup' ),
				'description' => __( 'Include wp-admin, wp-includes in backups', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Selective Folder Backup', 'swish-migrate-and-backup' ),
				'description' => __( 'Choose themes, plugins, uploads, mu-plugins', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Priority Support', 'swish-migrate-and-backup' ),
				'description' => __( 'Get help when you need it', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
			array(
				'feature'     => __( 'Future Updates', 'swish-migrate-and-backup' ),
				'description' => __( 'All future Pro features included', 'swish-migrate-and-backup' ),
				'free'        => false,
				'pro'         => true,
			),
		);
	}

	/**
	 * Render the Pro page.
	 *
	 * @return void
	 */
	public function render(): void {
		$features = $this->get_features();
		?>
		<div class="wrap swish-backup-wrap">
			<?php AdminNav::render(); ?>

			<div class="swish-pro-page">
				<!-- Hero Section -->
				<div class="swish-pro-hero">
					<div class="swish-pro-hero-content">
						<span class="swish-pro-badge"><?php esc_html_e( 'PRO', 'swish-migrate-and-backup' ); ?></span>
						<h1><?php esc_html_e( 'Unlock the Full Power of Swish Backup', 'swish-migrate-and-backup' ); ?></h1>
						<p class="swish-pro-tagline">
							<?php esc_html_e( 'Remove size limits, backup entire multisite networks, and get premium features designed for professionals.', 'swish-migrate-and-backup' ); ?>
						</p>
						<a href="<?php echo esc_url( self::PRO_URL ); ?>" class="swish-pro-cta-button" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Get Swish Backup Pro', 'swish-migrate-and-backup' ); ?>
						</a>
					</div>
				</div>

				<!-- Key Benefits -->
				<div class="swish-pro-benefits">
					<div class="swish-pro-benefit">
						<div class="swish-pro-benefit-icon">
							<span class="dashicons dashicons-cloud-upload"></span>
						</div>
						<h3><?php esc_html_e( 'Unlimited Backups', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'No more 1GB size limit. Backup sites of any size without restrictions.', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-pro-benefit">
						<div class="swish-pro-benefit-icon">
							<span class="dashicons dashicons-networking"></span>
						</div>
						<h3><?php esc_html_e( 'Multisite Networks', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Backup entire WordPress networks or select specific sites to include.', 'swish-migrate-and-backup' ); ?></p>
					</div>
					<div class="swish-pro-benefit">
						<div class="swish-pro-benefit-icon">
							<span class="dashicons dashicons-admin-settings"></span>
						</div>
						<h3><?php esc_html_e( 'Advanced Controls', 'swish-migrate-and-backup' ); ?></h3>
						<p><?php esc_html_e( 'Choose exactly what to backup with granular folder selection.', 'swish-migrate-and-backup' ); ?></p>
					</div>
				</div>

				<!-- Feature Comparison Table -->
				<div class="swish-pro-comparison">
					<h2><?php esc_html_e( 'Feature Comparison', 'swish-migrate-and-backup' ); ?></h2>
					<table class="swish-pro-table">
						<thead>
							<tr>
								<th class="swish-pro-feature-col"><?php esc_html_e( 'Feature', 'swish-migrate-and-backup' ); ?></th>
								<th class="swish-pro-plan-col"><?php esc_html_e( 'Free', 'swish-migrate-and-backup' ); ?></th>
								<th class="swish-pro-plan-col swish-pro-plan-pro"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $features as $feature ) : ?>
								<tr class="<?php echo ! empty( $feature['highlight'] ) ? 'swish-pro-highlight' : ''; ?>">
									<td class="swish-pro-feature-col">
										<strong><?php echo esc_html( $feature['feature'] ); ?></strong>
										<span class="swish-pro-feature-desc"><?php echo esc_html( $feature['description'] ); ?></span>
									</td>
									<td class="swish-pro-plan-col">
										<?php if ( is_bool( $feature['free'] ) ) : ?>
											<?php if ( $feature['free'] ) : ?>
												<span class="swish-pro-check"><span class="dashicons dashicons-yes-alt"></span></span>
											<?php else : ?>
												<span class="swish-pro-cross"><span class="dashicons dashicons-dismiss"></span></span>
											<?php endif; ?>
										<?php else : ?>
											<span class="swish-pro-text"><?php echo esc_html( $feature['free'] ); ?></span>
										<?php endif; ?>
									</td>
									<td class="swish-pro-plan-col swish-pro-plan-pro">
										<?php if ( is_bool( $feature['pro'] ) ) : ?>
											<?php if ( $feature['pro'] ) : ?>
												<span class="swish-pro-check"><span class="dashicons dashicons-yes-alt"></span></span>
											<?php else : ?>
												<span class="swish-pro-cross"><span class="dashicons dashicons-dismiss"></span></span>
											<?php endif; ?>
										<?php else : ?>
											<span class="swish-pro-text swish-pro-text-highlight"><?php echo esc_html( $feature['pro'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- CTA Section -->
				<div class="swish-pro-cta-section">
					<h2><?php esc_html_e( 'Ready to Go Pro?', 'swish-migrate-and-backup' ); ?></h2>
					<p><?php esc_html_e( 'Get unlimited backups, multisite support, and all premium features today.', 'swish-migrate-and-backup' ); ?></p>
					<a href="<?php echo esc_url( self::PRO_URL ); ?>" class="swish-pro-cta-button swish-pro-cta-large" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-star-filled"></span>
						<?php esc_html_e( 'Upgrade to Pro Now', 'swish-migrate-and-backup' ); ?>
					</a>
					<p class="swish-pro-guarantee">
						<span class="dashicons dashicons-shield"></span>
						<?php esc_html_e( '30-day money-back guarantee. No questions asked.', 'swish-migrate-and-backup' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}
}
