<?php
/**
 * Swish Archiver - Base class for .swish archive format.
 *
 * Custom streaming archive format designed for reliable backups
 * on shared hosting with minimal memory usage and resumability.
 *
 * Archive Format:
 * - Header block per file: name (255) | size (14) | mtime (12) | prefix (4096)
 * - File content follows header
 * - EOF marker (4377 bytes) indicates valid complete archive
 *
 * @package SwishMigrateAndBackup\Archive
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Archive;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base archiver class for .swish format.
 */
class SwishArchiver {

	/**
	 * Header block size in bytes.
	 * name (255) + size (14) + mtime (12) + prefix (4096) = 4377
	 */
	public const HEADER_SIZE = 4377;

	/**
	 * File name field size.
	 */
	public const NAME_SIZE = 255;

	/**
	 * File size field size.
	 */
	public const SIZE_FIELD = 14;

	/**
	 * Modification time field size.
	 */
	public const MTIME_SIZE = 12;

	/**
	 * Path prefix field size.
	 */
	public const PREFIX_SIZE = 4096;

	/**
	 * Chunk size for streaming (512KB).
	 */
	public const CHUNK_SIZE = 524288;

	/**
	 * Archive file path.
	 *
	 * @var string
	 */
	protected string $file_path;

	/**
	 * File handle.
	 *
	 * @var resource|null
	 */
	protected $file_handle = null;

	/**
	 * EOF marker string.
	 *
	 * @var string
	 */
	protected string $eof_marker;

	/**
	 * Constructor.
	 *
	 * @param string $file_path Archive file path.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
		// EOF marker: null-padded header block with special signature.
		$this->eof_marker = pack( 'a' . self::HEADER_SIZE, '' );
	}

	/**
	 * Get the archive file path.
	 *
	 * @return string
	 */
	public function get_file_path(): string {
		return $this->file_path;
	}

	/**
	 * Check if archive is open.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return null !== $this->file_handle && is_resource( $this->file_handle );
	}

	/**
	 * Close the archive.
	 *
	 * @return bool
	 */
	public function close(): bool {
		if ( $this->is_open() ) {
			$result = fclose( $this->file_handle );
			$this->file_handle = null;
			return $result;
		}
		return true;
	}

	/**
	 * Get current position in archive.
	 *
	 * @return int
	 */
	public function get_position(): int {
		if ( ! $this->is_open() ) {
			return 0;
		}
		return (int) ftell( $this->file_handle );
	}

	/**
	 * Seek to position in archive.
	 *
	 * @param int $offset Byte offset.
	 * @param int $whence Seek mode (SEEK_SET, SEEK_CUR, SEEK_END).
	 * @return bool
	 */
	public function seek( int $offset, int $whence = SEEK_SET ): bool {
		if ( ! $this->is_open() ) {
			return false;
		}
		return 0 === fseek( $this->file_handle, $offset, $whence );
	}

	/**
	 * Get archive file size.
	 *
	 * @return int
	 */
	public function get_size(): int {
		if ( file_exists( $this->file_path ) ) {
			return (int) filesize( $this->file_path );
		}
		return 0;
	}

	/**
	 * Check if archive is valid (has EOF marker).
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		if ( ! file_exists( $this->file_path ) ) {
			return false;
		}

		$handle = fopen( $this->file_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		// Seek to potential EOF marker position.
		if ( fseek( $handle, -self::HEADER_SIZE, SEEK_END ) === -1 ) {
			fclose( $handle );
			return false;
		}

		$marker = fread( $handle, self::HEADER_SIZE );
		fclose( $handle );

		return $marker === $this->eof_marker;
	}

	/**
	 * Truncate archive at specified offset.
	 *
	 * Used to recover from partial writes.
	 *
	 * @param int $offset Byte offset to truncate at.
	 * @return bool
	 */
	public function truncate( int $offset ): bool {
		if ( ! $this->is_open() ) {
			return false;
		}

		$current_size = $this->get_size();
		if ( $current_size > $offset ) {
			return ftruncate( $this->file_handle, $offset );
		}

		return true;
	}

	/**
	 * Create header block for a file.
	 *
	 * @param string $name     File name (basename).
	 * @param int    $size     File size in bytes.
	 * @param int    $mtime    Modification timestamp.
	 * @param string $prefix   Path prefix (directory path).
	 * @return string Header block.
	 */
	protected function create_header( string $name, int $size, int $mtime, string $prefix = '' ): string {
		$header = '';

		// File name (255 bytes, null-padded).
		$header .= pack( 'a' . self::NAME_SIZE, $name );

		// File size (14 bytes, zero-padded decimal).
		$header .= sprintf( '%014d', $size );

		// Modification time (12 bytes, zero-padded octal).
		$header .= sprintf( '%012o', $mtime );

		// Path prefix (4096 bytes, null-padded).
		$header .= pack( 'a' . self::PREFIX_SIZE, $prefix );

		return $header;
	}

	/**
	 * Parse header block.
	 *
	 * @param string $header Raw header block.
	 * @return array|false Parsed header data or false on failure.
	 */
	protected function parse_header( string $header ) {
		if ( strlen( $header ) !== self::HEADER_SIZE ) {
			return false;
		}

		// Check if EOF marker.
		if ( $header === $this->eof_marker ) {
			return array( 'eof' => true );
		}

		$offset = 0;

		// File name.
		$name = rtrim( substr( $header, $offset, self::NAME_SIZE ), "\0" );
		$offset += self::NAME_SIZE;

		// File size.
		$size = (int) substr( $header, $offset, self::SIZE_FIELD );
		$offset += self::SIZE_FIELD;

		// Modification time.
		$mtime = octdec( substr( $header, $offset, self::MTIME_SIZE ) );
		$offset += self::MTIME_SIZE;

		// Path prefix.
		$prefix = rtrim( substr( $header, $offset, self::PREFIX_SIZE ), "\0" );

		return array(
			'name'   => $name,
			'size'   => $size,
			'mtime'  => $mtime,
			'prefix' => $prefix,
			'path'   => $prefix ? $prefix . '/' . $name : $name,
			'eof'    => false,
		);
	}

	/**
	 * Destructor - ensure file handle is closed.
	 */
	public function __destruct() {
		$this->close();
	}
}
