<?php
/**
 * A second request for the same job must not run a step while another request
 * holds the job lock: it gets a "busy" answer and the state is unchanged.
 */
require __DIR__ . '/lib.php';
wp_set_current_user( 1 );

$req = new WP_REST_Request( 'POST', '/swish-backup/v1/pipeline/start' ); $req->set_param( 'type', 'database' );
$start = rest_do_request( $req )->get_data();
$job_id = $start['job_id'] ?? '';
swish_assert( '' !== $job_id, 'job started' );

// Hold the lock from a second connection, as a concurrent request would.
$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$res = $other->query( "SELECT GET_LOCK('swish_pipeline_{$job_id}', 0)" );
swish_assert_same( '1', (string) $res->fetch_row()[0], 'test connection holds the job lock' );

$before = get_option( 'swish_pipeline_job_' . $job_id );
$req = new WP_REST_Request( 'POST', '/swish-backup/v1/pipeline/continue/' . $job_id );
$resp = rest_do_request( $req );
$data = $resp->get_data();
swish_assert_same( 200, $resp->get_status(), 'busy answer is HTTP 200 (not an error the browser would count)' );
swish_assert( ! empty( $data['busy'] ), 'response flags busy' );
swish_assert( empty( $data['completed'] ) && 'indexing' === ( $data['phase'] ?? '' ), 'phase unchanged while locked' );
wp_cache_delete( 'swish_pipeline_job_' . $job_id, 'options' );
$after = get_option( 'swish_pipeline_job_' . $job_id );
swish_assert_same( $before['index_offset'], $after['index_offset'], 'no step executed while locked' );

$other->query( "SELECT RELEASE_LOCK('swish_pipeline_{$job_id}')" ); $other->close();

// Once released, the step runs.
$data = rest_do_request( new WP_REST_Request( 'POST', '/swish-backup/v1/pipeline/continue/' . $job_id ) )->get_data();
swish_assert( empty( $data['busy'] ), 'step runs after the lock is released' );

// Cleanup: finish the job quickly then remove artifacts.
for ( $i = 0; $i < 100; $i++ ) { $data = rest_do_request( new WP_REST_Request( 'POST', '/swish-backup/v1/pipeline/continue/' . $job_id ) )->get_data(); if ( ! empty( $data['completed'] ) || 'failed' === $data['phase'] ) { break; } }
if ( ! empty( $data['file_path'] ) ) { @unlink( $data['file_path'] ); }
$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->prefix . 'swish_backup_jobs', array( 'job_id' => $job_id ) );
delete_option( 'swish_pipeline_job_' . $job_id );
swish_done( 'rest-pipeline-lock' );
