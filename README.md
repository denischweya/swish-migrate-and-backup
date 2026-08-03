# Swish Migrate and Backup

**The ultimate WordPress backup, migration, and restore plugin with cloud storage and full multisite support.**

Swish Migrate and Backup is a powerful, production-ready WordPress plugin that simplifies website backups, migrations, and restores. Create full site backups (database, plugins, themes, uploads, and core files), database-only backups, or files-only backups with intelligent chunked processing for large sites — with no size limits. Store your backups locally or sync them to Amazon S3, Dropbox, or Google Drive. Schedule automated backups with WP-Cron, verify backup integrity, and restore with one click.

Migrating to a new domain? Swish handles serialization-safe search and replace for seamless URL updates. Running a network? Back up every site (or a selection) from Network Admin, migrate whole networks, extract a single subsite into a standalone install, or duplicate a site to a new URL. Built with security in mind — featuring nonce verification, capability checks, encrypted credentials, and signed download URLs. Extensible architecture lets developers add custom storage adapters. Built by [Denis Bosire](https://denis.swishfolio.com/).

## Features

### Backup
- **Full Site Backup**: Database, plugins, themes, uploads, and optional WordPress core files
- **Database-Only Backup**: Quick SQL dump with chunked, resumable table processing
- **Files-Only Backup**: Archive themes, plugins, and uploads
- **`.swish` Streaming Archive Format**: Custom append-friendly format built for shared hosting — backups resume from the exact file and byte offset after a timeout (restore also accepts legacy `.zip` and `.tar.gz`)
- **No Size Limits**: No cap on backup or restore size
- **Chunked Processing**: Memory-safe operations for large sites, with adaptive batch sizing per host (WP Engine, Kinsta, Flywheel presets)
- **Scheduled Backups**: Automated hourly/daily/weekly/monthly backups via WP-Cron with retention counts
- **Backup Verification**: Archive integrity checks before a job is marked complete

### Multisite
- **Network Backups**: Back up all sites or a selection from Network Admin
- **Archive Modes**: One archive for the whole network, or separate archives per site
- **Multisite Migration**: Import network backups, or extract a single subsite from a network backup into a standalone WordPress install
- **Site Duplication**: Clone any site in the network to a new URL, with live slug availability checks

### Storage Destinations
- **Local Storage**: Store backups on your server (protected directory, tokenized downloads)
- **Amazon S3**: Full AWS S3 integration with multipart uploads
- **Dropbox**: OAuth-based Dropbox integration
- **Google Drive**: OAuth-based Google Drive integration
- **Extensible**: Add custom storage adapters via interface

### Migration & Restore
- **One-Click Restore**: Restore any backup with one click
- **URL Replacement**: Serialization-safe search and replace with dry-run preview
- **Migration Wizard**: Step-by-step migration guide with detailed, phase-labeled progress
- **Resumable Import Pipeline**: 11-phase import that survives timeouts and the database swap itself
- **Resumable Downloads**: HTTP Range support and X-Sendfile/X-Accel-Redirect for multi-GB archives (curl/aria2c friendly)

### Interfaces
- **Admin UI**: Dashboard, Backups, Schedules, Migration, Settings, Logs, and Documentation pages (light/dark theme on multisite)
- **REST API**: Full API under `swish-backup/v1` for integration with other tools
- **WP-CLI**: `wp swish backup|import|status|cleanup`
- **Standalone Extractor**: `extract-swish.php` CLI tool to unpack `.swish` archives anywhere

### Security
- **Nonce Verification**: All actions protected by nonces
- **Capability Checks**: Admin-only operations (`manage_options`; `manage_network` for network operations)
- **Encrypted Credentials**: AES-256-CBC encryption for cloud storage API keys
- **Protected Backups**: Deny-all `.htaccess` on the backup directory
- **Signed Download URLs**: Temporary, expiring, token-verified download links
- **Hardened Extraction**: Path-traversal rejection on every archive entry

### Languages
Fully translated into English, Japanese (日本語), Spanish (Español), French (Français), Simplified Chinese (简体中文), and Arabic (العربية). Translation files ship with the plugin in `languages/`.

## Requirements

- PHP 8.1 or higher
- WordPress 6.0 or higher
- PHP Extensions: zip, json, openssl; mysqli is required for backup/restore/migration (the plugin still activates without it — e.g. in SQLite-based WordPress Playground/Studio — with a notice explaining the limitation)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Swish Backup** in the admin menu (or **Network Admin → Swish Backup** on multisite)

## Configuration

### Storage Settings

1. Go to **Swish Backup > Settings**
2. Configure your preferred storage destinations:
   - **Amazon S3**: Enter Access Key, Secret Key, Bucket, and Region
   - **Dropbox**: Enter your Access Token
   - **Google Drive**: Configure OAuth credentials

### Backup Settings

- **Backup Contents**: Toggles for Database, Plugins, Themes, Uploads, and Core Files
- **Performance**: Files per Request and Database Rows per Batch, with quick presets for Shared Hosting, VPS/Managed, and Dedicated servers
- **Exclude Patterns**: Add files/folders to exclude
- **Email Notifications**: Get notified when backups complete

## Usage

### Creating a Backup

1. Go to **Swish Backup > Backups**
2. Click **Create Backup**
3. Select backup type (Full, Database, or Files)
4. Wait for the backup to complete — large sites process in resumable chunks

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

### Multisite Network Backups

1. Go to **Network Admin > Swish Backup**
2. Select all sites or pick individual sites
3. Choose the archive mode (single network archive, or one archive per site)
4. Create the backup; download links are signed and expire automatically

To duplicate a site, use **Network Admin > Swish Backup > Duplicate**; to import a network backup (or extract one subsite into a standalone install), use the Migration page.

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

### WP-CLI

```bash
wp swish backup --type=full            # Full backup (database + files)
wp swish backup --type=database        # Database-only backup
wp swish backup --type=full --include-core   # Include WordPress core files

wp swish import /path/to/backup.swish  # Import a backup (auto-detects old URL)
wp swish import backup.swish --old-url=https://old.com --new-url=https://new.com
wp swish import backup.swish --skip-url-replace

wp swish status                        # Check active import session
wp swish cleanup                       # Clean up stale import sessions
```

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
- `GET /wp-json/swish-backup/v1/backup/{id}/download` - Get a tokenized download URL
- `DELETE /wp-json/swish-backup/v1/backup/{id}` - Delete backup
- `POST /wp-json/swish-backup/v1/restore` - Restore backup
- `POST /wp-json/swish-backup/v1/import` - Import a backup (upload or server path)
- `POST /wp-json/swish-backup/v1/migrate` - Run migration
- `POST /wp-json/swish-backup/v1/search-replace` - Search and replace (supports dry run)
- `GET /wp-json/swish-backup/v1/job/{id}` - Job status
- `GET|POST /wp-json/swish-backup/v1/settings` - Read/update settings

Multisite endpoints (super admin):

- `GET /wp-json/swish-backup/v1/pro/multisite/sites` - List network sites
- `POST /wp-json/swish-backup/v1/pro/multisite/backup` - Start a network backup
- `GET /wp-json/swish-backup/v1/pro/multisite/backup/{job_id}/status` - Network backup status
- `POST /wp-json/swish-backup/v1/pro/multisite/estimate` - Estimate backup size

## File Structure

```
swish-migrate-and-backup/
├── swish-migrate-and-backup.php   # Main plugin file, constants, bootstrap
├── uninstall.php                  # Cleanup on uninstall
├── extract-swish.php              # Standalone CLI .swish extractor
├── composer.json                  # Composer configuration
│
├── src/
│   ├── Core/                      # Container, Plugin bootstrap, Activator, ServerLimits, CacheManager
│   ├── Backup/                    # BackupManager, BackupPipeline, streaming engines, DatabaseBackup, FileBackup
│   ├── Archive/                   # .swish format: archiver, compressor, extractor
│   ├── Import/                    # 11-phase resumable import pipeline
│   ├── Export/                    # Streaming export
│   ├── Restore/                   # RestoreManager (.swish/.zip/.tar.gz)
│   ├── Migration/                 # Migrator, serialization-safe SearchReplace
│   ├── Storage/                   # Local, S3, Dropbox, Google Drive adapters + contracts
│   ├── Multisite/                 # Network backup, multisite migration, site duplication
│   ├── Queue/                     # JobQueue, cron Scheduler
│   ├── Api/                       # REST controllers (core + multisite)
│   ├── Admin/                     # Admin pages (+ Admin/Multisite network UI)
│   ├── Security/                  # AES-256-CBC credential encryption
│   ├── Logger/                    # File + database logging
│   └── CLI/                       # WP-CLI commands
│
├── assets/                        # Admin CSS/JS (single-site and multisite)
├── build/                         # Compiled admin assets
└── languages/                     # Translations
```

## Development

### Code Standards

```bash
composer install
composer phpcs
composer phpcbf
```

## License

GPL-2.0-or-later

## Support

For support, please open an issue on the GitHub repository.

## Changelog

Condensed highlights — see [CHANGELOG.md](CHANGELOG.md) for full details.

### 1.4.0 (2026-08-03)
- Added full translations for Spanish, French, Simplified Chinese, and Arabic, bundled in `languages/` (Japanese already available via translate.wordpress.org)

### 1.3.1 (2026-07-14)
- Fixed: literal `%` characters in database content leaked into SQL dumps as `{64-hex}` placeholder-escape tokens, corrupting serialized data on restore
- The plugin now activates without mysqli (SQLite environments such as WordPress Playground/Studio) with a notice explaining that backup/restore/migration require MySQL/MariaDB

### 1.3.0 (2026-07-13)
- Fixed multisite migration imports failing with repeated 500/403 errors and never reporting completion: progress now survives the database restore (file mirror), the session-loss window (soft nonce + job-UUID fallback), and the brief unreachable period during restore

### 1.2.1
- Fixed full backups producing no archive (.swish path collision on finalize); streaming engine now writes genuine `.swish` directly
- Per-job database locks stop cron/poll races from corrupting or mis-failing jobs
- Automatic schema upgrades after plugin updates; fixed duplicate core-file archiving (up to 40% archive bloat)

### 1.2.0
- All Pro features merged into the free plugin: multisite network backups, multisite migration, single-subsite extraction, site duplication
- Removed the 4GB backup and 500MB per-file size limits
- Security hardening: signed expiring download URLs, path containment on imports, stricter archive extraction

### 1.1.9 – 1.1.11
- Introduced the custom `.swish` streaming archive format and made it the only format for new backups (restore keeps `.zip`/`.tar.gz` support)
- PHP 7.4 compatibility fixes; Windows-safe CLI download commands

### 1.1.8
- Fixed missing files in backups (full enumeration before archiving); symlink/Docker path fixes; vendor directories included again
- Adaptive performance tuning per host, hosting presets, WP-CLI commands (`wp swish backup|import|status|cleanup`)

### 1.1.5 – 1.1.7
- Large-download reliability: X-Sendfile/X-Accel-Redirect, HTTP Range resume, token-authenticated downloads outside wp-admin
- New Logs admin page; `.tar.gz`/`.swish` import support; Docker/symlink path fixes

### 1.1.2 – 1.1.3
- True streaming file backups (memory-capped, resumable time slices)
- Chunked database backup with keyset pagination for 100k+ row tables

### 1.0.x
- Initial release and iteration: full/database/files backups; Local, S3, Dropbox, Google Drive storage; migration wizard with serialization-safe URL replacement; scheduled backups; REST API; async processing; adaptive memory management; streaming ZIP extraction
