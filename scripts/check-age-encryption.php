<?php
/** Real WordPress storage, preflight and HTTP decryption, using disposable data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function age_wp_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }
$gate      = bin2hex( random_bytes( 12 ) );
$option    = 'backupplus_age_test_' . $gate;
$directory = wp_upload_dir()['basedir'] . '/' . $option;
mkdir( $directory, 0700 );
$mu_file = WPMU_PLUGIN_DIR . '/' . $option . '.php';
$user    = wp_insert_user(
	array(
		'user_login' => $option,
		'user_pass'  => wp_generate_password( 40 ),
		'role'       => 'administrator',
	)
);
age_wp_expect( ! is_wp_error( $user ), 'Disposable administrator must be created' );
$tickets          = array();
$original_user    = get_current_user_id();
$original_cookies = $_COOKIE;
$remove_tree      = function ( $path ) use ( &$remove_tree ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return; }
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$remove_tree( $path . '/' . $entry );
		}
	}
	rmdir( $path );
};
$cleanup          = function () use ( $option, $directory, $mu_file, $user, &$tickets, $remove_tree ) {
	foreach ( $tickets as $ticket ) {
		delete_transient( 'backupplus_age_restore_' . $ticket );
	}
	foreach ( array( '', '_phrase', '_history', '_cron' ) as $suffix ) {
		delete_option( $option . $suffix );
	}
	if ( is_file( $mu_file ) ) {
		unlink( $mu_file );
	}
	$remove_tree( $directory );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( get_userdata( $user ) ) {
		wp_delete_user( $user );
	}
};
register_shutdown_function( $cleanup );
$isolation = <<<'PHP'
add_filter('pre_option_backupplus_dir', function() { return TEST_DIRECTORY; }, 100);
foreach (array('backupplus_database_encryption' => '', 'backupplus_encryptionphrase' => '_phrase', 'backupplus_backup_history' => '_history', 'cron' => '_cron') as $name => $suffix) {
    add_filter('pre_option_'.$name, function() use ($suffix) { return get_option(TEST_OPTION.$suffix, '_phrase' === $suffix ? '' : array()); }, 100);
    add_filter('pre_update_option_'.$name, function($value, $old) use ($suffix) { update_option(TEST_OPTION.$suffix, $value, false); return $old; }, 100, 2);
}
PHP;
$isolation = strtr(
	$isolation,
	array(
		'TEST_DIRECTORY' => var_export( $directory, true ),
		'TEST_OPTION'    => var_export( $option, true ),
	)
);
eval( $isolation );
$http_isolation = '<?php if (!isset($_SERVER["HTTP_X_BACKUPPLUS_AGE_TEST"]) || !hash_equals(' . var_export( $gate, true ) . ', $_SERVER["HTTP_X_BACKUPPLUS_AGE_TEST"])) return;' . "\n" . $isolation . <<<'PHP'

add_action('plugins_loaded', function() {
    remove_action('init', array('BackupPlus_Credential_Key_Store', 'initialize'), 20);
    remove_action('init', array('BackupPlus_Credential_Key_Rotation', 'schedule'), 21);
});
PHP;
file_put_contents( $mu_file, $http_isolation );
try {
	global $backupplus, $backupplus_admin;
	require dirname( __DIR__ ) . '/backupplus.php';
	require_once BACKUPPLUS_DIR . '/backup.php';
	$backupplus->admin_menu();
	BackupPlus_Options::admin_init();
	wp_set_current_user( $user );
	$session                     = WP_Session_Tokens::get_instance( $user )->create( time() + 600 );
	$logged_in                   = wp_generate_auth_cookie( $user, time() + 600, 'logged_in', $session );
	$auth                        = wp_generate_auth_cookie( $user, time() + 600, 'auth', $session );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	$headers                     = array(
		'X-BackupPlus-Age-Test' => $gate,
		'Cookie'                => AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in ),
	);
	$fixture                     = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
	$recipients                  = array_column( $fixture['keys'], 'recipient' );
	update_option(
		'backupplus_database_encryption',
		array(
			'mode'    => 'age',
			'sources' => implode( "\n", $recipients ),
		)
	);
	$config = get_option( $option );
	age_wp_expect( 'age' === $config['mode'] && 3 === count( $config['recipients'] ), 'Real registered settings must save all public recipients' );
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $option ) );
	age_wp_expect( false === strpos( $raw, 'PRIVATE KEY' ) && false === strpos( $raw, $fixture['passphrase'] ), 'Raw settings must contain no private keys or key passphrases' );
	$timestamp = time() - 1000;
	$name      = 'backup_' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d-Hi' ) . '_' . $gate . '_0123456789ab-db.gz';
	$sql       = '# WordPress MySQL database backup' . "\n# Created by BackupPlus version 3.0.0\n# Backup of: " . site_url() . "\n# Home URL: " . home_url() . "\n# Table prefix: " . $wpdb->prefix . "\n";
	foreach ( array( 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'users', 'usermeta' ) as $table ) {
		$sql .= 'CREATE TABLE `' . $wpdb->prefix . $table . '` (`id` bigint NOT NULL);' . "\n";
	}
	for ( $i = 0; $i < 500; $i++ ) {
		$sql .= '# ' . hash( 'sha256', 'disposable row ' . $i ) . "\n";
	}
	$plain = gzencode( $sql );
	file_put_contents( $directory . '/' . $name, $plain );
	$worker    = ( new ReflectionClass( 'BackupPlus_Backup' ) )->newInstanceWithoutConstructor();
	$encrypted = $worker->encrypt_file( $name );
	age_wp_expect( $name . '.crypt' === $encrypted && BackupPlus_Age_Restore::is_age( $directory . '/' . $encrypted ), 'Real backup writer must honor the saved age setting' );
	update_option(
		$option . '_history',
		array(
			$timestamp => array(
				'db'      => $encrypted,
				'nonce'   => '0123456789ab',
				'service' => 'none',
				'native'  => true,
				'db-size' => filesize( $directory . '/' . $encrypted ),
			),
		)
	);
	$request = function ( $url, $body, $method = 'POST', $custom_headers = null ) use ( $headers ) {
		if ( 'GET' === $method && $body ) {
			$url  = add_query_arg( $body, $url );
			$body = array(); }
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => null === $custom_headers ? $headers : $custom_headers,
				'body'        => $body,
				'redirection' => 0,
				'timeout'     => 60,
			)
		);
		age_wp_expect( ! is_wp_error( $response ), 'The HTTP test endpoint must be reachable' );
		return $response;
	};
	$url     = admin_url( 'admin-post.php' );
	$base    = array(
		'action'   => 'backupplus_decrypt_database',
		'_wpnonce' => wp_create_nonce( 'backupplus-decrypt-database' ),
		'file'     => $encrypted,
	);
	foreach ( $fixture['keys'] as $key ) {
		$response = $request(
			$url,
			$base + array(
				'identity'       => $key['identity'],
				'key_passphrase' => $fixture['passphrase'],
			)
		);
		age_wp_expect( 200 === wp_remote_retrieve_response_code( $response ) && $plain === wp_remote_retrieve_body( $response ), 'Each identity must download the exact decrypted database over POST' );
		age_wp_expect( false !== strpos( wp_remote_retrieve_header( $response, 'cache-control' ), 'no-store' ) && '' !== wp_remote_retrieve_header( $response, 'content-disposition' ), 'Decrypted downloads must be attachments with no-store headers' );
	}
	$wrong = $request(
		$url,
		$base + array(
			'identity'       => $fixture['keys']['ed25519']['identity'],
			'key_passphrase' => 'sentinel-wrong-password',
		)
	);
	foreach ( array( $fixture['passphrase'], 'sentinel-wrong-password', $fixture['keys']['ed25519']['identity'] ) as $secret ) {
		age_wp_expect( false === strpos( wp_remote_retrieve_body( $wrong ), $secret ), 'Failed HTTP decryption must not disclose secrets' );
	}
	age_wp_expect( '' === wp_remote_retrieve_header( $wrong, 'content-disposition' ), 'Failed decryption must not publish an attachment' );
	$sentinel = 'configured-fallback-passphrase-sentinel';
	update_option( $option . '_phrase', $sentinel );
	$fallback = $request( $url, $base );
	age_wp_expect( 400 === wp_remote_retrieve_response_code( $fallback ) && false === strpos( wp_remote_retrieve_body( $fallback ), $sentinel ), 'A failed configured fallback must not disclose the saved passphrase' );
	$direct        = 'direct-backup-passphrase-sentinel';
	$failed_direct = $request( $url, $base + array( 'key' => $direct ) );
	age_wp_expect( 400 === wp_remote_retrieve_response_code( $failed_direct ) && false === strpos( wp_remote_retrieve_body( $failed_direct ), $direct ), 'A failed explicitly supplied backup passphrase must not be echoed' );
	update_option( $option . '_phrase', '' );
	$denied = array( $request( $url, array_merge( $base, array( '_wpnonce' => 'invalid' ) ) ), $request( $url, $base, 'GET' ), $request( $url, array_merge( $base, array( 'file' => '../outside.gz.crypt' ) ) ) );
	foreach ( $denied as $response ) {
		age_wp_expect( wp_remote_retrieve_response_code( $response ) >= 400 && '' === wp_remote_retrieve_header( $response, 'content-disposition' ), 'Nonce, method and filename guards must reject requests' );
	}
	$old_get = $request(
		add_query_arg(
			array(
				'page'            => 'backupplus',
				'action'          => 'downloadfile',
				'backupplus_file' => $encrypted,
			),
			admin_url( 'options-general.php' )
		),
		array(),
		'GET'
	);
	age_wp_expect( 405 === wp_remote_retrieve_response_code( $old_get ), 'Legacy GET decryption links must reject downloads' );
	$anonymous = $headers;
	unset( $anonymous['Cookie'] );
	$response = $request( $url, $base, 'POST', $anonymous );
	age_wp_expect( wp_remote_retrieve_response_code( $response ) >= 400 && '' === wp_remote_retrieve_header( $response, 'content-disposition' ), 'Anonymous requests must not decrypt files' );

	// Simulate the browser protocol over real HTTP. Only plaintext crosses the upload API.
	$browser_prepare = function ( $entities ) use ( $request, $timestamp, $directory, $fixture, &$tickets ) {
		$command   = function ( $data ) use ( $request ) {
			$response = $request(
				admin_url( 'admin-ajax.php' ),
				array_merge(
					array(
						'action'    => 'backupplus_ajax',
						'subaction' => 'restore_decryption',
						'nonce'     => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
					),
					$data
				)
			);
			$result   = json_decode( wp_remote_retrieve_body( $response ), true );
			age_wp_expect( is_array( $result ) && ! isset( $result['error'] ), 'Browser transfer command must succeed' );
			return $result;
		};
		$manifest  = $command(
			array(
				'operation' => 'manifest',
				'timestamp' => $timestamp,
				'entities'  => $entities,
			)
		);
		$tickets[] = $manifest['ticket'];
		foreach ( $manifest['files'] as $file ) {
			$plain = BackupPlus_Encryption::decrypt( $directory . '/' . $file, $fixture['keys']['ed25519']['identity'], true, $fixture['passphrase'] );
			age_wp_expect( is_array( $plain ), 'Test-only browser simulation must decrypt independently' );
			$bytes = file_get_contents( $plain['fullpath'] );
			unlink( $plain['fullpath'] );
			for ( $offset = 0; $offset < strlen( $bytes ); $offset += 524288 ) {
				$chunk = substr( $bytes, $offset, 524288 );
				$data  = array(
					'operation' => 'upload',
					'ticket'    => $manifest['ticket'],
					'file'      => $file,
					'offset'    => $offset,
					'chunk'     => base64_encode( $chunk ),
				);
				if ( $offset + strlen( $chunk ) === strlen( $bytes ) ) {
					$data['size'] = strlen( $bytes );
					$data['hash'] = hash( 'sha256', $bytes );
				}
				$command( $data );
			}
		}
		return $manifest['ticket'];
	};

	$ajax     = admin_url( 'admin-ajax.php' );
	$restore  = array(
		'backupplus_restore'    => array( 'db' ),
		'backupplus_age_ticket' => $browser_prepare( array( 'db' ) ),
	);
	$response = $request(
		$ajax,
		array(
			'action'      => 'backupplus_ajax',
			'subaction'   => 'restore_alldownloaded',
			'nonce'       => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
			'timestamp'   => $timestamp,
			'restoreopts' => http_build_query( $restore ),
		)
	);
	$result   = json_decode( wp_remote_retrieve_body( $response ), true );
	age_wp_expect( is_array( $result ) && '' === $result['e'], 'The real restore preflight must analyze the age-encrypted database: ' . ( is_array( $result ) ? $result['e'] : 'invalid response' ) );
	$info = json_decode( $result['i'], true );
	age_wp_expect( ! empty( $info['age_ticket'] ), 'Restore preflight must return an opaque prepared-file reference' );
	$tickets[] = $info['age_ticket'];
	$ticket    = $info['age_ticket'];
	wp_cache_delete( '_transient_backupplus_age_restore_' . $ticket, 'options' );
	$transient = get_transient( 'backupplus_age_restore_' . $ticket );
	foreach ( array( $fixture['passphrase'], $fixture['keys']['ed25519']['identity'] ) as $secret ) {
		age_wp_expect( false === strpos( serialize( $transient ), $secret ) && false === strpos( wp_remote_retrieve_body( $response ), $secret ), 'Real DB transients and preflight responses must not contain private keys or passphrases' );
	}
	// Exercise the actual restore-start serializer while intercepting its job
	// writes in memory. This does not run SQL restoration or alter real job data.
	$job_values = array();
	$job_write  = function ( $value, $name, $old ) use ( &$job_values ) {
		if ( 0 === strpos( $name, 'backupplus_jobdata_' ) || 'backupplus_restore_in_progress' === $name ) {
			$job_values[ $name ] = $value;
			return $old; }
		return $value;
	};
	$job_read   = function ( $pre, $name ) use ( &$job_values ) {
		return isset( $job_values[ $name ] ) ? $job_values[ $name ] : $pre;
	};
	add_filter( 'pre_update_option', $job_write, PHP_INT_MAX, 3 );
	add_filter( 'pre_option', $job_read, PHP_INT_MAX, 2 );
	$original_request = $_REQUEST;
	try {
		$_REQUEST = array(
			'action'                              => 'backupplus_restore',
			'backupplus_ajax_restore'             => 'start_ajax_restore',
			'backup_timestamp'                    => $timestamp,
			'backupplus_restore'                  => array( 'db' ),
			'backupplus_age_ticket'               => $ticket,
			'backupplus_age_identity'             => $fixture['keys']['ed25519']['identity'],
			'backupplus_age_key_passphrase'       => $fixture['passphrase'],
			'backupplus_restorer_restore_options' => http_build_query(
				array(
					'backupplus_age_identity'       => $fixture['keys']['ed25519']['identity'],
					'backupplus_age_key_passphrase' => $fixture['passphrase'],
					'innocent_option'               => 'retained',
				)
			),
		);
		ob_start();
		$backupplus_admin->prepare_restore();
		$start_html = ob_get_clean();
		$job_id     = $backupplus->nonce;
		$saved_job  = $job_values[ 'backupplus_jobdata_' . $job_id ];
		age_wp_expect( 'retained' === $saved_job['backupplus_restorer_restore_options']['innocent_option'], 'Restore serializer must retain ordinary restore options' );
		foreach ( array( $fixture['passphrase'], $fixture['keys']['ed25519']['identity'] ) as $secret ) {
			age_wp_expect( false === strpos( serialize( $saved_job ), $secret ) && false === strpos( $start_html, $secret ), 'Restore-start job data and HTML must never retain submitted identities or passphrases' );
		}
		$get_options = new ReflectionMethod( $backupplus_admin, 'get_restore_options_from_jobdata' );
		if ( PHP_VERSION_ID < 80100 ) {
			$get_options->setAccessible( true );
		}
		$restore_options = $get_options->invoke( $backupplus_admin );
		age_wp_expect( $ticket === $restore_options['backupplus_age_ticket'], 'Actual restore options must claim the prepared reference for the job' );
	} finally {
		$_REQUEST = $original_request;
		remove_filter( 'pre_update_option', $job_write, PHP_INT_MAX );
		remove_filter( 'pre_option', $job_read, PHP_INT_MAX );
	}
	$prepared = BackupPlus_Age_Restore::file( $directory . '/' . $encrypted, $ticket, $job_id );
	age_wp_expect( is_array( $prepared ) && $plain === file_get_contents( $prepared['fullpath'] ), 'Background restore must resolve verified plaintext without a key' );
	wp_set_current_user( 0 );
	age_wp_expect( is_array( BackupPlus_Age_Restore::file( $directory . '/' . $encrypted, $ticket, $job_id ) ), 'A background process must continue without an administrator session' );
	wp_set_current_user( $user );
	// Use the actual completed-file backup and ZIP restoration paths in an
	// isolated filesystem. No live WordPress files or database tables are moved.
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	require_once BACKUPPLUS_DIR . '/restorer.php';
	if ( ! defined( 'FS_CHMOD_DIR' ) ) {
		define( 'FS_CHMOD_DIR', 0755 );
	}
	if ( ! defined( 'FS_CHMOD_FILE' ) ) {
		define( 'FS_CHMOD_FILE', 0644 );
	}
	class AgeTestFilesystem extends WP_Filesystem_Direct {
		public $test_content;
		public function wp_content_dir() {
			return $this->test_content; }
	}
	global $wp_filesystem;
	$original_filesystem           = $wp_filesystem;
	$test_filesystem               = new AgeTestFilesystem( null );
	$test_filesystem->test_content = $directory . '/filesystem/';
	mkdir( $test_filesystem->test_content . 'upgrade', 0700, true );
	add_filter( 'pre_update_option', $job_write, PHP_INT_MAX, 3 );
	add_filter( 'pre_option', $job_read, PHP_INT_MAX, 2 );
	try {
		foreach ( array( 'age' ) as $mode ) {
			$backupplus->nonce = bin2hex( random_bytes( 6 ) );
			$backupplus->jobdata_reset();
			$job_config = array(
				'mode'       => $mode,
				'recipients' => $recipients,
			);
			$backupplus->jobdata_set(
				'option_cache',
				array(
					'backupplus_database_encryption' => $job_config,
					'backupplus_encryptionphrase'    => 'synthetic archive passphrase',
				)
			);
			$parts          = array( preg_replace( '/-db\.gz$/', '-uploads.zip', $name ), preg_replace( '/-db\.gz$/', '-uploads2.zip', $name ) );
			$expected_files = array();
			$zip_bytes      = array();
			foreach ( $parts as $index => $part ) {
				$zip = new ZipArchive();
				age_wp_expect( true === $zip->open( $directory . '/' . $part, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Disposable ZIP part must open' );
				$expected_files[ $index ] = array(
					'uploads/part' . $index . '/wp-config.php' => '<?php /* synthetic WordPress AUTH_KEY */',
					'uploads/part' . $index . '/ssh.key'  => 'synthetic private key sentinel',
					'uploads/part' . $index . '/last.txt' => 'last restored file',
				);
				foreach ( $expected_files[ $index ] as $file => $contents ) {
					$zip->addFromString( $file, $contents );
				}
				$zip->close();
				$zip_bytes[ $index ] = file_get_contents( $directory . '/' . $part );
				touch( $directory . '/' . $part, time() - 60 );
			}
			$backupplus->jobdata_set( 'backup_files', 'finished' );
			$backupplus->jobdata_set( 'backup_files_array', array( 'uploads' => $parts ) );
			$files = $worker->resumable_backup_of_files( 0 );
			age_wp_expect( is_array( $files ) && $parts[1] . '.crypt' === $files['uploads'][1], 'The actual completed-file pipeline must encrypt every ZIP part' );
			foreach ( $parts as $index => $part ) {
				age_wp_expect( ! file_exists( $directory . '/' . $part ) && $backupplus->jobdata_get( 'sha256-uploads' . $index . '.crypt' ) === hash_file( 'sha256', $directory . '/' . $part . '.crypt' ), 'The pipeline must remove plaintext and record ciphertext checksums' );
			}
			$history = array(
				'uploads'       => $files['uploads'],
				'uploads-size'  => $files['uploads-size'],
				'uploads1-size' => $files['uploads1-size'],
				'nonce'         => '0123456789ab',
				'service'       => 'none',
				'native'        => true,
			);
			update_option( $option . '_history', array( $timestamp => $history ) );
			BackupPlus_Backup_History::rebuild( false );
			$rebuilt = BackupPlus_Backup_History::get_history( $timestamp );
			age_wp_expect( $files['uploads'] === $rebuilt['uploads'] && ! empty( $rebuilt['incremental_sets'] ), 'Rescans and incremental history must recognize encrypted split ZIPs' );
			$raw_download = $request(
				add_query_arg(
					array(
						'page'             => 'backupplus',
						'action'           => 'backupplus_spool_file',
						'what'             => 'uploads',
						'backup_timestamp' => $timestamp,
						'findex'           => 0,
					),
					admin_url( 'options-general.php' )
				),
				array(),
				'GET'
			);
			age_wp_expect( 200 === wp_remote_retrieve_response_code( $raw_download ) && file_get_contents( $directory . '/' . $files['uploads'][0] ) === wp_remote_retrieve_body( $raw_download ), 'Ordinary downloads must preserve the encrypted ZIP exactly' );
			$key_fields = array(
				'identity'       => $fixture['keys']['ed25519']['identity'],
				'key_passphrase' => $fixture['passphrase'],
			);
			$download   = $request( $url, array_merge( $base, array( 'file' => $files['uploads'][0] ), $key_fields ) );
			age_wp_expect( 200 === wp_remote_retrieve_response_code( $download ) && $zip_bytes[0] === wp_remote_retrieve_body( $download ) && 'application/zip' === wp_remote_retrieve_header( $download, 'content-type' ), 'Manual POST decryption must return the authenticated ZIP' );
			$restore                          = array( 'backupplus_restore' => array( 'uploads' ) );
			$restore['backupplus_age_ticket'] = $browser_prepare( array( 'uploads' ) );
			$preflight                        = $request(
				$ajax,
				array(
					'action'      => 'backupplus_ajax',
					'subaction'   => 'restore_alldownloaded',
					'nonce'       => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
					'timestamp'   => $timestamp,
					'restoreopts' => http_build_query( $restore ),
				)
			);
			$scan                             = json_decode( wp_remote_retrieve_body( $preflight ), true );
			age_wp_expect( is_array( $scan ) && '' === $scan['e'], 'File-only HTTP preflight must accept the configured encryption: ' . ( is_array( $scan ) ? $scan['e'] : 'invalid response' ) );
			$prepared_info = json_decode( $scan['i'], true );
			$file_ticket   = $prepared_info['age_ticket'];
			$tickets[]     = $file_ticket;
			BackupPlus_Age_Restore::claim( $file_ticket, $timestamp, $backupplus->nonce );
			delete_transient( 'backupplus_age_restore_' . $file_ticket ); // Simulate replacing the options table during DB restoration.
			foreach ( array( $fixture['keys']['ed25519']['identity'], $fixture['passphrase'], 'synthetic archive passphrase' ) as $secret ) {
				age_wp_expect( false === strpos( serialize( $backupplus->jobdata_get( 'backupplus_prepared_archives' ) ), $secret ), 'Bindings preserved across database restoration must not contain private keys or passphrases' );
			}
			$restorer                  = ( new ReflectionClass( 'BackupPlus_Restorer' ) )->newInstanceWithoutConstructor();
			$restorer->restore_options = array( 'backupplus_age_ticket' => $file_ticket );
			$restorer->skin            = new class() { public function feedback( $message ) {} };
			$restorer->strings         = array( 'unpack_package' => 'Unpacking test archive' );
			$unpack                    = new ReflectionMethod( $restorer, 'unpack_package_archive' );
			if ( PHP_VERSION_ID < 80100 ) {
				$unpack->setAccessible( true );
			}
			$wp_filesystem = $test_filesystem;
			wp_set_current_user( 0 ); // Restore continuation needs only the bound job.
			foreach ( $files['uploads'] as $index => $file ) {
				$prepared            = BackupPlus_Age_Restore::file( $directory . '/' . $file, $file_ticket, $backupplus->nonce );
				$original_ciphertext = file_get_contents( $directory . '/' . $file );
				file_put_contents( $directory . '/' . $file, $original_ciphertext . 'changed' );
				age_wp_expect( is_wp_error( $unpack->invoke( $restorer, $file, 'uploads' ) ), 'Changed ciphertext must fail before extraction' );
				file_put_contents( $directory . '/' . $file, $original_ciphertext );
				// Exercise both supported ZIP extractors using extensionless staging files.
				$zip_engine = function () use ( $index ) {
					return 0 === $index;
				};
				add_filter( 'unzip_file_use_ziparchive', $zip_engine );
				try {
					if ( 'age' === $mode && 0 === $index ) {
						$stop = function ( $archive, $entry_index, $entry_info ) use ( $backupplus ) {
							if ( 1 !== $entry_index ) {
								return;
							}
							$backupplus->jobdata_set(
								BackupPlus_Filesystem_Functions::get_jobdata_progress_key( $archive ),
								array(
									'index' => $entry_index,
									'info'  => $entry_info,
								)
							);
							throw new RuntimeException( 'Synthetic interrupted extraction' );
						};
						add_action( 'backupplus_unzip_file_unzipped', $stop, 99, 3 );
						try {
							$unpack->invoke( $restorer, $file, 'uploads' );
							age_wp_expect( false, 'Extraction must be interrupted by the test hook' ); } catch ( RuntimeException $e ) {
							age_wp_expect( 'Synthetic interrupted extraction' === $e->getMessage(), 'Only the synthetic extraction interruption is expected' ); } finally {
								remove_action( 'backupplus_unzip_file_unzipped', $stop, 99 ); }
							age_wp_expect( is_file( $prepared['fullpath'] ), 'Interrupted extraction must retain verified plaintext for resumption' );
							$progress_key = BackupPlus_Filesystem_Functions::get_jobdata_progress_key( $prepared['fullpath'] );
							$continuation = new ReflectionProperty( $restorer, 'continuation_data' );
							if ( PHP_VERSION_ID < 80100 ) {
								$continuation->setAccessible( true );
							}
							$continuation->setValue( $restorer, array( $progress_key => $backupplus->jobdata_get( $progress_key ) ) );
					}
					$unpacked = $unpack->invoke( $restorer, $file, 'uploads' );
				} finally {
					remove_filter( 'unzip_file_use_ziparchive', $zip_engine ); }
				age_wp_expect( is_string( $unpacked ) && is_dir( $unpacked ), 'The real restorer must unpack verified encrypted ZIPs' );
				foreach ( $expected_files[ $index ] as $expected => $contents ) {
					age_wp_expect( $contents === file_get_contents( $unpacked . '/' . $expected ), 'File restoration must recover synthetic configuration and key bytes exactly' );
				}
				age_wp_expect( ! file_exists( $prepared['fullpath'] ) && is_file( $directory . '/' . $file ), 'Successful extraction removes prepared plaintext and retains encrypted originals' );
			}
			wp_set_current_user( $user );
			$wp_filesystem = $original_filesystem;
		}
		// Ciphertext is arbitrary binary: an old upload repair must not trim
		// bytes that happen to spell "null" at a chunk boundary.
		$upload_name   = preg_replace( '/-db\.gz$/', '-uploads9.zip.crypt', $name );
		$upload_chunks = array( BackupPlus_Age_Crypto::MAGIC . "synthetic transport bytes\0null", "last binary chunk\xffnull" );
		foreach ( $upload_chunks as $index => $chunk ) {
			$boundary = 'BackupPlusTest' . bin2hex( random_bytes( 12 ) );
			$body     = '';
			foreach ( array(
				'action'      => 'plupload_action',
				'_ajax_nonce' => wp_create_nonce( 'backupplus-uploader' ),
				'name'        => $upload_name,
				'chunks'      => 2,
				'chunk'       => $index,
			) as $field => $value ) {
				$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . "\"\r\n\r\n" . $value . "\r\n";
			}
			$body           .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"async-upload\"; filename=\"" . $upload_name . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $chunk . "\r\n--" . $boundary . "--\r\n";
			$upload_response = $request( $ajax, $body, 'POST', $headers + array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) );
			$upload_result   = json_decode( wp_remote_retrieve_body( $upload_response ), true );
			age_wp_expect( 200 === wp_remote_retrieve_response_code( $upload_response ) && is_array( $upload_result ) && empty( $upload_result['e'] ), 'The real chunked uploader must accept encrypted ZIP archive names' );
		}
		age_wp_expect( implode( '', $upload_chunks ) === file_get_contents( $directory . '/' . $upload_name ), 'Chunked uploads must preserve every ciphertext byte, including null suffixes' );
		unlink( $directory . '/' . $upload_name );
		echo "PASS: real split-file encryption, ciphertext downloads/uploads, rescans, file-only preflight, background ZIP/PclZip extraction/resumption and plaintext cleanup\n";
	} finally {
		wp_set_current_user( $user );
		$wp_filesystem = $original_filesystem;
		remove_filter( 'pre_update_option', $job_write, PHP_INT_MAX );
		remove_filter( 'pre_option', $job_read, PHP_INT_MAX );
	}
	$subscriber = new WP_User( $user );
	$subscriber->set_role( 'subscriber' );
	$response = $request( $url, $base );
	age_wp_expect( wp_remote_retrieve_response_code( $response ) >= 400 && '' === wp_remote_retrieve_header( $response, 'content-disposition' ), 'Subscribers must not decrypt files' );
	$subscriber->set_role( 'administrator' );
	ob_start();
	BackupPlus_Database_Encryption::render();
	BackupPlus_Database_Encryption::restore_fields();
	$html = ob_get_clean();
	age_wp_expect( preg_match( '/<select id="backupplus-database-encryption"[^>]*>(.*?)<\/select>/s', $html, $select ) && 2 === substr_count( $select[1], '<option ' ) && false === strpos( $select[1], 'password' ) && false === strpos( $html, 'id="backupplus_encryptionphrase"' ), 'Backup settings must offer only Disabled and age public keys without a saved passphrase field' );
	age_wp_expect( false === strpos( $html, 'id="backupplus-restore-passphrase"' ) && false === strpos( $html, 'name="backupplus_age_identity"' ) && false === strpos( $html, 'name="backupplus_age_key_passphrase"' ), 'Restore credentials must be browser-only fields' );
	age_wp_expect(
		BackupPlus_Age_Restore::has_encrypted(
			array(
				'plugins' => array( 'backup_2026-01-01-0000_Test_0123456789ab-plugins.zip' ),
				'db'      => 'backup_2026-01-01-0000_Test_0123456789ab-db.gz.crypt',
			)
		) && ! BackupPlus_Age_Restore::has_encrypted(
			array(
				'plugins' => array( 'backup_2026-01-01-0000_Test_0123456789ab-plugins.zip' ),
				'db'      => 'backup_2026-01-01-0000_Test_0123456789ab-db.gz',
			)
		),
		'Restore key fields must be requested only for backup sets with encrypted archives'
	);
	age_wp_expect( false !== strpos( $html, 'github:USERNAME' ) && false !== strpos( $html, 'id="backupplus-age-identity"' ) && false === strpos( $html, $fixture['passphrase'] ), 'Settings and restore forms must expose public recipient and request-only key controls' );
	echo "PASS: real WordPress age settings, backup writer, POST downloads, authorization, restore preflight and private-key-free DB continuation\n";
} finally {
	wp_set_current_user( $original_user );
	$_COOKIE = $original_cookies;
	$cleanup();
}
