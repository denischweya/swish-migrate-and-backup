<?php
/**
 * Duplicate Site UI Component.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin\Multisite;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Multisite\MultisiteDetector;

/**
 * Renders site duplication UI for multisite.
 */
final class DuplicateUI {

	/**
	 * Multisite detector.
	 *
	 * @var MultisiteDetector
	 */
	private MultisiteDetector $detector;

	/**
	 * Constructor.
	 *
	 * @param MultisiteDetector $detector Multisite detector.
	 */
	public function __construct( MultisiteDetector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Render the duplicate sites page.
	 *
	 * @return void
	 */
	public function render(): void {
		$sites        = $this->detector->get_network_sites();
		$network_info = $this->detector->get_network_info();

		?>
		<div class="swish-duplicate-sites">
			<!-- Search/Filter Bar -->
			<div class="swish-site-selector-header swish-flex swish-items-center swish-justify-between swish-mb-4">
				<div class="swish-search-input" style="max-width: 320px; flex: 1;">
					<span class="material-symbols-outlined" style="color: var(--swish-text-muted);">search</span>
					<input
						type="text"
						id="swish-duplicate-search"
						class="swish-site-search"
						placeholder="<?php esc_attr_e( 'Search sites...', 'swish-migrate-and-backup' ); ?>"
					/>
				</div>
				<div class="swish-text-secondary" style="font-size: var(--swish-text-sm);">
					<?php
					printf(
						/* translators: %d: number of sites */
						esc_html__( '%d sites in network', 'swish-migrate-and-backup' ),
						count( $sites )
					);
					?>
				</div>
			</div>

			<?php if ( ! empty( $sites ) ) : ?>
				<div class="swish-card">
					<div class="swish-card-body" style="padding: 0;">
						<table class="swish-table swish-sites-table" id="swish-duplicate-sites-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Site Name', 'swish-migrate-and-backup' ); ?></th>
									<th><?php esc_html_e( 'URL', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
									<th style="text-align: center; width: 200px;"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sites as $site ) : ?>
									<?php
									$size = $this->detector->estimate_site_size( $site['blog_id'] );
									?>
									<tr class="swish-site-row"
										data-site-id="<?php echo esc_attr( $site['blog_id'] ); ?>"
										data-site-name="<?php echo esc_attr( strtolower( $site['site_name'] ) ); ?>"
										data-site-url="<?php echo esc_attr( strtolower( $site['site_url'] ) ); ?>"
										data-site-title="<?php echo esc_attr( $site['site_name'] ); ?>">
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
										<td class="swish-table-cell-mono swish-text-right"><?php echo esc_html( size_format( $size['total_size'] ) ); ?></td>
										<td style="text-align: center;">
											<div class="swish-flex swish-gap-2 swish-justify-center">
												<!-- Duplicate -->
												<button type="button"
													class="swish-btn swish-btn-secondary swish-btn-sm swish-duplicate-btn swish-tooltip"
													data-site-id="<?php echo esc_attr( $site['blog_id'] ); ?>"
													data-site-name="<?php echo esc_attr( $site['site_name'] ); ?>"
													data-site-url="<?php echo esc_attr( $site['site_url'] ); ?>"
													data-tooltip="<?php esc_attr_e( 'Duplicate', 'swish-migrate-and-backup' ); ?>">
													<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>
												</button>
												<!-- Dashboard -->
												<a href="<?php echo esc_url( trailingslashit( $site['site_url'] ) . 'wp-admin/' ); ?>"
													class="swish-btn swish-btn-secondary swish-btn-sm swish-tooltip"
													target="_blank"
													rel="noopener noreferrer"
													data-tooltip="<?php esc_attr_e( 'Dashboard', 'swish-migrate-and-backup' ); ?>">
													<span class="material-symbols-outlined" style="font-size: 16px;">dashboard</span>
												</a>
												<!-- Delete -->
												<?php if ( ! $site['is_main'] ) : ?>
													<button type="button"
														class="swish-btn swish-btn-danger swish-btn-sm swish-delete-site-btn swish-tooltip"
														data-site-id="<?php echo esc_attr( $site['blog_id'] ); ?>"
														data-site-name="<?php echo esc_attr( $site['site_name'] ); ?>"
														data-site-url="<?php echo esc_attr( $site['site_url'] ); ?>"
														data-tooltip="<?php esc_attr_e( 'Delete', 'swish-migrate-and-backup' ); ?>">
														<span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
													</button>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
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

		<?php
		$this->render_duplicate_modal( $network_info );
		$this->render_delete_modal();
		$this->render_progress_modal();
		$this->render_scripts( $network_info );
	}

	/**
	 * Render the duplication form modal.
	 *
	 * @param array $network_info Network information.
	 * @return void
	 */
	private function render_duplicate_modal( array $network_info ): void {
		$is_subdomain = $network_info['is_subdomain'] ?? false;
		$domain       = $network_info['domain'] ?? '';
		$path         = $network_info['path'] ?? '/';

		// Remove www prefix for subdomain display.
		$display_domain = preg_replace( '/^www\./', '', $domain );
		?>
		<div id="swish-duplicate-modal" class="swish-modal-overlay" style="display: none;">
			<div class="swish-modal-content" style="max-width: 500px;">
				<div class="swish-modal-header">
					<h3 class="swish-modal-title">
						<span class="material-symbols-outlined">content_copy</span>
						<?php esc_html_e( 'Duplicate Site', 'swish-migrate-and-backup' ); ?>
					</h3>
					<button type="button" class="swish-modal-close">
						<span class="material-symbols-outlined">close</span>
					</button>
				</div>
				<div class="swish-modal-body">
					<input type="hidden" id="duplicate-source-site-id" value="" />

					<!-- Source Site Info -->
					<div class="swish-form-group">
						<label class="swish-form-label"><?php esc_html_e( 'Source Site', 'swish-migrate-and-backup' ); ?></label>
						<div style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
							<div class="swish-flex swish-items-center swish-gap-2">
								<span class="material-symbols-outlined" style="font-size: 18px; color: var(--swish-primary-600);">language</span>
								<div>
									<strong id="duplicate-source-name"></strong>
									<div class="swish-text-secondary" style="font-size: var(--swish-text-sm);" id="duplicate-source-url"></div>
								</div>
							</div>
						</div>
					</div>

					<!-- New Site Title -->
					<div class="swish-form-group">
						<label class="swish-form-label" for="duplicate-new-title">
							<?php esc_html_e( 'New Site Title', 'swish-migrate-and-backup' ); ?>
							<span style="color: var(--swish-danger-600);">*</span>
						</label>
						<input
							type="text"
							id="duplicate-new-title"
							class="swish-form-input"
							placeholder="<?php esc_attr_e( 'Enter site title...', 'swish-migrate-and-backup' ); ?>"
							required
						/>
					</div>

					<!-- New Site URL -->
					<div class="swish-form-group">
						<label class="swish-form-label" for="duplicate-new-slug">
							<?php esc_html_e( 'New Site URL', 'swish-migrate-and-backup' ); ?>
							<span style="color: var(--swish-danger-600);">*</span>
						</label>
						<?php if ( $is_subdomain ) : ?>
							<div class="swish-input-group" style="display: flex; align-items: center;">
								<input
									type="text"
									id="duplicate-new-slug"
									class="swish-form-input"
									placeholder="<?php esc_attr_e( 'newsite', 'swish-migrate-and-backup' ); ?>"
									style="border-top-right-radius: 0; border-bottom-right-radius: 0; flex: 1;"
									required
								/>
								<span style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border: 1px solid var(--swish-border-default); border-left: 0; border-radius: 0 var(--swish-radius-lg) var(--swish-radius-lg) 0; color: var(--swish-text-secondary);">
									.<?php echo esc_html( $display_domain ); ?>
								</span>
							</div>
						<?php else : ?>
							<div class="swish-input-group" style="display: flex; align-items: center;">
								<span style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border: 1px solid var(--swish-border-default); border-right: 0; border-radius: var(--swish-radius-lg) 0 0 var(--swish-radius-lg); color: var(--swish-text-secondary);">
									<?php echo esc_html( $domain . rtrim( $path, '/' ) ); ?>/
								</span>
								<input
									type="text"
									id="duplicate-new-slug"
									class="swish-form-input"
									placeholder="<?php esc_attr_e( 'newsite', 'swish-migrate-and-backup' ); ?>"
									style="border-top-left-radius: 0; border-bottom-left-radius: 0; flex: 1;"
									required
								/>
							</div>
						<?php endif; ?>
						<p class="swish-help-text" style="margin-top: var(--swish-space-2);">
							<span class="material-symbols-outlined" style="font-size: 14px; vertical-align: middle;">info</span>
							<?php esc_html_e( 'Use only lowercase letters, numbers, and hyphens.', 'swish-migrate-and-backup' ); ?>
						</p>
						<p id="duplicate-url-preview" class="swish-text-primary" style="margin-top: var(--swish-space-2); font-weight: var(--swish-font-medium);"></p>
						<!-- URL availability status -->
						<div id="duplicate-url-status" style="margin-top: var(--swish-space-2); display: none;">
							<span id="duplicate-url-status-icon" class="material-symbols-outlined" style="font-size: 16px; vertical-align: middle;"></span>
							<span id="duplicate-url-status-text" style="font-size: var(--swish-text-sm);"></span>
						</div>
					</div>

					<!-- Duplication Options -->
					<div class="swish-form-group">
						<label class="swish-form-label"><?php esc_html_e( 'Duplication Options', 'swish-migrate-and-backup' ); ?></label>
						<div style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
							<label class="swish-checkbox-label" style="display: flex; align-items: center; gap: var(--swish-space-2); cursor: pointer;">
								<input type="checkbox" id="duplicate-copy-uploads" checked style="width: 18px; height: 18px;" />
								<div>
									<span style="font-weight: var(--swish-font-medium);"><?php esc_html_e( 'Copy media uploads', 'swish-migrate-and-backup' ); ?></span>
									<p class="swish-help-text" style="margin: var(--swish-space-1) 0 0 0; font-size: var(--swish-text-xs);">
										<?php esc_html_e( 'Include all images, videos, and other uploaded files from the source site.', 'swish-migrate-and-backup' ); ?>
									</p>
								</div>
							</label>
						</div>
					</div>

					<!-- Error message container -->
					<div id="duplicate-error-message" class="swish-alert swish-alert-danger" style="display: none;">
						<span class="material-symbols-outlined">error</span>
						<span id="duplicate-error-text"></span>
					</div>
				</div>
				<div class="swish-modal-footer">
					<button type="button" class="swish-btn swish-btn-secondary swish-modal-close">
						<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
					</button>
					<button type="button" class="swish-btn swish-btn-primary" id="swish-start-duplicate">
						<span class="material-symbols-outlined">content_copy</span>
						<?php esc_html_e( 'Start Duplication', 'swish-migrate-and-backup' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the delete confirmation modal.
	 *
	 * @return void
	 */
	private function render_delete_modal(): void {
		?>
		<div id="swish-delete-site-modal" class="swish-modal-overlay" style="display: none;">
			<div class="swish-modal-content" style="max-width: 480px;">
				<div class="swish-modal-header" style="background: var(--swish-danger-50); border-bottom-color: var(--swish-danger-100);">
					<h3 class="swish-modal-title" style="color: var(--swish-danger-700);">
						<span class="material-symbols-outlined">warning</span>
						<?php esc_html_e( 'Delete Site', 'swish-migrate-and-backup' ); ?>
					</h3>
					<button type="button" class="swish-modal-close">
						<span class="material-symbols-outlined">close</span>
					</button>
				</div>
				<div class="swish-modal-body">
					<input type="hidden" id="delete-site-id" value="" />

					<!-- Warning Message -->
					<div class="swish-alert swish-alert-danger" style="margin-bottom: var(--swish-space-4);">
						<span class="material-symbols-outlined">error</span>
						<div>
							<strong><?php esc_html_e( 'This action cannot be undone!', 'swish-migrate-and-backup' ); ?></strong>
							<p style="margin: var(--swish-space-2) 0 0 0;">
								<?php esc_html_e( 'Deleting this site will permanently remove all its content, media files, and database tables.', 'swish-migrate-and-backup' ); ?>
							</p>
						</div>
					</div>

					<!-- Site Info -->
					<div class="swish-form-group">
						<label class="swish-form-label"><?php esc_html_e( 'Site to Delete', 'swish-migrate-and-backup' ); ?></label>
						<div style="padding: var(--swish-space-3); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg); border: 1px solid var(--swish-danger-200);">
							<div class="swish-flex swish-items-center swish-gap-2">
								<span class="material-symbols-outlined" style="font-size: 18px; color: var(--swish-danger-600);">language</span>
								<div>
									<strong id="delete-site-name" style="color: var(--swish-danger-700);"></strong>
									<div class="swish-text-secondary" style="font-size: var(--swish-text-sm);" id="delete-site-url"></div>
								</div>
							</div>
						</div>
					</div>

					<!-- Confirmation Input -->
					<div class="swish-form-group">
						<label class="swish-form-label" for="delete-confirmation-input">
							<?php
							printf(
								/* translators: %s: the word DELETE in caps */
								esc_html__( 'Type %s to confirm:', 'swish-migrate-and-backup' ),
								'<strong style="color: var(--swish-danger-600);">DELETE</strong>'
							);
							?>
						</label>
						<input
							type="text"
							id="delete-confirmation-input"
							class="swish-form-input"
							placeholder="<?php esc_attr_e( 'Type DELETE here...', 'swish-migrate-and-backup' ); ?>"
							autocomplete="off"
							spellcheck="false"
							style="border-color: var(--swish-danger-300);"
						/>
						<p class="swish-help-text" style="margin-top: var(--swish-space-2); color: var(--swish-text-tertiary);">
							<?php esc_html_e( 'This confirmation is required to prevent accidental deletions.', 'swish-migrate-and-backup' ); ?>
						</p>
					</div>

					<!-- Error message container -->
					<div id="delete-error-message" class="swish-alert swish-alert-danger" style="display: none;">
						<span class="material-symbols-outlined">error</span>
						<span id="delete-error-text"></span>
					</div>
				</div>
				<div class="swish-modal-footer">
					<button type="button" class="swish-btn swish-btn-secondary swish-modal-close">
						<?php esc_html_e( 'Cancel', 'swish-migrate-and-backup' ); ?>
					</button>
					<button type="button" class="swish-btn swish-btn-danger" id="swish-confirm-delete" disabled>
						<span class="material-symbols-outlined">delete_forever</span>
						<?php esc_html_e( 'Delete Site Permanently', 'swish-migrate-and-backup' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the progress modal.
	 *
	 * @return void
	 */
	private function render_progress_modal(): void {
		?>
		<div id="swish-duplicate-progress-modal" class="swish-modal-overlay" style="display: none;">
			<div class="swish-modal-content">
				<div class="swish-modal-header">
					<h3 class="swish-modal-title">
						<span class="material-symbols-outlined">content_copy</span>
						<?php esc_html_e( 'Duplicating Site', 'swish-migrate-and-backup' ); ?>
					</h3>
				</div>
				<div class="swish-modal-body">
					<div class="swish-progress-container">
						<div class="swish-progress-bar">
							<div class="swish-progress-fill" id="swish-duplicate-progress-fill" style="width: 0%;"></div>
						</div>
						<div style="text-align: center; margin-top: var(--swish-space-3);">
							<span id="swish-duplicate-progress-percent" style="font-size: var(--swish-text-2xl); font-weight: var(--swish-font-bold); color: var(--swish-text-primary);">0%</span>
						</div>
					</div>

					<div style="text-align: center; margin: var(--swish-space-4) 0;">
						<p id="swish-duplicate-progress-message" style="margin: 0; color: var(--swish-text-secondary);">
							<?php esc_html_e( 'Initializing...', 'swish-migrate-and-backup' ); ?>
						</p>
					</div>

					<div id="swish-duplicate-progress-details" style="background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg); padding: var(--swish-space-4);">
						<h4 style="margin: 0 0 var(--swish-space-3) 0; font-size: var(--swish-text-sm); font-weight: var(--swish-font-semibold); text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e( 'Duplication Progress', 'swish-migrate-and-backup' ); ?>
						</h4>
						<div class="swish-import-log" id="swish-duplicate-log"></div>
					</div>
				</div>

				<!-- Success state -->
				<div class="swish-modal-state" id="swish-duplicate-success" style="display: none;">
					<div class="swish-modal-state-icon success">
						<span class="material-symbols-outlined">check_circle</span>
					</div>
					<h3><?php esc_html_e( 'Site Duplicated!', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-duplicate-success-message"></p>
					<div class="swish-modal-state-actions">
						<a href="#" class="swish-btn swish-btn-primary" id="swish-visit-new-site" target="_blank">
							<span class="material-symbols-outlined">open_in_new</span>
							<?php esc_html_e( 'Visit New Site', 'swish-migrate-and-backup' ); ?>
						</a>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-duplicate-close">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Error state -->
				<div class="swish-modal-state" id="swish-duplicate-error" style="display: none;">
					<div class="swish-modal-state-icon error">
						<span class="material-symbols-outlined">error</span>
					</div>
					<h3><?php esc_html_e( 'Duplication Failed', 'swish-migrate-and-backup' ); ?></h3>
					<p id="swish-duplicate-error-message"></p>
					<div class="swish-modal-state-actions">
						<button type="button" class="swish-btn swish-btn-primary" id="swish-duplicate-retry">
							<?php esc_html_e( 'Retry', 'swish-migrate-and-backup' ); ?>
						</button>
						<button type="button" class="swish-btn swish-btn-secondary" id="swish-duplicate-close-error">
							<?php esc_html_e( 'Close', 'swish-migrate-and-backup' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render JavaScript for the page.
	 *
	 * @param array $network_info Network information.
	 * @return void
	 */
	private function render_scripts( array $network_info ): void {
		$is_subdomain   = $network_info['is_subdomain'] ?? false;
		$domain         = $network_info['domain'] ?? '';
		$path           = $network_info['path'] ?? '/';
		$display_domain = preg_replace( '/^www\./', '', $domain );
		?>
		<script>
		( function( $ ) {
			'use strict';

			const SwishDuplicate = {
				jobId: null,
				pollInterval: null,
				sourceData: {},
				slugCheckTimeout: null,
				slugAvailable: false,
				networkInfo: {
					isSubdomain: <?php echo $is_subdomain ? 'true' : 'false'; ?>,
					domain: '<?php echo esc_js( $display_domain ); ?>',
					path: '<?php echo esc_js( rtrim( $path, '/' ) ); ?>'
				},

				stepLabels: {
					init: {
						title: '<?php echo esc_js( __( 'Initializing', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Preparing duplication', 'swish-migrate-and-backup' ) ); ?>'
					},
					database: {
						title: '<?php echo esc_js( __( 'Copying Database', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Duplicating site tables', 'swish-migrate-and-backup' ) ); ?>'
					},
					uploads: {
						title: '<?php echo esc_js( __( 'Copying Uploads', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Duplicating media files', 'swish-migrate-and-backup' ) ); ?>'
					},
					replace: {
						title: '<?php echo esc_js( __( 'URL Replacement', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Updating URLs in database', 'swish-migrate-and-backup' ) ); ?>'
					},
					finalize: {
						title: '<?php echo esc_js( __( 'Finalizing', 'swish-migrate-and-backup' ) ); ?>',
						detail: '<?php echo esc_js( __( 'Completing duplication', 'swish-migrate-and-backup' ) ); ?>'
					}
				},

				init: function() {
					this.bindEvents();
				},

				bindEvents: function() {
					const self = this;

					// Search filter.
					$( '#swish-duplicate-search' ).on( 'input', function() {
						const query = $( this ).val().toLowerCase();
						$( '#swish-duplicate-sites-table tbody tr' ).each( function() {
							const name = $( this ).data( 'site-name' ) || '';
							const url = $( this ).data( 'site-url' ) || '';
							const matches = name.indexOf( query ) !== -1 || url.indexOf( query ) !== -1;
							$( this ).toggle( matches );
						} );
					} );

					// Duplicate button click.
					$( document ).on( 'click', '.swish-duplicate-btn', function() {
						const $btn = $( this );
						self.sourceData = {
							siteId: $btn.data( 'site-id' ),
							siteName: $btn.data( 'site-name' ),
							siteUrl: $btn.data( 'site-url' )
						};
						self.openDuplicateModal();
					} );

					// Close modal.
					$( '#swish-duplicate-modal .swish-modal-close' ).on( 'click', function() {
						self.closeDuplicateModal();
					} );

					// Backdrop click.
					$( '#swish-duplicate-modal' ).on( 'click', function( e ) {
						if ( e.target === this ) {
							self.closeDuplicateModal();
						}
					} );

					// Title input - auto-generate slug.
					$( '#duplicate-new-title' ).on( 'input', function() {
						const title = $( this ).val();
						const slug = self.titleToSlug( title );
						$( '#duplicate-new-slug' ).val( slug );
						self.updateUrlPreview( slug );
						self.checkSlugAvailability( slug );
					} );

					// Slug input - sanitize and preview.
					$( '#duplicate-new-slug' ).on( 'input', function() {
						let val = $( this ).val().toLowerCase().replace( /[^a-z0-9-]/g, '' );
						$( this ).val( val );
						self.updateUrlPreview( val );
						self.checkSlugAvailability( val );
					} );

					// Start duplication.
					$( '#swish-start-duplicate' ).on( 'click', function() {
						self.startDuplication();
					} );

					// Progress modal close buttons.
					$( '#swish-duplicate-close, #swish-duplicate-close-error' ).on( 'click', function() {
						self.closeProgressModal();
						location.reload();
					} );

					// Retry button.
					$( '#swish-duplicate-retry' ).on( 'click', function() {
						self.closeProgressModal();
						self.openDuplicateModal();
					} );

					// Delete button click.
					$( document ).on( 'click', '.swish-delete-site-btn', function() {
						const $btn = $( this );
						self.deleteData = {
							siteId: $btn.data( 'site-id' ),
							siteName: $btn.data( 'site-name' ),
							siteUrl: $btn.data( 'site-url' )
						};
						self.openDeleteModal();
					} );

					// Delete modal close.
					$( '#swish-delete-site-modal .swish-modal-close' ).on( 'click', function() {
						self.closeDeleteModal();
					} );

					// Delete modal backdrop click.
					$( '#swish-delete-site-modal' ).on( 'click', function( e ) {
						if ( e.target === this ) {
							self.closeDeleteModal();
						}
					} );

					// Delete confirmation input.
					$( '#delete-confirmation-input' ).on( 'input', function() {
						const val = $( this ).val().trim().toUpperCase();
						const isValid = val === 'DELETE';
						$( '#swish-confirm-delete' ).prop( 'disabled', ! isValid );

						// Visual feedback.
						if ( val.length > 0 ) {
							$( this ).css( 'border-color', isValid ? 'var(--swish-success-500)' : 'var(--swish-danger-300)' );
						} else {
							$( this ).css( 'border-color', 'var(--swish-danger-300)' );
						}
					} );

					// Confirm delete button.
					$( '#swish-confirm-delete' ).on( 'click', function() {
						self.deleteSite();
					} );
				},

				updateUrlPreview: function( slug ) {
					if ( ! slug ) {
						$( '#duplicate-url-preview' ).text( '' );
						return;
					}

					let url;
					if ( this.networkInfo.isSubdomain ) {
						url = 'https://' + slug + '.' + this.networkInfo.domain;
					} else {
						url = 'https://' + this.networkInfo.domain + this.networkInfo.path + '/' + slug + '/';
					}
					$( '#duplicate-url-preview' ).text( url );
				},

				titleToSlug: function( title ) {
					return title
						.toLowerCase()
						.trim()
						.replace( /\s+/g, '-' )           // Replace spaces with hyphens.
						.replace( /[^a-z0-9-]/g, '' )     // Remove invalid characters.
						.replace( /-+/g, '-' )            // Replace multiple hyphens with single.
						.replace( /^-|-$/g, '' );         // Remove leading/trailing hyphens.
				},

				checkSlugAvailability: function( slug ) {
					const self = this;

					// Clear previous timeout.
					if ( this.slugCheckTimeout ) {
						clearTimeout( this.slugCheckTimeout );
					}

					// Reset state.
					this.slugAvailable = false;
					$( '#swish-start-duplicate' ).prop( 'disabled', true );

					// Don't check empty or too short slugs.
					if ( ! slug || slug.length < 2 ) {
						this.hideUrlStatus();
						return;
					}

					// Show checking state.
					this.showUrlStatus( 'checking', '<?php echo esc_js( __( 'Checking availability...', 'swish-migrate-and-backup' ) ); ?>' );

					// Debounce the check (500ms delay).
					this.slugCheckTimeout = setTimeout( function() {
						$.ajax( {
							url: swishBackupPro.ajaxUrl,
							type: 'POST',
							data: {
								action: 'swish_backup_check_slug_available',
								nonce: swishBackupPro.nonce,
								slug: slug
							},
							success: function( response ) {
								if ( response.success ) {
									self.slugAvailable = true;
									self.showUrlStatus( 'available', response.data.message );
									$( '#swish-start-duplicate' ).prop( 'disabled', false );
								} else {
									self.slugAvailable = false;
									self.showUrlStatus( 'taken', response.data.message );
									$( '#swish-start-duplicate' ).prop( 'disabled', true );
								}
							},
							error: function() {
								self.slugAvailable = false;
								self.showUrlStatus( 'error', '<?php echo esc_js( __( 'Could not verify availability.', 'swish-migrate-and-backup' ) ); ?>' );
							}
						} );
					}, 500 );
				},

				showUrlStatus: function( status, message ) {
					const $container = $( '#duplicate-url-status' );
					const $icon = $( '#duplicate-url-status-icon' );
					const $text = $( '#duplicate-url-status-text' );

					$container.show();
					$text.text( message );

					// Reset classes.
					$icon.removeClass();
					$icon.addClass( 'material-symbols-outlined' );
					$icon.css( 'font-size', '16px' );
					$icon.css( 'vertical-align', 'middle' );

					switch ( status ) {
						case 'checking':
							$icon.addClass( 'swish-spin' ).text( 'progress_activity' );
							$icon.css( 'color', 'var(--swish-text-secondary)' );
							$text.css( 'color', 'var(--swish-text-secondary)' );
							break;
						case 'available':
							$icon.text( 'check_circle' );
							$icon.css( 'color', 'var(--swish-success-600)' );
							$text.css( 'color', 'var(--swish-success-600)' );
							break;
						case 'taken':
							$icon.text( 'error' );
							$icon.css( 'color', 'var(--swish-danger-600)' );
							$text.css( 'color', 'var(--swish-danger-600)' );
							break;
						case 'error':
							$icon.text( 'warning' );
							$icon.css( 'color', 'var(--swish-warning-600)' );
							$text.css( 'color', 'var(--swish-warning-600)' );
							break;
					}
				},

				hideUrlStatus: function() {
					$( '#duplicate-url-status' ).hide();
				},

				openDuplicateModal: function() {
					const defaultTitle = this.sourceData.siteName + ' (Copy)';
					const defaultSlug = this.titleToSlug( defaultTitle );

					$( '#duplicate-source-site-id' ).val( this.sourceData.siteId );
					$( '#duplicate-source-name' ).text( this.sourceData.siteName );
					$( '#duplicate-source-url' ).text( this.sourceData.siteUrl );
					$( '#duplicate-new-title' ).val( defaultTitle );
					$( '#duplicate-new-slug' ).val( defaultSlug );
					this.updateUrlPreview( defaultSlug );
					$( '#duplicate-error-message' ).hide();
					$( '#swish-start-duplicate' ).prop( 'disabled', true ); // Disabled until slug is verified.
					this.hideUrlStatus();
					$( '#swish-duplicate-modal' ).show().addClass( 'active' );
					$( '#duplicate-new-title' ).focus().select();

					// Check slug availability for the default slug.
					this.checkSlugAvailability( defaultSlug );
				},

				closeDuplicateModal: function() {
					$( '#swish-duplicate-modal' ).hide().removeClass( 'active' );
				},

				showError: function( message ) {
					$( '#duplicate-error-text' ).text( message );
					$( '#duplicate-error-message' ).show();
				},

				startDuplication: function() {
					const self = this;
					const siteId = $( '#duplicate-source-site-id' ).val();
					const newTitle = $( '#duplicate-new-title' ).val().trim();
					const newSlug = $( '#duplicate-new-slug' ).val().trim();
					const copyUploads = $( '#duplicate-copy-uploads' ).is( ':checked' );

					// Validate.
					if ( ! newTitle ) {
						this.showError( '<?php echo esc_js( __( 'Please enter a site title.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					if ( ! newSlug ) {
						this.showError( '<?php echo esc_js( __( 'Please enter a URL slug.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					if ( newSlug.length < 2 ) {
						this.showError( '<?php echo esc_js( __( 'URL slug must be at least 2 characters.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					if ( ! this.slugAvailable ) {
						this.showError( '<?php echo esc_js( __( 'Please choose an available URL.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					// Close form modal, show progress modal.
					this.closeDuplicateModal();
					this.openProgressModal();

					// Add init log entry.
					this.addLogEntry( 'init', 'in-progress' );

					// Start the AJAX request.
					$.ajax( {
						url: swishBackupPro.ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_duplicate_site_async',
							nonce: swishBackupPro.nonce,
							source_site_id: siteId,
							new_slug: newSlug,
							new_title: newTitle,
							copy_uploads: copyUploads ? 1 : 0
						},
						success: function( response ) {
							if ( response.success && response.data.job_id ) {
								self.jobId = response.data.job_id;
								self.addLogEntry( 'init', 'completed' );
								self.startPolling();
							} else {
								self.addLogEntry( 'init', 'failed' );
								self.showProgressError( response.data.message || '<?php echo esc_js( __( 'Failed to start duplication.', 'swish-migrate-and-backup' ) ); ?>' );
							}
						},
						error: function() {
							self.addLogEntry( 'init', 'failed' );
							self.showProgressError( '<?php echo esc_js( __( 'Network error. Please try again.', 'swish-migrate-and-backup' ) ); ?>' );
						}
					} );
				},

				openProgressModal: function() {
					$( '#swish-duplicate-progress-modal' ).show().addClass( 'active' );
					$( '#swish-duplicate-progress-modal .swish-modal-content' ).removeClass( 'show-success show-error' );
					$( '#swish-duplicate-success, #swish-duplicate-error' ).hide();
					$( '#swish-duplicate-progress-modal .swish-modal-body' ).show();
					this.resetProgress();
				},

				closeProgressModal: function() {
					$( '#swish-duplicate-progress-modal' ).hide().removeClass( 'active' );
					this.stopPolling();
				},

				resetProgress: function() {
					$( '#swish-duplicate-progress-fill' ).css( 'width', '0%' );
					$( '#swish-duplicate-progress-percent' ).text( '0%' );
					$( '#swish-duplicate-progress-message' ).text( '<?php echo esc_js( __( 'Initializing...', 'swish-migrate-and-backup' ) ); ?>' );
					$( '#swish-duplicate-log' ).empty();
				},

				updateProgress: function( percent ) {
					$( '#swish-duplicate-progress-fill' ).css( 'width', percent + '%' );
					$( '#swish-duplicate-progress-percent' ).text( Math.round( percent ) + '%' );
				},

				updateMessage: function( message ) {
					$( '#swish-duplicate-progress-message' ).text( message );
				},

				addLogEntry: function( step, status, customDetail ) {
					const $log = $( '#swish-duplicate-log' );
					const stepInfo = this.stepLabels[ step ] || { title: step, detail: '' };
					const detail = customDetail || stepInfo.detail;

					let $entry = $log.find( '[data-step="' + step + '"]' );

					if ( $entry.length === 0 ) {
						const html = '<div class="swish-log-entry ' + status + '" data-step="' + step + '">' +
							'<span class="swish-log-icon"></span>' +
							'<div class="swish-log-content">' +
								'<div class="swish-log-title">' + stepInfo.title + '</div>' +
								( detail ? '<div class="swish-log-detail">' + detail + '</div>' : '' ) +
							'</div>' +
						'</div>';
						$log.append( html );
						$log.scrollTop( $log[0].scrollHeight );
					} else {
						$entry.removeClass( 'pending in-progress completed failed' ).addClass( status );
						if ( customDetail ) {
							let $detail = $entry.find( '.swish-log-detail' );
							if ( $detail.length === 0 ) {
								$entry.find( '.swish-log-content' ).append( '<div class="swish-log-detail">' + customDetail + '</div>' );
							} else {
								$detail.text( customDetail );
							}
						}
					}
				},

				startPolling: function() {
					const self = this;
					this.pollInterval = setInterval( function() {
						self.checkProgress();
					}, 1000 );
				},

				stopPolling: function() {
					if ( this.pollInterval ) {
						clearInterval( this.pollInterval );
						this.pollInterval = null;
					}
				},

				checkProgress: function() {
					const self = this;

					if ( ! this.jobId ) {
						return;
					}

					$.ajax( {
						url: swishBackupPro.ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_check_duplicate_progress',
							nonce: swishBackupPro.nonce,
							job_id: this.jobId
						},
						success: function( response ) {
							if ( response.success ) {
								const data = response.data;

								self.updateProgress( data.progress || 0 );
								self.updateMessage( data.message || '' );

								// Update log entries.
								if ( data.current_step ) {
									const steps = [ 'init', 'database', 'uploads', 'replace', 'finalize' ];
									const currentIndex = steps.indexOf( data.current_step );

									steps.forEach( function( step, index ) {
										if ( index < currentIndex ) {
											self.addLogEntry( step, 'completed' );
										} else if ( index === currentIndex ) {
											if ( data.current_step === 'completed' ) {
												self.addLogEntry( step, 'completed' );
											} else {
												self.addLogEntry( step, 'in-progress', data.message || null );
											}
										}
									} );
								}

								// Check for completion.
								if ( data.current_step === 'completed' ) {
									self.updateProgress( 100 );
									// Mark all as completed.
									$( '#swish-duplicate-log .swish-log-entry' ).each( function() {
										$( this ).removeClass( 'pending in-progress' ).addClass( 'completed' );
									} );
									setTimeout( function() {
										self.showProgressSuccess( data.message || '<?php echo esc_js( __( 'Site duplicated successfully!', 'swish-migrate-and-backup' ) ); ?>', data.new_site_url );
									}, 500 );
								} else if ( data.current_step === 'failed' ) {
									self.showProgressError( data.message || '<?php echo esc_js( __( 'Duplication failed.', 'swish-migrate-and-backup' ) ); ?>' );
								}
							}
						}
					} );
				},

				showProgressSuccess: function( message, newSiteUrl ) {
					this.stopPolling();
					$( '#swish-duplicate-progress-modal .swish-modal-content' ).addClass( 'show-success' );
					$( '#swish-duplicate-success' ).show();
					$( '#swish-duplicate-success-message' ).text( message );

					if ( newSiteUrl ) {
						$( '#swish-visit-new-site' ).attr( 'href', newSiteUrl ).show();
					} else {
						$( '#swish-visit-new-site' ).hide();
					}
				},

				showProgressError: function( message ) {
					this.stopPolling();
					$( '#swish-duplicate-progress-modal .swish-modal-content' ).addClass( 'show-error' );
					$( '#swish-duplicate-error' ).show();
					$( '#swish-duplicate-error-message' ).text( message );
				},

				// Delete site functions.
				deleteData: {},

				openDeleteModal: function() {
					$( '#delete-site-id' ).val( this.deleteData.siteId );
					$( '#delete-site-name' ).text( this.deleteData.siteName );
					$( '#delete-site-url' ).text( this.deleteData.siteUrl );
					$( '#delete-confirmation-input' ).val( '' ).css( 'border-color', 'var(--swish-danger-300)' );
					$( '#swish-confirm-delete' ).prop( 'disabled', true );
					$( '#delete-error-message' ).hide();
					$( '#swish-delete-site-modal' ).show().addClass( 'active' );
					$( '#delete-confirmation-input' ).focus();
				},

				closeDeleteModal: function() {
					$( '#swish-delete-site-modal' ).hide().removeClass( 'active' );
				},

				showDeleteError: function( message ) {
					$( '#delete-error-text' ).text( message );
					$( '#delete-error-message' ).show();
				},

				deleteSite: function() {
					const self = this;
					const siteId = $( '#delete-site-id' ).val();
					const confirmation = $( '#delete-confirmation-input' ).val().trim().toUpperCase();

					if ( confirmation !== 'DELETE' ) {
						this.showDeleteError( '<?php echo esc_js( __( 'Please type DELETE to confirm.', 'swish-migrate-and-backup' ) ); ?>' );
						return;
					}

					// Disable button and show loading state.
					const $btn = $( '#swish-confirm-delete' );
					const originalText = $btn.html();
					$btn.prop( 'disabled', true ).html( '<span class="material-symbols-outlined swish-spin">progress_activity</span> <?php esc_html_e( 'Deleting...', 'swish-migrate-and-backup' ); ?>' );

					$.ajax( {
						url: swishBackupPro.ajaxUrl,
						type: 'POST',
						data: {
							action: 'swish_backup_delete_site',
							nonce: swishBackupPro.nonce,
							site_id: siteId
						},
						success: function( response ) {
							if ( response.success ) {
								// Close modal and reload page.
								self.closeDeleteModal();
								location.reload();
							} else {
								$btn.prop( 'disabled', false ).html( originalText );
								self.showDeleteError( response.data.message || '<?php echo esc_js( __( 'Failed to delete site.', 'swish-migrate-and-backup' ) ); ?>' );
							}
						},
						error: function() {
							$btn.prop( 'disabled', false ).html( originalText );
							self.showDeleteError( '<?php echo esc_js( __( 'Network error. Please try again.', 'swish-migrate-and-backup' ) ); ?>' );
						}
					} );
				}
			};

			$( document ).ready( function() {
				SwishDuplicate.init();
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
