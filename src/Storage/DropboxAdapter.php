<?php
/**
 * Dropbox Storage Adapter.
 *
 * @package SwishMigrateAndBackup\Storage
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SwishMigrateAndBackup\Storage\Contracts\AbstractStorageAdapter;
use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Security\Encryption;

/**
 * Dropbox storage adapter.
 *
 * Implements Dropbox API v2 for file storage operations.
 */
final class DropboxAdapter extends AbstractStorageAdapter {

	/**
	 * Dropbox API base URL.
	 */
	private const API_URL = 'https://api.dropboxapi.com/2';

	/**
	 * Dropbox content API base URL.
	 */
	private const CONTENT_URL = 'https://content.dropboxapi.com/2';

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * Constructor.
	 *
	 * @param Logger     $logger     Logger instance.
	 * @param Encryption $encryption Encryption service.
	 */
	public function __construct( Logger $logger, Encryption $encryption ) {
		parent::__construct( $logger );
		$this->encryption = $encryption;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'dropbox';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Dropbox', 'swish-migrate-and-backup' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['access_token'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function connect(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			$response = $this->api_request( 'POST', '/users/get_current_account' );
			return isset( $response['account_id'] );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Dropbox connection failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload( string $local_path, string $remote_path ): bool {
		if ( ! file_exists( $local_path ) ) {
			return $this->log_error( 'Source file does not exist', array( 'path' => $local_path ) );
		}

		$file_size = filesize( $local_path );
		$remote_path = $this->format_path( $remote_path );

		// Use chunked upload for files larger than 150MB.
		if ( $file_size > 150 * 1024 * 1024 ) {
			return $this->upload_chunked( $local_path, $remote_path );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $local_path );

			$response = $this->content_request(
				'/files/upload',
				$content,
				array(
					'path'       => $remote_path,
					'mode'       => 'overwrite',
					'autorename' => false,
					'mute'       => true,
				)
			);

			if ( isset( $response['id'] ) ) {
				$this->logger->info( 'File uploaded to Dropbox', array(
					'path' => $remote_path,
					'size' => $file_size,
				) );
				return true;
			}

			return $this->log_error( 'Dropbox upload failed', array( 'response' => $response ) );
		} catch ( \Exception $e ) {
			return $this->log_error( 'Dropbox upload exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload_chunked(
		string $local_path,
		string $remote_path,
		int $chunk_size = 8388608,
		?callable $progress_callback = null
	): bool {
		$remote_path = $this->format_path( $remote_path );
		$file_size = filesize( $local_path );
		$total_chunks = (int) ceil( $file_size / $chunk_size );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $local_path, 'rb' );
			if ( ! $handle ) {
				return $this->log_error( 'Failed to open file for reading' );
			}

			// Start upload session.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$first_chunk = fread( $handle, $chunk_size );
			$response = $this->content_request(
				'/files/upload_session/start',
				$first_chunk,
				array( 'close' => false )
			);

			if ( ! isset( $response['session_id'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return $this->log_error( 'Failed to start upload session' );
			}

			$session_id = $response['session_id'];
			$offset = strlen( $first_chunk );
			$chunk_num = 1;

			if ( $progress_callback ) {
				$progress = min( 100, (int) ( ( $chunk_num / $total_chunks ) * 100 ) );
				$progress_callback( $progress, $chunk_num, $total_chunks );
			}

			// Upload remaining chunks.
			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $handle, $chunk_size );

				if ( feof( $handle ) ) {
					// Last chunk - finish the session.
					$response = $this->content_request(
						'/files/upload_session/finish',
						$chunk,
						array(
							'cursor' => array(
								'session_id' => $session_id,
								'offset'     => $offset,
							),
							'commit' => array(
								'path'       => $remote_path,
								'mode'       => 'overwrite',
								'autorename' => false,
								'mute'       => true,
							),
						)
					);
				} else {
					// Append chunk.
					$response = $this->content_request(
						'/files/upload_session/append_v2',
						$chunk,
						array(
							'cursor' => array(
								'session_id' => $session_id,
								'offset'     => $offset,
							),
							'close'  => false,
						)
					);
				}

				$offset += strlen( $chunk );
				++$chunk_num;

				if ( $progress_callback ) {
					$progress = min( 100, (int) ( ( $chunk_num / $total_chunks ) * 100 ) );
					$progress_callback( $progress, $chunk_num, $total_chunks );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			if ( isset( $response['id'] ) ) {
				$this->logger->info( 'Chunked upload completed to Dropbox', array(
					'path'   => $remote_path,
					'chunks' => $total_chunks,
				) );
				return true;
			}

			return $this->log_error( 'Failed to complete chunked upload' );
		} catch ( \Exception $e ) {
			return $this->log_error( 'Chunked upload exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $remote_path, string $local_path ): bool {
		$remote_path = $this->format_path( $remote_path );

		try {
			$response = wp_remote_post(
				self::CONTENT_URL . '/files/download',
				array(
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->get_access_token(),
						'Dropbox-API-Arg' => wp_json_encode( array( 'path' => $remote_path ) ),
					),
					'timeout' => 300,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->log_error( 'Download request failed: ' . $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return $this->log_error( 'Download failed', array( 'code' => $code ) );
			}

			$local_dir = dirname( $local_path );
			if ( ! is_dir( $local_dir ) && ! wp_mkdir_p( $local_dir ) ) {
				return $this->log_error( 'Failed to create local directory' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$result = file_put_contents( $local_path, wp_remote_retrieve_body( $response ) );

			return false !== $result;
		} catch ( \Exception $e ) {
			return $this->log_error( 'Download exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( string $remote_path ): bool {
		$remote_path = $this->format_path( $remote_path );

		try {
			$response = $this->api_request( 'POST', '/files/delete_v2', array(
				'path' => $remote_path,
			) );

			return isset( $response['metadata'] );
		} catch ( \Exception $e ) {
			// File might not exist, which is fine for delete.
			return true;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( string $path = '' ): array {
		$path = $this->format_path( $path );
		if ( empty( $path ) ) {
			$path = '';
		}

		try {
			$response = $this->api_request( 'POST', '/files/list_folder', array(
				'path'      => $path,
				'recursive' => false,
			) );

			$files = array();
			$entries = $response['entries'] ?? array();

			foreach ( $entries as $entry ) {
				$files[] = array(
					'name'         => $entry['name'],
					'path'         => $entry['path_display'],
					'size'         => $entry['size'] ?? 0,
					'modified'     => isset( $entry['server_modified'] ) ? strtotime( $entry['server_modified'] ) : 0,
					'is_directory' => 'folder' === $entry['.tag'],
				);
			}

			// Sort by modification time, newest first.
			usort( $files, fn( $a, $b ) => $b['modified'] <=> $a['modified'] );

			return $files;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Dropbox list exception: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_metadata( string $remote_path ): ?array {
		$remote_path = $this->format_path( $remote_path );

		try {
			$response = $this->api_request( 'POST', '/files/get_metadata', array(
				'path' => $remote_path,
			) );

			if ( ! isset( $response['id'] ) ) {
				return null;
			}

			return array(
				'name'     => $response['name'],
				'path'     => $response['path_display'],
				'size'     => $response['size'] ?? 0,
				'modified' => isset( $response['server_modified'] ) ? strtotime( $response['server_modified'] ) : 0,
				'hash'     => $response['content_hash'] ?? '',
			);
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string {
		$remote_path = $this->format_path( $remote_path );

		try {
			$response = $this->api_request( 'POST', '/files/get_temporary_link', array(
				'path' => $remote_path,
			) );

			return $response['link'] ?? null;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_storage_info(): array {
		try {
			$response = $this->api_request( 'POST', '/users/get_space_usage' );

			return array(
				'used'  => $response['used'] ?? null,
				'total' => $response['allocation']['allocated'] ?? null,
			);
		} catch ( \Exception $e ) {
			return parent::get_storage_info();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'name'        => 'access_token',
				'label'       => __( 'Access Token', 'swish-migrate-and-backup' ),
				'type'        => 'password',
				'description' => __( 'Your Dropbox access token. Generate one from the Dropbox App Console.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'folder_path',
				'label'       => __( 'Backup Folder', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Folder path for storing backups (e.g., /Backups/MySite).', 'swish-migrate-and-backup' ),
				'default'     => '/SwishBackups',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_settings( array $settings ): bool {
		// Encrypt access token.
		if ( ! empty( $settings['access_token'] ) ) {
			$settings['access_token'] = $this->encryption->encrypt( $settings['access_token'] );
		}

		return parent::save_settings( $settings );
	}

	/**
	 * Get the OAuth authorization URL.
	 *
	 * @param string $app_key     Dropbox app key.
	 * @param string $redirect_uri Redirect URI after authorization.
	 * @return string Authorization URL.
	 */
	public function get_auth_url( string $app_key, string $redirect_uri ): string {
		return add_query_arg(
			array(
				'client_id'     => $app_key,
				'redirect_uri'  => urlencode( $redirect_uri ),
				'response_type' => 'code',
				'token_access_type' => 'offline',
			),
			'https://www.dropbox.com/oauth2/authorize'
		);
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $code         Authorization code.
	 * @param string $app_key      Dropbox app key.
	 * @param string $app_secret   Dropbox app secret.
	 * @param string $redirect_uri Redirect URI.
	 * @return array|null Token response or null on failure.
	 */
	public function exchange_code_for_token(
		string $code,
		string $app_key,
		string $app_secret,
		string $redirect_uri
	): ?array {
		$response = wp_remote_post(
			'https://api.dropboxapi.com/oauth2/token',
			array(
				'body' => array(
					'code'         => $code,
					'grant_type'   => 'authorization_code',
					'client_id'    => $app_key,
					'client_secret' => $app_secret,
					'redirect_uri' => $redirect_uri,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['access_token'] ?? null ? $body : null;
	}

	/**
	 * Get the access token.
	 *
	 * @return string
	 */
	private function get_access_token(): string {
		$settings = $this->get_settings();
		return $this->encryption->decrypt( $settings['access_token'] ?? '' );
	}

	/**
	 * Format a path for Dropbox API.
	 *
	 * @param string $path Path to format.
	 * @return string Formatted path.
	 */
	private function format_path( string $path ): string {
		$settings = $this->get_settings();
		$folder = trim( $settings['folder_path'] ?? '/SwishBackups', '/' );
		$path = trim( $path, '/' );

		if ( empty( $path ) ) {
			return '/' . $folder;
		}

		return '/' . $folder . '/' . $path;
	}

	/**
	 * Make an API request to Dropbox.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @return array Response data.
	 * @throws \RuntimeException On API error.
	 */
	private function api_request( string $method, string $endpoint, array $data = array() ): array {
		$response = wp_remote_request(
			self::API_URL . $endpoint,
			array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => ! empty( $data ) ? wp_json_encode( $data ) : '',
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$error = $body['error_summary'] ?? 'Unknown error';
			throw new \RuntimeException( "Dropbox API error: {$error}" );
		}

		return $body ?? array();
	}

	/**
	 * Make a content upload request to Dropbox.
	 *
	 * @param string $endpoint   API endpoint.
	 * @param string $content    File content.
	 * @param array  $api_args   API arguments.
	 * @return array Response data.
	 * @throws \RuntimeException On API error.
	 */
	private function content_request( string $endpoint, string $content, array $api_args ): array {
		$response = wp_remote_post(
			self::CONTENT_URL . $endpoint,
			array(
				'headers' => array(
					'Authorization'   => 'Bearer ' . $this->get_access_token(),
					'Content-Type'    => 'application/octet-stream',
					'Dropbox-API-Arg' => wp_json_encode( $api_args ),
				),
				'body'    => $content,
				'timeout' => 300,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$error = $body['error_summary'] ?? 'Unknown error';
			throw new \RuntimeException( "Dropbox API error: {$error}" );
		}

		return $body ?? array();
	}
}
