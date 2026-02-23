# Swish Migrate and Backup

A production-ready WordPress backup and migration plugin with cloud storage support.

## Features

### Backup
- **Full Site Backup**: Database, plugins, themes, uploads, and core files
- **Database-Only Backup**: Quick SQL dump
- **Files-Only Backup**: Archive themes, plugins, and uploads
- **Chunked Processing**: Memory-safe operations for large sites
- **Incremental Backups**: Resume failed backups
- **Scheduled Backups**: Automated backups via WP-Cron
- **Backup Verification**: Checksum validation

### Storage Destinations
- **Local Storage**: Store backups on your server
- **Amazon S3**: Full AWS S3 integration with multipart uploads
- **Dropbox**: OAuth-based Dropbox integration
- **Google Drive**: OAuth-based Google Drive integration
- **Extensible**: Add custom storage adapters via interface

### Migration
- **One-Click Restore**: Restore any backup with one click
- **URL Replacement**: Serialization-safe search and replace
- **Migration Wizard**: Step-by-step migration guide
- **Domain Rewriting**: Automatic URL updates for migrations

### Security
- **Nonce Verification**: All actions protected by nonces
- **Capability Checks**: Admin-only operations
- **Encrypted Credentials**: Secure storage of API keys
- **Protected Backups**: .htaccess protection for backup files
- **Signed Download URLs**: Temporary, secure download links

## Requirements

- PHP 8.1 or higher
- WordPress 6.0 or higher
- PHP Extensions: zip, json, mysqli, openssl

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Swish Backup** in the admin menu

## Configuration

### Storage Settings

1. Go to **Swish Backup > Settings**
2. Configure your preferred storage destinations:
   - **Amazon S3**: Enter Access Key, Secret Key, Bucket, and Region
   - **Dropbox**: Enter your Access Token
   - **Google Drive**: Configure OAuth credentials

### Backup Settings

- **Compression Level**: Choose between speed and file size
- **Backup Contents**: Select what to include in backups
- **Exclude Patterns**: Add files/folders to exclude
- **Email Notifications**: Get notified when backups complete

## Usage

### Creating a Backup

1. Go to **Swish Backup > Backups**
2. Click **Create Backup**
3. Select backup type (Full, Database, or Files)
4. Wait for the backup to complete

### Restoring a Backup

1. Go to **Swish Backup > Backups**
2. Find the backup you want to restore
3. Click **Restore**
4. Confirm the restore options
5. Click **Restore Now**

### Migrating a Site

1. On the source site, create a full backup
2. Download the backup file
3. On the destination site, go to **Swish Backup > Migration**
4. Select **Import Backup**
5. Upload the backup file
6. Configure URL replacement
7. Start the migration

### Scheduling Backups

1. Go to **Swish Backup > Schedules**
2. Click **Add Schedule**
3. Configure:
   - Schedule name
   - Frequency (hourly, daily, weekly, monthly)
   - Backup type
   - Storage destinations
   - Retention count
4. Save the schedule

## Hooks and Filters

### Actions

```php
// Before backup starts
do_action( 'swish_backup_before', $job_id, $options );

// After backup completes
do_action( 'swish_backup_after', $job_id, $result );

// Before restore starts
do_action( 'swish_backup_restore_before', $backup_path, $options );

// After restore completes
do_action( 'swish_backup_restore_after', $backup_path, $manifest );

// After storage adapters registered
do_action( 'swish_backup_storage_registered', $storage_manager );
```

### Adding Custom Storage Adapters

```php
add_action( 'swish_backup_storage_registered', function( $storage_manager ) {
    $storage_manager->register_adapter(
        'my_storage',
        new MyCustomStorageAdapter()
    );
});
```

## REST API

The plugin provides REST API endpoints for programmatic access:

- `POST /wp-json/swish-backup/v1/backup` - Create backup
- `GET /wp-json/swish-backup/v1/backups` - List backups
- `GET /wp-json/swish-backup/v1/backup/{id}` - Get backup details
- `DELETE /wp-json/swish-backup/v1/backup/{id}` - Delete backup
- `POST /wp-json/swish-backup/v1/restore` - Restore backup
- `POST /wp-json/swish-backup/v1/migrate` - Run migration
- `POST /wp-json/swish-backup/v1/search-replace` - Search and replace

## File Structure

```
swish-migrate-and-backup/
├── swish-migrate-and-backup.php   # Main plugin file
├── uninstall.php                   # Cleanup on uninstall
├── composer.json                   # Composer configuration
├── README.md                       # This file
│
├── src/
│   ├── Core/                       # Core classes
│   │   ├── Container.php           # DI Container
│   │   ├── Plugin.php              # Plugin bootstrap
│   │   ├── Activator.php           # Activation handler
│   │   └── Deactivator.php         # Deactivation handler
│   │
│   ├── Backup/                     # Backup functionality
│   │   ├── BackupManager.php       # Backup orchestration
│   │   ├── DatabaseBackup.php      # Database backup
│   │   ├── FileBackup.php          # File backup
│   │   └── BackupArchiver.php      # Archive handling
│   │
│   ├── Restore/                    # Restore functionality
│   │   └── RestoreManager.php      # Restore handling
│   │
│   ├── Migration/                  # Migration functionality
│   │   ├── Migrator.php            # Migration orchestration
│   │   └── SearchReplace.php       # Search and replace
│   │
│   ├── Storage/                    # Storage adapters
│   │   ├── Contracts/              # Interfaces
│   │   │   ├── StorageAdapterInterface.php
│   │   │   └── AbstractStorageAdapter.php
│   │   ├── StorageManager.php      # Adapter management
│   │   ├── LocalAdapter.php        # Local storage
│   │   ├── S3Adapter.php           # Amazon S3
│   │   ├── DropboxAdapter.php      # Dropbox
│   │   └── GoogleDriveAdapter.php  # Google Drive
│   │
│   ├── Admin/                      # Admin interface
│   │   ├── AdminMenu.php           # Menu registration
│   │   ├── Dashboard.php           # Dashboard page
│   │   ├── BackupsPage.php         # Backups page
│   │   ├── SettingsPage.php        # Settings page
│   │   ├── SchedulesPage.php       # Schedules page
│   │   └── MigrationPage.php       # Migration wizard
│   │
│   ├── Api/                        # REST API
│   │   └── RestController.php      # API endpoints
│   │
│   ├── Queue/                      # Background processing
│   │   ├── JobQueue.php            # Job queue
│   │   └── Scheduler.php           # Cron scheduling
│   │
│   ├── Logger/                     # Logging
│   │   └── Logger.php              # Log handler
│   │
│   └── Security/                   # Security
│       └── Encryption.php          # Encryption handler
│
├── assets/
│   ├── css/
│   │   └── admin.css               # Admin styles
│   └── js/
│       └── admin.js                # Admin scripts
│
├── languages/                      # Translations
│
└── tests/                          # Unit tests
    ├── Unit/
    └── Integration/
```

## Development

### Running Tests

```bash
composer install
composer test
```

### Code Standards

```bash
composer phpcs
composer phpcbf
```

## License

GPL-2.0-or-later

## Support

For support, please open an issue on the GitHub repository.

## Changelog

### 1.0.0
- Initial release
- Full site backup functionality
- Database and file backup support
- Local, S3, Dropbox, and Google Drive storage
- Migration wizard with URL replacement
- Scheduled backups
- REST API
