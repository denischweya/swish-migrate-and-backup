<?php
/**
 * Two drivers (browser poll + server loopback) call ImportPipeline::execute()
 * for the same session. While one holds the lock the other must do nothing.
 */
require __DIR__ . '/lib.php';
use SwishMigrateAndBackup\Import\ImportPipeline;
use SwishMigrateAndBackup\Import\ImportSession;
use SwishMigrateAndBackup\Core\Container;
use SwishMigrateAndBackup\Restore\RestoreManager;
use SwishMigrateAndBackup\Migration\Migrator;
use SwishMigrateAndBackup\Logger\Logger;

ImportSession::force_clear();
$session = ImportSession::create_session( '/nonexistent/backup.swish', array() );
$key = $session['secret_key'];

$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$lock = 'swish_import_' . substr( md5( $key ), 0, 32 );
$other->query( "SELECT GET_LOCK('{$lock}', 0)" );

$c = Container::get_instance();
$p = new ImportPipeline( new Logger(), $c->get( RestoreManager::class ), $c->get( Migrator::class ) );
$r = $p->execute( $key );
swish_assert( ! empty( $r['busy'] ) && empty( $r['completed'] ), 'execute() reports busy while another driver holds the lock' );
swish_assert_same( 'initialized', ImportSession::get_state()['phase'] ?? '', 'state untouched while locked' );
$other->query( "SELECT RELEASE_LOCK('{$lock}')" ); $other->close();

$r = $p->execute( $key );
swish_assert( empty( $r['busy'] ), 'execute() runs once the lock is free' );
swish_assert( ! empty( $r['completed'] ) && empty( $r['success'] ), 'validate phase fails for a missing file (expected) once it runs' );
ImportSession::force_clear();
swish_done( 'import-lock' );
