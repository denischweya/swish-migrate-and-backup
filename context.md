# Swish Migrate and Backup — Complete Plugin Specification

This document is a self-contained specification of the Swish Migrate and Backup WordPress plugin, detailed enough to re-implement the plugin from scratch on any platform. It reflects version **1.3.1**. (The former Pro add-on — multisite features — was merged into this single free plugin in v1.2.0.)

---

## 1. Product Overview

**Swish Migrate and Backup** is a WordPress backup, restore, and migration plugin designed to work reliably on constrained shared hosting through chunked, resumable processing.

### Feature list

- **Backups**: full (database + files), database-only, files-only; optional WordPress core files (wp-admin, wp-includes, root PHP files); selective wp-content folders (themes, plugins, uploads, mu-plugins); granular file/table exclusions. No size limits (backup size and per-file caps are `null`/`PHP_INT_MAX` by default, filterable).
- **Archive formats**: custom `.swish` streaming format (default, see §5); restore also supports legacy `.zip` and `.tar.gz`.
- **Restore & Migration**: full restore, migration to a new domain with automatic URL search-replace (serialization-aware), standalone search-replace tool with dry-run preview.
- **Import**: upload a backup file, pick one from the server (`wp-content/swish-backups/`), or import via CLI; 11-phase resumable import pipeline.
- **Storage**: local directory plus Amazon S3, Dropbox, and Google Drive adapters; credentials encrypted with AES-256-CBC.
- **Scheduling**: cron-based scheduled backups (hourly/daily/weekly/monthly) with retention counts and per-schedule storage destinations.
- **Multisite** (formerly Pro): network-wide backups of all or selected sites, single-archive or per-site-archive modes, shared-table handling, multisite migration/import (including multisite→single-site extraction of one subsite), site duplication within a network, network-admin UI with light/dark theme.
- **Interfaces**: React dashboard (wp-scripts build), PHP-rendered admin pages, REST API (`swish-backup/v1`), admin-ajax endpoints, WP-CLI commands, standalone CLI extractor (`extract-swish.php`).

### Requirements

- WordPress ≥ 6.0, PHP ≥ 8.1, PHP extensions: `zip`, `json`. `mysqli` is required for backup/restore/migration to actually run but does NOT block activation — SQLite environments (WordPress Playground/Studio) can activate the plugin and browse the UI (translation preview); a warning notice on the plugin's admin pages explains the limitation.
- Text domain: `swish-migrate-and-backup`. License GPL-2.0+.

---

## 2. Architecture

### Bootstrap flow

