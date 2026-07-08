#!/usr/bin/env php
<?php
/**
 * Standalone .swish archive extractor
 *
 * Usage: php extract-swish.php <archive.swish> [destination]
 */

// CLI-only tool; never serve over the web.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php extract-swish.php <archive.swish> [destination]\n";
    echo "  archive.swish  - Path to the .swish backup file\n";
    echo "  destination    - Optional extraction directory (defaults to ./extracted)\n";
    exit(1);
}

$archive_path = $argv[1];
$destination = $argv[2] ?? './extracted';

if (!file_exists($archive_path)) {
    echo "Error: Archive not found: $archive_path\n";
    exit(1);
}

// Header constants
define('HEADER_SIZE', 4377);
define('NAME_SIZE', 255);
define('SIZE_FIELD', 14);
define('MTIME_SIZE', 12);
define('PREFIX_SIZE', 4096);
define('CHUNK_SIZE', 524288); // 512KB

$fp = fopen($archive_path, 'rb');
if (!$fp) {
    echo "Error: Cannot open archive\n";
    exit(1);
}

$file_count = 0;
$total_bytes = 0;
$archive_size = filesize($archive_path);

echo "Extracting: $archive_path\n";
echo "Destination: $destination\n";
echo "Archive size: " . format_bytes($archive_size) . "\n";
echo str_repeat('-', 60) . "\n";

while (!feof($fp)) {
    $header = fread($fp, HEADER_SIZE);

    // Check for EOF or incomplete header
    if (strlen($header) < HEADER_SIZE) {
        break;
    }

    // Check for EOF marker (all nulls)
    if (trim($header, "\0") === '') {
        echo str_repeat('-', 60) . "\n";
        echo "EOF marker found - archive is complete\n";
        break;
    }

    // Parse header
    $name = trim(substr($header, 0, NAME_SIZE), "\0");
    $size = (int)trim(substr($header, NAME_SIZE, SIZE_FIELD), "\0");
    $mtime = (int)trim(substr($header, NAME_SIZE + SIZE_FIELD, MTIME_SIZE), "\0");
    $prefix = trim(substr($header, NAME_SIZE + SIZE_FIELD + MTIME_SIZE, PREFIX_SIZE), "\0");

    // Build full path
    $relative_path = $prefix ? $prefix . '/' . $name : $name;

    // Reject absolute paths and traversal segments so a crafted archive
    // cannot write outside the destination directory.
    $normalized = str_replace('\\', '/', $relative_path);
    if (strpos($normalized, '/') === 0 || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
        echo "Skipping unsafe entry (path traversal): $relative_path\n";
        fseek($fp, $size, SEEK_CUR);
        continue;
    }

    $full_path = $destination . '/' . $relative_path;

    // Create directory
    $dir = dirname($full_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Extract file content
    $out = fopen($full_path, 'wb');
    if (!$out) {
        echo "Error: Cannot write to $full_path\n";
        // Skip the file content
        fseek($fp, $size, SEEK_CUR);
        continue;
    }

    $remaining = $size;
    while ($remaining > 0) {
        $to_read = min(CHUNK_SIZE, $remaining);
        $chunk = fread($fp, $to_read);
        if ($chunk === false || strlen($chunk) === 0) {
            break;
        }
        fwrite($out, $chunk);
        $remaining -= strlen($chunk);
    }
    fclose($out);

    // Set modification time
    if ($mtime > 0) {
        touch($full_path, $mtime);
    }

    $file_count++;
    $total_bytes += $size;

    // Progress output
    $progress = ftell($fp) / $archive_size * 100;
    printf("[%5.1f%%] %s (%s)\n", $progress, $relative_path, format_bytes($size));
}

fclose($fp);

echo str_repeat('-', 60) . "\n";
echo "Extraction complete!\n";
echo "Files extracted: $file_count\n";
echo "Total size: " . format_bytes($total_bytes) . "\n";

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
