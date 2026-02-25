<?php
/**
 * Documentation Page.
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
 * Documentation page controller.
 */
final class DocumentationPage {

	/**
	 * Get documentation sections.
	 *
	 * @return array Documentation sections.
	 */
	private function get_sections(): array {
		return array(
			'getting-started' => array(
				'title' => __( 'Getting Started', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-welcome-learn-more',
			),
			'creating-backups' => array(
				'title' => __( 'Creating Backups', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-database-add',
			),
			'restoring-backups' => array(
				'title' => __( 'Restoring Backups', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-backup',
			),
			'scheduled-backups' => array(
				'title' => __( 'Scheduled Backups', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-calendar-alt',
			),
			'cloud-storage' => array(
				'title' => __( 'Cloud Storage', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-cloud',
			),
			'migration' => array(
				'title' => __( 'Site Migration', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-migrate',
			),
			'pro-features' => array(
				'title' => __( 'Pro Features', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-star-filled',
			),
			'troubleshooting' => array(
				'title' => __( 'Troubleshooting', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-sos',
			),
			'faq' => array(
				'title' => __( 'FAQ', 'swish-migrate-and-backup' ),
				'icon'  => 'dashicons-editor-help',
			),
		);
	}

	/**
	 * Get admin page URL.
	 *
	 * @param string $page Page slug.
	 * @return string Admin page URL.
	 */
	private function get_admin_url( string $page ): string {
		return admin_url( 'admin.php?page=' . $page );
	}

	/**
	 * Render admin page link.
	 *
	 * @param string $page  Page slug.
	 * @param string $label Link label.
	 * @return string HTML link.
	 */
	private function admin_link( string $page, string $label ): string {
		return sprintf(
			'<a href="%s" class="swish-docs-admin-link">%s</a>',
			esc_url( $this->get_admin_url( $page ) ),
			esc_html( $label )
		);
	}

	/**
	 * Render the documentation page.
	 *
	 * @return void
	 */
	public function render(): void {
		$sections = $this->get_sections();
		?>
		<div class="wrap swish-backup-wrap">
			<?php AdminNav::render(); ?>

			<div class="swish-docs-page">
				<div class="swish-docs-header">
					<h1>
						<span class="dashicons dashicons-book"></span>
						<?php esc_html_e( 'Documentation', 'swish-migrate-and-backup' ); ?>
					</h1>
					<p class="swish-docs-intro">
						<?php esc_html_e( 'Learn how to use Swish Backup to protect your WordPress site with easy-to-follow guides.', 'swish-migrate-and-backup' ); ?>
					</p>
				</div>

				<div class="swish-docs-layout">
					<!-- Sidebar Navigation -->
					<div class="swish-docs-sidebar">
						<nav class="swish-docs-nav">
							<?php foreach ( $sections as $slug => $section ) : ?>
								<a href="#<?php echo esc_attr( $slug ); ?>" class="swish-docs-nav-item">
									<span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
									<?php echo esc_html( $section['title'] ); ?>
								</a>
							<?php endforeach; ?>
						</nav>
					</div>

					<!-- Main Content -->
					<div class="swish-docs-content">

						<!-- Getting Started -->
						<section id="getting-started" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-welcome-learn-more"></span>
								<?php esc_html_e( 'Getting Started', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Welcome to Swish Backup', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Swish Backup is a powerful WordPress plugin that helps you create backups of your website, store them safely, and restore them when needed. Whether you\'re protecting against data loss or migrating to a new server, Swish Backup has you covered.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Dashboard Overview', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'The Dashboard is your central hub for managing backups. Here you can:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Create new backups with one click', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'View your recent backups', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'See storage status and space used', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Access quick actions for common tasks', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'First Steps', 'swish-migrate-and-backup' ); ?></h3>
								<ol>
									<li>
										<strong><?php esc_html_e( 'Create your first backup:', 'swish-migrate-and-backup' ); ?></strong>
										<?php
										printf(
											/* translators: %s: Dashboard link */
											esc_html__( 'Go to %s and click "Create Backup" to protect your site immediately.', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup', __( 'Dashboard', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li>
										<strong><?php esc_html_e( 'Configure settings:', 'swish-migrate-and-backup' ); ?></strong>
										<?php
										printf(
											/* translators: %s: Settings link */
											esc_html__( 'Visit %s to customize backup options and set up cloud storage.', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-settings', __( 'Settings', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li>
										<strong><?php esc_html_e( 'Set up automatic backups:', 'swish-migrate-and-backup' ); ?></strong>
										<?php
										printf(
											/* translators: %s: Schedules link */
											esc_html__( 'Go to %s to enable automatic backups so you never forget.', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-schedules', __( 'Schedules', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
								</ol>
							</div>
						</section>

						<!-- Creating Backups -->
						<section id="creating-backups" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-database-add"></span>
								<?php esc_html_e( 'Creating Backups', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Backup Types', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Swish Backup offers three types of backups:', 'swish-migrate-and-backup' ); ?></p>

								<div class="swish-docs-feature-list">
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-database-export"></span>
										<div>
											<strong><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Includes everything: database, themes, plugins, uploads, and media files. This is the most complete backup option and recommended for most users.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-database"></span>
										<div>
											<strong><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Only backs up your WordPress database (posts, pages, settings, users). This is the fastest option and creates smaller backup files.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-media-archive"></span>
										<div>
											<strong><?php esc_html_e( 'Files Only', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Backs up only your files (themes, plugins, uploads) without the database. Useful if you have a separate database backup solution.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'How to Create a Backup', 'swish-migrate-and-backup' ); ?></h3>
								<ol>
									<li>
										<?php
										printf(
											/* translators: 1: Dashboard link, 2: Backups link */
											esc_html__( 'Go to %1$s or %2$s', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup', __( 'Dashboard', 'swish-migrate-and-backup' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											$this->admin_link( 'swish-backup-backups', __( 'Backups', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li><?php esc_html_e( 'Click the "Create Backup" button', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Select your backup type (Full, Database, or Files)', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Wait for the backup to complete - you\'ll see a progress bar', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Once complete, your backup will appear in the backups list', 'swish-migrate-and-backup' ); ?></li>
								</ol>
								<div class="swish-docs-tip">
									<span class="dashicons dashicons-lightbulb"></span>
									<p><?php esc_html_e( 'Tip: Create a full backup before making major changes to your site, such as updating WordPress, themes, or plugins.', 'swish-migrate-and-backup' ); ?></p>
								</div>
							</div>
						</section>

						<!-- Restoring Backups -->
						<section id="restoring-backups" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-backup"></span>
								<?php esc_html_e( 'Restoring Backups', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'How to Restore a Backup', 'swish-migrate-and-backup' ); ?></h3>
								<ol>
									<li>
										<?php
										printf(
											/* translators: %s: Backups link */
											esc_html__( 'Go to %s', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-backups', __( 'Backups', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li><?php esc_html_e( 'Find the backup you want to restore', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Click the "Restore" button', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Choose what to restore (database, files, or both)', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Optionally, enable "Create backup before restore" for safety', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Click "Restore Now" to begin', 'swish-migrate-and-backup' ); ?></li>
								</ol>
								<div class="swish-docs-warning">
									<span class="dashicons dashicons-warning"></span>
									<p><?php esc_html_e( 'Warning: Restoring a backup will overwrite your current site data. Always enable "Create backup before restore" unless you\'re sure you want to replace everything.', 'swish-migrate-and-backup' ); ?></p>
								</div>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Restore Options', 'swish-migrate-and-backup' ); ?></h3>
								<ul>
									<li><strong><?php esc_html_e( 'Restore Database:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Restores all your content, settings, and user data', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Restore Files:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Restores themes, plugins, and uploaded files', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Create backup before restore:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Creates a safety backup of your current site first', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>
						</section>

						<!-- Scheduled Backups -->
						<section id="scheduled-backups" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-calendar-alt"></span>
								<?php esc_html_e( 'Scheduled Backups', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Setting Up Automatic Backups', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Automatic backups ensure your site is always protected without you having to remember to create backups manually.', 'swish-migrate-and-backup' ); ?></p>
								<ol>
									<li>
										<?php
										printf(
											/* translators: %s: Schedules link */
											esc_html__( 'Go to %s', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-schedules', __( 'Schedules', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li><?php esc_html_e( 'Click "Add New Schedule"', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Choose a frequency (Hourly, Daily, Weekly, or Monthly)', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Select the backup type', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Choose where to store backups', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Save your schedule', 'swish-migrate-and-backup' ); ?></li>
								</ol>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Recommended Schedule', 'swish-migrate-and-backup' ); ?></h3>
								<ul>
									<li><strong><?php esc_html_e( 'Busy sites (e-commerce, news):', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Daily full backups', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Regular blogs:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Weekly full backups', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Static sites:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Monthly full backups', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>
						</section>

						<!-- Cloud Storage -->
						<section id="cloud-storage" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-cloud"></span>
								<?php esc_html_e( 'Cloud Storage', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Why Use Cloud Storage?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Storing backups in the cloud protects you even if something happens to your server. Your backups are safe and accessible from anywhere.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Supported Storage Providers', 'swish-migrate-and-backup' ); ?></h3>
								<div class="swish-docs-feature-list">
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-cloud"></span>
										<div>
											<strong><?php esc_html_e( 'Amazon S3', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Professional-grade cloud storage from Amazon Web Services. Great for businesses needing reliable, scalable storage.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-cloud"></span>
										<div>
											<strong><?php esc_html_e( 'Dropbox', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Easy-to-use cloud storage. Perfect if you already use Dropbox for other files.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-cloud"></span>
										<div>
											<strong><?php esc_html_e( 'Google Drive', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Store backups in your Google Drive account. Great integration with Google Workspace.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Setting Up Cloud Storage', 'swish-migrate-and-backup' ); ?></h3>
								<ol>
									<li>
										<?php
										printf(
											/* translators: %s: Settings link */
											esc_html__( 'Go to %s', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-settings', __( 'Settings', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li><?php esc_html_e( 'Find the Storage section', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Click on your preferred storage provider', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Enter your API credentials (instructions provided)', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Test the connection', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Save your settings', 'swish-migrate-and-backup' ); ?></li>
								</ol>
							</div>
						</section>

						<!-- Migration -->
						<section id="migration" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-migrate"></span>
								<?php esc_html_e( 'Site Migration', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Moving Your Site to a New Domain', 'swish-migrate-and-backup' ); ?></h3>
								<ol>
									<li><?php esc_html_e( 'Create a full backup on your current site', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Download the backup file', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Install WordPress on your new server/domain', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Install and activate Swish Backup', 'swish-migrate-and-backup' ); ?></li>
									<li>
										<?php
										printf(
											/* translators: %s: Migration link */
											esc_html__( 'Go to %s', 'swish-migrate-and-backup' ),
											$this->admin_link( 'swish-backup-migration', __( 'Migration', 'swish-migrate-and-backup' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										);
										?>
									</li>
									<li><?php esc_html_e( 'Upload your backup file', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Enter your old and new URLs', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Click "Migrate" - the plugin will update all URLs automatically', 'swish-migrate-and-backup' ); ?></li>
								</ol>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Search and Replace', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'The Migration page also includes a Search and Replace tool. This is useful for:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Changing URLs throughout your database', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Switching from HTTP to HTTPS', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Fixing broken links after a domain change', 'swish-migrate-and-backup' ); ?></li>
								</ul>
								<div class="swish-docs-tip">
									<span class="dashicons dashicons-lightbulb"></span>
									<p><?php esc_html_e( 'Tip: Always create a backup before running Search and Replace, just in case you need to undo the changes.', 'swish-migrate-and-backup' ); ?></p>
								</div>
							</div>
						</section>

						<!-- Pro Features -->
						<section id="pro-features" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-star-filled"></span>
								<?php esc_html_e( 'Pro Features', 'swish-migrate-and-backup' ); ?>
								<span class="swish-docs-pro-badge"><?php esc_html_e( 'PRO', 'swish-migrate-and-backup' ); ?></span>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'About Swish Backup Pro', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Swish Backup Pro unlocks powerful features for professionals and larger websites. Upgrade to remove limitations and access advanced functionality.', 'swish-migrate-and-backup' ); ?></p>
								<?php if ( ! AdminNav::is_pro_installed() ) : ?>
									<p>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=swish-backup-pro' ) ); ?>" class="button button-primary">
											<?php esc_html_e( 'Learn More About Pro', 'swish-migrate-and-backup' ); ?>
										</a>
									</p>
								<?php endif; ?>
							</div>

							<div class="swish-docs-card">
								<h3>
									<?php esc_html_e( 'Unlimited Backup Size', 'swish-migrate-and-backup' ); ?>
									<span class="swish-docs-pro-tag"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></span>
								</h3>
								<p><?php esc_html_e( 'The free version has a 1GB backup size limit. With Pro, you can backup sites of any size without restrictions. This is essential for:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'E-commerce sites with large product catalogs', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Media-heavy websites with lots of images and videos', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Established blogs with years of content', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Enterprise sites with extensive data', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>

							<div class="swish-docs-card">
								<h3>
									<?php esc_html_e( 'WordPress Multisite Support', 'swish-migrate-and-backup' ); ?>
									<span class="swish-docs-pro-tag"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></span>
								</h3>
								<p><?php esc_html_e( 'Pro enables full support for WordPress Multisite networks:', 'swish-migrate-and-backup' ); ?></p>
								<div class="swish-docs-feature-list">
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-networking"></span>
										<div>
											<strong><?php esc_html_e( 'Network-wide Backups', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Backup your entire multisite network with all subsites in a single operation.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-yes-alt"></span>
										<div>
											<strong><?php esc_html_e( 'Selective Site Backup', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Choose specific sites to include in your backup instead of backing up the entire network.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
									<div class="swish-docs-feature">
										<span class="dashicons dashicons-archive"></span>
										<div>
											<strong><?php esc_html_e( 'Flexible Archive Modes', 'swish-migrate-and-backup' ); ?></strong>
											<p><?php esc_html_e( 'Create a single archive containing all sites, or generate separate archives for each site.', 'swish-migrate-and-backup' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="swish-docs-card">
								<h3>
									<?php esc_html_e( 'WordPress Core Files Backup', 'swish-migrate-and-backup' ); ?>
									<span class="swish-docs-pro-tag"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></span>
								</h3>
								<p><?php esc_html_e( 'Include WordPress core files (wp-admin, wp-includes) in your backups. This is useful for:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Complete site cloning to a new server', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Disaster recovery where WordPress needs to be fully restored', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Preserving custom core modifications (not recommended, but sometimes necessary)', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>

							<div class="swish-docs-card">
								<h3>
									<?php esc_html_e( 'Selective Folder Backup', 'swish-migrate-and-backup' ); ?>
									<span class="swish-docs-pro-tag"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></span>
								</h3>
								<p><?php esc_html_e( 'Choose exactly which wp-content folders to include in your backup:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><strong><?php esc_html_e( 'Themes:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'All installed themes', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Plugins:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'All installed plugins', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'Uploads:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Media library files', 'swish-migrate-and-backup' ); ?></li>
									<li><strong><?php esc_html_e( 'MU-Plugins:', 'swish-migrate-and-backup' ); ?></strong> <?php esc_html_e( 'Must-use plugins', 'swish-migrate-and-backup' ); ?></li>
								</ul>
								<div class="swish-docs-tip">
									<span class="dashicons dashicons-lightbulb"></span>
									<p><?php esc_html_e( 'Tip: Exclude large folders like uploads when you only need to backup code changes, making backups faster and smaller.', 'swish-migrate-and-backup' ); ?></p>
								</div>
							</div>

							<div class="swish-docs-card">
								<h3>
									<?php esc_html_e( 'Priority Support', 'swish-migrate-and-backup' ); ?>
									<span class="swish-docs-pro-tag"><?php esc_html_e( 'Pro', 'swish-migrate-and-backup' ); ?></span>
								</h3>
								<p><?php esc_html_e( 'Pro users receive priority email support with faster response times. Our team is ready to help you with:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Setup and configuration assistance', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Troubleshooting backup issues', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Migration guidance', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Cloud storage configuration', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>
						</section>

						<!-- Troubleshooting -->
						<section id="troubleshooting" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-sos"></span>
								<?php esc_html_e( 'Troubleshooting', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Backup is Taking Too Long', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Large sites may take longer to backup. Try these solutions:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Create a Database-only backup first (it\'s faster)', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Check your server\'s PHP memory limit in Settings', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Consider backing up during low-traffic periods', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Backup Failed', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'If a backup fails, check the following:', 'swish-migrate-and-backup' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Disk space: Make sure you have enough free space', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Permissions: The wp-content folder must be writable', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Memory limit: Increase PHP memory if your host allows', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'File size: Free version has a 1GB limit - consider upgrading to Pro', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>

							<div class="swish-docs-card">
								<h3><?php esc_html_e( 'Cloud Storage Not Working', 'swish-migrate-and-backup' ); ?></h3>
								<ul>
									<li><?php esc_html_e( 'Double-check your API credentials', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Use the "Test Connection" button to verify settings', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Make sure your server can make outbound connections', 'swish-migrate-and-backup' ); ?></li>
									<li><?php esc_html_e( 'Check that the cURL PHP extension is enabled', 'swish-migrate-and-backup' ); ?></li>
								</ul>
							</div>
						</section>

						<!-- FAQ -->
						<section id="faq" class="swish-docs-section">
							<h2>
								<span class="dashicons dashicons-editor-help"></span>
								<?php esc_html_e( 'Frequently Asked Questions', 'swish-migrate-and-backup' ); ?>
							</h2>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'Where are my backups stored?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'By default, backups are stored in wp-content/swish-backups/ on your server. You can also configure cloud storage to save copies to Amazon S3, Dropbox, or Google Drive.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'How large can my backups be?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'The free version supports backups up to 1GB. For unlimited backup sizes, upgrade to Swish Backup Pro.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'Can I restore a backup to a different site?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Yes! Download your backup and use the Migration tool on the new site. The plugin will automatically update URLs during the migration process.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'Will backups slow down my site?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Backups use server resources while running, but they don\'t affect your site when not active. Schedule backups during low-traffic times for best results.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'Are my cloud storage credentials secure?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'Yes, all credentials are encrypted using AES-256-CBC encryption before being stored in your database. Only your site can decrypt them.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'Does this plugin work with WordPress Multisite?', 'swish-migrate-and-backup' ); ?></h3>
								<p><?php esc_html_e( 'The free version works on single sites. For full multisite network backup support, upgrade to Swish Backup Pro.', 'swish-migrate-and-backup' ); ?></p>
							</div>

							<div class="swish-docs-card swish-docs-faq">
								<h3><?php esc_html_e( 'How do I get support?', 'swish-migrate-and-backup' ); ?></h3>
								<p>
									<?php
									printf(
										/* translators: %s: Support URL */
										esc_html__( 'Visit our support page at %s or email us directly. Pro users receive priority support.', 'swish-migrate-and-backup' ),
										'<a href="https://swishbackup.swishfolio.com/support" target="_blank" rel="noopener noreferrer">swishbackup.swishfolio.com/support</a>'
									);
									?>
								</p>
							</div>
						</section>

					</div>
				</div>
			</div>
		</div>

		<script>
		(function() {
			'use strict';

			const navItems = document.querySelectorAll('.swish-docs-nav-item');
			const sections = document.querySelectorAll('.swish-docs-section');

			if (!navItems.length || !sections.length) return;

			// Smooth scroll with offset for admin bar
			navItems.forEach(function(item) {
				item.addEventListener('click', function(e) {
					e.preventDefault();
					const targetId = this.getAttribute('href').substring(1);
					const targetSection = document.getElementById(targetId);

					if (targetSection) {
						const adminBarHeight = document.getElementById('wpadminbar') ?
							document.getElementById('wpadminbar').offsetHeight : 0;
						const offset = adminBarHeight + 20;
						const targetPosition = targetSection.getBoundingClientRect().top + window.pageYOffset - offset;

						window.scrollTo({
							top: targetPosition,
							behavior: 'smooth'
						});

						// Update URL hash without jumping
						history.pushState(null, null, '#' + targetId);
					}
				});
			});

			// Scroll spy - highlight active section
			function updateActiveNav() {
				const adminBarHeight = document.getElementById('wpadminbar') ?
					document.getElementById('wpadminbar').offsetHeight : 0;
				const scrollPosition = window.pageYOffset + adminBarHeight + 100;

				let currentSection = '';

				// If at top of page, always highlight first section
				if (window.pageYOffset < 100) {
					currentSection = sections[0].getAttribute('id');
				} else {
					// Find the current section based on scroll position
					for (let i = sections.length - 1; i >= 0; i--) {
						const section = sections[i];
						const sectionTop = section.offsetTop - adminBarHeight - 120;

						if (window.pageYOffset >= sectionTop) {
							currentSection = section.getAttribute('id');
							break;
						}
					}

					// Fallback to first section if nothing found
					if (!currentSection) {
						currentSection = sections[0].getAttribute('id');
					}
				}

				// Update nav items
				navItems.forEach(function(item) {
					const href = item.getAttribute('href').substring(1);
					item.classList.remove('active');
					if (href === currentSection) {
						item.classList.add('active');
					}
				});
			}

			// Throttle scroll events for better performance
			let ticking = false;
			window.addEventListener('scroll', function() {
				if (!ticking) {
					window.requestAnimationFrame(function() {
						updateActiveNav();
						ticking = false;
					});
					ticking = true;
				}
			});

			// Initial check - always start with first section unless there's a hash
			if (window.location.hash) {
				const targetId = window.location.hash.substring(1);
				const targetSection = document.getElementById(targetId);
				if (targetSection) {
					setTimeout(function() {
						const adminBarHeight = document.getElementById('wpadminbar') ?
							document.getElementById('wpadminbar').offsetHeight : 0;
						const offset = adminBarHeight + 20;
						const targetPosition = targetSection.getBoundingClientRect().top + window.pageYOffset - offset;

						window.scrollTo({
							top: targetPosition,
							behavior: 'smooth'
						});
						updateActiveNav();
					}, 100);
				}
			} else {
				// No hash - highlight first section
				navItems[0].classList.add('active');
			}
		})();
		</script>
		<?php
	}
}
