<?php
/**
 * Storage Adapter Interface.
 *
 * @package SwishMigrateAndBackup\Storage\Contracts
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage\Contracts;

/**
 * Interface for storage adapters.
 *
 * All storage adapters must implement this interface to ensure
 * consistent behavior across different storage backends.
 */
interface StorageAdapterInterface {

	/**
	 * Get the unique identifier for this adapter.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Get the display name for this adapter.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Check if the adapter is configured and ready to use.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Test the connection to the storage backend.
	 *
	 * @return bool True if connection successful.
	 */
	public function connect(): bool;

	/**
	 * Upload a file to the storage backend.
	 *
	 * @param string $local_path   Path to the local file.
	 * @param string $remote_path  Destination path on the storage backend.
	 * @return bool True if upload successful.
	 */
	public function upload( string $local_path, string $remote_path ): bool;

	/**
	 * Upload a file in chunks for large files.
	 *
	 * @param string   $local_path   Path to the local file.
	 * @param string   $remote_path  Destination path on the storage backend.
	 * @param int      $chunk_size   Size of each chunk in bytes.
	 * @param callable $progress_callback Optional callback for progress updates.
	 * @return bool True if upload successful.
	 */
	public function upload_chunked(
		string $local_path,
		string $remote_path,
		int $chunk_size = 5242880,
		?callable $progress_callback = null
	): bool;

	/**
	 * Download a file from the storage backend.
	 *
	 * @param string $remote_path Path to the file on the storage backend.
	 * @param string $local_path  Destination path on the local filesystem.
	 * @return bool True if download successful.
	 */
	public function download( string $remote_path, string $local_path ): bool;

	/**
	 * Delete a file from the storage backend.
	 *
	 * @param string $remote_path Path to the file on the storage backend.
	 * @return bool True if deletion successful.
	 */
	public function delete( string $remote_path ): bool;

	/**
	 * List files in a directory on the storage backend.
	 *
	 * @param string $path Directory path to list.
	 * @return array Array of file information arrays.
	 */
	public function list( string $path = '' ): array;

	/**
	 * Check if a file exists on the storage backend.
	 *
	 * @param string $remote_path Path to the file.
	 * @return bool True if file exists.
	 */
	public function exists( string $remote_path ): bool;

	/**
	 * Get metadata for a file.
	 *
	 * @param string $remote_path Path to the file.
	 * @return array|null File metadata or null if not found.
	 */
	public function get_metadata( string $remote_path ): ?array;

	/**
	 * Get a signed/temporary URL for downloading a file.
	 *
	 * @param string $remote_path Path to the file.
	 * @param int    $expiry      URL expiry time in seconds.
	 * @return string|null Signed URL or null if not supported.
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string;

	/**
	 * Get storage usage information.
	 *
	 * @return array Array with 'used' and 'total' bytes (if available).
	 */
	public function get_storage_info(): array;

	/**
	 * Get configuration fields for the admin UI.
	 *
	 * @return array Array of field definitions.
	 */
	public function get_settings_fields(): array;

	/**
	 * Save configuration settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool True if saved successfully.
	 */
	public function save_settings( array $settings ): bool;

	/**
	 * Get current configuration settings.
	 *
	 * @return array Current settings.
	 */
	public function get_settings(): array;
}
