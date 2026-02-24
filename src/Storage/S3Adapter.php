<?php
/**
 * Amazon S3 Storage Adapter.
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
 * Amazon S3 storage adapter.
 *
 * Implements direct S3 API calls without requiring the AWS SDK.
 * This keeps the plugin lightweight and compatible with shared hosting.
 */
final class S3Adapter extends AbstractStorageAdapter {

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * S3 API endpoint.
	 *
	 * @var string|null
	 */
	private ?string $endpoint = null;

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
		return 's3';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Amazon S3', 'swish-migrate-and-backup' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['access_key'] ) &&
			! empty( $settings['secret_key'] ) &&
			! empty( $settings['bucket'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function connect(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			// Test connection by listing bucket (HEAD request).
			$response = $this->make_request( 'HEAD', '' );
			return 200 === $response['code'] || 404 === $response['code'];
		} catch ( \Exception $e ) {
			$this->logger->error( 'S3 connection failed: ' . $e->getMessage() );
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

		// Use multipart upload for files larger than 100MB.
		if ( $file_size > 100 * 1024 * 1024 ) {
			return $this->multipart_upload( $local_path, $remote_path );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $local_path );
			$content_type = $this->get_mime_type( $local_path );

			$response = $this->make_request(
				'PUT',
				$this->normalize_path( $remote_path ),
				$content,
				array(
					'Content-Type'   => $content_type,
					'Content-Length' => strlen( $content ),
					'x-amz-acl'      => 'private',
				)
			);

			if ( 200 === $response['code'] ) {
				$this->logger->info( 'File uploaded to S3', array(
					'path' => $remote_path,
					'size' => $file_size,
				) );
				return true;
			}

			return $this->log_error( 'S3 upload failed', array(
				'code'     => $response['code'],
				'response' => $response['body'],
			) );
		} catch ( \Exception $e ) {
			return $this->log_error( 'S3 upload exception: ' . $e->getMessage() );
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
		return $this->multipart_upload( $local_path, $remote_path, $chunk_size, $progress_callback );
	}

	/**
	 * Perform multipart upload for large files.
	 *
	 * @param string        $local_path         Local file path.
	 * @param string        $remote_path        Remote file path.
	 * @param int           $chunk_size         Chunk size in bytes.
	 * @param callable|null $progress_callback  Progress callback.
	 * @return bool
	 */
	private function multipart_upload(
		string $local_path,
		string $remote_path,
		int $chunk_size = 5242880,
		?callable $progress_callback = null
	): bool {
		$remote_path = $this->normalize_path( $remote_path );
		$file_size = filesize( $local_path );
		$total_parts = (int) ceil( $file_size / $chunk_size );

		try {
			// Initiate multipart upload.
			$response = $this->make_request(
				'POST',
				$remote_path . '?uploads',
				'',
				array( 'Content-Type' => $this->get_mime_type( $local_path ) )
			);

			if ( 200 !== $response['code'] ) {
				return $this->log_error( 'Failed to initiate multipart upload' );
			}

			// Parse upload ID from response.
			preg_match( '/<UploadId>([^<]+)<\/UploadId>/', $response['body'], $matches );
			if ( empty( $matches[1] ) ) {
				return $this->log_error( 'Failed to get upload ID' );
			}

			$upload_id = $matches[1];
			$parts = array();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $local_path, 'rb' );
			if ( ! $handle ) {
				return $this->log_error( 'Failed to open file for reading' );
			}

			$part_number = 1;
			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $handle, $chunk_size );

				$part_response = $this->make_request(
					'PUT',
					$remote_path . "?partNumber={$part_number}&uploadId=" . urlencode( $upload_id ),
					$chunk,
					array(
						'Content-Length' => strlen( $chunk ),
					)
				);

				if ( 200 !== $part_response['code'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
					$this->abort_multipart_upload( $remote_path, $upload_id );
					return $this->log_error( 'Failed to upload part ' . $part_number );
				}

				// Get ETag from response headers.
				$etag = '';
				foreach ( $part_response['headers'] as $header => $value ) {
					if ( strtolower( $header ) === 'etag' ) {
						$etag = trim( $value, '"' );
						break;
					}
				}

				$parts[] = array(
					'PartNumber' => $part_number,
					'ETag'       => $etag,
				);

				if ( $progress_callback ) {
					$progress = min( 100, (int) ( ( $part_number / $total_parts ) * 100 ) );
					$progress_callback( $progress, $part_number, $total_parts );
				}

				++$part_number;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			// Complete multipart upload.
			$complete_xml = $this->build_complete_multipart_xml( $parts );
			$complete_response = $this->make_request(
				'POST',
				$remote_path . '?uploadId=' . urlencode( $upload_id ),
				$complete_xml,
				array( 'Content-Type' => 'application/xml' )
			);

			if ( 200 === $complete_response['code'] ) {
				$this->logger->info( 'Multipart upload completed', array(
					'path'  => $remote_path,
					'parts' => count( $parts ),
				) );
				return true;
			}

			return $this->log_error( 'Failed to complete multipart upload' );
		} catch ( \Exception $e ) {
			return $this->log_error( 'Multipart upload exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Abort a multipart upload.
	 *
	 * @param string $remote_path Remote path.
	 * @param string $upload_id   Upload ID.
	 * @return void
	 */
	private function abort_multipart_upload( string $remote_path, string $upload_id ): void {
		try {
			$this->make_request(
				'DELETE',
				$remote_path . '?uploadId=' . urlencode( $upload_id )
			);
		} catch ( \Exception $e ) {
			$this->logger->error( 'Failed to abort multipart upload: ' . $e->getMessage() );
		}
	}

	/**
	 * Build XML for completing multipart upload.
	 *
	 * @param array $parts Array of parts with PartNumber and ETag.
	 * @return string
	 */
	private function build_complete_multipart_xml( array $parts ): string {
		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= '<Part>';
			$xml .= '<PartNumber>' . $part['PartNumber'] . '</PartNumber>';
			$xml .= '<ETag>"' . $part['ETag'] . '"</ETag>';
			$xml .= '</Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		return $xml;
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $remote_path, string $local_path ): bool {
		try {
			$response = $this->make_request( 'GET', $this->normalize_path( $remote_path ) );

			if ( 200 !== $response['code'] ) {
				return $this->log_error( 'S3 download failed', array(
					'code' => $response['code'],
					'path' => $remote_path,
				) );
			}

			$local_dir = dirname( $local_path );
			if ( ! is_dir( $local_dir ) && ! wp_mkdir_p( $local_dir ) ) {
				return $this->log_error( 'Failed to create local directory' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$result = file_put_contents( $local_path, $response['body'] );

			if ( false === $result ) {
				return $this->log_error( 'Failed to write downloaded file' );
			}

			return true;
		} catch ( \Exception $e ) {
			return $this->log_error( 'S3 download exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( string $remote_path ): bool {
		try {
			$response = $this->make_request( 'DELETE', $this->normalize_path( $remote_path ) );
			return 204 === $response['code'] || 200 === $response['code'];
		} catch ( \Exception $e ) {
			return $this->log_error( 'S3 delete exception: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( string $path = '' ): array {
		try {
			$prefix = $path ? $this->normalize_path( $path ) . '/' : '';
			$response = $this->make_request( 'GET', '?list-type=2&prefix=' . urlencode( $prefix ) );

			if ( 200 !== $response['code'] ) {
				return array();
			}

			$files = array();
			preg_match_all( '/<Contents>(.+?)<\/Contents>/s', $response['body'], $matches );

			foreach ( $matches[1] as $content ) {
				preg_match( '/<Key>([^<]+)<\/Key>/', $content, $key_match );
				preg_match( '/<Size>([^<]+)<\/Size>/', $content, $size_match );
				preg_match( '/<LastModified>([^<]+)<\/LastModified>/', $content, $modified_match );

				if ( ! empty( $key_match[1] ) ) {
					$files[] = array(
						'name'         => basename( $key_match[1] ),
						'path'         => $key_match[1],
						'size'         => (int) ( $size_match[1] ?? 0 ),
						'modified'     => strtotime( $modified_match[1] ?? '' ),
						'is_directory' => false,
					);
				}
			}

			return $files;
		} catch ( \Exception $e ) {
			$this->logger->error( 'S3 list exception: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_metadata( string $remote_path ): ?array {
		try {
			$response = $this->make_request( 'HEAD', $this->normalize_path( $remote_path ) );

			if ( 200 !== $response['code'] ) {
				return null;
			}

			$headers = $response['headers'];

			return array(
				'name'     => basename( $remote_path ),
				'path'     => $remote_path,
				'size'     => (int) ( $headers['Content-Length'] ?? $headers['content-length'] ?? 0 ),
				'modified' => strtotime( $headers['Last-Modified'] ?? $headers['last-modified'] ?? '' ),
				'etag'     => trim( $headers['ETag'] ?? $headers['etag'] ?? '', '"' ),
			);
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_download_url( string $remote_path, int $expiry = 3600 ): ?string {
		$settings = $this->get_settings();
		$bucket = $settings['bucket'] ?? '';
		$region = $settings['region'] ?? 'us-east-1';
		$access_key = $this->encryption->decrypt( $settings['access_key'] ?? '' );
		$secret_key = $this->encryption->decrypt( $settings['secret_key'] ?? '' );

		$remote_path = $this->normalize_path( $remote_path );
		$expiry_time = time() + $expiry;

		// Generate pre-signed URL using AWS Signature Version 4.
		$host = $this->get_endpoint();
		$datetime = gmdate( 'Ymd\THis\Z' );
		$date = gmdate( 'Ymd' );

		$credential_scope = "{$date}/{$region}/s3/aws4_request";
		$canonical_uri = '/' . $remote_path;
		$canonical_query_string = http_build_query( array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $access_key . '/' . $credential_scope,
			'X-Amz-Date'          => $datetime,
			'X-Amz-Expires'       => $expiry,
			'X-Amz-SignedHeaders' => 'host',
		) );

		$canonical_request = "GET\n{$canonical_uri}\n{$canonical_query_string}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$datetime}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

		// Calculate signature.
		$date_key = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$region_key = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', 's3', $region_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $service_key, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return "https://{$host}{$canonical_uri}?{$canonical_query_string}&X-Amz-Signature={$signature}";
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'name'        => 'access_key',
				'label'       => __( 'Access Key ID', 'swish-migrate-and-backup' ),
				'type'        => 'password',
				'description' => __( 'Your AWS Access Key ID.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'secret_key',
				'label'       => __( 'Secret Access Key', 'swish-migrate-and-backup' ),
				'type'        => 'password',
				'description' => __( 'Your AWS Secret Access Key.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'The S3 bucket to store backups in.', 'swish-migrate-and-backup' ),
				'required'    => true,
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'swish-migrate-and-backup' ),
				'type'        => 'select',
				'options'     => array(
					'us-east-1'      => 'US East (N. Virginia)',
					'us-east-2'      => 'US East (Ohio)',
					'us-west-1'      => 'US West (N. California)',
					'us-west-2'      => 'US West (Oregon)',
					'eu-west-1'      => 'EU (Ireland)',
					'eu-west-2'      => 'EU (London)',
					'eu-west-3'      => 'EU (Paris)',
					'eu-central-1'   => 'EU (Frankfurt)',
					'ap-northeast-1' => 'Asia Pacific (Tokyo)',
					'ap-southeast-1' => 'Asia Pacific (Singapore)',
					'ap-southeast-2' => 'Asia Pacific (Sydney)',
				),
				'default'     => 'us-east-1',
			),
			array(
				'name'        => 'path_prefix',
				'label'       => __( 'Path Prefix', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Optional folder path within the bucket.', 'swish-migrate-and-backup' ),
				'default'     => 'backups',
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'Custom Endpoint', 'swish-migrate-and-backup' ),
				'type'        => 'text',
				'description' => __( 'Custom S3-compatible endpoint (e.g., for MinIO, DigitalOcean Spaces).', 'swish-migrate-and-backup' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_settings( array $settings ): bool {
		// Encrypt sensitive fields.
		$sensitive_fields = array( 'access_key', 'secret_key' );

		foreach ( $sensitive_fields as $field ) {
			if ( ! empty( $settings[ $field ] ) ) {
				$settings[ $field ] = $this->encryption->encrypt( $settings[ $field ] );
			}
		}

		return parent::save_settings( $settings );
	}

	/**
	 * Get the S3 endpoint.
	 *
	 * @return string
	 */
	private function get_endpoint(): string {
		if ( $this->endpoint ) {
			return $this->endpoint;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['endpoint'] ) ) {
			$this->endpoint = $settings['endpoint'];
		} else {
			$bucket = $settings['bucket'] ?? '';
			$region = $settings['region'] ?? 'us-east-1';

			if ( 'us-east-1' === $region ) {
				$this->endpoint = "{$bucket}.s3.amazonaws.com";
			} else {
				$this->endpoint = "{$bucket}.s3.{$region}.amazonaws.com";
			}
		}

		return $this->endpoint;
	}

	/**
	 * Make an authenticated S3 API request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Request path.
	 * @param string $body    Request body.
	 * @param array  $headers Additional headers.
	 * @return array Response array with code, body, and headers.
	 */
	private function make_request(
		string $method,
		string $path,
		string $body = '',
		array $headers = array()
	): array {
		$settings = $this->get_settings();
		$access_key = $this->encryption->decrypt( $settings['access_key'] ?? '' );
		$secret_key = $this->encryption->decrypt( $settings['secret_key'] ?? '' );
		$region = $settings['region'] ?? 'us-east-1';
		$prefix = $settings['path_prefix'] ?? '';

		// Add prefix to path if set (except for bucket-level operations).
		if ( $prefix && ! empty( $path ) && ! str_starts_with( $path, '?' ) ) {
			$path = trim( $prefix, '/' ) . '/' . ltrim( $path, '/' );
		}

		$host = $this->get_endpoint();
		$datetime = gmdate( 'Ymd\THis\Z' );
		$date = gmdate( 'Ymd' );

		// Parse path and query.
		$parsed = wp_parse_url( '/' . ltrim( $path, '/' ) );
		$canonical_uri = $parsed['path'] ?? '/';
		$canonical_query = $parsed['query'] ?? '';

		// Build canonical headers.
		$signed_headers = array( 'host', 'x-amz-content-sha256', 'x-amz-date' );
		$payload_hash = hash( 'sha256', $body );

		$canonical_headers = "host:{$host}\n";
		$canonical_headers .= "x-amz-content-sha256:{$payload_hash}\n";
		$canonical_headers .= "x-amz-date:{$datetime}\n";

		foreach ( $headers as $key => $value ) {
			$lower_key = strtolower( $key );
			if ( ! in_array( $lower_key, $signed_headers, true ) ) {
				$signed_headers[] = $lower_key;
				$canonical_headers .= "{$lower_key}:" . trim( $value ) . "\n";
			}
		}

		sort( $signed_headers );
		$signed_headers_str = implode( ';', $signed_headers );

		// Build canonical request.
		$canonical_request = implode( "\n", array(
			$method,
			$canonical_uri,
			$canonical_query,
			$canonical_headers,
			$signed_headers_str,
			$payload_hash,
		) );

		// Build string to sign.
		$credential_scope = "{$date}/{$region}/s3/aws4_request";
		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$datetime,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		) );

		// Calculate signature.
		$date_key = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$region_key = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', 's3', $region_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $service_key, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Build authorization header.
		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$credential_scope,
			$signed_headers_str,
			$signature
		);

		// Build final headers.
		$request_headers = array_merge(
			$headers,
			array(
				'Host'                 => $host,
				'x-amz-date'           => $datetime,
				'x-amz-content-sha256' => $payload_hash,
				'Authorization'        => $authorization,
			)
		);

		// Make request.
		$url = 'https://' . $host . $canonical_uri;
		if ( $canonical_query ) {
			$url .= '?' . $canonical_query;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $request_headers,
				'body'    => $body,
				'timeout' => 300,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		return array(
			'code'    => wp_remote_retrieve_response_code( $response ),
			'body'    => wp_remote_retrieve_body( $response ),
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
		);
	}
}
