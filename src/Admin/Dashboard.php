<?php
/**
 * Dashboard Admin Page.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin;

use SwishMigrateAndBackup\Backup\BackupManager;
use SwishMigrateAndBackup\Storage\StorageManager;

/**
 * Dashboard page controller.
 */
final class Dashboard {

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private BackupManager $backup_manager;

	/**
	 * Storage manager.
	 *
	 * @var StorageManager
	 */
	private StorageManager $storage_manager;

	/**
	 * Constructor.
	 *
	 * @param BackupManager  $backup_manager  Backup manager.
	 * @param StorageManager $storage_manager Storage manager.
	 */
	public function __construct( BackupManager $backup_manager, StorageManager $storage_manager ) {
		$this->backup_manager  = $backup_manager;
		$this->storage_manager = $storage_manager;
	}

	/**
	 * Render the dashboard page.
	 *
	 * React-based dashboard mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<div id="swish-backup-dashboard">
				<!-- React app will mount here -->
				<div class="swish-loading">
					<span class="spinner is-active" style="float: none;"></span>
					<p><?php esc_html_e( 'Loading dashboard...', 'swish-migrate-and-backup' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
