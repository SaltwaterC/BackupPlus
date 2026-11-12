<?php
/**
 * BPS-11 over HTTP: a database restore that a time limit ends just after it replaced the table holding the restore state
 * continues logged out with the token issued to the restore page, and finishes.
 *
 * The restore state is the marker of the restore in progress, the continuation token and the job data. A single site keeps
 * them in wp_options; a network keeps them in wp_sitemeta, which a restore of the whole network replaces after wp_users and
 * wp_usermeta, so the administrator's session has also ended by then.
 *
 * An administrator whose session began after the backup starts the restore as the restore dialog does (the restore page, then
 * backupplus_ajaxrestore). A temporary must-use plugin ends that request once the state table has been restored, before the
 * restore's next checkpoint. A logged-out backupplus_ajaxrestore_continue request with the page's token must then finish the
 * restore. Without restore_network_bundle() the continuation is refused on a network, because the restored wp_sitemeta holds
 * neither the marker nor the token; without save_job_state() it finds no job data to resume.
 *
 * The backup, the post, the must-use plugin and the job data are removed afterwards. The database is left as the backup taken
 * at the start had it.
 *
 * Run on a single site and on the sub-directory network of dev/test-sites.sh:
 * podman exec backupplus-test-ms-wordpress wp --allow-root --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-interrupted-restore-continuation.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function continuation_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/backupplus.php';
global $backupplus, $wpdb;
$network = is_multisite();
continuation_expect( ! $network || is_plugin_active_for_network( 'backupplus/backupplus.php' ), 'BackupPlus must be network-active.' );
// The table that holds the restore state, and the name the state check reports
$state_table = $network ? $wpdb->sitemeta : $wpdb->options;

$tag        = bin2hex( random_bytes( 4 ) );
$mu_plugin  = WPMU_PLUGIN_DIR . '/backupplus-check-stop-restore.php';
$stop_flag  = WP_CONTENT_DIR . '/backupplus-check-stop-after-table';
$marker     = 'backupplus_check_continuation';
$timestamp  = null;
$job        = null;
$post       = null;
$admin      = null;
$directory  = $backupplus->backups_dir_location();
$passed     = false;
$maintained = file_exists( ABSPATH . '.maintenance' );
$wp_command = function ( $arguments ) {
	$command = 'wp --allow-root --path=' . escapeshellarg( ABSPATH ) . ' ' . $arguments . ' 2>&1';
	exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The test drives WP-CLI as a separate process, as an administrator would.
	return array( $status, trim( implode( "\n", $output ) ) );
};
$fresh      = function () {
	wp_cache_flush();
};

try {
	foreach ( $network ? get_super_admins() : get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'user_login',
		)
	) as $login ) {
		$admin = get_user_by( 'login', $login );
		if ( $admin ) {
			break;
		}
	}
	continuation_expect( $admin && ( $network ? is_super_admin( $admin->ID ) : user_can( $admin, 'manage_options' ) ), 'The site must have an administrator.' );

	// The state the restore must bring back, and a database backup (of the whole network, on a network)
	$post = wp_insert_post(
		array(
			'post_title'  => 'Original ' . $tag,
			'post_status' => 'publish',
		)
	);
	continuation_expect( $post > 0, 'A post must be created on the main site.' );
	list( $status, $output ) = $wp_command( 'backupplus backup --type=database --local --label=' . escapeshellarg( 'continuation-' . $tag ) . ' --porcelain' );
	continuation_expect( 0 === $status && preg_match( '/\A[0-9]+\z/', $output ), 'The database backup must succeed: ' . $output );
	$timestamp = (int) $output;
	$fresh();
	$backup = BackupPlus_Backup_History::get_history( $timestamp );
	continuation_expect( is_array( $backup ) && ! empty( $backup['db'] ), 'The backup must be in the history.' );

	// What changes after the backup: the post, an option in the state table, and the administrator's session
	wp_update_post(
		array(
			'ID'         => $post,
			'post_title' => 'Changed ' . $tag,
		)
	);
	update_site_option( $marker, $tag );
	$session      = WP_Session_Tokens::get_instance( $admin->ID )->create( time() + 3600 );
	$expires      = time() + 3600;
	$logged_in    = wp_generate_auth_cookie( $admin->ID, $expires, 'logged_in', $session );
	$auth         = wp_generate_auth_cookie( $admin->ID, $expires, 'auth', $session );
	$cookie       = AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in );
	$settings     = BackupPlus_Options::admin_page_url() . '?page=backupplus';
	$request      = function ( $url, $body, $with_cookie ) use ( $cookie ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $with_cookie ? array( 'Cookie' => $cookie ) : array(),
				'body'        => $body,
				'redirection' => 0,
				'timeout'     => 600,
			)
		);
		continuation_expect( ! is_wp_error( $response ), 'The site must answer ' . $url . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
		return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
	};
	$is_logged_in = function () use ( $cookie, $settings ) {
		$response = wp_remote_get(
			$settings,
			array(
				'headers'     => array( 'Cookie' => $cookie ),
				'redirection' => 0,
				'timeout'     => 60,
			)
		);
		return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	};
	continuation_expect( $is_logged_in(), 'The administrator must be logged in before the restore.' );

	// A request that a time limit ends once the state table is restored
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents(
		$mu_plugin,
		"<?php\n// Written and removed by scripts/check-interrupted-restore-continuation.php\nadd_action( 'backupplus_restored_db_table', function ( \$table ) {\n\t\$flag = WP_CONTENT_DIR . '/backupplus-check-stop-after-table';\n\tif ( is_file( \$flag ) && trim( (string) file_get_contents( \$flag ) ) === \$table ) {\n\t\tunlink( \$flag );\n\t\texit;\n\t}\n} );\n"
	);
	file_put_contents( $stop_flag, $state_table );

	// The restore dialog posts this form to the settings page, which answers with the restore page. The nonce is the
	// administrator's, for this session.
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	wp_set_current_user( $admin->ID );
	$nonce                 = wp_create_nonce( 'backupplus-credentialtest-nonce' );
	list( $status, $page ) = $request(
		$settings,
		array(
			'action'                              => 'backupplus_restore',
			'backupplus_ajax_restore'             => 'start_ajax_restore',
			'nonce'                               => $nonce,
			'backup_timestamp'                    => (string) $timestamp,
			'meta_foreign'                        => '0',
			'backupplus_restorer_backup_info'     => '',
			'backupplus_restorer_restore_options' => $network ? 'backupplus_restore_ms_whichsites=0' : '',
			'backupplus_restore'                  => array( 'db' ),
		),
		true
	);
	continuation_expect( 200 === $status && preg_match( '/id="backupplus_ajax_restore_job_id"[^>]*value="([0-9a-f]+)"/', $page, $job_match ) && preg_match( '/id="backupplus_ajax_restore_token"[^>]*value="([0-9a-f]{64})"/', $page, $token_match ), 'The restore page must carry the job ID and the continuation token: ' . $status . ' ' . substr( wp_strip_all_tags( $page ), 0, 500 ) );
	$job   = $job_match[1];
	$token = $token_match[1];
	$fresh();
	continuation_expect( get_site_option( 'backupplus_restore_in_progress' ) === $job, 'The restore in progress must be recorded.' );

	// The page starts the restore; the request ends after the state table
	list( $status, $body ) = $request(
		admin_url( 'admin-ajax.php' ),
		array(
			'action'                  => 'backupplus_ajaxrestore',
			'backupplus_ajax_restore' => 'do_ajax_restore',
			'job_id'                  => $job,
			'nonce'                   => $nonce,
		),
		true
	);
	continuation_expect( ! is_file( $stop_flag ), 'The restore must have reached ' . $state_table . ': ' . substr( wp_strip_all_tags( $body ), -1500 ) );
	continuation_expect( false === strpos( $body, 'backupplus_restore_successful' ), 'The first request must end before the restore finishes.' );
	$fresh();
	continuation_expect( false === get_site_option( $marker ), 'The restore must have replaced ' . $state_table . '.' );
	// WordPress answers every request, admin-ajax.php included, with 503 until 10 minutes after maintenance mode began. Move
	// that start back instead of waiting.
	continuation_expect( is_file( ABSPATH . '.maintenance' ), 'The interrupted restore must have left maintenance mode on.' );
	file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = ' . ( time() - 10 * MINUTE_IN_SECONDS - 1 ) . '; ?>' );
	// On a network wp_users and wp_usermeta come before wp_sitemeta in the dump; on a single site they come after wp_options
	continuation_expect( $is_logged_in() !== $network, $network ? "The restored wp_usermeta must have ended the administrator's session." : 'The session must last until wp_usermeta is restored.' );

	// The page continues logged out with its token
	list( $status, $body ) = $request(
		admin_url( 'admin-ajax.php' ),
		array(
			'action'                  => 'backupplus_ajaxrestore_continue',
			'backupplus_ajax_restore' => 'do_ajax_restore',
			'job_id'                  => $job,
			'token'                   => $token,
		),
		false
	);
	continuation_expect( '' !== $body, 'The continuation must be accepted after ' . $state_table . ' was restored, but it was refused without output.' );
	continuation_expect( false !== strpos( $body, 'backupplus_restore_successful' ), 'The continued restore must finish: ' . substr( wp_strip_all_tags( $body ), -2000 ) );
	$fresh();
	continuation_expect( 'Original ' . $tag === get_post( $post )->post_title, 'The database must be restored.' );
	continuation_expect( ! $is_logged_in(), "The restored wp_usermeta must have ended the administrator's session." );
	continuation_expect( false === get_site_option( 'backupplus_restore_in_progress' ), 'The finished restore must clear its marker.' );

	// The token was for that restore only
	list( $status, $body ) = $request(
		admin_url( 'admin-ajax.php' ),
		array(
			'action'                  => 'backupplus_ajaxrestore_continue',
			'backupplus_ajax_restore' => 'do_ajax_restore',
			'job_id'                  => $job,
			'token'                   => $token,
		),
		false
	);
	continuation_expect( '' === $body, 'The token must not continue a finished restore.' );

	$passed = true;
	echo 'PASS: ' . ( $network ? 'a whole-network' : 'a' ) . " database restore ended after $state_table was restored continues logged out with the restore page's token and finishes\n";
} finally {
	// A restore that this check left unfinished leaves the site in maintenance mode
	if ( ! $maintained && is_file( ABSPATH . '.maintenance' ) ) {
		unlink( ABSPATH . '.maintenance' );
	}
	foreach ( array( $mu_plugin, $stop_flag ) as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	wp_cache_flush();
	delete_site_option( $marker );
	if ( $job ) {
		if ( get_site_option( 'backupplus_restore_in_progress' ) === $job ) {
			delete_site_option( 'backupplus_restore_in_progress' );
		}
		BackupPlus_Restore_Continuation::clear( $job );
		delete_site_option( 'backupplus_jobdata_' . $job );
		if ( is_file( $directory . '/log.' . $job . '.txt' ) ) {
			if ( ! $passed ) {
				fwrite( STDERR, "Restore log (kept):\n" . implode( "\n", array_slice( file( $directory . '/log.' . $job . '.txt', FILE_IGNORE_NEW_LINES ), -60 ) ) . "\n" );
			} else {
				unlink( $directory . '/log.' . $job . '.txt' );
			}
		}
	}
	if ( $post ) {
		wp_delete_post( $post, true );
	}
	if ( $timestamp ) {
		$wp_command( 'backupplus delete ' . (int) $timestamp . ' --yes' );
	}
}
