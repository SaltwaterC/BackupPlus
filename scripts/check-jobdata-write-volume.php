<?php
/** Compare actual source-binlog bytes for old and current progress persistence using disposable metadata. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
require dirname( __DIR__ ) . '/backupplus.php';
global $backupplus, $wpdb;
function job_volume_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$jobs    = array( bin2hex( random_bytes( 6 ) ), bin2hex( random_bytes( 6 ) ) );
$job     = $jobs[1];
$before  = function () use ( $wpdb ) {
	$row = $wpdb->get_row( "SHOW GLOBAL STATUS LIKE 'Binlog_bytes_written'", ARRAY_A );
	return isset( $row['Value'] ) ? (int) $row['Value'] : null;
};
$parts   = function ( $id ) use ( $wpdb ) {
	$prefix = BackupPlus_Jobdata_Store::PREFIX . substr( hash( 'sha256', $id ), 0, 32 ) . '_';
	return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
};
$bytes   = 0;
$queries = 0;
$counter = function ( $query ) use ( &$bytes, &$queries ) {
	if ( preg_match( '/\A\s*(?:UPDATE|INSERT|REPLACE)\b/i', $query ) && false !== strpos( $query, 'backupplus_job' ) ) { $bytes += strlen( $query );
		++$queries; }
	return $query;
};
$files   = array( 'uploads' => array() );
for ( $i = 0; $i < 500; ++$i ) {
	$files['uploads'][ $i ]                          = 'backup_2026-01-01-0000_WriteVolume_abcdef123456-uploads' . ( $i + 1 ) . '.tar.zst.crypt';
	$files[ 'uploads' . ( $i ? $i : '' ) . '-size' ] = 123456;
}
$data = array(
	'job_type'                    => 'incremental',
	'jobstatus'                   => 'filescreating',
	'job_time_ms'                 => time(),
	'option_cache'                => array( 'synthetic' => 'private disposable credential' ),
	'previous_backup_files_array' => $files,
	'backup_files_array'          => $files,
);
add_filter( 'query', $counter );
try {
	$start = $before();
	for ( $i = 0; $i < 80; ++$i ) {
		$data['progress'] = $i;
		update_site_option( 'backupplus_jobdata_' . $jobs[0], BackupPlus_Credential_Storage::job( $data, $jobs[0], 'encrypt' ) );
	}
	$end               = $before();
	$legacy            = array(
		'query_bytes'  => $bytes,
		'writes'       => $queries,
		'binlog_bytes' => null === $start ? null : $end - $start,
	);
	$bytes             = $queries = 0;
	$start             = $before();
	$backupplus->nonce = $job;
	$backupplus->jobdata_reset();
	unset( $data['progress'] );
	$backupplus->jobdata_set_from_array( $data );
	for ( $i = 0; $i < 80; ++$i ) { $backupplus->jobdata_set( 'progress', $i ); }
	$end    = $before();
	$modern = array(
		'query_bytes'  => $bytes,
		'writes'       => $queries,
		'binlog_bytes' => null === $start ? null : $end - $start,
	);
	job_volume_expect( $modern['query_bytes'] < $legacy['query_bytes'] / 10, 'Progress writes must no longer scale with the full archive list.' );
	if ( null !== $legacy['binlog_bytes'] ) { job_volume_expect( $modern['binlog_bytes'] < $legacy['binlog_bytes'] / 10, 'Actual source binlog volume must drop by at least 90% for the same progress trace.' ); }
	job_volume_expect( count( $parts( $job ) ) === 1, 'Identical previous/current lists must share one immutable checkpoint.' );
	$raw = get_site_option( 'backupplus_jobdata_' . $job );
	job_volume_expect( strlen( serialize( $raw ) ) < 4096, 'Progress rows must stay small.' );
	$backupplus->jobdata_reset();
	job_volume_expect( $files === $backupplus->jobdata_get( 'backup_files_array' ), 'A resumed job must recover its full archive list.' );
	$writes = $queries;
	$backupplus->jobdata_set( 'progress', 79 );
	job_volume_expect( $queries === $writes && $raw === get_site_option( 'backupplus_jobdata_' . $job ), 'Unchanged saves must emit no SQL or fresh credential ciphertext.' );

	// All referenced versions are immutable: an interrupted parent write keeps the old checkpoint usable.
	$reject_parent = function ( $value, $old ) {
		return $old;
	};
	add_filter( 'pre_update_option_backupplus_jobdata_' . $job, $reject_parent, 100, 2 );
	$changed              = $files;
	$changed['uploads'][] = 'new-completed-part.tar.zst.crypt';
	$rejected             = false;
	try { $backupplus->jobdata_set( 'backup_files_array', $changed ); }
	catch ( RuntimeException $error ) { $rejected = true; }
	finally { remove_filter( 'pre_update_option_backupplus_jobdata_' . $job, $reject_parent, 100 ); }
	job_volume_expect( $rejected, 'A failed parent checkpoint must stop publication.' );
	$backupplus->jobdata_reset();
	job_volume_expect( $files === $backupplus->jobdata_get( 'backup_files_array' ), 'The previous list must survive a failed parent commit.' );
	$backupplus->jobdata_set( 'backup_files_array', $changed );
	$backupplus->jobdata_reset();
	job_volume_expect( $changed === $backupplus->jobdata_get( 'backup_files_array' ) && $files === $backupplus->jobdata_get( 'previous_backup_files_array' ), 'Changing one list must leave the prior checkpoint intact.' );
	$checkpoint      = get_site_option( 'backupplus_jobdata_' . $job );
	$hash            = $checkpoint['backup_files_array'][ BackupPlus_Jobdata_Store::MARKER ];
	$part_name       = BackupPlus_Jobdata_Store::PREFIX . substr( hash( 'sha256', $job ), 0, 32 ) . '_' . $hash;
	$part            = get_site_option( $part_name );
	$corrupt         = $part;
	$corrupt['data'] = 'corrupt checkpoint';
	foreach ( array( $corrupt, false ) as $invalid ) {
		if ( false === $invalid ) { delete_site_option( $part_name ); }
		else { update_site_option( $part_name, $invalid ); }
		$rejected = false;
		try { $backupplus->jobdata_getarray( $job ); }
		catch ( RuntimeException $error ) { $rejected = true; }
		job_volume_expect( $rejected, 'Missing or corrupt checkpoints must fail before a job resumes.' );
	}
	update_site_option( $part_name, $part );
	$rejected = false;
	try { BackupPlus_Jobdata_Store::unpack( $jobs[0], $checkpoint ); }
	catch ( RuntimeException $error ) { $rejected = true; }
	job_volume_expect( $rejected, 'Checkpoint references must remain bound to their own job.' );
	job_volume_expect( $files === $backupplus->jobdata_getarray( $jobs[0] )['backup_files_array'], 'Existing inline job records must still resume.' );
	delete_site_option( 'backupplus_jobdata_' . $job );
	job_volume_expect( array() === $parts( $job ), 'Expiring a job must remove all of its checkpoint versions.' );

	// An old pre-publication orphan must be reaped even if its parent never existed.
	$orphan = bin2hex( random_bytes( 6 ) );
	$jobs[] = $orphan;
	BackupPlus_Jobdata_Store::pack( $orphan, array( 'backup_files_array' => $files ), array() );
	foreach ( $parts( $orphan ) as $name ) { $part = get_site_option( $name );
		$part['created_at']                        = time() - 86401;
		update_site_option( $name, $part ); }
	BackupPlus_Jobdata_Store::collect_orphans();
	job_volume_expect( array() === $parts( $orphan ), 'Expired orphan checkpoint versions must be collected.' );
	echo wp_json_encode(
		array(
			'legacy'            => $legacy,
			'current'           => $modern,
			'reduction_percent' => round( 100 * ( 1 - $modern['query_bytes'] / $legacy['query_bytes'] ), 2 ),
		)
	), PHP_EOL;
	WP_CLI::success( 'Raw binlog/query volume, resumptions, no-op saves, atomic checkpoints and cleanup passed.' );
} finally {
	remove_filter( 'query', $counter );
	foreach ( $jobs as $id ) { delete_site_option( 'backupplus_jobdata_' . $id );
		BackupPlus_Jobdata_Store::deleted( 'backupplus_jobdata_' . $id ); }
}
