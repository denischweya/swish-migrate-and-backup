<?php
/**
 * Admin Menu Handler.
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
 * Registers and handles admin menu pages.
 */
final class AdminMenu {

	/**
	 * Dashboard page.
	 *
	 * @var Dashboard
	 */
	private Dashboard $dashboard;

	/**
	 * Backups page.
	 *
	 * @var BackupsPage
	 */
	private BackupsPage $backups_page;

	/**
	 * Settings page.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Schedules page.
	 *
	 * @var SchedulesPage
	 */
	private SchedulesPage $schedules_page;

	/**
	 * Migration page.
	 *
	 * @var MigrationPage
	 */
	private MigrationPage $migration_page;

	/**
	 * Constructor.
	 *
	 * @param Dashboard     $dashboard      Dashboard page.
	 * @param BackupsPage   $backups_page   Backups page.
	 * @param SettingsPage  $settings_page  Settings page.
	 * @param SchedulesPage $schedules_page Schedules page.
	 * @param MigrationPage $migration_page Migration page.
	 */
	public function __construct(
		Dashboard $dashboard,
		BackupsPage $backups_page,
		SettingsPage $settings_page,
		SchedulesPage $schedules_page,
		MigrationPage $migration_page
	) {
		$this->dashboard      = $dashboard;
		$this->backups_page   = $backups_page;
		$this->settings_page  = $settings_page;
		$this->schedules_page = $schedules_page;
		$this->migration_page = $migration_page;
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register(): void {
		// Main menu.
		add_menu_page(
			__( 'Swish Backup', 'swish-migrate-and-backup' ),
			__( 'Swish Backup', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup',
			array( $this->dashboard, 'render' ),
			'dashicons-cloud-saved',
			80
		);

		// Dashboard (same as main menu).
		add_submenu_page(
			'swish-backup',
			__( 'Dashboard', 'swish-migrate-and-backup' ),
			__( 'Dashboard', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup',
			array( $this->dashboard, 'render' )
		);

		// Backups.
		add_submenu_page(
			'swish-backup',
			__( 'Backups', 'swish-migrate-and-backup' ),
			__( 'Backups', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup-backups',
			array( $this->backups_page, 'render' )
		);

		// Schedules.
		add_submenu_page(
			'swish-backup',
			__( 'Schedules', 'swish-migrate-and-backup' ),
			__( 'Schedules', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup-schedules',
			array( $this->schedules_page, 'render' )
		);

		// Migration.
		add_submenu_page(
			'swish-backup',
			__( 'Migration', 'swish-migrate-and-backup' ),
			__( 'Migration', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup-migration',
			array( $this->migration_page, 'render' )
		);

		// Settings.
		add_submenu_page(
			'swish-backup',
			__( 'Settings', 'swish-migrate-and-backup' ),
			__( 'Settings', 'swish-migrate-and-backup' ),
			'manage_options',
			'swish-backup-settings',
			array( $this->settings_page, 'render' )
		);
	}
}
