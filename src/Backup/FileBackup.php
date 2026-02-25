<?php
/**
 * File Backup Handler.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Handles file backup operations with chunked processing.
 */
final class FileBackup {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Files/patterns to exclude.
	 *
	 * @var array
	 */
	private array $exclude_patterns = array();

	/**
	 * Maximum files per batch.
	 *
	 * @var int
	 */
	private int $files_per_batch = 100;

	/**
	 * Default files per batch.
	 */
	private const DEFAULT_FILES_PER_BATCH = 100;

	/**
	 * Whether to include WordPress core files.
	 *
	 * @var bool
	 */
	private bool $include_core = false;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;

		// Set default exclusions.
		$this->exclude_patterns = array(
			'*.log',
			'*.tmp',
			'*.swp',
			'.git',
			'.svn',
			'node_modules',
			'vendor',
			'wp-content/cache',
			'swish-backups',
			'wp-content/debug.log',
			'error_log',
		);
	}

	/**
	 * WordPress core files and directories to exclude.
	 *
	 * @return array
	 */
	private function get_wp_core_patterns(): array {
		return array(
			// Core directories.
			'wp-admin',
			'wp-includes',
			// Core root files.
			'index.php',
			'license.txt',
			'readme.html',
			'wp-activate.php',
			'wp-blog-header.php',
			'wp-comments-post.php',
			'wp-config-sample.php',
			'wp-cron.php',
			'wp-links-opml.php',
			'wp-load.php',
			'wp-login.php',
			'wp-mail.php',
			'wp-settings.php',
			'wp-signup.php',
			'wp-trackback.php',
			'xmlrpc.php',
		);
	}

	/**
	 * Set whether to include WordPress core files.
	 *
	 * @param bool $include Whether to include core files.
	 * @return self
	 */
	public function set_include_core( bool $include ): self {
		$this->include_core = $include;
		return $this;
	}

	/**
	 * Get whether WordPress core files are included.
	 *
	 * @return bool
	 */
	public function get_include_core(): bool {
		return $this->include_core;
	}

	/**
	 * Set exclude patterns.
	 *
	 * @param array $patterns Patterns to exclude.
	 * @return self
	 */
	public function set_exclude_patterns( array $patterns ): self {
		$this->exclude_patterns = array_merge( $this->exclude_patterns, $patterns );
		return $this;
	}

	/**
	 * Set the number of files per batch.
	 *
	 * @param int $files_per_batch Files per batch (25-500).
	 * @return self
	 */
	public function set_files_per_batch( int $files_per_batch ): self {
		$this->files_per_batch = max( 25, min( 500, $files_per_batch ) );
		return $this;
	}

	/**
	 * Get the current files per batch setting.
	 *
	 * @return int
	 */
	public function get_files_per_batch(): int {
		return $this->files_per_batch;
	}

	/**
	 * Get list of files to backup.
	 *
	 * @param array $directories Directories to scan.
	 * @return array List of file paths.
	 */
	public function get_file_list( array $directories ): array {
		$files = array();

		foreach ( $directories as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$directory,
					RecursiveDirectoryIterator::SKIP_DOTS
				),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				$path = $file->getPathname();

				if ( $this->should_exclude( $path ) ) {
					continue;
				}

				if ( $file->isFile() && $file->isReadable() ) {
					$files[] = array(
						'path'     => $path,
						'relative' => $this->get_relative_path( $path ),
						'size'     => $file->getSize(),
						'modified' => $file->getMTime(),
					);
				}
			}
		}

		return $files;
	}

	/**
	 * Get default directories to backup.
	 *
	 * @param array $options Backup options.
	 * @return array Directories to backup.
	 */
	public function get_backup_directories( array $options = array() ): array {
		$directories = array();

		// WordPress core files - excluded by default.
		// Target sites already have WordPress installed.
		$this->include_core = $options['backup_core_files'] ?? false;

		if ( $this->include_core ) {
			$directories[] = ABSPATH;
		}

		// Plugins.
		if ( $options['backup_plugins'] ?? true ) {
			$directories[] = WP_PLUGIN_DIR;
		}

		// Themes.
		if ( $options['backup_themes'] ?? true ) {
			$directories[] = get_theme_root();
		}

		// Uploads.
		if ( $options['backup_uploads'] ?? true ) {
			$upload_dir = wp_upload_dir();
			$directories[] = $upload_dir['basedir'];
		}

		// Custom directories.
		if ( ! empty( $options['custom_directories'] ) ) {
			$directories = array_merge( $directories, $options['custom_directories'] );
		}

		return array_unique( array_filter( $directories, 'is_dir' ) );
	}

	/**
	 * Create a backup of specified files.
	 *
	 * @param array         $files             Files to backup.
	 * @param string        $output_path       Output zip file path.
	 * @param callable|null $progress_callback Progress callback.
	 * @return bool True if successful.
	 */
	public function backup( array $files, string $output_path, ?callable $progress_callback = null ): bool {
		$this->logger->info( 'Starting file backup', array( 'file_count' => count( $files ) ) );

		try {
			$zip = new \ZipArchive();
			$result = $zip->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

			if ( true !== $result ) {
				$this->logger->error( 'Failed to create zip archive', array( 'error' => $result ) );
				return false;
			}

			$total_files = count( $files );
			$processed = 0;

			foreach ( $files as $file ) {
				$result = $zip->addFile( $file['path'], $file['relative'] );

				if ( ! $result ) {
					$this->logger->warning( 'Failed to add file to archive', array( 'file' => $file['path'] ) );
				}

				++$processed;

				if ( $progress_callback && 0 === $processed % 50 ) {
					$progress = (int) ( ( $processed / $total_files ) * 100 );
					$progress_callback( $progress, $file['relative'], $processed, $total_files );
				}

				// Prevent memory exhaustion.
				if ( 0 === $processed % 500 ) {
					$zip->close();
					$zip->open( $output_path );
				}
			}

			$zip->close();

			$this->logger->info( 'File backup completed', array(
				'files' => $total_files,
				'size'  => filesize( $output_path ),
			) );

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error( 'File backup failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Backup files in chunks for resumable processing.
	 *
	 * @param array  $files       Files to backup.
	 * @param string $output_path Output zip file path.
	 * @param int    $chunk_index Current chunk index.
	 * @param int    $chunk_size  Number of files per chunk.
	 * @return array Result with 'completed', 'next_chunk', 'total_chunks'.
	 */
	public function backup_chunk(
		array $files,
		string $output_path,
		int $chunk_index = 0,
		int $chunk_size = 100
	): array {
		$total_files = count( $files );
		$total_chunks = (int) ceil( $total_files / $chunk_size );
		$start = $chunk_index * $chunk_size;
		$chunk_files = array_slice( $files, $start, $chunk_size );

		if ( empty( $chunk_files ) ) {
			return array(
				'completed'    => true,
				'next_chunk'   => null,
				'total_chunks' => $total_chunks,
				'processed'    => $total_files,
			);
		}

		try {
			$zip = new \ZipArchive();

			// First chunk creates new archive, subsequent chunks append.
			$mode = 0 === $chunk_index
				? \ZipArchive::CREATE | \ZipArchive::OVERWRITE
				: \ZipArchive::CREATE;

			$result = $zip->open( $output_path, $mode );

			if ( true !== $result ) {
				throw new \RuntimeException( 'Failed to open zip archive' );
			}

			foreach ( $chunk_files as $file ) {
				$zip->addFile( $file['path'], $file['relative'] );
			}

			$zip->close();

			$next_chunk = $chunk_index + 1;
			$is_completed = $next_chunk >= $total_chunks;

			return array(
				'completed'    => $is_completed,
				'next_chunk'   => $is_completed ? null : $next_chunk,
				'total_chunks' => $total_chunks,
				'processed'    => min( $start + $chunk_size, $total_files ),
			);
		} catch ( \Exception $e ) {
			$this->logger->error( 'Chunk backup failed: ' . $e->getMessage() );
			return array(
				'completed'    => false,
				'error'        => $e->getMessage(),
				'next_chunk'   => $chunk_index,
				'total_chunks' => $total_chunks,
				'processed'    => $start,
			);
		}
	}

	/**
	 * Get file list for backup with state tracking.
	 *
	 * @param array $options Backup options.
	 * @return array File list with metadata.
	 */
	public function prepare_file_list( array $options = array() ): array {
		$directories = $this->get_backup_directories( $options );

		if ( ! empty( $options['exclude_files'] ) ) {
			$this->set_exclude_patterns( $options['exclude_files'] );
		}

		$files = $this->get_file_list( $directories );
		$total_size = array_sum( array_column( $files, 'size' ) );

		return array(
			'files'      => $files,
			'count'      => count( $files ),
			'total_size' => $total_size,
			'directories' => $directories,
		);
	}

	/**
	 * Check if a path should be excluded.
	 *
	 * @param string $path File path.
	 * @return bool True if should be excluded.
	 */
	private function should_exclude( string $path ): bool {
		$normalized_path = str_replace( '\\', '/', $path );
		$abspath = str_replace( '\\', '/', ABSPATH );

		// Exclude WordPress core files/directories unless explicitly included.
		if ( ! $this->include_core ) {
			foreach ( $this->get_wp_core_patterns() as $core_pattern ) {
				$core_path = $abspath . $core_pattern;

				// Check if path is the core file/directory or inside a core directory.
				if ( strpos( $normalized_path, $core_path ) === 0 ) {
					return true;
				}
			}
		}

		foreach ( $this->exclude_patterns as $pattern ) {
			// Check if it's a glob pattern.
			if ( strpos( $pattern, '*' ) !== false ) {
				if ( fnmatch( $pattern, basename( $path ) ) ) {
					return true;
				}
				if ( fnmatch( '*/' . $pattern, $normalized_path ) ) {
					return true;
				}
			} else {
				// Exact match or path contains pattern.
				if ( strpos( $normalized_path, '/' . $pattern . '/' ) !== false ) {
					return true;
				}
				if ( str_ends_with( $normalized_path, '/' . $pattern ) ) {
					return true;
				}
				// Check if basename matches (for directory names like 'swish-backups').
				if ( basename( $normalized_path ) === $pattern ) {
					return true;
				}
			}
		}

		// Exclude files larger than 500MB by default (Pro version can override).
		$max_file_size = apply_filters( 'swish_backup_max_file_size', 500 * 1024 * 1024 );
		if ( is_file( $path ) && $max_file_size > 0 && filesize( $path ) > $max_file_size ) {
			$this->logger->warning( 'Skipping large file', array( 'path' => $path, 'size' => filesize( $path ) ) );
			return true;
		}

		return false;
	}

	/**
	 * Get relative path from WordPress root.
	 *
	 * @param string $path Absolute path.
	 * @return string Relative path.
	 */
	private function get_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$root = str_replace( '\\', '/', ABSPATH );

		if ( strpos( $path, $root ) === 0 ) {
			return substr( $path, strlen( $root ) );
		}

		return basename( $path );
	}

	/**
	 * Calculate total size of files to backup.
	 *
	 * @param array $files File list.
	 * @return int Total size in bytes.
	 */
	public function calculate_total_size( array $files ): int {
		return array_sum( array_column( $files, 'size' ) );
	}

	/**
	 * Get backup directory sizes.
	 *
	 * @return array Directory => size map.
	 */
	public function get_directory_sizes(): array {
		$sizes = array();
		$directories = array(
			'core'    => ABSPATH,
			'plugins' => WP_PLUGIN_DIR,
			'themes'  => get_theme_root(),
			'uploads' => wp_upload_dir()['basedir'],
		);

		foreach ( $directories as $name => $path ) {
			$sizes[ $name ] = $this->calculate_directory_size( $path );
		}

		return $sizes;
	}

	/**
	 * Calculate directory size recursively.
	 *
	 * @param string $directory Directory path.
	 * @return int Size in bytes.
	 */
	private function calculate_directory_size( string $directory ): int {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$size = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! $this->should_exclude( $file->getPathname() ) ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Backup special WordPress files.
	 *
	 * @param string $output_dir Output directory.
	 * @return array List of backed up files.
	 */
	public function backup_wp_config( string $output_dir ): array {
		$files = array();

		// wp-config.php (sanitized).
		$wp_config = ABSPATH . 'wp-config.php';
		if ( file_exists( $wp_config ) ) {
			$sanitized = $this->sanitize_wp_config( $wp_config );
			$output_path = $output_dir . '/wp-config.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $output_path, $sanitized );
			$files[] = $output_path;
		}

		// .htaccess.
		$htaccess = ABSPATH . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			$output_path = $output_dir . '/.htaccess';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $htaccess, $output_path );
			$files[] = $output_path;
		}

		// robots.txt.
		$robots = ABSPATH . 'robots.txt';
		if ( file_exists( $robots ) ) {
			$output_path = $output_dir . '/robots.txt';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $robots, $output_path );
			$files[] = $output_path;
		}

		return $files;
	}

	/**
	 * Sanitize wp-config.php content.
	 *
	 * Remove sensitive credentials for security.
	 *
	 * @param string $file_path Path to wp-config.php.
	 * @return string Sanitized content.
	 */
	private function sanitize_wp_config( string $file_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		// Add warning comment.
		$warning = "<?php\n/**\n * BACKUP NOTICE: Sensitive credentials have been removed.\n";
		$warning .= " * You will need to update database credentials after restore.\n */\n\n";

		// Remove sensitive constants.
		$sensitive_constants = array(
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'DB_HOST',
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		foreach ( $sensitive_constants as $constant ) {
			$content = preg_replace(
				"/define\s*\(\s*['\"]" . $constant . "['\"]\s*,\s*['\"].*?['\"]\s*\);/s",
				"define( '{$constant}', 'REPLACE_ME' );",
				$content
			);
		}

		// Remove opening PHP tag if present (we'll add our own with warning).
		$content = preg_replace( '/^<\?php\s*/i', '', $content );

		return $warning . $content;
	}
}
