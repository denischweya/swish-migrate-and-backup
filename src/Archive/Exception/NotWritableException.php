<?php
/**
 * Not Writable Exception.
 *
 * @package SwishMigrateAndBackup\Archive\Exception
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Archive\Exception;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when file is not writable.
 */
class NotWritableException extends \RuntimeException {
}
