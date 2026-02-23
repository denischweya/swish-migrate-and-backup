<?php
/**
 * Local Storage Adapter.
 *
 * @package SwishMigrateAndBackup\Storage
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage;

use SwishMigrateAndBackup\Storage\Contracts\AbstractStorageAdapter;
use SwishMigrateAndBackup\Logger\Logger;

/**
 * Local filesystem storage adapter.
 */
final class LocalAdapter extends AbstractStorageAdapter {

	/**
	 * Backup directory path.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		parent::__construct( $logger );
		$this->backup_dir = $this->get_backup_directory();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'local';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Local Storage', 'swish-migrate-and-backup' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return is_dir( $this->backup_dir ) && is_writable( $this->backup_dir );
	}

	/**
	 * {@inheritdoc}
	 */
	public function connect(): bool {
		if ( ! is_dir( $this->backup_dir ) ) {
			$created = wp_mkdir_p( $this->backup_dir );
			if ( ! $created ) {
				return $this->log_error( 'Failed to create backup directory', array( 'path' => $this->backup_dir ) );
			}
		}

		return is_writable( $this->backup_dir );
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload( string $local_path, string $remote_path ): bool {
		if ( ! file_exists( $local_path ) ) {
			return $this->log_error( 'Source file does not exist', array( 'path' => $local_path ) );
		}

		$destination = $this->get_full_path( $remote_path );
		$destination_dir = dirname( $destination );

		// Create destination directory if needed.
		if ( ! is_dir( $destination_dir ) ) {
			if ( ! wp_mkdir_p( $destination_dir ) ) {
				return $this->log_error( 'Failed to create destination directory', array( 'path' => $destination_dir ) );
			}
		}

		// Use WP Filesystem API when possible.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Copy the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$result = copy( $local_path, $destination );

		if ( ! $result ) {
			return $this->log_error( 'Failed to copy file', array(
				'source'      => $local_path,
				'destination' => $destination,
			) );
		}

		$this->logger->info( 'File uploaded to local storage', array(
			'source'      => $local_path,
			'destination' => $destination,
			'size'        => filesize( $destination ),
		) );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload_chunked(
		string $local_path,
		string $remote_path,
		int $chunk_size = 5242880,
		?callable $progress_callback = null
	): bool {
		if ( ! file_exists( $local_path ) ) {
			return $this->log_error( 'Source file does not exist', array( 'path' => $local_path ) );
		}

		$destination = $this->get_full_path( $remote_path );
		$destination_dir = dirname( $destination );

		if ( ! is_dir( $destination_dir ) && ! wp_mkdir_p( $destination_dir ) ) {
			return $this->log_error( 'Failed to create destination directory' );
		}

		$file_size = filesize( $local_path );
		$total_chunks = (int) ceil( $file_size / $chunk_size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$source = fopen( $local_path, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$dest = fopen( $destination, 'wb' );

		if ( ! $source || ! $dest ) {
			return $this->log_error( 'Failed to open file handles' );
		}

		$chunk_num = 0;
		while ( ! feof( $source ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $source, $chunk_size );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $dest, $chunk );

			++$chunk_num;
			if ( $progress_callback ) {
				$progress = min( 100, (int) ( ( $chunk_num / $total_chunks ) * 100 ) );
				$progress_callback( $progress, $chunk_num, $total_chunks );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $source );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $dest );

		$this->logger->info( 'File uploaded to local storage (chunked)', array(
			'destination' => $destination,
			'size'        => filesize( $destination ),
			'chunks'      => $total_chunks,
		) );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $remote_path, string $local_path ): bool {
		$source = $this->get_full_path( $remote_path );

		if ( ! file_exists( $source ) ) {
			return $this->log_error( 'Source file does not exist', array( 'path' => $source ) );
		}

		$local_dir = dirname( $local_path );
		if ( ! is_dir( $local_dir ) && ! wp_mkdir_p( $local_dir ) ) {
			return $this->log_error( 'Failed to create local directory' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$result = copy( $source, $local_path );

		if ( ! $result ) {
			return $this->log_error( 'Failed to copy file', array(
				'source'      => $source,
				'destination' => $local_path,
			) );
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( string $remote_path ): bool {
		$file_path = $this->get_full_path( $remote_path );

		if ( ! file_exists( $file_path ) ) {
			return true; // Already deleted.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$result = unlink( $file_path );

		if ( ! $result ) {
			return $this->log_error( 'Failed to delete file', array( 'path' => $file_path ) );
		}

		$this->logger->info( 'File deleted from local storage', array( 'path' => $file_path ) );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( string $path = '' ): array {
		$dir_path = $this->get_full_path( $path );

		if ( ! is_dir( $dir_path ) ) {
			return array();
		}

		$files = array();
		$iterator = new \DirectoryIterator( $dir_path );

		foreach ( $iterator as $file ) {
			if ( $file->isDot() ) {
				continue;
			}

			$relative_path = $path ? $path . '/' . $file->getFilename() : $file->getFilename();

			$files[] = array(
				'name'         => $file->getFilename(),
				'path'         => $relative_path,
				'size'         => $file->isFile() ? $file->getSize() : 0,
				'modified'     => $file->getMTime(),
				'is_directory' => $file->isDir(),
			);
		}

		// Sort by modification time, newest first.
		usort( $files, fn( $a, $b ) => $b['modified'] <=> $a['modified'] );

		return $files;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_metadata( string $remote_path ): ?array {
		$file_path = $this->get_full_path( $remote_path );

		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		return array(
			'name'     => basename( $file_path ),
			'path'     => $remote_path,
			'size'     => filesize( $file_path ),
			'modified' => filemtime( $file_path ),
			'checksum' => md5_file( $file_path ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string {
		$file_path = $this->get_full_path( $remote_path );

		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		// Generate a signed download URL.
		$token = wp_generate_password( 32, false );
		$expiry_time = time() + $expiry;

		// Store the token temporarily.
		set_transient(
			'swish_backup_download_' . md5( $remote_path ),
			array(
				'token'  => $token,
				'path'   => $remote_path,
				'expiry' => $expiry_time,
			),
			$expiry
		);

		return add_query_arg(
			array(
				'swish_download' => $token,
				'file'           => urlencode( $remote_path ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_storage_info(): array {
		$used = $this->calculate_directory_size( $this->backup_dir );

		return array(
			'used'  => $used,
			'total' => disk_total_space( $this->backup_dir ) ?: null,
			'free'  => disk_free_space( $this->backup_dir ) ?: null,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'name'        => 'backup_path',
				'label'       => __( 'Backup Directory', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Custom backup directory path (leave empty for default).', 'swish-migrate-and-backup' ),
				'default'     => '',
			),
			array(
				'name'        => 'retention_days',
				'label'       => __( 'Retention Days', 'swish-migrate-and-backup' ),
				'type'        => 'number',
				'description' => __( 'Number of days to keep backups (0 = forever).', 'swish-migrate-and-backup' ),
				'default'     => 30,
			),
		);
	}

	/**
	 * Get the backup directory path.
	 *
	 * @return string
	 */
	private function get_backup_directory(): string {
		$custom_path = $this->get_setting( 'backup_path' );

		if ( ! empty( $custom_path ) && is_dir( $custom_path ) ) {
			return rtrim( $custom_path, '/\\' );
		}

		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/swish-backups';
	}

	/**
	 * Get the full path for a remote path.
	 *
	 * @param string $remote_path Remote path.
	 * @return string Full local path.
	 */
	private function get_full_path( string $remote_path ): string {
		$remote_path = $this->normalize_path( $remote_path );
		return $this->backup_dir . ( $remote_path ? '/' . $remote_path : '' );
	}

	/**
	 * Calculate total size of a directory.
	 *
	 * @param string $directory Directory path.
	 * @return int Size in bytes.
	 */
	private function calculate_directory_size( string $directory ): int {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$size = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Get the base backup directory.
	 *
	 * @return string
	 */
	public function get_base_directory(): string {
		return $this->backup_dir;
	}
}
