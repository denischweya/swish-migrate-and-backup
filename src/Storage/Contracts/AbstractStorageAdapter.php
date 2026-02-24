<?php
/**
 * Abstract Storage Adapter.
 *
 * @package SwishMigrateAndBackup\Storage\Contracts
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage\Contracts;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Logger\Logger;

/**
 * Abstract base class for storage adapters.
 *
 * Provides common functionality that can be shared across adapters.
 */
abstract class AbstractStorageAdapter implements StorageAdapterInterface {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Option name for storing settings.
	 *
	 * @var string
	 */
	protected string $option_name;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	protected ?array $settings = null;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger      = $logger;
		$this->option_name = 'swish_backup_storage_' . $this->get_id();
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
		// Default implementation falls back to regular upload.
		// Adapters should override this for large file support.
		return $this->upload( $local_path, $remote_path );
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists( string $remote_path ): bool {
		$metadata = $this->get_metadata( $remote_path );
		return null !== $metadata;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string {
		// Default implementation returns null (not supported).
		// Adapters should override this if they support signed URLs.
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_storage_info(): array {
		return array(
			'used'  => null,
			'total' => null,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_settings(): array {
		if ( null === $this->settings ) {
			$this->settings = get_option( $this->option_name, array() );
		}
		return $this->settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_settings( array $settings ): bool {
		$sanitized = $this->sanitize_settings( $settings );
		$result    = update_option( $this->option_name, $sanitized );

		if ( $result ) {
			$this->settings = $sanitized;
			$this->logger->info(
				sprintf( 'Settings saved for %s adapter', $this->get_name() ),
				array( 'adapter' => $this->get_id() )
			);
		}

		return $result;
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $settings Settings to sanitize.
	 * @return array Sanitized settings.
	 */
	protected function sanitize_settings( array $settings ): array {
		$sanitized = array();
		$fields    = $this->get_settings_fields();

		foreach ( $fields as $field ) {
			$key = $field['name'];
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$value = $settings[ $key ];

			switch ( $field['type'] ?? 'text' ) {
				case 'checkbox':
					$sanitized[ $key ] = (bool) $value;
					break;
				case 'number':
					$sanitized[ $key ] = (int) $value;
					break;
				case 'password':
				case 'secret':
					$sanitized[ $key ] = sanitize_text_field( $value );
					break;
				default:
					$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed Setting value or default.
	 */
	protected function get_setting( string $key, $default = null ) {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Log an error and return false.
	 *
	 * @param string $message Error message.
	 * @param array  $context Additional context.
	 * @return bool Always returns false.
	 */
	protected function log_error( string $message, array $context = array() ): bool {
		$context['adapter'] = $this->get_id();
		$this->logger->error( $message, $context );
		return false;
	}

	/**
	 * Normalize a remote path.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	protected function normalize_path( string $path ): string {
		// Remove leading/trailing slashes and normalize separators.
		$path = trim( $path, '/\\' );
		$path = str_replace( '\\', '/', $path );
		return $path;
	}

	/**
	 * Get file extension from path.
	 *
	 * @param string $path File path.
	 * @return string File extension.
	 */
	protected function get_extension( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get MIME type for a file.
	 *
	 * @param string $path File path.
	 * @return string MIME type.
	 */
	protected function get_mime_type( string $path ): string {
		$extension = $this->get_extension( $path );

		$mime_types = array(
			'zip'  => 'application/zip',
			'gz'   => 'application/gzip',
			'sql'  => 'application/sql',
			'json' => 'application/json',
			'txt'  => 'text/plain',
		);

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}
}
