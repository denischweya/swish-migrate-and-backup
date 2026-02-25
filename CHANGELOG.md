# Changelog

All notable changes to Swish Migrate and Backup will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
