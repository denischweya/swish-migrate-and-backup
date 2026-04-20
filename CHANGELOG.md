# Changelog

All notable changes to Swish Migrate and Backup will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.9] - 2026-04-20

### Added
- Async backup processing to prevent timeouts on managed hosting (WP Engine, Flywheel, etc.)
- Background job processing via WP Cron with automatic fallback for hosts with alternate cron
- Job status polling endpoint (`/job/{id}/process`) for triggering pending backups directly
- Real-time progress updates during backup process
- ETA (estimated time remaining) display during file backup showing files processed and time remaining

### Fixed
- Backup download returning "0" - changed from admin-ajax.php to admin.php endpoint
- Added proper download handler with token validation and security checks

### Changed
- Backup API now uses async processing by default
- Frontend polling improved with automatic fallback when WP Cron doesn't trigger

## [1.0.8] - 2026-04-15

### Fixed
- Replaced inline `<script>` tag with `wp_add_inline_script()` for WordPress.org compliance
- Replaced `move_uploaded_file()` with `wp_handle_upload()` for proper WordPress file handling

## [1.0.7] - 2026-02-25

### Changed
- Compatibility update for Pro plugin import fixes
- Minor stability improvements

## [1.0.6] - 2026-02-25

### Changed
- Compatibility update for Pro plugin URL auto-detection fixes
- Minor stability improvements

## [1.0.5] - 2026-02-25

### Changed
- Compatibility update for Pro plugin size estimation fixes
- Minor stability improvements

## [1.0.4] - 2026-02-25

### Changed
- Compatibility update for Pro plugin import/migration fixes
- Minor stability improvements

## [1.0.3] - 2026-02-25

### Added
- Auto-detection of old site URL during migration (pre-filled from backup manifest)
- Detailed migration progress with stage tracking similar to backup process
- Smooth scrolling and active state highlighting for documentation navigation
- REST endpoint for importing backup files (`/swish-backup/v1/import`)

### Changed
- Moved backup storage location from `wp-content/uploads/swish-backups/` to `wp-content/swish-backups/`
- Improved documentation page with better anchor link navigation

### Fixed
- Import feature Continue button now properly uploads and analyzes backup files
- Backup exclusion now always excludes swish-backups folder from backups
- Prevents backups from including previous backup archives

## [1.0.2] - 2025-02-25

### Added
- Enhanced backup progress modal with detailed stage tracking
- Visual progress log showing each backup stage as it completes
- Green checkmarks for completed stages
- Red indicators for failed stages
- Animated spinner for in-progress stages

### Changed
- Improved progress feedback during backup process
- Better error handling and messaging

### Fixed
- Minor UI improvements for progress display

## [1.0.1] - 2025-02-20

### Added
- Option to include/exclude WordPress core files in backups

### Changed
- Enhanced CSS for admin layout with responsive design
- Refactored JavaScript asset enqueuing for improved performance

### Fixed
- Minor bug fixes and improvements

## [1.0.0] - 2025-02-15

### Added
- Initial release
- Full site backup support (database, plugins, themes, uploads)
- Database-only backup option
- Files-only backup option
- Local storage adapter
- Amazon S3 storage adapter
- Dropbox storage adapter
- Google Drive storage adapter
- Site migration with automatic URL replacement
- Search and replace functionality with serialization support
- Scheduled backups (hourly, daily, weekly, monthly)
- Chunked processing for large sites
- AES-256-CBC encrypted cloud storage credentials
- Full REST API for integration
