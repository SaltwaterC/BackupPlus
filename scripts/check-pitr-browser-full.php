<?php
/** Full-snapshot browser job handoff and execution on the redirected disposable database. */
require_once BACKUPPLUS_DIR . '/includes/class-pitr-restore.php';
// The wider native suite uses history filters. This regression must persist history in SQL.
$current_history = BackupPlus_Backup_History::get_history();
remove_all_filters( 'pre_option_backupplus_backup_history' );
remove_all_filters( 'pre_update_option_backupplus_backup_history' );
BackupPlus_Backup_History::save_history( $current_history );
BackupPlus_Backup_History::always_get_from_db();
// Earlier selective imports deliberately do not rescan history. Normalize any stale
// fixture archives before testing that the full import preserves current history.
BackupPlus_Backup_History::rebuild();
$backupplus->admin_menu();
$browser_config = function ( $value, $name ) use ( $config ) {
	return BackupPlus_PITR::OPTION === $name ? $config : $value;
};
add_filter( 'backupplus_get_option', $browser_config, 200, 2 );
// Core cache cleanup expects the postmeta table, even in this minimal WordPress fixture.
$application->query( 'USE `' . DB_NAME . '`; CREATE TABLE IF NOT EXISTS `' . $wpdb->postmeta . '` (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255), meta_value longtext) ENGINE=InnoDB' );
$expected_value = $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0];
$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=999' );
$history_worker = new BackupPlus_PITR( $config );
$history_worker->run( 'capture' );
unset( $history_worker );
// The encrypted baseline must use the same importer and the application connection, without replay credentials.
add_filter( 'backupplus_user_can_manage', '__return_true', 100 );
$baseline_ticket  = '';
$original_request = $_REQUEST;
try {
	$baseline_time = null;
	foreach ( BackupPlus_Backup_History::get_history() as $time => $backup ) {
		if ( isset( $backup['pitr']['chain_id'] ) && $encrypted_full['chain_id'] === $backup['pitr']['chain_id'] ) { $baseline_time = $time;
			break; }
	}
	pitr_check( null !== $baseline_time, 'The encrypted baseline disappeared from history.' );
	$later_file = 'backup_' . gmdate( 'Y-m-d-Hi', $baseline_time + 86400 ) . '_HistoryFixture_0123456789ab-plugins.zip';
	$zip        = new ZipArchive();
	$zip->open( $backupplus->backups_dir_location() . '/' . $later_file, ZipArchive::CREATE );
	$zip->addFromString( 'plugins/fixture.txt', 'Later file backup' );
	$zip->close();
	$history_before                                  = BackupPlus_Backup_History::get_history();
	$history_before[ $baseline_time ]['label']       = 'Current PITR chain';
	$history_before[ $baseline_time ]['always_keep'] = true;
	$history_before[ $baseline_time + 86400 ]        = array(
		'nonce'        => '0123456789ab',
		'plugins'      => array( $later_file ),
		'plugins-size' => filesize( $backupplus->backups_dir_location() . '/' . $later_file ),
		'native'       => true,
		'service'      => array( 'none' ),
		'label'        => 'Later file backup',
		'always_keep'  => true,
	);
	BackupPlus_Backup_History::save_history( $history_before );
	$history_before            = BackupPlus_Backup_History::get_history();
	$baseline_set              = BackupPlus_Backup_History::get_history( $baseline_time );
	$baseline_set['timestamp'] = $baseline_time;
	$prepared                  = BackupPlus_PITR_Restore::manifest( $baseline_time, $encrypted_full['backup_id'], '' );
	$baseline_ticket           = $prepared['ticket'];
	$plain                     = BackupPlus_Encryption::decrypt( BackupPlus_Age_Restore::download( $baseline_ticket, $baseline_set['db'] ), $key['identity'], true, '' );
	pitr_check( is_array( $plain ), 'Could not prepare the encrypted baseline fixture.' );
	$bytes = file_get_contents( $plain['fullpath'] );
	unlink( $plain['fullpath'] );
	for ( $offset = 0; $offset < strlen( $bytes ); $offset += 524288 ) {
		$chunk = substr( $bytes, $offset, 524288 );
		$last  = $offset + strlen( $chunk ) === strlen( $bytes );
		BackupPlus_Age_Restore::upload( $baseline_ticket, $baseline_set['db'], $offset, $chunk, $last ? strlen( $bytes ) : null, $last ? hash( 'sha256', $bytes ) : '' );
	}
	// Submit the same top-level fields as the restore form, then load the job in a new request.
	global $backupplus_admin;
	$_REQUEST = array(
		'action'                              => 'backupplus_restore',
		'backupplus_ajax_restore'             => 'start_ajax_restore',
		'backup_timestamp'                    => $baseline_time,
		'backupplus_restore'                  => array( 'db' ),
		'backupplus_pitr_restore_point'       => $encrypted_full['backup_id'],
		'backupplus_age_ticket'               => $baseline_ticket,
		'backupplus_restorer_restore_options' => '',
	);
	ob_start();
	$backupplus_admin->prepare_restore();
	ob_end_clean();
	$job_id = $backupplus->nonce;
	$backupplus->jobdata_reset();
	$_REQUEST    = array(
		'action' => 'backupplus_ajaxrestore',
		'job_id' => $job_id,
	);
	$get_options = new ReflectionMethod( $backupplus_admin, 'get_restore_options_from_jobdata' );
	if ( PHP_VERSION_ID < 80100 ) { $get_options->setAccessible( true ); }
	$restore_options = $get_options->invoke( $backupplus_admin );
	pitr_check( $encrypted_full['backup_id'] === $restore_options['backupplus_pitr_restore_point'], 'The saved full-snapshot selection did not reach restore execution.' );
	$backupplus->jobdata_set( 'restore_options', $restore_options );
	$backupplus->jobdata_reset();
	$restore_options = $backupplus->jobdata_get( 'restore_options' );
	pitr_check( $encrypted_full['backup_id'] === $restore_options['backupplus_pitr_restore_point'], 'Restore continuation lost the full-snapshot selection.' );
	$encrypted_importer             = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $baseline_set, false, $restore_options );
	$GLOBALS['backupplus_restorer'] = $encrypted_importer;
	$errors                         = new ReflectionProperty( $encrypted_importer, 'errors' );
	if ( PHP_VERSION_ID < 80100 ) { $errors->setAccessible( true ); }
	$imported = $encrypted_importer->perform_restore( array( 'db' => 'db' ), $restore_options );
	pitr_check( true === $imported && ! $errors->getValue( $encrypted_importer ), 'The full restore execution rejected an encrypted PITR baseline: ' . ( is_wp_error( $imported ) ? $imported->get_error_message() : json_encode( $imported ) ) );
	pitr_check( $expected_value === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Encrypted baseline restore replayed later changes or lost snapshot data.' );
	pitr_check( $history_before === BackupPlus_Backup_History::get_history(), 'Full-snapshot restore replaced current PITR/file backup history, labels or retention locks with historical options.' );
	$encrypted_importer->post_restore_clean_up( true, false );
	foreach ( array( false, true ) as $remote_scan ) {
		BackupPlus_Backup_History::rebuild( $remote_scan );
		$rescanned_history = BackupPlus_Backup_History::get_history();
		foreach ( $history_before as $time => $backup ) {
			// Remote scans may refresh destinations on other ordinary backups in the wider suite.
			if ( empty( $backup['pitr'] ) && $time !== $baseline_time + 86400 ) { continue; }
			pitr_check( isset( $rescanned_history[ $time ] ) && $backup === $rescanned_history[ $time ], 'Post-restore rescan changed PITR or later file history at ' . $time . ' (remote=' . (int) $remote_scan . ').' );
		}
	}
	$history_after_scans = BackupPlus_Backup_History::get_history();
	$stored_history      = maybe_unserialize( BackupPlus_SQL::wpdb()->value( 'SELECT option_value FROM %i WHERE option_name=?', array( $wpdb->options ), array( 'backupplus_backup_history' ) ) );
	pitr_check( isset( $stored_history[ $baseline_time ]['pitr']['chain_id'] ) && $encrypted_full['chain_id'] === $stored_history[ $baseline_time ]['pitr']['chain_id'] && ! isset( $stored_history[ $baseline_time ]['pitr']['points'] ), 'The restored database must retain the compact PITR chain identity.' );
	$durable_history = BackupPlus_PITR::decorate_history( $stored_history );
	pitr_check( $history_after_scans[ $baseline_time ]['pitr'] === $durable_history[ $baseline_time ]['pitr'] && 2 <= count( $durable_history[ $baseline_time ]['pitr']['points'] ), 'PITR recovery points must reconstruct from the durable index after restoring and rescanning.' );
	// A generic rescan must leave PITR availability to the chain index, even without a local full archive.
	$full_path = $backupplus->backups_dir_location() . '/' . $baseline_set['db'];
	rename( $full_path, $full_path . '.fixture-hidden' );
	try {
		BackupPlus_Backup_History::rebuild();
		pitr_check( $history_after_scans === BackupPlus_Backup_History::get_history(), 'An ordinary rescan pruned a PITR chain with no local full archive.' );
	} finally {
		rename( $full_path . '.fixture-hidden', $full_path );
	}
	// Plaintext PITR snapshots must not be offered to the foreign SQL-dump scanner.
	$plain_path = substr( $full_path, 0, -strlen( '.crypt' ) );
	file_put_contents( $plain_path, gzencode( "# PITR snapshot fixture\n" ) );
	$foreign_checked = false;
	$foreign_filter  = function ( $accepted, $filename ) use ( $plain_path, &$foreign_checked ) {
		if ( basename( $plain_path ) === $filename ) { $foreign_checked = true; }
		return $accepted;
	};
	add_filter( 'backupplus_accept_foreign', $foreign_filter, 200, 2 );
	try {
		BackupPlus_Backup_History::rebuild();
		pitr_check( ! $foreign_checked && $history_after_scans === BackupPlus_Backup_History::get_history(), 'A plaintext PITR snapshot was scanned as an ordinary foreign backup.' );
	} finally {
		remove_filter( 'backupplus_accept_foreign', $foreign_filter, 200 );
		unlink( $plain_path );
	}
	// Ordinary local backups still disappear when their archives have been removed.
	unlink( $backupplus->backups_dir_location() . '/' . $later_file );
	BackupPlus_Backup_History::rebuild();
	$remaining_history = BackupPlus_Backup_History::get_history();
	pitr_check( ! isset( $remaining_history[ $baseline_time + 86400 ] ) && $history_before[ $baseline_time ] === $remaining_history[ $baseline_time ], 'Rescanning must prune missing ordinary backups while retaining the PITR chain.' );
	// A failed restore must put its returned WP_Error in the visible error list.
	$backupplus->errors = array();
	$_REQUEST           = array(
		'action'             => 'backupplus_restore',
		'backup_timestamp'   => 1,
		'backupplus_restore' => array( 'db' ),
	);
	ob_start();
	$backupplus_admin->prepare_restore();
	$failure_html = ob_get_clean();
	pitr_check( preg_match( '#<div class="backupplus_restore_errors">.*Backup does not exist in the backup history.*</div>#s', $failure_html ), 'Restore failure rendered an empty error box.' );
	$backupplus->errors = array();
} finally {
	$_REQUEST = $original_request;
	if ( $baseline_ticket ) { BackupPlus_Age_Restore::discard( $baseline_ticket ); }
	remove_filter( 'backupplus_user_can_manage', '__return_true', 100 );
	remove_filter( 'backupplus_get_option', $browser_config, 200 );
}
echo "PITR encrypted full-snapshot form submission, saved-job handoff, SQL import, persistent history, repeated rescans and visible failure messages passed.\n";
