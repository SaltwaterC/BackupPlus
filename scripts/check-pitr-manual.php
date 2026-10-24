<?php

/** Real admin request, cron dispatch and native capture on the disposable source. */

backupplus_try_include_file( 'admin.php', 'include_once' );
$manual_admin = new BackupPlus_Admin();
// The fixture intercepts cron persistence. A queued event must still report success.
add_filter(
	'pre_schedule_event',
	function ( $result, $event ) use ( &$test_cron ) {
		if ( 'backupplus_backupnow_start' !== $event->hook ) {
			return $result;
		}
		$test_cron[ $event->timestamp ][ $event->hook ][ md5( serialize( $event->args ) ) ] = array(
			'schedule' => false,
			'args'     => $event->args,
		);
		$test_cron['version'] = 2;
		return true;
	},
	100,
	2
);
add_filter(
	'pre_http_request',
	function ( $result, $arguments, $url ) {
		return false !== strpos( $url, 'wp-cron.php' ) ? array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		) : $result;
	},
	100,
	3
);
$manual_capture = function ( $files = false ) use ( $manual_admin, $config, &$history, &$backupplus ) {
	$configuration = function ( $value, $name ) use ( $config ) {
		return BackupPlus_PITR::OPTION === $name ? $config : $value;
	};
	add_filter( 'backupplus_get_option', $configuration, 200, 2 );
	$reply = null;
	$count = count( $history );
	$ok    = $manual_admin->request_backupnow(
		array(
			'incremental'            => 1,
			'backupnow_nodb'         => 0,
			'backupnow_nofiles'      => $files ? 0 : 1,
			'backupnow_nocloud'      => 1,
			'database_selection'     => 'stale invalid logical dump selection',
			'onlythesetableentities' => array( 'stale invalid logical dump selection' ),
		),
		function ( $message ) use ( &$reply ) {
			$reply = $message;
		}
	);
	pitr_check( true === $ok && isset( $reply['nonce'] ) && $count === count( $history ), 'The manual request did not queue capture independently of logical dump selection: ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : json_encode( array( $ok, $reply, $count, count( $history ) ) ) ) );
	$queued = null;
	foreach ( _get_cron_array() as $timestamp => $hooks ) {
		foreach ( isset( $hooks['backupplus_backupnow_start'] ) ? $hooks['backupplus_backupnow_start'] : array() as $event ) {
			if ( $reply['nonce'] === $event['args'][1]['use_nonce'] ) {
				$queued = $event['args'];
				wp_unschedule_event( $timestamp, 'backupplus_backupnow_start', $event['args'] );
			}
		}
	}
	pitr_check( $queued && ! empty( $queued[1]['pitr_capture'] ), 'The queued backup lost its PITR routing marker.' );
	$file_options = null;
	$observe      = function ( $options ) use ( &$file_options ) {
		$file_options = $options;
	};
	remove_action( 'backupplus_backupnow_backup', array( $backupplus, 'backupnow_files' ) );
	add_action( 'backupplus_backupnow_backup', $observe );
	try {
		$result = $backupplus->dispatch_backupnow( $queued[0], $queued[1] );
		pitr_check( $result && $reply['nonce'] === $backupplus->nonce && 'finished' === $backupplus->jobdata_get( 'jobstatus' ), 'Manual capture failed or lost its progress identifier.' );
		pitr_check( $files === is_array( $file_options ) && ( ! $files || ( ! empty( $file_options['incremental'] ) && empty( $file_options['pitr_capture'] ) ) ), 'A manual request incorrectly dispatched its file stage.' );
		pitr_check( false !== strpos( file_get_contents( $backupplus->get_logfile_name( $reply['nonce'] ) ), 'PITR database recovery point captured.' ), 'Manual capture did not publish its result in the requested log.' );
		return $result;
	} finally {
		remove_filter( 'backupplus_get_option', $configuration, 200 );
		remove_action( 'backupplus_backupnow_backup', $observe );
		add_action( 'backupplus_backupnow_backup', array( $backupplus, 'backupnow_files' ) );
	}
};