`swish-migrate-and-backup.php` (main file):
1. Defines constants: `SWISH_BACKUP_VERSION`, `SWISH_BACKUP_PLUGIN_FILE`, `SWISH_BACKUP_PLUGIN_DIR`, `SWISH_BACKUP_PLUGIN_URL`, `SWISH_BACKUP_PLUGIN_BASENAME`.
2. Loads Composer autoloader if `vendor/autoload.php` exists, else registers a PSR-4 fallback autoloader mapping `SwishMigrateAndBackup\` → `src/`. **Classes must sit at PSR-4-exact paths** (e.g. `SwishMigrateAndBackup\Admin\Multisite\AdminLayout` ⇔ `src/Admin/Multisite/AdminLayout.php`).
3. On `plugins_loaded`: `Container::get_instance()` → `new Plugin($container)` → `Plugin::boot()`.
4. Activation/deactivation hooks → `Core\Activator::activate()` / `Core\Deactivator::deactivate()`.

`Core\Plugin::boot()` = `maybe_upgrade()` (schema migrations keyed on `swish_backup_db_version`) → `register_services()` (all container bindings) → `init_hooks()` → fires `swish_backup_booted` action.

### Service container

`Core\Container`: singleton registry. `singleton(class, factory)` caches instances; `bind()` returns fresh instances; `get()`, `has()`, `instance()`. All services are registered as singletons with closure factories receiving the container. Fires `swish_backup_services_registered` after registration.

### Module map (namespace `SwishMigrateAndBackup\`)

| Namespace | Responsibility |
|---|---|
| `Core\` | Container, Plugin bootstrap, Activator, Deactivator, ServerLimits (adaptive batch sizing per host), CacheManager |
| `Backup\` | BackupManager (orchestrator), BackupPipeline (chunked queue pipeline), DatabaseBackup, FileBackup, FileBackupChunker, BackupArchiver, StreamingArchiver, StreamingTarBackup (historical name — streams `.swish`, not tar; default engine for dashboard/async backups), TarArchiver, SwishArchiver, BackupState (checkpoints), FileQueue, ChunkingManager |
| `Archive\` | `.swish` format: SwishArchiver, SwishCompressor, SwishExtractor + `Exception\{NotWritableException, QuotaExceededException}` |
| `Import\` | ImportPipeline (11 phases), ImportSession (cross-request state) |
| `Export\` | ExportController (streaming export), ExportAjaxHandler |
| `Restore\` | RestoreManager (zip/tar/.swish restore, chunked DB import) |
| `Migration\` | Migrator (backup+restore+URL replace), SearchReplace (serialization-aware) |
| `Storage\` | `Contracts\{StorageAdapterInterface, AbstractStorageAdapter}`, LocalAdapter, S3Adapter, DropboxAdapter, GoogleDriveAdapter, StorageManager |
| `Queue\` | JobQueue, Scheduler |
| `Security\` | Encryption (AES-256-CBC, random IV, master key in `swish_backup_encryption_key` option; used for cloud credentials only) |
| `Logger\` | PSR-3-style Logger; writes to `wp-content/swish-backups/logs/`, mirrors to `swish_backup_logs` table, fires `swish_backup_logged` |
| `Api\` | RestController (core routes), MultisiteRestController (multisite routes) |
| `Admin\` | AdminMenu, AdminNav, Dashboard, BackupsPage, SettingsPage, SchedulesPage, MigrationPage, LogsPage, DocumentationPage |
| `Admin\Multisite\` | AdminLayout (page chrome + theme), SiteSelectorUI, BackupHistoryUI, MigrationUI, DuplicateUI, ProgressModal |
| `Multisite\` | MultisiteModule (bootstrap), MultisiteDetector, MultisiteManager, NetworkBackup, MultisiteMigration, SiteDuplicator, ArchiveMode |
| `CLI\` | Commands (WP-CLI) |

### Hooks registered by `Plugin::init_hooks()`

- Admin: `admin_menu` → AdminMenu::register; `admin_enqueue_scripts`; `plugin_action_links_{basename}`.
- REST: `rest_api_init` → RestController::register_routes; `rest_authentication_errors` (prio 100) → `bypass_rest_auth_for_import()` (import endpoints authenticate via a secret key that survives DB restore).
- Cron/async: `swish_backup_scheduled_backup` → Scheduler; `swish_backup_process_async`, `swish_backup_continue` → BackupManager.
- `init`: `register_storage_adapters()` (fires `swish_backup_storage_registered`), `handle_backup_download()` (tokenized downloads, works outside wp-admin), ExportAjaxHandler::register.
- AJAX (with `nopriv` twins, secured by per-job nonce/secret): `swish_process_backup`, `swish_import_continue`.
- **Multisite module**: if the legacy Pro add-on is NOT active (`! defined('SWISH_BACKUP_PRO_VERSION')`), `MultisiteModule::boot()` runs; otherwise (admin requests) `admin_init` → `deactivate_legacy_pro_plugin()` silently deactivates the old add-on and shows a one-time notice. Pro options/tables are reused, never deleted.
- WP-CLI: `wp swish backup|import|status|cleanup`.

### Extension filters/actions (public API)

- Filters: `swish_backup_size_limit` (default `null` = unlimited), `swish_backup_max_file_size` (default `PHP_INT_MAX`), `swish_backup_is_pro` (default `true`), `swish_backup_has_multisite`, `swish_backup_plugin_info`, `swish_backup_soft_time_limit`, `swish_backup_force_tar`, `swish_backup_force_zip`.
- Actions: `swish_backup_booted`, `swish_backup_services_registered`, `swish_backup_storage_registered`, `swish_backup_before`, `swish_backup_after`, `swish_backup_restore_before`, `swish_backup_restore_after`, `swish_backup_logged`, `swish_backup_admin_page_after_content` (Dashboard extension point; the multisite module renders its backup history here).

---

## 3. Directory Layout

```
swish-migrate-and-backup/
├── swish-migrate-and-backup.php   # Main file, constants, autoloader, bootstrap
├── uninstall.php                  # Guarded cleanup: options, transients, user meta, 7 tables, cron
├── extract-swish.php              # Standalone CLI .swish extractor (PHP_SAPI check, traversal-safe)
├── readme.txt                     # wp.org readme (stable tag matches plugin version)
├── .phpcs.xml.dist                # WPCS ruleset (PHP 8.1+, WP 6.0+, documented exclusions)
├── composer.json                  # PSR-4 autoload src/ → SwishMigrateAndBackup\ ; dev: phpunit, wpcs
├── package.json                   # @wordpress/scripts build for src/js → build/
├── src/                           # PHP (PSR-4)
├── src/js/                        # React dashboard source (index.js, components/, api/, styles.css)
├── build/                         # Compiled React app (index.js, index.css, index.asset.php)
└── assets/
    ├── js/admin.js                # Legacy jQuery admin app (non-dashboard pages)
    ├── js/pro-admin.js            # Multisite admin JS (jQuery; AJAX to multisite handlers)
    └── css/admin.css, pro-admin.css (+ -variables, -components imported by it)
