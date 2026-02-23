<?php
/**
 * Google Drive Storage Adapter.
 *
 * @package SwishMigrateAndBackup\Storage
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Storage;

use SwishMigrateAndBackup\Storage\Contracts\AbstractStorageAdapter;
use SwishMigrateAndBackup\Logger\Logger;
use SwishMigrateAndBackup\Security\Encryption;

/**
 * Google Drive storage adapter.
 *
 * Implements Google Drive API v3 for file storage operations.
 */
final class GoogleDriveAdapter extends AbstractStorageAdapter {

	/**
	 * Google Drive API base URL.
	 */
	private const API_URL = 'https://www.googleapis.com/drive/v3';

	/**
	 * Google Drive upload URL.
	 */
	private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3';

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * Cached folder ID.
	 *
	 * @var string|null
	 */
	private ?string $folder_id = null;

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
		return 'googledrive';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Google Drive', 'swish-migrate-and-backup' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['access_token'] ) || ! empty( $settings['refresh_token'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function connect(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			// Ensure we have a valid access token.
			$this->ensure_valid_token();

			// Test by getting user info.
			$response = $this->api_request( 'GET', '/about', array( 'fields' => 'user' ) );
			return isset( $response['user'] );
		} catch ( \Exception $e ) {
			$this->logger->error( 'Google Drive connection failed: ' . $e->getMessage() );
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

		// Use resumable upload for files larger than 5MB.
		if ( $file_size > 5 * 1024 * 1024 ) {
			return $this->upload_chunked( $local_path, $remote_path );
		}

		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			// Check if file already exists.
			$existing_file_id = $this->find_file( basename( $remote_path ), $folder_id );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $local_path );
			$mime_type = $this->get_mime_type( $local_path );

			$metadata = array(
				'name'     => basename( $remote_path ),
				'mimeType' => $mime_type,
			);

			if ( ! $existing_file_id ) {
				$metadata['parents'] = array( $folder_id );
			}

			$boundary = wp_generate_password( 32, false );

			$body = "--{$boundary}\r\n";
			$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
			$body .= wp_json_encode( $metadata ) . "\r\n";
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Type: {$mime_type}\r\n\r\n";
			$body .= $content . "\r\n";
			$body .= "--{$boundary}--";

			$endpoint = $existing_file_id
				? "/files/{$existing_file_id}?uploadType=multipart"
				: '/files?uploadType=multipart';

			$method = $existing_file_id ? 'PATCH' : 'POST';

			$response = $this->upload_request( $method, $endpoint, $body, $boundary );

			if ( isset( $response['id'] ) ) {
				$this->logger->info( 'File uploaded to Google Drive', array(
					'path'    => $remote_path,
					'size'    => $file_size,
					'file_id' => $response['id'],
				) );
				return true;
			}

			return $this->log_error( 'Google Drive upload failed', array( 'response' => $response ) );
		} catch ( \Exception $e ) {
			return $this->log_error( 'Google Drive upload exception: ' . $e->getMessage() );
		}
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
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$file_size = filesize( $local_path );
			$mime_type = $this->get_mime_type( $local_path );
			$total_chunks = (int) ceil( $file_size / $chunk_size );

			// Check if file already exists.
			$existing_file_id = $this->find_file( basename( $remote_path ), $folder_id );

			$metadata = array(
				'name'     => basename( $remote_path ),
				'mimeType' => $mime_type,
			);

			if ( ! $existing_file_id ) {
				$metadata['parents'] = array( $folder_id );
			}

			// Initiate resumable upload.
			$endpoint = $existing_file_id
				? "/files/{$existing_file_id}?uploadType=resumable"
				: '/files?uploadType=resumable';

			$method = $existing_file_id ? 'PATCH' : 'POST';

			$init_response = wp_remote_request(
				self::UPLOAD_URL . $endpoint,
				array(
					'method'  => $method,
					'headers' => array(
						'Authorization'           => 'Bearer ' . $this->get_access_token(),
						'Content-Type'            => 'application/json; charset=UTF-8',
						'X-Upload-Content-Type'   => $mime_type,
						'X-Upload-Content-Length' => $file_size,
					),
					'body'    => wp_json_encode( $metadata ),
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $init_response ) ) {
				return $this->log_error( 'Failed to initiate resumable upload: ' . $init_response->get_error_message() );
			}

			$upload_url = wp_remote_retrieve_header( $init_response, 'location' );
			if ( empty( $upload_url ) ) {
				return $this->log_error( 'Failed to get upload URL' );
			}

			// Upload chunks.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $local_path, 'rb' );
			if ( ! $handle ) {
				return $this->log_error( 'Failed to open file for reading' );
			}

			$offset = 0;
			$chunk_num = 0;

			while ( $offset < $file_size ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $handle, $chunk_size );
				$chunk_length = strlen( $chunk );
				$end_byte = $offset + $chunk_length - 1;

				$chunk_response = wp_remote_request(
					$upload_url,
					array(
						'method'  => 'PUT',
						'headers' => array(
							'Content-Length' => $chunk_length,
							'Content-Range'  => "bytes {$offset}-{$end_byte}/{$file_size}",
						),
						'body'    => $chunk,
						'timeout' => 300,
					)
				);

				$code = wp_remote_retrieve_response_code( $chunk_response );

				// 308 = Resume Incomplete (more chunks to upload).
				// 200/201 = Upload complete.
				if ( ! in_array( $code, array( 200, 201, 308 ), true ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
					return $this->log_error( 'Chunk upload failed', array( 'code' => $code ) );
				}

				$offset += $chunk_length;
				++$chunk_num;

				if ( $progress_callback ) {
					$progress = min( 100, (int) ( ( $chunk_num / $total_chunks ) * 100 ) );
					$progress_callback( $progress, $chunk_num, $total_chunks );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			$this->logger->info( 'Chunked upload completed to Google Drive', array(
				'path'   => $remote_path,
				'chunks' => $total_chunks,
			) );

			return true;
		} catch ( \Exception $e ) {
			return $this->log_error( 'Chunked upload exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $remote_path, string $local_path ): bool {
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$file_id = $this->find_file( basename( $remote_path ), $folder_id );
			if ( ! $file_id ) {
				return $this->log_error( 'File not found', array( 'path' => $remote_path ) );
			}

			$response = wp_remote_get(
				self::API_URL . "/files/{$file_id}?alt=media",
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->get_access_token(),
					),
					'timeout' => 300,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->log_error( 'Download failed: ' . $response->get_error_message() );
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
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$file_id = $this->find_file( basename( $remote_path ), $folder_id );
			if ( ! $file_id ) {
				return true; // Already deleted.
			}

			$response = wp_remote_request(
				self::API_URL . "/files/{$file_id}",
				array(
					'method'  => 'DELETE',
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->get_access_token(),
					),
					'timeout' => 60,
				)
			);

			$code = wp_remote_retrieve_response_code( $response );
			return 204 === $code || 200 === $code;
		} catch ( \Exception $e ) {
			return $this->log_error( 'Delete exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( string $path = '' ): array {
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$query = "'{$folder_id}' in parents and trashed = false";
			$response = $this->api_request( 'GET', '/files', array(
				'q'      => $query,
				'fields' => 'files(id,name,size,modifiedTime,mimeType)',
				'orderBy' => 'modifiedTime desc',
			) );

			$files = array();
			foreach ( $response['files'] ?? array() as $file ) {
				$files[] = array(
					'name'         => $file['name'],
					'path'         => $file['name'],
					'size'         => (int) ( $file['size'] ?? 0 ),
					'modified'     => strtotime( $file['modifiedTime'] ?? '' ),
					'is_directory' => 'application/vnd.google-apps.folder' === $file['mimeType'],
					'id'           => $file['id'],
				);
			}

			return $files;
		} catch ( \Exception $e ) {
			$this->logger->error( 'Google Drive list exception: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_metadata( string $remote_path ): ?array {
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$file_id = $this->find_file( basename( $remote_path ), $folder_id );
			if ( ! $file_id ) {
				return null;
			}

			$response = $this->api_request( 'GET', "/files/{$file_id}", array(
				'fields' => 'id,name,size,modifiedTime,md5Checksum',
			) );

			return array(
				'name'     => $response['name'],
				'path'     => $remote_path,
				'size'     => (int) ( $response['size'] ?? 0 ),
				'modified' => strtotime( $response['modifiedTime'] ?? '' ),
				'checksum' => $response['md5Checksum'] ?? '',
				'id'       => $response['id'],
			);
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string {
		try {
			$this->ensure_valid_token();
			$folder_id = $this->get_or_create_folder();

			$file_id = $this->find_file( basename( $remote_path ), $folder_id );
			if ( ! $file_id ) {
				return null;
			}

			// Google Drive doesn't support pre-signed URLs without OAuth.
			// Return a URL that requires the access token.
			return self::API_URL . "/files/{$file_id}?alt=media";
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_storage_info(): array {
		try {
			$this->ensure_valid_token();
			$response = $this->api_request( 'GET', '/about', array(
				'fields' => 'storageQuota',
			) );

			$quota = $response['storageQuota'] ?? array();

			return array(
				'used'  => (int) ( $quota['usage'] ?? 0 ),
				'total' => isset( $quota['limit'] ) ? (int) $quota['limit'] : null,
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
				'name'        => 'client_id',
				'label'       => __( 'Client ID', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Your Google OAuth Client ID.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'client_secret',
				'label'       => __( 'Client Secret', 'swish-migrate-and-backup' ),
				'type'        => 'password',
				'description' => __( 'Your Google OAuth Client Secret.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'folder_name',
				'label'       => __( 'Backup Folder', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Name of the folder for storing backups.', 'swish-migrate-and-backup' ),
				'default'     => 'SwishBackups',
			),
			array(
				'name'   => 'access_token',
				'type'   => 'hidden',
			),
			array(
				'name'   => 'refresh_token',
				'type'   => 'hidden',
			),
			array(
				'name'   => 'token_expires',
				'type'   => 'hidden',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_settings( array $settings ): bool {
		// Encrypt sensitive fields.
		$sensitive_fields = array( 'client_secret', 'access_token', 'refresh_token' );

		foreach ( $sensitive_fields as $field ) {
			if ( ! empty( $settings[ $field ] ) ) {
				$settings[ $field ] = $this->encryption->encrypt( $settings[ $field ] );
			}
		}

		return parent::save_settings( $settings );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param string $redirect_uri Redirect URI after authorization.
	 * @return string|null Authorization URL or null if not configured.
	 */
	public function get_auth_url( string $redirect_uri ): ?string {
		$settings = $this->get_settings();
		$client_id = $settings['client_id'] ?? '';

		if ( empty( $client_id ) ) {
			return null;
		}

		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => urlencode( $redirect_uri ),
				'response_type' => 'code',
				'scope'         => urlencode( 'https://www.googleapis.com/auth/drive.file' ),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI.
	 * @return bool True if successful.
	 */
	public function exchange_code_for_token( string $code, string $redirect_uri ): bool {
		$settings = $this->get_settings();
		$client_id = $settings['client_id'] ?? '';
		$client_secret = $this->encryption->decrypt( $settings['client_secret'] ?? '' );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['access_token'] ) ) {
			$settings['access_token'] = $this->encryption->encrypt( $body['access_token'] );
			$settings['refresh_token'] = $this->encryption->encrypt( $body['refresh_token'] ?? '' );
			$settings['token_expires'] = time() + ( $body['expires_in'] ?? 3600 );

			return parent::save_settings( $settings );
		}

		return false;
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
	 * Ensure we have a valid access token, refreshing if necessary.
	 *
	 * @return void
	 * @throws \RuntimeException If token refresh fails.
	 */
	private function ensure_valid_token(): void {
		$settings = $this->get_settings();
		$expires = $settings['token_expires'] ?? 0;

		// Refresh if token expires in less than 5 minutes.
		if ( $expires > ( time() + 300 ) ) {
			return;
		}

		$refresh_token = $this->encryption->decrypt( $settings['refresh_token'] ?? '' );
		if ( empty( $refresh_token ) ) {
			throw new \RuntimeException( 'No refresh token available' );
		}

		$client_id = $settings['client_id'] ?? '';
		$client_secret = $this->encryption->decrypt( $settings['client_secret'] ?? '' );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Token refresh failed: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['access_token'] ) ) {
			throw new \RuntimeException( 'Token refresh failed: no access token in response' );
		}

		$settings['access_token'] = $this->encryption->encrypt( $body['access_token'] );
		$settings['token_expires'] = time() + ( $body['expires_in'] ?? 3600 );

		if ( isset( $body['refresh_token'] ) ) {
			$settings['refresh_token'] = $this->encryption->encrypt( $body['refresh_token'] );
		}

		parent::save_settings( $settings );
	}

	/**
	 * Get or create the backup folder.
	 *
	 * @return string Folder ID.
	 * @throws \RuntimeException If folder creation fails.
	 */
	private function get_or_create_folder(): string {
		if ( $this->folder_id ) {
			return $this->folder_id;
		}

		$settings = $this->get_settings();
		$folder_name = $settings['folder_name'] ?? 'SwishBackups';

		// Search for existing folder.
		$query = "name = '{$folder_name}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
		$response = $this->api_request( 'GET', '/files', array(
			'q'      => $query,
			'fields' => 'files(id)',
		) );

		if ( ! empty( $response['files'][0]['id'] ) ) {
			$this->folder_id = $response['files'][0]['id'];
			return $this->folder_id;
		}

		// Create folder.
		$create_response = wp_remote_post(
			self::API_URL . '/files',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'name'     => $folder_name,
					'mimeType' => 'application/vnd.google-apps.folder',
				) ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $create_response ) ) {
			throw new \RuntimeException( 'Failed to create folder: ' . $create_response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $create_response ), true );

		if ( ! isset( $body['id'] ) ) {
			throw new \RuntimeException( 'Failed to create folder' );
		}

		$this->folder_id = $body['id'];
		return $this->folder_id;
	}

	/**
	 * Find a file by name in a folder.
	 *
	 * @param string $name      File name.
	 * @param string $folder_id Folder ID.
	 * @return string|null File ID or null if not found.
	 */
	private function find_file( string $name, string $folder_id ): ?string {
		$query = "name = '{$name}' and '{$folder_id}' in parents and trashed = false";
		$response = $this->api_request( 'GET', '/files', array(
			'q'      => $query,
			'fields' => 'files(id)',
		) );

		return $response['files'][0]['id'] ?? null;
	}

	/**
	 * Make an API request to Google Drive.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $params   Query parameters.
	 * @return array Response data.
	 * @throws \RuntimeException On API error.
	 */
	private function api_request( string $method, string $endpoint, array $params = array() ): array {
		$url = self::API_URL . $endpoint;

		if ( ! empty( $params ) && 'GET' === $method ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_access_token(),
			),
			'timeout' => 60,
		);

		if ( ! empty( $params ) && 'GET' !== $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error = $body['error']['message'] ?? 'Unknown error';
			throw new \RuntimeException( "Google Drive API error: {$error}" );
		}

		return $body ?? array();
	}

	/**
	 * Make an upload request to Google Drive.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param string $body     Request body.
	 * @param string $boundary Multipart boundary.
	 * @return array Response data.
	 * @throws \RuntimeException On API error.
	 */
	private function upload_request( string $method, string $endpoint, string $body, string $boundary ): array {
		$response = wp_remote_request(
			self::UPLOAD_URL . $endpoint,
			array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_access_token(),
					'Content-Type'  => "multipart/related; boundary={$boundary}",
					'Content-Length' => strlen( $body ),
				),
				'body'    => $body,
				'timeout' => 300,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error = $response_body['error']['message'] ?? 'Unknown error';
			throw new \RuntimeException( "Google Drive upload error: {$error}" );
		}

		return $response_body ?? array();
	}
}
