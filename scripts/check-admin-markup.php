<?php
/** Check rendered job status, upload destinations and restore table controls without changing site data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}

function admin_markup_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function admin_markup_document( $html ) {
	$previous = libxml_use_internal_errors( true );
	$document = new DOMDocument();
	$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$xpath = new DOMXPath( $document );
	$ids   = array();
	foreach ( $xpath->query( '//*[@id]' ) as $element ) {
		$id = $element->getAttribute( 'id' );
		admin_markup_expect( '' !== $id && ! preg_match( '/\s/', $id ), 'An ID contains whitespace: ' . $id );
		admin_markup_expect( ! isset( $ids[ $id ] ), 'Duplicate ID: ' . $id );
		$ids[ $id ] = true;
	}
	foreach ( $xpath->query( '//label[@for]' ) as $label ) {
		admin_markup_expect( isset( $ids[ $label->getAttribute( 'for' ) ] ), 'A label has no matching control: ' . $label->getAttribute( 'for' ) );
	}
	return $xpath;
}

$directory = get_temp_dir() . 'backupplus-admin-markup-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory );
register_shutdown_function(
	function () use ( $directory ) {
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink( $directory . '/' . $entry );
			}
		}
		rmdir( $directory );
	}
);
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory;
	},
	100
);
add_filter(
	'pre_option_cron',
	function () {
		return array();
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) {
		return $old;
	},
	100,
	2
);

global $backupplus, $backupplus_admin, $wpdb;
require dirname( __DIR__ ) . '/backupplus.php';
backupplus_try_include_file( 'admin.php', 'include_once' );

try {
	$job_id   = '0123456789ab';
	$job_data = array(
		'backup_time' => time(),
		'jobstatus'   => 'begun',
	);
	$read_job = function () use ( &$job_data ) {
		return $job_data;
	};
	add_filter( 'pre_option_backupplus_jobdata_' . $job_id, $read_job, 100 );
	add_filter( 'pre_site_option_backupplus_jobdata_' . $job_id, $read_job, 100 );
	$method    = new ReflectionMethod( 'BackupPlus_Admin', 'print_active_job' );
	$fragments = array( 'active_job' => $method->invoke( $backupplus_admin, $job_id, false, time() + 300, 1 ) );
	$xpath     = admin_markup_document( $fragments['active_job'] );
	admin_markup_expect( 1 === $xpath->query( '//*[@id="backupplus-jobid-' . $job_id . '"]' )->length, 'The active job must be addressable by its full ID' );

	$job_data = array();
	file_put_contents( $backupplus->get_logfile_name( $job_id ), 'Finished test job' );
	touch( $backupplus->get_logfile_name( $job_id ), time() - 180 );
	$response                  = $backupplus_admin->get_activejobs_list( array( 'thisjobonly' => $job_id ) );
	$fragments['finished_job'] = $response['j'];
	$xpath                     = admin_markup_document( $fragments['finished_job'] );
	admin_markup_expect( 1 === $xpath->query( '//*[@id="backupplus-jobid-' . $job_id . '" and contains(@class, "backupplus_finished")]' )->length, 'The completed job must be detectable by its full ID and finished class' );
	foreach ( array( false, true ) as $wide ) {
		ob_start();
		$backupplus_admin->render_active_jobs_and_log_table( $wide, false );
		$scaffolding = ob_get_clean();
		$xpath       = admin_markup_document( $scaffolding );
		admin_markup_expect( 1 === $xpath->query( '//*[@id="backupplus_activejobsrow"]/*[@id="backupplus_activejobs"]' )->length, 'The backup panel must contain a valid jobs container' );
		$expected_class = $wide ? 'minimum-height' : 'hidden';
		admin_markup_expect( 1 === $xpath->query( '//*[@id="backupplus_activejobsrow" and contains(@class, "' . $expected_class . '")]' )->length, 'The backup panel must use the expected layout class' );
	}

	// Force two destinations only for this render, without configuring or contacting either provider.
	$services = function () {
		return array( 's3', 'sftp' );
	};
	add_filter( 'BackupPlus_savestorage', $services );
	$fragments['upload'] = $backupplus_admin->include_template( 'wp-admin/settings/upload-backups-modal.php', true );
	remove_filter( 'BackupPlus_savestorage', $services );
	$xpath = admin_markup_document( $fragments['upload'] );
	foreach ( array( 's3', 'sftp' ) as $service ) {
		admin_markup_expect( 1 === $xpath->query( '//input[@id="backupplus_remote_' . $service . '" and @type="checkbox" and @checked and @value="' . $service . '"]' )->length, 'Upload destinations need distinct, checked checkboxes' );
	}

	foreach ( array( '3.0.0', '1.16.32' ) as $version ) {
		$dump = '# Backup of: ' . site_url() . "\n# Created by UpdraftPlus version $version\n# Table prefix: {$wpdb->prefix}\n";
		foreach ( array( $wpdb->posts, $wpdb->term_relationships ) as $table ) {
			$dump .= "CREATE TABLE `$table` (id bigint);\n";
		}
		$dump .= '# ' . str_repeat( 'padding ', 200 ) . "\n";
		file_put_contents( $directory . '/tables.sql', $dump );
		$analysis = $backupplus->analyse_db_file( time(), array(), $directory . '/tables.sql' );
		admin_markup_expect( empty( $analysis[2] ), 'The synthetic database must scan without errors' );
		$fragments[ 'tables_' . $version ] = $analysis[3]['addui'];
		$xpath                             = admin_markup_document( $analysis[3]['addui'] );
		foreach ( array( $wpdb->posts, $wpdb->term_relationships ) as $table ) {
			$inputs = $xpath->query( '//input[@id="backupplus_restore_table_' . $table . '" and @type="checkbox" and @name="backupplus_restore_tables_options[]" and @value="' . $table . '"]' );
			admin_markup_expect( 1 === $inputs->length, 'Restore tables must render as usable checkboxes: ' . $table );
			$expected_checked = '1.16.32' !== $version || $wpdb->term_relationships !== $table;
			admin_markup_expect( $expected_checked === $inputs->item( 0 )->hasAttribute( 'checked' ), 'Restore table defaults must preserve the composite-key safeguard' );
		}
	}
	if ( in_array( 'json', $args, true ) ) {
		echo wp_json_encode( $fragments );
	} else {
		WP_CLI::success( 'Job status IDs, upload labels and restore table controls passed.' );
	}
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
}