```

---

## 4. Database Schema (7 custom tables, `dbDelta`)

Site-prefixed (`$wpdb->prefix`):
1. **`swish_backup_jobs`** — id PK, job_id varchar(64) UNIQUE, type ('full'|'database'|'files'), status ('pending'|'processing'|'completed'|'failed'), progress decimal(5,2), started_at, completed_at, file_path varchar(512), file_size bigint, checksum varchar(64), manifest longtext, error_message text, steps_log longtext, size_limit_exceeded tinyint, created_at. Keys: status, type, created_at, size_limit_exceeded.
2. **`swish_backup_logs`** — id, job_id, level varchar(16), message text, context longtext, created_at. Keys: job_id, level, created_at.
3. **`swish_backup_schedules`** — id, name, frequency, backup_type, storage_destinations varchar(512), retention_count int (default 5), next_run, last_run, is_active tinyint, options longtext, created_at. Keys: frequency, is_active, next_run.
4. **`swish_backup_state`** — file-based checkpoint rows for resumable pipelines (created by `BackupState::create_table()`).
5. **`swish_file_queue`** — chunked file-processing queue (created by `FileQueue::create_table()`); has `updated_at` index for stale-row queries.

Network-scoped (**`$wpdb->base_prefix`** — required so subsite contexts read the same tables):
6. **`swish_backup_multisite_jobs`** — id, job_id UNIQUE, network_id, site_ids text (JSON), archive_mode ('single'|'separate'), total_sites, completed_sites, backup_files text (JSON), status, created_at, completed_at. Keys: status, network_id, created_at.
7. **`swish_backup_site_backups`** — id, parent_job_id, site_id, site_url, archive_path varchar(512), file_size, status, error_message, started_at, completed_at. Keys: parent_job_id, site_id, status.

Schema versions: `swish_backup_db_version` (core, `Activator::DB_VERSION`, currently 1.0.4) and `swish_backup_pro_db_version` (multisite, currently 1.0.1 — option name retained from the Pro add-on for in-place upgrades). Because plugin updates do not fire the activation hook, `Activator::maybe_upgrade_database()` (static) is hooked on `init` and re-runs the `dbDelta` table creation whenever the stored version differs from `Activator::DB_VERSION` — bump that constant whenever any CREATE TABLE changes. `MultisiteModule::maybe_upgrade_database()` additionally self-heals missing multisite tables via `Activator::create_multisite_tables()` (static).

### Options / transients / user meta / cron

- Options: `swish_backup_settings` (default_storage, compression_level, chunk_size, exclusions, backup_* toggles, notification settings), `swish_backup_directory` (default `WP_CONTENT_DIR . '/swish-backups'`), `swish_backup_encryption_key`, `swish_backup_db_version`, `swish_backup_pro_db_version`, `swish_backup_pro_settings` (archive_mode_default, multisite_enabled), `swish_backup_storage_{local|s3|dropbox|googledrive}`, `swish_backup_job_queue`.
- Transients: `swish_backup_download_{md5(path)}` (download token, see §8), `swish_backup_progress_{job_id}`, `swish_backup_params_{job_id}`, `swish_import_job_{job_id}`, `swish_duplicate_params_{job_id}`, `swish_duplicate_lock_{job_id}`, `swish_backup_activated`.
- User meta: `swish_backup_theme` ('light'|'dark', per-user multisite UI theme).
- Cron hooks: `swish_backup_scheduled_backup`, `swish_backup_cleanup` (daily), `swish_backup_process_async`, `swish_backup_continue`, and single-event `swish_backup_run_multisite_backup`, `swish_backup_run_import`, `swish_backup_run_duplicate_site`. Custom intervals: `swish_backup_weekly`, `swish_backup_monthly`.
- `uninstall.php` (guarded by `WP_UNINSTALL_PLUGIN` + `delete_plugins` cap) removes all of the above plus drops all 7 tables; backup files themselves are intentionally NOT deleted (commented opt-in block).

### Backup directory

`wp-content/swish-backups/` (+ `temp/`, `logs/`, `imports/`), protected by deny-all `.htaccess` and `index.php`. Archives must never be linked directly — always via tokenized download (§8).

---

## 5. The `.swish` Archive Format

Custom streaming format optimized for append/resume on shared hosting.

- Sequence of records: **4377-byte header** + raw file content. Header layout (null-padded ASCII fields):
  - bytes 0–254 (255): file name
  - bytes 255–268 (14): file size in bytes (decimal string)
  - bytes 269–280 (12): mtime (unix decimal string)
  - bytes 281–4376 (4096): directory prefix (path relative to site root, no leading slash)
- Full relative path = `prefix ? prefix . '/' . name : name`.
- **EOF marker**: one all-null 4377-byte header terminates the archive; written by `SwishArchiver::close(true)`; `is_valid()` verifies its presence (archives without it are treated as incomplete).
- Extraction reads headers sequentially, streams content in 512 KB chunks. Extractors MUST reject entries whose normalized path starts with `/` or contains `..` segments (path traversal).
- `SwishCompressor` wraps optional compression; `SwishExtractor` performs chunked, resumable extraction inside WordPress; `extract-swish.php` is the dependency-free CLI equivalent.

---

## 6. Backup Pipeline

Two engines exist, both producing genuine `.swish` archives (§5):

**A. `Backup\StreamingTarBackup`** (name is historical; it writes swish headers, not tar) — the engine behind the dashboard/REST/async flow. `BackupManager::run_full_backup()` always delegates to `run_full_backup_streaming()`: chunked DB dump (25 s slices) → file scan (`FileBackup::prepare_file_list()`, which **dedupes by absolute path** because the ABSPATH/core scan overlaps the plugin/theme/upload scans) → special files first (`manifest.json`, `database.sql`, `wp-config.php`) → streamed archive writing in 10 s slices, resumable from exact file + byte offset via `BackupState`, EOF marker on finalize. Continuations run via cron `swish_backup_continue` with a status-poll fallback.
- **Concurrency**: every job is serialized with a MySQL named lock (`GET_LOCK('swish_backup_job_{id}')`) acquired by `process_async_backup()` and `continue_backup()`; duplicate cron spawns / poll fallbacks exit quietly instead of truncating the archive being written. `continue_backup()` also exits quietly for jobs already in a terminal state, and `fail_job()` never downgrades a `completed`/`cancelled` job.
- **Sync callers** (WP-CLI `--legacy`, pre-migration safety backups, REST `async=false`): `create_full_backup()` starts the same streaming job and drives `continue_backup()` in-process until the job reaches a terminal state.
- Finalization sanity-checks that the archive file exists before marking the job complete (fails the job with a clear error otherwise).

**B. `Backup\BackupPipeline`** phase machine (WP-CLI default): `INIT → INDEXING → PROCESSING → FINALIZING → COMPLETE | FAILED`.

- **Indexing**: enumerate all files fully before archiving (no time-based aborts during enumeration; only memory pressure yields) into the `swish_file_queue` table.
- **Processing**: pop file batches from the queue, append to the archive; per-request hard time budget (~15 s default browser mode; 60% of `max_execution_time` adaptive), memory threshold 75%; work is chained via AJAX (`swish_process_backup`) / loopback / cron (`swish_backup_continue`) until done. CLI mode disables time yielding and uses 500-file batches.
- **Finalizing**: write manifest, write `.swish` EOF marker, verify, register the backup row (CLI path also calls `BackupManager::register_backup()` so CLI backups appear in the UI), fire `swish_backup_after`.
- `ServerLimits` detects managed hosts (WP Engine, Kinsta, Flywheel…) and tunes batch sizes.
- Manifest records site URL, WP version, table list, counts, and `is_multisite()` flag.

**Import pipeline** (`Import\ImportPipeline`) phases: `validate → extract → enumerate → preserve → content → database → database_critical → url_replace → finalize → cleanup` (11 including init), resumable via `ImportSession`; chained through `swish_import_continue` AJAX; progress exposes `phase_label` + human-readable detail strings. After DB import it re-activates this plugin in `active_plugins` and verifies table prefix.

**Export system** (`Export\`): streaming export chained through `swish_export_start` (nonce + `manage_options`) and `swish_export_process` (also `nopriv`; intentionally tolerates expired nonces because chains outlive nonce lifetime — the effective gate is the server-generated UUIDv4 job id, validated against existing job state before any work; unknown ids → 404).

---

## 7. Multisite Subsystem

Bootstrapped by `Multisite\MultisiteModule` (registered in the container, booted from `Plugin::init_hooks()`).

### Components

- **MultisiteDetector**: `is_multisite()`, `get_network_sites()` (via `get_sites(['number'=>9999])`), `get_shared_tables()` vs `get_site_tables(id)`, `get_site_prefix(id)` (`base_prefix` for site 1, `base_prefix . {id} . '_'` otherwise), `get_site_uploads_path(id)` (uses `switch_to_blog`/`restore_current_blog`), `estimate_site_size(id)` (information_schema, prepared), `get_network_info()`.
  - Shared tables: users, usermeta, blogs, blogmeta, site, sitemeta, sitecategories, registration_log, signups.
- **MultisiteManager**: orchestrates jobs. `backup_network()`, `backup_sites(ids, opts)`, `schedule_background_backup()` (writes job row + `swish_backup_params_{job}` transient + single cron event), `run_scheduled_backup()`, `get_job_progress()` (transient `swish_backup_progress_{job}`), `get_multisite_backups()`, `delete_multisite_backup()`, `get_backup_download_url(filename)` → **delegates to `LocalAdapter::get_download_url()`** (signed, expiring; never a direct `content_url`).
- **NetworkBackup**: does the work. Single-archive mode: optional core files → manifest.json → shared tables SQL → per-site SQL dumps (via `switch_to_blog`) → optional wp-content folders → one **`.swish` archive** (built with `Backup\SwishArchiver`, timeout 0 = no time slicing, EOF marker on close; the staging dir is normalized with `realpath()` before computing relative entry paths). Separate mode: shared pieces once, then per-site `.swish` archives each containing shared data + that site's DB + per-site manifest. Progress callbacks `(job_id, percent, step, message)` with dynamically computed phase ranges. Temp dir: `sys_get_temp_dir() . '/swish-backup-' . $job_id`. Backup filenames: `{host}-multisite-{mode}-{Y-m-d-His}.swish`, `{sitename}-site-{id}-{timestamp}.swish`.
- **MultisiteMigration**: `validate_backup(path, original_name)` accepts `.swish` and legacy `.zip` (extension gate on the original filename; format detected by content — ZIP magic bytes — via `read_manifest_from_archive()`, so extension-less uploaded temp files work). Detects pro-format `backup_type: 'multisite'` and free-format `multisite: true`; multisite backup on single-site WP → `requires_site_selection` + `available_sites`. `import_backup(path, options)` (options: `search_replace` map, `import_shared_tables`, `confirm_conversion`, `import_as_single_site`, `site_id`), `start_import_async()` (transient `swish_import_job_{id}` + cron). Outer extraction branches by content: `.swish` → `SwishExtractor::extract_all()` (which itself rejects absolute/`..` entry paths); ZIP → `safe_extract_zip()`: lexical rejection of absolute/`..` entries BEFORE any mkdir, then realpath containment, streamed file extraction. Nested legacy payloads (`files.zip`, `files-*.zip`, `files*.tar.gz`) inside old archives are still handled. Reuses free-plugin `Migration\SearchReplace` for URL preview/dry-run. `get_available_backups()` globs `wp-content/swish-backups` for `.swish` and `.zip`. User-supplied `existing_backup` paths are resolved with realpath and must live inside the backups directory.
- **SiteDuplicator**: `validate_duplication(source_id, slug)` (site exists; slug format; reserved slugs www/web/root/admin/main/invite/administrator/files; exact domain+path uniqueness via prepared `{$wpdb->blogs}` query), `schedule_duplicate_job()` (transient params + lock `swish_duplicate_lock_{id}` + cron), `run_duplicate_job()` (create site, copy tables, optionally copy uploads, URL replace, finalize). Debug logging to `swish-backups/duplicate-log.log` only when `WP_DEBUG`.
- **ArchiveMode**: constants `single`/`separate`, validation, defaults, `get_recommended_mode(count)` (separate for >10 sites).

### Multisite manifest JSON

```json
{
  "version": "<plugin version>", "backup_type": "multisite",
  "archive_mode": "single|separate", "include_core_files": bool,
  "created_at": "Y-m-d H:i:s",
  "network": { "network_id": 1, "domain": "", "path": "/", "site_count": n,
               "is_subdomain": bool, "base_prefix": "wp_" },
  "sites": [ { "site_id": 1, "site_url": "", "site_name": "", "domain": "",
               "path": "/", "is_main": bool, "table_prefix": "wp_",
               "tables": [...], "uploads_path": "" } ],
  "shared_tables": [...], "shared_database_file": "network-shared.sql",
  "wordpress_version": "6.x"
}
```

### Menus & pages

- Multisite (network admin, cap `manage_network`, prio 100): top-level `swish-backup-multisite` (Create Backup + dashboard tabs) with submenus `swish-backup-migration`, `swish-backup-duplicate`. Pages render via `AdminLayout::render_start/render_header/…/render_end`, Material-Symbols icons, light/dark theme from user meta.
- Single-site (cap `manage_options`): submenu `swish-backup-pro-migration` under `swish-backup` (slug retained for URL stability; menu label "Multisite Import") to import multisite backups. The multisite UI header badge reads "Multisite" (`.swish-pro-tag`); there is no PRO badge anywhere post-merge.
- Core admin (cap `manage_options`): top-level `swish-backup` (Dashboard, position 80, `dashicons-cloud-saved`) with submenus `swish-backup-backups`, `-schedules`, `-migration`, `-settings`, `-logs`, `-docs`.

### AJAX contract (all `wp_ajax_`, nonce action `swish_backup_pro_nonce`, no nopriv)

Backup: `swish_backup_start_multisite_backup`, `…_start_multisite_backup_async`, `…_check_progress`, `…_delete_multisite_backup`.
Migration: `…_validate_multisite_import`, `…_import_multisite`, `…_import_multisite_async`, `…_check_import_progress`, `…_preview_search_replace`.
Duplication: `…_duplicate_site_async`, `…_check_duplicate_progress`, `…_delete_site`, `…_check_slug_available`.
Misc: `…_save_theme` (nonce + `read` cap; writes own user meta).
Capabilities: `manage_network` for network operations; import/validate handlers accept `manage_network` OR `manage_options` (single-site conversion path). Progress pollers also opportunistically trigger stuck pending jobs directly (WP-Cron-less hosts), guarded by run locks.

### Assets

`admin_enqueue_scripts` on hooks containing `swish-backup`/`swish-multisite`/`swish-migration`: Google Fonts (Inter + Material Symbols — external CDN, a known wp.org/GDPR consideration), `assets/css/pro-admin.css` (imports `-variables` and `-components`), `assets/js/pro-admin.js` (jQuery), localized object `swishBackupPro = { version, isMultisite, nonce, ajaxUrl, theme }`.

---

## 8. Downloads (tokenized)

`LocalAdapter::get_download_url(path, expiry)` generates `wp_generate_password(32)` token, stores `swish_backup_download_{md5(path)}` transient `{token, path, expiry}`, returns `home_url()?swish_download={token}&file={path}`. `Plugin::handle_backup_download()` (on `init`, works logged-out): validates token via `hash_equals`, checks expiry, realpath-contains the file inside the backup dir, then serves via X-Sendfile/X-Accel-Redirect if available else chunked PHP streaming with HTTP Range support (resumable, curl/aria2c-friendly). Multisite history UI uses these URLs (24 h expiry).

---

## 9. REST API (`swish-backup/v1`)

Core (`RestController`, permission `manage_options`, REST nonce `wp_rest`):
`POST /backup` (type, async, batch sizes, exclusions), `GET /backups`, `GET|DELETE /backup/{id}`, `GET /backup/{id}/download` (returns tokenized URL, 24 h), `POST /restore`, `POST /import` (upload or `server_path`, whitelisted to backup dirs), `GET /import/list`, `POST /migrate`, `POST /search-replace` (dry_run supported), `POST /storage/test`, `GET /job/{id}`, `POST /job/{id}/process`, `POST /job/{id}/cancel`, `GET|POST /settings`.

Multisite (`MultisiteRestController`; permission `manage_network` on multisite, `manage_options` on single site):
`GET /pro/multisite/sites`, `POST /pro/multisite/backup` (site_ids[], archive_mode enum single|separate, options), `GET /pro/multisite/backup/{job_id}/status`, `POST /pro/multisite/estimate`.

`rest_authentication_errors` filter (prio 100) bypasses cookie auth ONLY for `/swish-backup/v1/import/` endpoints, which use their own secret-key auth designed to survive a database restore.

---

## 10. Frontend

- **React dashboard** (`src/js/`, built with `@wordpress/scripts` → `build/`): components App, Dashboard, BackupList, MigrationPanel, ProgressModal; uses `@wordpress/{element,components,api-fetch,i18n}`; enqueued only on the top-level dashboard page with localized `swishBackupData = { apiUrl, nonce, isProActive (default true), backupsPageUrl, settingsPageUrl }`. Build: `npm run build`.
- **Legacy admin.js** (jQuery) on other pages: backup start/poll UI (localStorage-persisted active jobs), restore/download/delete, CLI download commands, bulk ops, import upload with progress, migration log. Localized `swishBackup = { apiUrl, nonce, isProActive, backupsPageUrl, maxUploadSize(+Formatted), postMaxSize(+Formatted), i18n{...} }`. All server/manifest-derived strings are HTML-escaped via a local `escapeHtml()` before `.html()` insertion.
- **pro-admin.js** (jQuery) for multisite pages: site selector, progress modal (`SwishProgressModal.startBackup(siteIds, archiveMode, options)`), history actions, duplicate form with live slug availability, import wizard; talks to the AJAX contract in §7 using `swishBackupPro.nonce`.

---

## 11. Storage Adapter Interface

`Storage\Contracts\StorageAdapterInterface`: `upload()`, `download()`, `delete()`, `list_files()`, `get_file_info()`, `get_download_url(path, expiry)`, `get_storage_info()`, `test_connection()`, plus identity/config methods; `AbstractStorageAdapter` provides settings access (per-adapter option `swish_backup_storage_{key}`, credentials encrypted via `Security\Encryption`). `StorageManager` registers adapters in the container and fires `swish_backup_storage_registered` so third parties can add adapters. LocalAdapter base dir = `backup_path` setting or `WP_CONTENT_DIR . '/swish-backups'`.

---

## 12. WP-CLI

- `wp swish backup [--type=full|database|files] [--include-core] [--legacy]` — pipeline backup with CLI-optimized batching; registers the finished backup in the jobs table (visible in admin immediately).
- `wp swish import <file> [--old-url=… --new-url=…] [--skip-url-replace]`
- `wp swish status`, `wp swish cleanup`.

---

## 13. Security Model (invariants to preserve)

1. Every admin AJAX handler: nonce (`check_ajax_referer`) + capability check; every REST route: capability permission callback.
2. All superglobal reads sanitized (`sanitize_text_field(wp_unslash())`, `absint`, `sanitize_title`, `sanitize_file_name`).
3. All output escaped (`esc_html`, `esc_attr`, `esc_url`; `esc_js`/`echo esc_js(__())` inside inline scripts; JS-side `escapeHtml()` for API-derived strings).
4. SQL: `$wpdb->prepare()` for all values; interpolated table names only from internal lists/`$wpdb` properties, annotated with `phpcs:ignore` justifications. Multisite job tables always via `$wpdb->base_prefix`.
5. Path containment everywhere user input meets the filesystem: realpath + prefix check against the backups dir (imports, downloads); archive extraction rejects absolute/`..` entries before creating directories.
6. Backup archives are never web-exposed: deny-all `.htaccess` + tokenized, expiring, `hash_equals`-verified download URLs.
7. Uninstall guarded by `WP_UNINSTALL_PLUGIN` and `delete_plugins`.
8. Debug file logging gated behind `WP_DEBUG`.
9. `extract-swish.php` refuses non-CLI SAPIs.
10. **SQL dump writers must strip the wpdb placeholder escape.** `$wpdb->_real_escape()` (and `esc_sql()`/`prepare()` output) replaces every literal `%` with a per-request `{64-hex}` token that core only removes when the query executes via `wpdb::query()` — never in text written to a file. Any value escaped for dump/export output must be wrapped in `$wpdb->remove_placeholder_escape()` (DatabaseBackup, ExportController, NetworkBackup ×2), otherwise the token is persisted into the backup and corrupts serialized data on restore (v1.3.1 fix).

---

## 14. Coding Standards

- WordPress Coding Standards via `.phpcs.xml.dist` (PHP 8.1+, WP 6.0+, text domain enforced). `declare(strict_types=1)`, typed properties/returns, `final` classes, PSR-4 file naming (WPCS filename sniffs relaxed accordingly).
- Every PHP file: ABSPATH guard (`if ( ! defined( 'ABSPATH' ) ) { exit; }`).
- Documented deviations: direct filesystem streaming instead of `WP_Filesystem` (required for large-archive performance); `@` suppression on best-effort cleanup ops; direct DB queries for custom tables (no caching layer applies).
- i18n: all user-facing strings wrapped with the `swish-migrate-and-backup` text domain; translations auto-loaded from wp.org (no manual `load_plugin_textdomain`).

## 15. Legacy Pro Add-on Compatibility

The plugin absorbs `swish-migrate-and-backup-pro` (last v1.1.1, namespace `SwishMigrateAndBackupPro\`, zero licensing code). Compatibility rules:
- Detect it via `defined('SWISH_BACKUP_PRO_VERSION')` at `plugins_loaded` (it defines this at file scope). If present: skip booting the built-in MultisiteModule for that request (the add-on registers identical hooks; booting both would double-run every cron job) and auto-deactivate it on `admin_init` with an informational notice.
- Reuse its options (`swish_backup_pro_db_version`, `swish_backup_pro_settings`) and both multisite tables in place — existing job history must survive.
- Keep stable for JS/back-compat: AJAX action names, nonce action `swish_backup_pro_nonce`, localized object name `swishBackupPro`, REST route prefix `/pro/multisite/`, single-site submenu slug `swish-backup-pro-migration`, filters `swish_backup_is_pro`/`swish_backup_has_multisite`/`swish_backup_plugin_info`.
