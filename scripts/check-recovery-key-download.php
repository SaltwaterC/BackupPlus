<?php
/** Exercise the real download and restore endpoints with disposable credentials. */
call_user_func(
	function ( $directory, $option ) {
		global $wpdb;
		$gate    = bin2hex( random_bytes( 16 ) );
		$mu_file = WPMU_PLUGIN_DIR . '/backupplus-recovery-test-' . $gate . '.php';
		$user_id = wp_insert_user(
			array(
				'user_login' => 'recovery-test-' . $gate,
				'user_pass'  => wp_generate_password( 40 ),
				'role'       => 'administrator',
			)
		);
		dropbox_wp_expect( ! is_wp_error( $user_id ), 'A disposable recovery-test administrator must be created' );
		$original_user    = get_current_user_id();
		$original_cookies = $_COOKIE;
		$cleanup          = function () use ( $mu_file, $user_id ) {
			if ( file_exists( $mu_file ) ) {
				unlink( $mu_file );
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		};
		register_shutdown_function( $cleanup );
		try {
			// Isolate HTTP requests to this test's key file/options without bypassing auth.
			$fixture = <<<'MU'
<?php
if (!isset($_SERVER['HTTP_X_BACKUPPLUS_RECOVERY_TEST']) || !hash_equals(TEST_GATE, $_SERVER['HTTP_X_BACKUPPLUS_RECOVERY_TEST'])) return;
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', TEST_DIRECTORY);
// The site's own saved credentials and jobs use its real key; hide them and drop writes.
foreach (TEST_HIDDEN_OPTIONS as $test_name) {
    add_filter('pre_option_'.$test_name, '__return_empty_array');
    add_filter('pre_update_option_'.$test_name, function($value, $old) { return $old; }, 10, 2);
}
foreach (TEST_HIDDEN_JOBS as $test_name) {
    add_filter('pre_site_option_'.$test_name, '__return_empty_array');
    add_filter('pre_update_site_option_'.$test_name, function($value, $old) { return $old; }, 10, 2);
}
$test_metadata = array('backupplus_credential_key_directory' => TEST_DIRECTORY, 'backupplus_dropbox_token_format' => 1, 'backupplus_credential_setup_error' => '', 'backupplus_credential_rotation_key' => '', 'backupplus_credential_rotation_error' => '');
foreach (array_keys($test_metadata) as $test_name) {
    add_filter('pre_option_'.$test_name, function($value) use (&$test_metadata, $test_name) { return $test_metadata[$test_name]; });
    add_filter('pre_update_option_'.$test_name, function($value, $old) use (&$test_metadata, $test_name) { $test_metadata[$test_name] = $value; return $old; }, 10, 2);
}
add_filter('pre_option_backupplus_dropbox', function($value) { return get_option(TEST_OPTION); });
add_filter('pre_update_option_backupplus_dropbox', function($value, $old) { update_option(TEST_OPTION, $value, false); return $old; }, 10, 2);
add_filter('pre_option_cron', function($value) { return get_option(TEST_OPTION.'_cron', array('version' => 2)); });
add_filter('pre_update_option_cron', function($value, $old) { update_option(TEST_OPTION.'_cron', $value, false); return $old; }, 10, 2);
MU;
			$fixture = strtr(
				$fixture,
				array(
					'TEST_GATE'           => var_export( $gate, true ),
					'TEST_DIRECTORY'      => var_export( $directory, true ),
					'TEST_OPTION'         => var_export( $option, true ),
					'TEST_HIDDEN_OPTIONS' => var_export( array_values( array_diff( BackupPlus_Credential_Storage::option_names(), array( 'backupplus_dropbox' ) ) ), true ),
					'TEST_HIDDEN_JOBS'    => var_export( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'backupplus_jobdata_' ) . '%' ) ), true ),
				)
			);
			dropbox_wp_expect( false !== file_put_contents( $mu_file, $fixture ), 'HTTP test isolation must be installed' );
			$session                     = WP_Session_Tokens::get_instance( $user_id )->create( time() + 300 );
			$logged_in                   = wp_generate_auth_cookie( $user_id, time() + 300, 'logged_in', $session );
			$auth                        = wp_generate_auth_cookie( $user_id, time() + 300, 'auth', $session );
			$cookie                      = AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in );
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
			wp_set_current_user( $user_id );
			ob_start();
			BackupPlus_Credential_Key_Admin::render();
			$document = new DOMDocument();
			@$document->loadHTML( ob_get_clean() );
			$panel = $document->getElementById( 'backupplus-credential-keys' );
			dropbox_wp_expect( null !== $panel, 'The actual download control must render' );
			$nonce   = $panel->getAttribute( 'data-nonce' );
			$url     = $panel->getAttribute( 'data-download-url' );
			$headers = array(
				'Cookie'                     => $cookie,
				'X-BackupPlus-Recovery-Test' => $gate,
			);
			$body    = array(
				'action'   => 'backupplus_recovery_key',
				'_wpnonce' => $nonce,
			);
			$config  = BackupPlus_Credential_Key_Store::configuration();
			$request = function ( $url, $headers, $body, $method = 'POST' ) {
				if ( 'GET' === $method ) {
					$url  = add_query_arg( $body, $url );
					$body = array();
				}
				$response = wp_remote_request(
					$url,
					array(
						'method'      => $method,
						'headers'     => $headers,
						'body'        => $body,
						'redirection' => 0,
						'timeout'     => 10,
					)
				);
				dropbox_wp_expect( ! is_wp_error( $response ), 'The recovery HTTP endpoint must be reachable' );
				return $response;
			};
			// Exercise the same authenticated request used by Advanced Tools > Cron events.
			BackupPlus_Credential_Key_Rotation::schedule();
			BackupPlus_Credential_Key_Rotation::queue_retry();
			wp_schedule_single_event( time() + 600, 'backupplus_backup_resume' );
			wp_schedule_single_event( time() + 600, 'unrelated_fixture_cron' );
			$pitr_fixture_time = time() + 601;
			wp_schedule_single_event( $pitr_fixture_time, BackupPlus_PITR::HOOK );
			$cron_response = $request(
				admin_url( 'admin-ajax.php' ),
				$headers,
				array(
					'action'    => 'backupplus_ajax',
					'subaction' => 'get_cron_events',
					'nonce'     => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
				)
			);
			$cron_rows     = json_decode( wp_remote_retrieve_body( $cron_response ), true );
			dropbox_wp_expect( 200 === wp_remote_retrieve_response_code( $cron_response ) && is_array( $cron_rows ), 'The Cron events page must receive valid JSON over HTTP' );
			$cron_by_hook = array_column( $cron_rows, null, 'hook' );
			dropbox_wp_expect( isset( $cron_by_hook[ BackupPlus_Credential_Key_Rotation::HOOK ] ) && 'Annually' === $cron_by_hook[ BackupPlus_Credential_Key_Rotation::HOOK ]['name'] && ! empty( $cron_by_hook[ BackupPlus_Credential_Key_Rotation::HOOK ]['time'] ), 'The Cron events page must show annual credential rotation with its interval and next run' );
			dropbox_wp_expect( isset( $cron_by_hook[ BackupPlus_Credential_Key_Rotation::RETRY_HOOK ] ) && 'Non-repeating' === $cron_by_hook[ BackupPlus_Credential_Key_Rotation::RETRY_HOOK ]['name'], 'The Cron events page must show pending credential rotation retries as non-repeating' );
			dropbox_wp_expect( isset( $cron_by_hook['backupplus_backup_resume'] ) && 'Non-repeating' === $cron_by_hook['backupplus_backup_resume']['name'], 'The Cron events page must still show one-off backup tasks' );
			dropbox_wp_expect( ! isset( $cron_by_hook['unrelated_fixture_cron'] ), 'The Cron events page must continue to exclude unrelated tasks' );
			// Every request reconciles the PITR schedule with the settings, which removes the fixture event while capture is off.
			if ( empty( BackupPlus_PITR::config()['enabled'] ) ) {
				backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'include_once' );
				$commands     = new BackupPlus_WPAdmin_Commands( $GLOBALS['backupplus'] );
				$cron_by_hook = array_column( $commands->get_cron_events(), null, 'hook' );
			}
			dropbox_wp_expect( isset( $cron_by_hook[ BackupPlus_PITR::HOOK ] ), 'The Cron events page must show database PITR capture' );
			wp_clear_scheduled_hook( BackupPlus_Credential_Key_Rotation::RETRY_HOOK );
			wp_clear_scheduled_hook( 'backupplus_backup_resume' );
			wp_clear_scheduled_hook( 'unrelated_fixture_cron' );
			// Remove only the fixture event; a site's own PITR schedule stays.
			wp_unschedule_event( $pitr_fixture_time, BackupPlus_PITR::HOOK );
			echo "PASS: Cron events HTTP listing includes annual rotation and one-off retries\n";
			$response = $request( $url, $headers, $body );
			dropbox_wp_expect( 200 === wp_remote_retrieve_response_code( $response ), 'An authorized recovery download must succeed over HTTP' );
			dropbox_wp_expect( 'application/octet-stream' === wp_remote_retrieve_header( $response, 'content-type' ), 'Recovery downloads must be binary attachments' );
			dropbox_wp_expect( 'attachment; filename="backupplus-recovery-key.php"' === wp_remote_retrieve_header( $response, 'content-disposition' ), 'The recovery attachment must use its expected filename' );
			dropbox_wp_expect( false !== strpos( wp_remote_retrieve_header( $response, 'cache-control' ), 'no-store' ) && 'nosniff' === wp_remote_retrieve_header( $response, 'x-content-type-options' ), 'Recovery downloads must prevent caching and content sniffing' );
			$download = wp_remote_retrieve_body( $response );
			dropbox_wp_expect( BackupPlus_Credential_Key_Store::encode( $config ) === $download, 'The response must contain exactly the current recovery format, with no HTML, notices or trailing output' );
			dropbox_wp_expect( $config === BackupPlus_Credential_Key_Store::decode( $download ), 'The actual downloaded file must validate and preserve the entire key ring' );
			$maintenance_url = admin_url( 'admin-ajax.php' );
			$maintenance     = array(
				'action'    => 'backupplus_credential_keys',
				'nonce'     => $nonce,
				'directory' => $directory,
			);
			$rotate          = $request( $maintenance_url, $headers, $maintenance + array( 'operation' => 'rotate' ) );
			$rotate_result   = json_decode( wp_remote_retrieve_body( $rotate ), true );
			dropbox_wp_expect(
				! empty( $rotate_result['success'] ),
				sprintf(
					'Rotation after downloading a recovery copy must succeed over HTTP (HTTP %d: %s)',
					wp_remote_retrieve_response_code( $rotate ),
					isset( $rotate_result['message'] ) ? $rotate_result['message'] : 'invalid response'
				)
			);
			$newer = BackupPlus_Credential_Key_Store::configuration();
			dropbox_wp_expect( $newer['active'] !== $config['active'], 'HTTP rotation must activate a newer key' );
			wp_cache_delete( $option, 'options' );
			$newer_credentials = get_option( $option );
			$restore_request   = $maintenance + array(
				'operation' => 'restore',
				'value'     => $download,
			);
			$restore_older     = $request( $maintenance_url, $headers, $restore_request );
			$older_result      = json_decode( wp_remote_retrieve_body( $restore_older ), true );
			dropbox_wp_expect( ! empty( $older_result['success'] ) && false !== strpos( $older_result['message'], 'active' ), 'Restoring an older recovery copy must report activation over HTTP' );
			$restored = BackupPlus_Credential_Key_Store::configuration();
			dropbox_wp_expect( $restored['active'] === $config['active'] && $restored['keys'] === $newer['keys'], 'HTTP restore must reactivate the uploaded key while retaining newer keys' );
			wp_cache_delete( $option, 'options' );
			dropbox_wp_expect( $newer_credentials === get_option( $option ), 'Restoring the active key must preserve credentials written under the newer key' );
			BackupPlus_Dropbox_Token_Migration::verify( $restored );
			$repeat        = $request( $maintenance_url, $headers, $restore_request );
			$repeat_result = json_decode( wp_remote_retrieve_body( $repeat ), true );
			dropbox_wp_expect( ! empty( $repeat_result['success'] ) && $restored === BackupPlus_Credential_Key_Store::configuration(), 'Repeated HTTP restoration must be idempotent' );
			$conflict                                = $config;
			$conflict['keys'][ $conflict['active'] ] = base64_encode( random_bytes( 32 ) );
			$rejected                                = $request(
				$maintenance_url,
				$headers,
				$maintenance + array(
					'operation' => 'restore',
					'value'     => BackupPlus_Credential_Key_Store::encode( $conflict ),
				)
			);
			$rejected_result                         = json_decode( wp_remote_retrieve_body( $rejected ), true );
			dropbox_wp_expect( isset( $rejected_result['success'] ) && false === $rejected_result['success'] && $restored === BackupPlus_Credential_Key_Store::configuration(), 'Conflicting HTTP recovery uploads must preserve the active key and full key ring' );
			// Refresh the recovery copy so missing-file recovery also includes the newer key.
			$config   = $restored;
			$download = wp_remote_retrieve_body( $request( $url, $headers, $body ) );
			dropbox_wp_expect( $config === BackupPlus_Credential_Key_Store::decode( $download ), 'A fresh recovery download must include retained newer keys' );
			// Upload precisely the bytes delivered by the download endpoint.
			unlink( $directory . '/credentials.php' );
			$restore = $request(
				admin_url( 'admin-ajax.php' ),
				$headers,
				array(
					'action'    => 'backupplus_credential_keys',
					'nonce'     => $nonce,
					'operation' => 'restore',
					'value'     => $download,
					'directory' => $directory,
				)
			);
			$result  = json_decode( wp_remote_retrieve_body( $restore ), true );
			dropbox_wp_expect( 200 === wp_remote_retrieve_response_code( $restore ) && ! empty( $result['success'] ), 'The downloaded recovery file must restore through the actual AJAX endpoint' );
			dropbox_wp_expect( $config === BackupPlus_Credential_Key_Store::configuration(), 'HTTP recovery must restore the original active and historical keys' );
			$bad_nonce             = $body;
			$bad_nonce['_wpnonce'] = 'invalid';
			$denied                = array( $request( $url, $headers, $bad_nonce ), $request( $url, $headers, $body, 'GET' ) );
			dropbox_wp_expect( 403 === wp_remote_retrieve_response_code( $denied[0] ) && 405 === wp_remote_retrieve_response_code( $denied[1] ), 'The download endpoint must explicitly reject invalid nonces and GET requests' );
			$anonymous = $headers;
			unset( $anonymous['Cookie'] );
			$denied[]   = $request( $url, $anonymous, $body );
			$subscriber = new WP_User( $user_id );
			$subscriber->set_role( 'subscriber' );
			$denied[] = $request( $url, $headers, $body );
			foreach ( $denied as $response ) {
				dropbox_wp_expect( wp_remote_retrieve_response_code( $response ) >= 400 && '' === wp_remote_retrieve_header( $response, 'content-disposition' ), 'Bad nonces, GET, anonymous users and subscribers must not download recovery keys' );
				foreach ( $config['keys'] as $key ) {
					dropbox_wp_expect( false === strpos( wp_remote_retrieve_body( $response ), $key ), 'Rejected requests must never disclose key material' );
				}
			}
			dropbox_wp_expect( $config === BackupPlus_Credential_Key_Store::configuration(), 'Rejected downloads must leave the key file unchanged' );
		} finally {
			wp_set_current_user( $original_user );
			$_COOKIE = $original_cookies;
			$cleanup();
		}
		echo "PASS: authenticated HTTP recovery download, restore after rotation, retained newer credentials, missing-file recovery and access rejection\n";
	},
	$fixture_directory,
	$fixture_option
);
