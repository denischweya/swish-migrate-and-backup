<?php
/**
 * BackupPipeline::get_backup_directories() must not return a directory that is
 * inside another returned directory: with core files enabled the site root
 * already contains wp-content, and listing both indexed every file twice.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Backup\BackupPipeline;
use SwishMigrateAndBackup\Logger\Logger;

$p = new BackupPipeline( new Logger() );
$opts = array( 'backup_core_files' => true, 'backup_plugins' => true, 'backup_themes' => true, 'backup_uploads' => true );
$p->configure( $opts );
$dirs = $p->get_backup_directories( $opts );
swish_assert_same( 1, count( $dirs ), 'core enabled: only the site root is scanned (' . implode( ', ', $dirs ) . ')' );
swish_assert( realpath( $dirs[0] ?? '' ) === realpath( ABSPATH ), 'that directory is ABSPATH' );

$opts = array( 'backup_core_files' => false, 'backup_plugins' => true, 'backup_themes' => true, 'backup_uploads' => true );
$p = new BackupPipeline( new Logger() );
$p->configure( $opts );
$dirs = $p->get_backup_directories( $opts );
swish_assert_same( 3, count( $dirs ), 'core disabled: plugins, themes and uploads are scanned separately' );

$opts = array( 'backup_core_files' => false, 'backup_plugins' => false, 'backup_themes' => false, 'backup_uploads' => false );
$p = new BackupPipeline( new Logger() );
$p->configure( $opts );
swish_assert_same( array(), $p->get_backup_directories( $opts ), 'database-only: no directories' );
swish_done( 'pipeline-directories' );
