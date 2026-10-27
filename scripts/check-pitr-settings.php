<?php

/** Real WordPress option sanitization, schedule reconciliation and one-control markup. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
$directory = get_temp_dir() . 'backupplus-pitr-settings-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$current = array( 'enabled' => false );
$cron    = array();
foreach ( array( 'backupplus_pitr_status', 'backupplus_lastmessage', 'backupplus_lastlogmessage' ) as $name ) {
	add_filter(
		'pre_update_option_' . $name,
		function ( $value, $old ) {
			return $old;
		},
		100,
		2
	);
}
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory;
	},
	100
);
add_filter(
	'pre_option_backupplus_pitr',
	function () use ( &$current ) {
		return $current;
	},
	100
);
add_filter(
	'pre_update_option_backupplus_pitr',
	function ( $value, $old ) use ( &$current ) {
		$current = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_cron',
	function () use ( &$cron ) {
		return $cron;
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) use ( &$cron ) {
		$cron = $value;
		return $old;
	},
	100,
	2
);
$remove = function ( $path ) use ( &$remove ) {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( ! $entry->isDot() ) {
				$remove( $entry->getPathname() );
			}
		} rmdir( $path );
	} else {
		unlink( $path );
	}
};
try {
	require dirname( __DIR__ ) . '/backupplus.php';
	BackupPlus_PITR_Admin::settings();
	$current = array(
		'enabled'  => true,
		'interval' => 5,
	);
	BackupPlus_PITR::reconcile_schedule();
	if ( 5 !== BackupPlus_PITR::config()['interval'] || 'backupplus_pitr_5' !== wp_get_schedule( BackupPlus_PITR::HOOK ) ) {
		throw new RuntimeException( 'A saved five-minute interval was not preserved.' );
	}
	$expected = array(
		0   => 'Disabled',
		1   => 'Every minute',
		5   => 'Every 5 minutes',
		10  => 'Every 10 minutes',
		15  => 'Every 15 minutes',
		30  => 'Every 30 minutes',
		60  => 'Every hour',
		120 => 'Every 2 hours',
		180 => 'Every 3 hours',
		240 => 'Every 4 hours',
		360 => 'Every 6 hours',
		480 => 'Every 8 hours',
		720 => 'Every 12 hours',
	);
	foreach ( $expected as $minutes => $label ) {
		$previous = $current;
		update_option( BackupPlus_PITR::OPTION, array( 'interval' => (string) $minutes ) );
		// The test intercepts persistence; dispatch the same notification as a committed save.
		do_action( 'updated_option', BackupPlus_PITR::OPTION, $previous, $current );
		if ( ( 0 !== $minutes ) !== $current['enabled'] || ( $minutes && $minutes !== $current['interval'] ) || isset( $current['global_read_lock'] ) ) {
			throw new RuntimeException( 'Saving the capture dropdown failed: ' . $label );
		}
		$recurrence = $minutes ? 'backupplus_pitr_' . min( 60, $minutes ) : false;
		if ( $recurrence !== wp_get_schedule( BackupPlus_PITR::HOOK ) ) {
			throw new RuntimeException( 'Capture or daily baseline checks were incorrectly scheduled.' );
		}
		ob_start();
		BackupPlus_PITR_Admin::controls( $current );
		$html     = ob_get_clean();
		$document = new DOMDocument();
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		$xpath = new DOMXPath( $document );
		if ( 1 !== $xpath->query( '//select[@id="backupplus-pitr-interval"]' )->length || 1 !== $xpath->query( '//input[@type="checkbox" and @name="backupplus_pitr[exact]"]' )->length || 1 !== $xpath->query( '//input[@type="time"]' )->length || 1 !== $xpath->query( '//input[@name="backupplus_pitr[time]"]' )->length ) {
			throw new RuntimeException( 'PITR settings must render the interval and exact-time controls.' );
		}
		$actual = array();
		foreach ( $xpath->query( '//select[@id="backupplus-pitr-interval"]/option' ) as $option ) {
			$actual[ (int) $option->getAttribute( 'value' ) ] = $option->textContent;
		}
		if ( $expected !== $actual || (string) $minutes !== $xpath->query( '//select[@id="backupplus-pitr-interval"]/option[@selected]' )->item( 0 )->getAttribute( 'value' ) ) {
			throw new RuntimeException( 'Dropdown choices or selected value differed from the requested UI.' );
		}
	}
	$previous = $current;
	update_option( BackupPlus_PITR::OPTION, array( 'interval' => '7' ) );
	if ( $previous !== $current ) {
		throw new RuntimeException( 'An unsupported capture interval changed saved settings.' );
	}
	global $wpdb;
	$engine = false !== stripos( $wpdb->db_server_info(), 'mariadb' ) ? 'mariadb' : 'mysql';
	if ( $engine !== $current['engine'] || ( fileperms( $current['source_config'] ) & 0077 ) ) {
		throw new RuntimeException( 'Engine detection or private credential permissions failed.' );
	}
	$disabled            = $current;
	$disabled['enabled'] = false;
	BackupPlus_PITR::save_configuration( $disabled );
	if ( $current['enabled'] || false === has_filter( 'sanitize_option_' . BackupPlus_PITR::OPTION, array( 'BackupPlus_PITR_Admin', 'sanitize' ) ) ) {
		throw new RuntimeException( 'Programmatic restore state was re-enabled by the settings form sanitizer.' );
	}
	update_option( BackupPlus_PITR::OPTION, array( 'interval' => '0' ) );
	do_action( 'updated_option', BackupPlus_PITR::OPTION, $previous, $current );
	if ( $current['enabled'] || BackupPlus_PITR::next_scheduled() || ! is_file( $current['source_config'] ) ) {
		throw new RuntimeException( 'Disabling PITR failed to pause capture and preserve its configuration.' );
	}
	backupplus_try_include_file( 'admin.php', 'include_once' );
	$admin               = new BackupPlus_Admin();
	$current['enabled']  = true;
	$current['interval'] = 1;
	BackupPlus_PITR::reconcile_schedule();
	$next_pitr = BackupPlus_PITR::next_scheduled();
	$pitr_html = $admin->next_scheduled_pitr_backups_output( true );
	if ( ! $next_pitr || false === strpos( $pitr_html, wp_date( 'D, F j, Y H:i', $next_pitr ) ) || false === strpos( $pitr_html, 'backupplus_next_scheduled_date_time' ) ) {
		throw new RuntimeException( 'The PITR schedule display did not use the next cron run and site timezone.' );
	}
	$current['enabled'] = false;
	if ( false === strpos( $admin->next_scheduled_pitr_backups_output( true ), 'Nothing currently scheduled' ) ) { throw new RuntimeException( 'Disabled PITR displayed a stale scheduled run.' ); }
	BackupPlus_PITR::reconcile_schedule();

	$request = array(
		'incremental'       => 1,
		'backupnow_nodb'    => 0,
		'backupnow_nofiles' => 1,
	);
	$before  = $cron;
	$result  = $admin->request_backupnow( $request );
	if ( ! is_wp_error( $result ) || 'pitr_disabled' !== $result->get_error_code() || $before !== $cron ) {
		throw new RuntimeException( 'A disabled PITR request was accepted or fell back to a logical database dump.' );
	}
	global $backupplus;
	$manual_nonce = bin2hex( random_bytes( 6 ) );
	try {
		$result = $backupplus->dispatch_backupnow(
			'backupplus_backupnow_backup_database',
			array(
				'incremental'  => true,
				'pitr_capture' => true,
				'use_nonce'    => $manual_nonce,
			)
		);
		if ( false !== $result || $manual_nonce !== $backupplus->nonce || ! $backupplus->jobdata_get( 'pitr_error' ) || 'finished' !== $backupplus->jobdata_get( 'jobstatus' ) ) {
			throw new RuntimeException( 'PITR disabled after queuing did not fail with the requested progress identifier.' );
		}
		$response = $admin->get_activejobs_list( array( 'thisjobonly' => $manual_nonce ) );
		if ( false === strpos( $response['j'], 'Database recovery point failed:' ) ) {
			throw new RuntimeException( 'The manual capture failure was missing from admin progress.' );
		}
	} finally {
		delete_site_option( 'backupplus_jobdata_' . $manual_nonce );
	}
	// Drive a native TLS failure through the real worker, progress renderer and log.
	$clients = $directory . '/tls-clients';
	mkdir( $clients, 0700 );
	$client = <<<'SH'
#!/bin/sh
case "$*" in
  *--version*) echo 'MariaDB 11.8.6'; exit 0 ;;
esac
# Read the inherited options before the TLS handshake, as the real client does.
cat <&3 >/dev/null
echo 'ERROR 2026 (HY000): TLS/SSL error: self-signed certificate' >&2
echo 'password=private-secret; SELECT private_data FROM customer_table' >&2
exit 1
SH;
	foreach ( array( 'mariadb', 'mariadb-dump', 'mariadb-binlog' ) as $binary ) {
		file_put_contents( $clients . '/' . $binary, $client );
		chmod( $clients . '/' . $binary, 0700 );
	}
	$current['enabled']       = true;
	$current['engine']        = 'mariadb';
	$current['bin_directory'] = $clients;
	add_filter(
		'backupplus_get_option',
		function ( $value, $name ) {
			if ( 'backupplus_service' === $name ) { return array( 'none' ); }
			if ( 'backupplus_database_encryption' === $name ) { return array( 'mode' => 'none' ); }
			return $value;
		},
		200,
		2
	);
	$manual_nonce = bin2hex( random_bytes( 6 ) );
	try {
		$result   = $backupplus->dispatch_backupnow(
			'backupplus_backupnow_backup_database',
			array(
				'incremental'  => true,
				'pitr_capture' => true,
				'use_nonce'    => $manual_nonce,
			)
		);
		$response = $admin->get_activejobs_list( array( 'thisjobonly' => $manual_nonce ) );
		$log      = file_get_contents( $backupplus->get_logfile_name( $manual_nonce ) );
		foreach ( array( $backupplus->jobdata_get( 'pitr_error' ), $response['j'], $log ) as $message ) {
			if ( false !== $result || false === strpos( $message, 'TLS certificate verification failed' ) || false === strpos( $message, 'self-signed' ) || false !== strpos( $message, 'private-secret' ) || false !== strpos( $message, 'customer_table' ) ) {
				throw new RuntimeException( 'The TLS failure was hidden or leaked native credentials/SQL in progress or logs.' );
			}
		}
	} finally {
		delete_site_option( 'backupplus_jobdata_' . $manual_nonce );
	}
	echo "PITR interval and exact-time settings, native engine detection and all capture schedules passed.\n";
} finally {
	$remove( $directory );
}
