<?php
/**
 * Site Selector UI Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Multisite\MultisiteManager;
use SwishMigrateAndBackup\Multisite\ArchiveMode;

/**
 * Renders site selection UI for multisite backups.
 */
final class SiteSelectorUI {

	/**
	 * Multisite manager.
	 *
	 * @var MultisiteManager
	 */
	private MultisiteManager $multisite_manager;

	/**
	 * Constructor.
	 *
	 * @param MultisiteManager $multisite_manager Multisite manager.
	 */
	public function __construct( MultisiteManager $multisite_manager ) {
		$this->multisite_manager = $multisite_manager;
	}

	/**
	 * Render site selector.
	 *
	 * @param array $options Render options.
	 * @return void
	 */
	public function render( array $options = array() ): void {
		$sites           = $this->multisite_manager->get_sites_for_selection();
		$selected_sites  = $options['selected_sites'] ?? array();
		$archive_modes   = ArchiveMode::get_available_modes();
		$default_mode    = $options['default_mode'] ?? ArchiveMode::get_default_mode();
		$show_search     = $options['show_search'] ?? true;
		$show_select_all = $options['show_select_all'] ?? true;
		$initial_display = $options['initial_display'] ?? 20;

		?>
		<div class="swish-site-selector">
			<?php if ( $show_search || $show_select_all ) : ?>
				<div class="swish-site-selector-header swish-flex swish-items-center swish-justify-between swish-mb-4">
					<?php if ( $show_search ) : ?>
						<div class="swish-search-input" style="max-width: 320px; flex: 1;">
							<span class="material-symbols-outlined" style="color: var(--swish-text-muted);">search</span>
							<input
								type="text"
								class="swish-site-search"
								placeholder="<?php esc_attr_e( 'Search sites...', 'swish-migrate-and-backup' ); ?>"
							/>
						</div>
					<?php endif; ?>

					<?php if ( $show_select_all ) : ?>
						<div class="swish-site-selector-actions swish-flex swish-gap-2">
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-select-all-sites">
								<?php esc_html_e( 'Select All', 'swish-migrate-and-backup' ); ?>
							</button>
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-deselect-all-sites">
								<?php esc_html_e( 'Deselect All', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $sites ) ) : ?>
				<div class="swish-card">
					<div class="swish-card-body" style="padding: 0;">
						<table class="swish-table swish-sites-table">
							<thead>
								<tr>
									<th style="width: 50px;">
										<input type="checkbox" id="swish-select-all-checkbox" class="swish-checkbox" />
									</th>
									<th><?php esc_html_e( 'Site Name', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'URL', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sites as $index => $site ) : ?>
									<?php
									$is_selected = in_array( $site['blog_id'], $selected_sites, true );
									$row_class = $index >= $initial_display ? 'swish-site-row-hidden' : '';
									?>
									<tr class="swish-site-row <?php echo esc_attr( $row_class ); ?>"
										data-site-id="<?php echo esc_attr( $site['blog_id'] ); ?>"
										data-site-name="<?php echo esc_attr( strtolower( $site['site_name'] ) ); ?>"
										data-site-url="<?php echo esc_attr( strtolower( $site['site_url'] ) ); ?>"
										style="<?php echo esc_attr( $index >= $initial_display ? 'display: none;' : '' ); ?>">
										<td>
											<input
												type="checkbox"
												class="swish-checkbox swish-site-checkbox"
												name="site_ids[]"
												value="<?php echo esc_attr( $site['blog_id'] ); ?>"
												<?php checked( $is_selected ); ?>
											/>
										</td>
										<td>
											<div class="swish-flex swish-items-center swish-gap-2">
												<span class="material-symbols-outlined" style="font-size: 18px; color: var(--swish-primary-600);">language</span>
												<div>
													<span class="swish-table-cell-primary"><?php echo esc_html( $site['site_name'] ); ?></span>
													<?php if ( $site['is_main'] ) : ?>
														<span class="swish-badge swish-badge-info" style="margin-left: var(--swish-space-2);">
															<?php esc_html_e( 'MAIN', 'swish-migrate-and-backup' ); ?>
														</span>
													<?php endif; ?>
												</div>
											</div>
										</td>
										<td class="swish-table-cell-secondary"><?php echo esc_html( $site['site_url'] ); ?></td>
										<td class="swish-table-cell-mono swish-text-right"><?php echo esc_html( $site['formatted_size'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( count( $sites ) > $initial_display ) : ?>
						<div class="swish-card-footer" style="justify-content: center;">
							<button type="button" class="swish-btn swish-btn-secondary swish-load-more-sites">
								<span class="material-symbols-outlined" style="font-size: 18px;">expand_more</span>
								<span>
									<?php
									printf(
										/* translators: %d: number of additional sites */
										esc_html__( 'Load More (%d remaining)', 'swish-migrate-and-backup' ),
										count( $sites ) - $initial_display
									);
									?>
								</span>
							</button>
						</div>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php
				AdminLayout::render_empty_state(
					__( 'No Sites Found', 'swish-migrate-and-backup' ),
					__( 'There are no sites available in this network.', 'swish-migrate-and-backup' ),
					'language'
				);
				?>
			<?php endif; ?>
		</div>

		<?php $this->render_archive_mode_selector( $archive_modes, $default_mode ); ?>
		<?php $this->render_backup_options(); ?>
		<?php
	}

	/**
	 * Render backup options (core files, wp-content folders, etc).
	 *
	 * @return void
	 */
	private function render_backup_options(): void {
		$core_size    = $this->estimate_core_files_size();
		$folder_sizes = $this->estimate_wp_content_folder_sizes();
		?>
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<?php esc_html_e( 'Backup Options', 'swish-migrate-and-backup' ); ?>
				</h4>
			</div>
			<div class="swish-card-body">
				<!-- Backup Type Selection -->
				<div class="swish-backup-type-selector swish-mb-4">
					<div class="swish-radio-group" style="flex-direction: row; gap: var(--swish-space-6);">
						<label class="swish-radio-wrapper">
							<input
								type="radio"
								name="backup_type"
								id="backup_type_full"
								value="full"
								checked="checked"
								class="swish-radio"
							/>
							<div>
								<strong><?php esc_html_e( 'Full Backup', 'swish-migrate-and-backup' ); ?></strong>
								<span style="color: var(--swish-text-tertiary); margin-left: var(--swish-space-2);">
									<?php esc_html_e( '(Database + Files)', 'swish-migrate-and-backup' ); ?>
								</span>
							</div>
						</label>
						<label class="swish-radio-wrapper">
							<input
								type="radio"
								name="backup_type"
								id="backup_type_database"
								value="database"
								class="swish-radio"
							/>
							<div>
								<strong><?php esc_html_e( 'Database Only', 'swish-migrate-and-backup' ); ?></strong>
								<span style="color: var(--swish-text-tertiary); margin-left: var(--swish-space-2);">
									<?php esc_html_e( '(Faster, smaller backup)', 'swish-migrate-and-backup' ); ?>
								</span>
							</div>
						</label>
					</div>
				</div>

				<!-- File Options Container -->
				<div id="swish-file-options">
					<!-- WordPress Core Files -->
					<div class="swish-form-group">
						<label class="swish-checkbox-wrapper">
							<input
								type="checkbox"
								name="include_core_files"
								id="include_core_files"
								value="1"
								checked="checked"
								class="swish-checkbox"
							/>
							<div>
								<span class="swish-checkbox-label">
									<?php esc_html_e( 'Include WordPress Core Files', 'swish-migrate-and-backup' ); ?>
								</span>
								<span class="swish-badge swish-badge-neutral" style="margin-left: var(--swish-space-2);">
									<?php echo esc_html( $core_size ); ?>
								</span>
								<p style="font-size: var(--swish-text-sm); color: var(--swish-text-tertiary); margin-top: var(--swish-space-1);">
									<?php esc_html_e( 'Include wp-admin, wp-includes, and core PHP files.', 'swish-migrate-and-backup' ); ?>
								</p>
							</div>
						</label>
					</div>

					<!-- WP-Content Folders -->
					<div class="swish-form-group">
						<div style="margin-bottom: var(--swish-space-3);">
							<span class="swish-form-label"><?php esc_html_e( 'Include wp-content Folders', 'swish-migrate-and-backup' ); ?></span>
						</div>

						<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--swish-space-3);">
							<!-- Themes -->
							<label class="swish-checkbox-wrapper" style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
								<input
									type="checkbox"
									name="include_themes"
									id="include_themes"
									value="1"
									checked="checked"
									class="swish-checkbox swish-folder-checkbox"
								/>
								<div>
									<span class="swish-checkbox-label"><?php esc_html_e( 'Themes', 'swish-migrate-and-backup' ); ?></span>
									<span class="swish-badge swish-badge-neutral"><?php echo esc_html( $folder_sizes['themes'] ); ?></span>
								</div>
							</label>

							<!-- Plugins -->
							<label class="swish-checkbox-wrapper" style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
								<input
									type="checkbox"
									name="include_plugins"
									id="include_plugins"
									value="1"
									checked="checked"
									class="swish-checkbox swish-folder-checkbox"
								/>
								<div>
									<span class="swish-checkbox-label"><?php esc_html_e( 'Plugins', 'swish-migrate-and-backup' ); ?></span>
									<span class="swish-badge swish-badge-neutral"><?php echo esc_html( $folder_sizes['plugins'] ); ?></span>
								</div>
							</label>

							<!-- Uploads -->
							<label class="swish-checkbox-wrapper" style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
								<input
									type="checkbox"
									name="include_uploads"
									id="include_uploads"
									value="1"
									checked="checked"
									class="swish-checkbox swish-folder-checkbox"
								/>
								<div>
									<span class="swish-checkbox-label"><?php esc_html_e( 'Uploads', 'swish-migrate-and-backup' ); ?></span>
									<span class="swish-badge swish-badge-neutral"><?php echo esc_html( $folder_sizes['uploads'] ); ?></span>
								</div>
							</label>

							<!-- MU-Plugins -->
							<label class="swish-checkbox-wrapper" style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
								<input
									type="checkbox"
									name="include_mu_plugins"
									id="include_mu_plugins"
									value="1"
									checked="checked"
									class="swish-checkbox swish-folder-checkbox"
								/>
								<div>
									<span class="swish-checkbox-label"><?php esc_html_e( 'MU-Plugins', 'swish-migrate-and-backup' ); ?></span>
									<span class="swish-badge swish-badge-neutral"><?php echo esc_html( $folder_sizes['mu-plugins'] ); ?></span>
								</div>
							</label>
						</div>

						<div class="swish-flex swish-gap-2 swish-mt-4">
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-select-all-folders">
								<?php esc_html_e( 'Select All', 'swish-migrate-and-backup' ); ?>
							</button>
							<button type="button" class="swish-btn swish-btn-secondary swish-btn-sm swish-deselect-all-folders">
								<?php esc_html_e( 'Deselect All', 'swish-migrate-and-backup' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script>
		jQuery( document ).ready( function( $ ) {
			// Backup type toggle.
			$( 'input[name="backup_type"]' ).on( 'change', function() {
				if ( $( '#backup_type_database' ).is( ':checked' ) ) {
					$( '#swish-file-options' ).css( { opacity: 0.5, pointerEvents: 'none' } );
				} else {
					$( '#swish-file-options' ).css( { opacity: 1, pointerEvents: 'auto' } );
				}
			} );

			// Select all folders.
			$( '.swish-select-all-folders' ).on( 'click', function() {
				$( '#include_core_files, .swish-folder-checkbox' ).prop( 'checked', true );
			} );

			// Deselect all folders.
			$( '.swish-deselect-all-folders' ).on( 'click', function() {
				$( '#include_core_files, .swish-folder-checkbox' ).prop( 'checked', false );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Estimate wp-content folder sizes.
	 *
	 * @return array Formatted size strings for each folder.
	 */
	private function estimate_wp_content_folder_sizes(): array {
		$wp_content = WP_CONTENT_DIR;

		return array(
			'themes'     => $this->get_formatted_directory_size( $wp_content . '/themes' ),
			'plugins'    => $this->get_formatted_directory_size( $wp_content . '/plugins' ),
			'uploads'    => $this->get_formatted_directory_size( $wp_content . '/uploads' ),
			'mu-plugins' => $this->get_formatted_directory_size( $wp_content . '/mu-plugins' ),
		);
	}

	/**
	 * Get formatted directory size.
	 *
	 * @param string $dir Directory path.
	 * @return string Formatted size or "N/A".
	 */
	private function get_formatted_directory_size( string $dir ): string {
		if ( ! is_dir( $dir ) ) {
			return __( 'N/A', 'swish-migrate-and-backup' );
		}

		$size = $this->get_directory_size( $dir );
		return size_format( $size, 1 );
	}

	/**
	 * Estimate WordPress core files size.
	 *
	 * @return string Formatted size string.
	 */
	private function estimate_core_files_size(): string {
		$size = 0;

		// wp-admin size.
		if ( is_dir( ABSPATH . 'wp-admin' ) ) {
			$size += $this->get_directory_size( ABSPATH . 'wp-admin' );
		}

		// wp-includes size.
		if ( is_dir( ABSPATH . 'wp-includes' ) ) {
			$size += $this->get_directory_size( ABSPATH . 'wp-includes' );
		}

		// Add ~500KB for root PHP files.
		$size += 500 * 1024;

		return size_format( $size, 1 );
	}

	/**
	 * Get directory size recursively.
	 *
	 * @param string $dir Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( string $dir ): int {
		$size = 0;

		if ( ! is_dir( $dir ) ) {
			return $size;
		}

		// Directories to exclude from size calculation.
		$exclude_dirs = array( 'swish-backups', 'cache', 'wflogs' );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					function ( $current, $key, $iterator ) use ( $exclude_dirs ) {
						// Skip excluded directories.
						if ( $current->isDir() && in_array( $current->getFilename(), $exclude_dirs, true ) ) {
							return false;
						}
						return true;
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			// Silently handle permission errors.
			return 0;
		}

		return $size;
	}

	/**
	 * Render archive mode selector.
	 *
	 * @param array  $modes        Available modes.
	 * @param string $selected_mode Selected mode.
	 * @return void
	 */
	private function render_archive_mode_selector( array $modes, string $selected_mode ): void {
		?>
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<?php esc_html_e( 'Archive Mode', 'swish-migrate-and-backup' ); ?>
				</h4>
			</div>
			<div class="swish-card-body">
				<p style="color: var(--swish-text-secondary); margin-bottom: var(--swish-space-4);">
					<?php esc_html_e( 'Choose how you want to package the backup files.', 'swish-migrate-and-backup' ); ?>
				</p>

				<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--swish-space-4);">
					<?php foreach ( $modes as $mode ) : ?>
						<label class="swish-archive-mode-option <?php echo esc_attr( $mode['value'] === $selected_mode ? 'selected' : '' ); ?>"
							 data-mode="<?php echo esc_attr( $mode['value'] ); ?>"
							 style="display: block; padding: var(--swish-space-4); border: 2px solid var(--swish-border-default); border-radius: var(--swish-radius-lg); cursor: pointer; transition: all var(--swish-transition-fast); <?php echo esc_attr( $mode['value'] === $selected_mode ? 'border-color: var(--swish-primary-600); background: var(--swish-primary-50);' : '' ); ?>">
							<div class="swish-flex swish-items-center swish-gap-3">
								<input
									type="radio"
									name="archive_mode"
									value="<?php echo esc_attr( $mode['value'] ); ?>"
									<?php checked( $mode['value'], $selected_mode ); ?>
									class="swish-radio"
								/>
								<div>
									<strong style="color: var(--swish-text-primary);"><?php echo esc_html( $mode['label'] ); ?></strong>
									<p style="margin: var(--swish-space-1) 0 0; font-size: var(--swish-text-sm); color: var(--swish-text-tertiary);">
										<?php echo esc_html( $mode['description'] ); ?>
									</p>
								</div>
							</div>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render site count summary.
	 *
	 * @param int $selected_count Number of selected sites.
	 * @param int $total_count    Total number of sites.
	 * @return void
	 */
	public function render_site_count_summary( int $selected_count, int $total_count ): void {
		?>
		<div class="swish-site-count-summary">
			<p style="color: var(--swish-text-secondary); font-size: var(--swish-text-sm);">
				<?php
				printf(
					/* translators: 1: selected count, 2: total count */
					esc_html__( 'Selected %1$d of %2$d sites', 'swish-migrate-and-backup' ),
					esc_html( $selected_count ),
					esc_html( $total_count )
				);
				?>
			</p>
		</div>
		<?php
	}
}
