<?php
/**
 * Archive write exception.
 *
 * @package SwishMigrateAndBackup\Backup
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when the archive itself cannot be written (disk full, quota, I/O error).
 *
 * Distinct from running out of time, so callers fail the job with a clear
 * message instead of retrying the same write forever.
 */
final class ArchiveWriteException extends \RuntimeException {}
