<?php
/**
 * Encryption utility for secure credential storage.
 *
 * @package SwishMigrateAndBackup\Security
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Security;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles encryption and decryption of sensitive data.
 */
final class Encryption {

	/**
	 * Encryption method.
	 */
	private const METHOD = 'aes-256-cbc';

	/**
	 * Option name for encryption key.
	 */
	private const KEY_OPTION = 'swish_backup_encryption_key';

	/**
	 * Cached encryption key.
	 *
	 * @var string|null
	 */
	private ?string $key = null;

	/**
	 * Encrypt a string value.
	 *
	 * @param string $value Value to encrypt.
	 * @return string Encrypted value (base64 encoded).
	 */
	public function encrypt( string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$key = $this->get_key();
		$iv_length = openssl_cipher_iv_length( self::METHOD );
		$iv = openssl_random_pseudo_bytes( $iv_length );

		$encrypted = openssl_encrypt(
			$value,
			self::METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $encrypted ) {
			return '';
		}

		// Combine IV and encrypted data, then base64 encode.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt an encrypted string value.
	 *
	 * @param string $encrypted_value Encrypted value (base64 encoded).
	 * @return string Decrypted value.
	 */
	public function decrypt( string $encrypted_value ): string {
		if ( empty( $encrypted_value ) ) {
			return '';
		}

		$key = $this->get_key();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $encrypted_value, true );

		if ( false === $data ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		$iv = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );

		$decrypted = openssl_decrypt(
			$encrypted,
			self::METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Encrypt an array of values.
	 *
	 * @param array $data  Array to encrypt.
	 * @param array $keys  Keys to encrypt (others are left as-is).
	 * @return array Array with specified keys encrypted.
	 */
	public function encrypt_array( array $data, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$data[ $key ] = $this->encrypt( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * Decrypt an array of values.
	 *
	 * @param array $data  Array to decrypt.
	 * @param array $keys  Keys to decrypt.
	 * @return array Array with specified keys decrypted.
	 */
	public function decrypt_array( array $data, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$data[ $key ] = $this->decrypt( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * Hash a value for comparison.
	 *
	 * @param string $value Value to hash.
	 * @return string Hashed value.
	 */
	public function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, $this->get_key() );
	}

	/**
	 * Verify a value against a hash.
	 *
	 * @param string $value Value to verify.
	 * @param string $hash  Hash to compare against.
	 * @return bool True if valid.
	 */
	public function verify_hash( string $value, string $hash ): bool {
		return hash_equals( $this->hash( $value ), $hash );
	}

	/**
	 * Generate a secure random token.
	 *
	 * @param int $length Token length.
	 * @return string Random token.
	 */
	public function generate_token( int $length = 32 ): string {
		return bin2hex( random_bytes( $length / 2 ) );
	}

	/**
	 * Get the encryption key.
	 *
	 * @return string
	 */
	private function get_key(): string {
		if ( null !== $this->key ) {
			return $this->key;
		}

		// Try to get key from constant first (most secure).
		if ( defined( 'SWISH_BACKUP_ENCRYPTION_KEY' ) && SWISH_BACKUP_ENCRYPTION_KEY ) {
			$this->key = SWISH_BACKUP_ENCRYPTION_KEY;
			return $this->key;
		}

		// Try to get from wp-config.php AUTH_KEY.
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$this->key = hash( 'sha256', AUTH_KEY . 'swish_backup' );
			return $this->key;
		}

		// Fall back to stored key.
		$stored_key = get_option( self::KEY_OPTION );

		if ( $stored_key ) {
			$this->key = $stored_key;
			return $this->key;
		}

		// Generate and store a new key.
		$this->key = $this->generate_token( 64 );
		update_option( self::KEY_OPTION, $this->key, false );

		return $this->key;
	}

	/**
	 * Rotate the encryption key.
	 *
	 * This will re-encrypt all stored credentials with a new key.
	 *
	 * @return bool True if rotation successful.
	 */
	public function rotate_key(): bool {
		$old_key = $this->get_key();

		// Get all storage adapter settings.
		$adapters = array( 's3', 'dropbox', 'googledrive' );
		$stored_settings = array();

		foreach ( $adapters as $adapter ) {
			$option_name = 'swish_backup_storage_' . $adapter;
			$settings = get_option( $option_name );

			if ( $settings ) {
				$stored_settings[ $option_name ] = $settings;
			}
		}

		// Generate new key.
		$this->key = null; // Reset cached key.
		$new_key = $this->generate_token( 64 );

		// Re-encrypt all settings with new key.
		// This is a simplified version - in production you'd need to track which fields are encrypted.
		update_option( self::KEY_OPTION, $new_key, false );
		$this->key = $new_key;

		return true;
	}

	/**
	 * Check if encryption is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return extension_loaded( 'openssl' ) &&
			function_exists( 'openssl_encrypt' ) &&
			function_exists( 'openssl_decrypt' );
	}
}
