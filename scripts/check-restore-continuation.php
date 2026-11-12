<?php
/**
 * BPS-11 in real WordPress, over HTTP: a logged-out request continues a restore only with the token issued to the restore page,
 * sent in the POST body, for the restore in progress and before the token expires. The continuation cannot start a restore or
 * save filesystem credentials. Starting a restore over AJAX and downloading the latest log need a manager as well as a nonce.
 *
 * The restore marker and token are restored afterwards; the disposable users, log file and job data are removed.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-restore-continuation.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function restore_continuation_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

require dirname( __DIR__ ) . '/backupplus.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $backupplus;

$missing          = new stdClass();
$saved            = array();
$names            = array( 'backupplus_restore_in_progress', BackupPlus_Restore_Continuation::OPTION );
$job              = bin2hex( random_bytes( 6 ) );
$log_job          = bin2hex( random_bytes( 6 ) );
$label            = 'backupplus_continuation_' . bin2hex( random_bytes( 6 ) );
$directory        = $backupplus->backups_dir_location();
$users            = array();
$original_user    = get_current_user_id();
$original_cookies = $_COOKIE;
foreach ( $names as $name ) {
	$saved[ $name ] = get_site_option( $name, $missing );
}
$fresh   = function ( $name ) {
	foreach ( array( $name, 'alloptions', 'notoptions' ) as $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( $key, 'site-options' );
	}
	return get_site_option( $name, false );
};
$cleanup = function () use ( $names, &$saved, $missing, $job, $log_job, $directory, &$users ) {
	foreach ( $names as $name ) {
		if ( $saved[ $name ] === $missing ) {
			delete_site_option( $name );
		} else {
			update_site_option( $name, $saved[ $name ] );
		}
	}
	delete_site_option( 'backupplus_jobdata_' . $job );
	foreach ( array( $job, $log_job ) as $name ) {
		if ( is_file( $directory . '/log.' . $name . '.txt' ) ) {
			unlink( $directory . '/log.' . $name . '.txt' );
		}
	}
	foreach ( $users as $user ) {
		if ( get_userdata( $user ) ) {
			wp_delete_user( $user );
		}
	}
	$users = array();
};
register_shutdown_function( $cleanup );

try {
	$ajax = admin_url( 'admin-ajax.php' );
	/**
	 * POST to admin-ajax.php, logged out unless cookies are given.
	 *
	 * @return Array - the status code and the body
	 */
	$post = function ( array $body, $cookie = '', $query = '' ) use ( $ajax ) {
		$response = wp_remote_post(
			$ajax . $query,
			array(
				'headers'     => '' === $cookie ? array() : array( 'Cookie' => $cookie ),
				'body'        => $body,
				'redirection' => 0,
				'timeout'     => 120,
			)
		);
		restore_continuation_expect( ! is_wp_error( $response ), 'admin-ajax.php must answer: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
		return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
	};
	// Nothing of the job may exist after a refused continuation: it is refused before the job is loaded
	$untouched = function ( $case ) use ( $job, $directory, $fresh ) {
		restore_continuation_expect( false === $fresh( 'backupplus_jobdata_' . $job ), $case . ': the refused continuation must not create job data' );
		restore_continuation_expect( ! is_file( $directory . '/log.' . $job . '.txt' ), $case . ': the refused continuation must not open the job log' );
	};

	update_site_option( 'backupplus_restore_in_progress', $job );
	$token    = BackupPlus_Restore_Continuation::issue( $job );
	$continue = array(
		'action'                  => 'backupplus_ajaxrestore_continue',
		'backupplus_ajax_restore' => 'do_ajax_restore',
		'job_id'                  => $job,
	);
	$stored   = $fresh( BackupPlus_Restore_Continuation::OPTION );
	restore_continuation_expect( is_array( $stored ) && hash( 'sha256', $token ) === $stored['hash'] && false === strpos( serialize( $stored ), $token ), 'Only the hash of the token may be stored' );

	// The finding: the job ID alone no longer continues the restore
	foreach ( array(
		'no token'                  => array( $continue, '' ),
		'another token'             => array( $continue + array( 'token' => bin2hex( random_bytes( 32 ) ) ), '' ),
		'the stored hash'           => array( $continue + array( 'token' => $stored['hash'] ), '' ),
		'the token in the URL only' => array( $continue, '?token=' . $token ),
		'start a restore'           => array(
			array_merge(
				$continue,
				array(
					'backupplus_ajax_restore' => 'start_ajax_restore',
					'token'                   => $token,
				)
			),
			'',
		),
		'filesystem credentials'    => array(
			array_merge(
				$continue,
				array(
					'backupplus_ajax_restore' => 'continue_ajax_restore',
					'token'                   => $token,
					'hostname'                => 'attacker.invalid',
					'username'                => 'attacker',
					'password'                => 'attacker',
					'connection_type'         => 'ftp',
				)
			),
			'',
		),
	) as $case => $request ) {
		list( $status, $body ) = $post( $request[0], '', $request[1] );
		restore_continuation_expect( 200 === $status && '' === $body, $case . ': the continuation must be refused without output, got ' . $status . ': ' . substr( $body, 0, 300 ) );
		$untouched( $case );
	}

	// The token of another job, or of a restore that is no longer in progress, is refused
	update_site_option( 'backupplus_restore_in_progress', $log_job );
	list( $status, $body ) = $post( $continue + array( 'token' => $token ) );
	restore_continuation_expect( '' === $body, 'A token must only continue the restore in progress' );
	$untouched( 'another restore in progress' );
	update_site_option( 'backupplus_restore_in_progress', $job );

	// An expired token is refused
	update_site_option( BackupPlus_Restore_Continuation::OPTION, array_merge( $stored, array( 'expires' => time() - 1 ) ) );
	list( $status, $body ) = $post( $continue + array( 'token' => $token ) );
	restore_continuation_expect( '' === $body, 'An expired token must be refused' );
	$untouched( 'expired token' );
	update_site_option( BackupPlus_Restore_Continuation::OPTION, $stored );

	// The issued token continues the restore while logged out. The job has no saved restore, so it stops with the error that says so.
	list( $status, $body ) = $post( $continue + array( 'token' => $token ) );
	restore_continuation_expect( 200 === $status && false !== strpos( $body, 'backupplus_restore_error' ) && false !== strpos( $body, 'job_id_nojobdata' ) && false === strpos( $body, 'job_id_mismatch' ), 'The issued token must continue the restore in progress: ' . substr( $body, 0, 500 ) );
	$jobdata = $fresh( 'backupplus_jobdata_' . $job );
	restore_continuation_expect( is_array( $jobdata ) && 'restore' === $jobdata['job_type'] && ! isset( $jobdata['filesystem_credentials'] ), 'The continuation must load the job without filesystem credentials' );

	// Starting a restore over AJAX is not open to logged-out requests at all, even with a nonce made for user 0
	wp_set_current_user( 0 );
	$_COOKIE               = array();
	list( $status, $body ) = $post(
		array(
			'action'                  => 'backupplus_ajaxrestore',
			'backupplus_ajax_restore' => 'do_ajax_restore',
			'job_id'                  => $job,
			'nonce'                   => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
		)
	);
	// admin-ajax.php answers 400 and "0" for an action without a handler; the WordPress HTTP client can drop that one-byte body from PHP's built-in server
	restore_continuation_expect( 400 === $status && in_array( $body, array( '0', '' ), true ), 'backupplus_ajaxrestore must not be registered for logged-out requests: ' . $status . ': ' . substr( $body, 0, 300 ) );

	// A logged-in user who is not a manager is refused, with a valid nonce of their own, by backupplus_ajaxrestore and downloadlatestmodlog
	$marker = 'BPS-11 latest log ' . bin2hex( random_bytes( 8 ) );
	file_put_contents( $directory . '/log.' . $log_job . '.txt', $marker );
	touch( $directory . '/log.' . $log_job . '.txt', time() + 3600 );
	foreach ( array(
		'subscriber'    => false,
		'administrator' => true,
	) as $role => $manager ) {
		$user = wp_insert_user(
			array(
				'user_login' => $label . '_' . $role,
				'user_pass'  => wp_generate_password( 40 ),
				'role'       => $role,
			)
		);
		restore_continuation_expect( ! is_wp_error( $user ), 'A disposable ' . $role . ' must be created' );
		$users[] = $user;
		wp_set_current_user( $user );
		restore_continuation_expect( $manager === BackupPlus_Options::user_can_manage(), 'The ' . $role . ' must ' . ( $manager ? '' : 'not ' ) . 'manage BackupPlus' );
		$session                     = WP_Session_Tokens::get_instance( $user )->create( time() + 600 );
		$logged_in                   = wp_generate_auth_cookie( $user, time() + 600, 'logged_in', $session );
		$auth                        = wp_generate_auth_cookie( $user, time() + 600, 'auth', $session );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
		$cookie                      = AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in );

		$response = wp_remote_get(
			BackupPlus_Options::admin_page_url() . '?page=backupplus&action=downloadlatestmodlog&wpnonce=' . wp_create_nonce( 'backupplus_download' ),
			array(
				'headers'     => array( 'Cookie' => $cookie ),
				'redirection' => 0,
				'timeout'     => 60,
			)
		);
		restore_continuation_expect( ! is_wp_error( $response ), 'The log download must answer' );
		$served = false !== strpos( wp_remote_retrieve_body( $response ), $marker );
		restore_continuation_expect( $manager === $served, 'The latest log must be served to ' . ( $manager ? 'a manager' : 'nobody else' ) . ', status ' . wp_remote_retrieve_response_code( $response ) );

		if ( ! $manager ) {
			list( $status, $body ) = $post(
				array(
					'action'                  => 'backupplus_ajaxrestore',
					'backupplus_ajax_restore' => 'do_ajax_restore',
					'job_id'                  => $job,
					'nonce'                   => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
				),
				$cookie
			);
			restore_continuation_expect( 'Security Check' === $body, 'backupplus_ajaxrestore must refuse a user who is not a manager: ' . substr( $body, 0, 300 ) );
		}
	}

	echo "PASS: restore continuation needs the issued token, cannot save filesystem credentials, and restore start and log download need a manager\n";
} finally {
	wp_set_current_user( $original_user );
	$_COOKIE = $original_cookies;
	$cleanup();
}
