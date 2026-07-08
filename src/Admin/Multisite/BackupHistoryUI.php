<?php
/**
 * Backup History UI Component.
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

/**
 * Renders backup history list.
 */
final class BackupHistoryUI {

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
	 * Render backup history.
	 *
	 * @param array $options Render options.
	 * @return void
	 */
	public function render( array $options = array() ): void {
		$limit   = $options['limit'] ?? 20;
		$backups = $this->multisite_manager->get_multisite_backups( $limit );

		?>
		<div class="swish-card swish-mt-4">
			<div class="swish-card-header">
				<h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
					<span class="material-symbols-outlined" style="vertical-align: middle; margin-right: var(--swish-space-2);">history</span>
					<?php esc_html_e( 'Backup History', 'swish-migrate-and-backup' ); ?>
				</h4>
			</div>

			<?php if ( empty( $backups ) ) : ?>
				<div class="swish-card-body">
					<?php
					AdminLayout::render_empty_state(
						__( 'No Backups Yet', 'swish-migrate-and-backup' ),
						__( 'Create your first multisite backup using the form above.', 'swish-migrate-and-backup' ),
						'backup'
					);
					?>
				</div>
			<?php else : ?>
				<div class="swish-card-body" style="padding: 0;">
					<table class="swish-table swish-backups-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date & Time', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Archive Mode', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Sites', 'swish-migrate-and-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'swish-migrate-and-backup' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Actions', 'swish-migrate-and-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $backup ) : ?>
								<?php
								$total_size = 0;
								foreach ( $backup['files'] as $file ) {
									$total_size += $file['size'];
								}
								$created_time = strtotime( $backup['created_at'] );
								?>
								<tr data-job-id="<?php echo esc_attr( $backup['job_id'] ); ?>" data-status="success">
									<td>
										<div class="swish-flex" style="flex-direction: column;">
											<span class="swish-table-cell-primary">
												<?php echo esc_html( wp_date( get_option( 'date_format' ), $created_time ) ); ?>
											</span>
											<span class="swish-table-cell-secondary">
												<?php echo esc_html( wp_date( get_option( 'time_format' ), $created_time ) ); ?>
											</span>
										</div>
									</td>
									<td>
										<?php if ( $backup['archive_mode'] === 'single' ) : ?>
											<span class="swish-badge swish-badge-info">
												<?php esc_html_e( 'Single Archive', 'swish-migrate-and-backup' ); ?>
											</span>
										<?php else : ?>
											<span class="swish-badge swish-badge-success">
												<?php esc_html_e( 'Separate', 'swish-migrate-and-backup' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<div class="swish-flex swish-items-center swish-gap-2">
											<span class="material-symbols-outlined" style="font-size: 18px; color: var(--swish-primary-600);">language</span>
											<span class="swish-table-cell-primary">
												<?php
												printf(
													/* translators: %d: number of sites */
													esc_html( _n( '%d site', '%d sites', $backup['total_sites'], 'swish-migrate-and-backup' ) ),
													absint( $backup['total_sites'] )
												);
												?>
											</span>
										</div>
									</td>
									<td>
										<span class="swish-table-cell-mono"><?php echo esc_html( size_format( $total_size ) ); ?></span>
									</td>
									<td>
										<div class="swish-table-actions">
											<?php if ( count( $backup['files'] ) === 1 ) : ?>
												<?php $file = $backup['files'][0]; ?>
												<a href="<?php echo esc_url( $this->multisite_manager->get_backup_download_url( $file['filename'] ) ); ?>"
												   class="swish-btn-icon"
												   title="<?php esc_attr_e( 'Download', 'swish-migrate-and-backup' ); ?>"
												   download>
													<span class="material-symbols-outlined">download</span>
												</a>
											<?php else : ?>
												<button type="button"
														class="swish-btn-icon swish-toggle-files"
														title="<?php esc_attr_e( 'View Files', 'swish-migrate-and-backup' ); ?>"
														data-job-id="<?php echo esc_attr( $backup['job_id'] ); ?>">
													<span class="material-symbols-outlined">folder_open</span>
												</button>
											<?php endif; ?>
											<button type="button"
													class="swish-btn-icon swish-restore-backup"
													title="<?php esc_attr_e( 'Restore', 'swish-migrate-and-backup' ); ?>"
													data-job-id="<?php echo esc_attr( $backup['job_id'] ); ?>">
												<span class="material-symbols-outlined">settings_backup_restore</span>
											</button>
											<button type="button"
													class="swish-btn-icon danger swish-delete-backup"
													title="<?php esc_attr_e( 'Delete', 'swish-migrate-and-backup' ); ?>"
													data-job-id="<?php echo esc_attr( $backup['job_id'] ); ?>">
												<span class="material-symbols-outlined">delete</span>
											</button>
										</div>

										<?php if ( count( $backup['files'] ) > 1 ) : ?>
											<div class="swish-backup-files"
												 id="files-<?php echo esc_attr( $backup['job_id'] ); ?>"
												 style="display: none; margin-top: var(--swish-space-4); padding: var(--swish-space-4); background: var(--swish-surface-container-low); border-radius: var(--swish-radius-lg);">
												<div style="display: flex; flex-direction: column; gap: var(--swish-space-2);">
													<?php foreach ( $backup['files'] as $file ) : ?>
														<div class="swish-flex swish-items-center swish-justify-between" style="padding: var(--swish-space-2) var(--swish-space-3); background: var(--swish-bg-card); border-radius: var(--swish-radius-md);">
															<div class="swish-flex swish-items-center swish-gap-2">
																<span class="material-symbols-outlined" style="font-size: 18px; color: var(--swish-primary-500);">archive</span>
																<span style="font-size: var(--swish-text-sm); color: var(--swish-text-primary);">
																	<?php echo esc_html( $file['filename'] ); ?>
																</span>
																<?php if ( $file['site_id'] ) : ?>
																	<span class="swish-badge swish-badge-neutral" style="font-size: 9px;">
																		<?php printf( esc_html__( 'Site %d', 'swish-migrate-and-backup' ), absint( $file['site_id'] ) ); ?>
																	</span>
																<?php endif; ?>
															</div>
															<div class="swish-flex swish-items-center swish-gap-2">
																<span style="font-size: var(--swish-text-sm); color: var(--swish-text-tertiary); font-family: var(--swish-font-mono);">
																	<?php echo esc_html( size_format( $file['size'] ) ); ?>
																</span>
																<a href="<?php echo esc_url( $this->multisite_manager->get_backup_download_url( $file['filename'] ) ); ?>"
																   class="swish-btn swish-btn-secondary swish-btn-sm"
																   download>
																	<span class="material-symbols-outlined" style="font-size: 16px;">download</span>
																</a>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
											</div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="swish-card-footer">
					<span class="swish-pagination-text">
						<?php
						printf(
							/* translators: %d: number of backups shown */
							esc_html__( 'Showing %d backups', 'swish-migrate-and-backup' ),
							count( $backups )
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>

		<script>
		jQuery( document ).ready( function( $ ) {
			// Toggle file list visibility.
			$( '.swish-toggle-files' ).on( 'click', function() {
				const jobId = $( this ).data( 'job-id' );
				const $files = $( '#files-' + jobId );
				const $icon = $( this ).find( '.material-symbols-outlined' );

				if ( $files.is( ':visible' ) ) {
					$files.slideUp();
					$icon.text( 'folder_open' );
				} else {
					$files.slideDown();
					$icon.text( 'folder_off' );
				}
			} );

			// Delete backup.
			$( '.swish-delete-backup' ).on( 'click', function() {
				if ( ! confirm( '<?php echo esc_js( __( 'Are you sure you want to delete this backup? This action cannot be undone.', 'swish-migrate-and-backup' ) ); ?>' ) ) {
					return;
				}

				const $button = $( this );
				const jobId = $button.data( 'job-id' );
				const $row = $button.closest( 'tr' );
				const $icon = $button.find( '.material-symbols-outlined' );

				$button.prop( 'disabled', true );
				$icon.text( 'hourglass_empty' ).addClass( 'swish-animate-spin' );

				$.ajax( {
					url: swishBackupPro.ajaxUrl,
					type: 'POST',
					data: {
						action: 'swish_backup_delete_multisite_backup',
						nonce: swishBackupPro.nonce,
						job_id: jobId
					},
					success: function( response ) {
						if ( response.success ) {
							$row.css( 'background', 'var(--swish-error-50)' ).fadeOut( 400, function() {
								$( this ).remove();

								// Check if table is empty.
								if ( $( '.swish-backups-table tbody tr' ).length === 0 ) {
									location.reload();
								}
							} );
						} else {
							alert( '<?php echo esc_js( __( 'Error:', 'swish-migrate-and-backup' ) ); ?> ' + ( response.data.message || '<?php echo esc_js( __( 'Failed to delete backup', 'swish-migrate-and-backup' ) ); ?>' ) );
							$button.prop( 'disabled', false );
							$icon.text( 'delete' ).removeClass( 'swish-animate-spin' );
						}
					},
					error: function() {
						alert( '<?php echo esc_js( __( 'AJAX error. Please try again.', 'swish-migrate-and-backup' ) ); ?>' );
						$button.prop( 'disabled', false );
						$icon.text( 'delete' ).removeClass( 'swish-animate-spin' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}
}
