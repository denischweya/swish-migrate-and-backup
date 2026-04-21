# Changelog

All notable changes to Swish Migrate and Backup will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.17] - 2026-04-21

### Fixed
- Duplicate "Migrate Site" card on dashboard
- REST API download route not matching backup IDs with underscores (e.g., `backup_uuid`)

### Changed
- Pipeline Batch Size defaults: Shared hosting (150), VPS (250), Dedicated server (500)

## [1.0.16] - 2026-04-21

### Fixed
- Critical bug: ServerLimits timing check caused immediate yield due to int/float comparison (`0 === 0.0` is false in PHP 8)
- Files stuck in 'processing' status now reset to 'pending' for retry
- Reduced stale processing threshold from 300s to 30s for faster recovery
- Partial file resume corruption - files now restart from scratch instead of attempting resume

### Added
- Detailed logging for file processing: batch info, file status, yield reasons
- Context data now included in log file output for better debugging

### Changed
- Overall progress calculation now includes all phases (indexing 0-10%, processing 10-95%, finalizing 95-100%)

## [1.0.15] - 2026-04-21

### Added
- Archive format setting (Auto/ZIP/TAR.GZ) in settings page
- Streaming file scanning for large sites (writes to disk, not memory)
- Memory-efficient tar creation using `--files-from` for 50k+ files
- Docker/ddev environment detection with automatic ZIP fallback
- CPU/IO throttling with `nice` and `ionice` for tar operations

### Fixed
- Backup failing at 70% on large sites (2GB+) due to memory exhaustion
- Server crashes during archive creation in containerized environments
- Timeout issues during file staging step

### Changed
- Tar backups now skip staging step entirely (direct archive from source)
- File list written to disk instead of held in memory
- Fast GZIP compression (level 1) for better performance
- Auto-fallback to ZIP if tar fails or times out

## [1.0.12] - 2026-04-20

### Changed
- Dashboard now has three action boxes: Backup Now, Search & Replace, and Migrate Site
- Renamed "Migrate Site" panel to "Search & Replace" for clarity
- Added dedicated "Migrate Site" button linking to the migration page

## [1.0.11] - 2026-04-20

### Fixed
- Vendor folders are now included in backups (previously excluded, causing plugin errors after migration)

## [1.0.10] - 2026-04-20

### Added
- Memory-aware adaptive batch sizing for database and file backups
- Memory-aware adaptive batch sizing for URL search/replace during migration
- Automatic batch size reduction when memory pressure is detected
- Stream-based ZIP extraction to prevent memory exhaustion on large backups
- Memory threshold monitoring (32MB) to trigger adaptive processing
- User-friendly error messages for server limit errors (memory, timeout, upload size)
- Pre-import memory check with clear warning if insufficient memory available
- Shutdown handler to catch and report fatal errors during import/migration

### Fixed
- Memory exhaustion during import/restore of large backups (now uses streaming extraction)
- Import failing with `wp_handle_upload()` undefined function error
- Large file extraction causing PHP memory limit errors
- Fatal errors during import now show helpful messages instead of silent failures
- Connection reset during "Updating URLs" phase on memory-constrained servers
- Connection reset during "Finalizing" phase caused by pre-migration backup creation

### Changed
- ZIP extraction now reads files in 8KB chunks instead of loading entire files into memory
- Database backup automatically reduces batch size when memory is low
- File backup flushes ZIP archive more frequently under memory pressure
- File backup progress now shows percentage instead of time estimation
- URL search/replace now processes 100 rows per batch (reduced from 500)
- Transient cache clearing now uses batched deletion (1000 at a time)
- Pre-migration backup is now opt-in to avoid memory exhaustion during migration

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
