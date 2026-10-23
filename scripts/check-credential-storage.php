<?php
/** SEC-005: real WordPress options, provider/form APIs, imports and key lifecycle. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function credential_wp_expect( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	} }
function credential_wp_reject( $callback, $message ) {
	try {
		$callback();
	} catch ( Exception $e ) {
		return;
	} throw new RuntimeException( $message ); }
$prefix    = 'backupplus_sec005_' . bin2hex( random_bytes( 8 ) );
$directory = wp_upload_dir()['basedir'] . '/' . $prefix;
$job_id    = $prefix . '_job';
$job_name  = 'backupplus_jobdata_' . $job_id;
$created   = array( $job_name );
$metadata  = array();
$old_user  = get_current_user_id();

register_shutdown_function(
	function () use ( &$created, $directory, $old_user ) {
		foreach ( $created as $name ) {
			delete_option( $name );
		}
		if ( is_dir( $directory ) ) {
			foreach ( scandir( $directory ) as $file ) {
				if ( '.' !== $file && '..' !== $file ) {
					unlink( $directory . '/' . $file );
				}
			} rmdir( $directory ); }
		wp_set_current_user( $old_user );
	}
);
define( 'BACKUPPLUS_CREDENTIAL_KEY_DIR', $directory );
$names = array( 'backupplus_encryptionphrase', 'backupplus_extradbs', 'backupplus_ftp_pass', 'backupplus_googledrive_secret', 'backupplus_googledrive_token' );
foreach ( glob( dirname( __DIR__ ) . '/methods/*.php' ) as $file ) {
	$headers = get_file_data( $file, array( 'id' => 'Provider ID' ) );
	if ( $headers['id'] ) {
		$names[] = 'backupplus_' . $headers['id'];
	}
}
foreach ( $names as $index => $name ) {
	$test_name = $prefix . '_' . $index;
	$created[] = $test_name;
	add_filter(
		'pre_option_' . $name,
		function () use ( $test_name ) {
			return get_option( $test_name, array() );
		}
	);
	add_filter(
		'pre_update_option_' . $name,
		function ( $value, $old ) use ( $test_name ) {
			update_option( $test_name, $value, false );
			return $old;
		},
		10,
		2
	);
	$isolated[ $name ] = $test_name;
}
foreach ( array( 'backupplus_credential_key_directory', 'backupplus_dropbox_token_format', 'backupplus_credential_setup_error', 'backupplus_credential_rotation_key', 'backupplus_credential_rotation_error', 'cron' ) as $name ) {
	add_filter(
		'pre_option_' . $name,
		function () use ( &$metadata, $name ) {
			return isset( $metadata[ $name ] ) ? $metadata[ $name ] : '';
		}
	);
	add_filter(
		'pre_update_option_' . $name,
		function ( $value, $old ) use ( &$metadata, $name ) {
			$metadata[ $name ] = $value;
			return $old;
		},
		10,
		2
	);
}
add_filter(
	'query',
	function ( $query ) use ( $job_name ) {
		global $wpdb;
		if ( 0 === strpos( $query, "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE " ) ) {
			return $query . $wpdb->prepare( ' AND option_name = %s', $job_name );
		}
		return $query;
	}
);
$fixtures = array(
	'dropbox'      => array( 'secret' => 'synthetic-sec005-Dropbox-app-secret' ),
	'ftp'          => array( 'pass' => 'synthetic-sec005-FTP' ),
	'sftp'         => array(
		'pass'           => 'synthetic-sec005-SSH',
		'key'            => "-----BEGIN PRIVATE KEY-----\nsynthetic-sec005-private-key",
		'key_passphrase' => 'synthetic-sec005-key-phrase',
	),
	'webdav'       => array(
		'pass' => 'synthetic-sec005-WebDAV',
		'url'  => 'https://user:synthetic-sec005-url-password@example.test/backups',
	),
	's3'           => array(
		'secretkey'    => 'synthetic-sec005-S3',
		'sessiontoken' => 'synthetic-sec005-session',
	),
	's3generic'    => array( 'secretkey' => 'synthetic-sec005-S3-compatible' ),
	'dreamobjects' => array( 'secretkey' => 'synthetic-sec005-DreamObjects' ),
	'cloudfiles'   => array( 'apikey' => 'synthetic-sec005-Rackspace' ),
	'openstack'    => array( 'password' => 'synthetic-sec005-OpenStack' ),
	'googledrive'  => array(
		'secret' => 'synthetic-sec005-client',
		'token'  => array( 'refresh_token' => 'synthetic-sec005-refresh' ),
	),
);
foreach ( $fixtures as $provider => $secrets ) {
	update_option(
		$isolated[ 'backupplus_' . $provider ],
		array(
			'version'  => 1,
			'settings' => array(
				'test-instance' => array_merge(
					array(
						'host' => 'example.test',
						'user' => 'user',
					),
					$secrets
				),
			),
		),
		false
	);
}
$dropbox_legacy  = json_decode( file_get_contents( __DIR__ . '/fixtures/dropbox-legacy-token.json' ), true );
$dropbox_options = get_option( $isolated['backupplus_dropbox'] );
$dropbox_options['settings']['test-instance']['tk_access_token'] = $dropbox_legacy['ciphertext'];
update_option( $isolated['backupplus_dropbox'], $dropbox_options, false );
update_option( $isolated['backupplus_encryptionphrase'], 'synthetic-sec005-legacy-phrase', false );
update_option(
	$job_name,
	array(
		'job_type'        => 'backup',
		'option_cache'    => array( 'backupplus_ftp' => array( 'pass' => 'synthetic-sec005-old-job' ) ),
		'backup_database' => array(
			'external' => array(
				'status' => 'begun',
				'dbinfo' => array(
					'host' => 'db.test',
					'pass' => 'synthetic-sec005-external-db',
				),
			),
		),
	),
	false
);
// Importing settings updates ordinary settings too; suppress those unrelated writes.
add_filter(
	'pre_update_option',
	function ( $value, $name, $old ) use ( $prefix, $job_name ) {
		return 0 === strpos( $name, 'backupplus_' ) && 0 !== strpos( $name, $prefix ) && $name !== $job_name ? $old : $value;
	},
	PHP_INT_MAX,
	3
);
require dirname( __DIR__ ) . '/backupplus.php';
BackupPlus_Credential_Key_Store::initialize();
credential_wp_expect( ! get_option( 'backupplus_credential_setup_error' ), 'Automatic credential migration must succeed' );
credential_wp_expect( BackupPlus_Credential_Key_Store::upgrade_complete( 'credentials' ), 'Generic migration must record its protected completion marker' );
global $wpdb, $backupplus, $backupplus_admin;
foreach ( $fixtures as $provider => $secrets ) {
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $isolated[ 'backupplus_' . $provider ] ) );
	credential_wp_expect( false === strpos( $raw, 'synthetic-sec005-' ), 'Migration must remove plaintext provider secrets: ' . $provider );
	$options = BackupPlus_Options::get_backupplus_option( 'backupplus_' . $provider );
	foreach ( $secrets as $field => $value ) {
		credential_wp_expect( $options['settings']['test-instance'][ $field ] === $value, 'Migrated provider credentials must remain readable: ' . $provider . '/' . $field );
	}
}
credential_wp_expect( false === strpos( serialize( get_option( $job_name ) ), 'synthetic-sec005-' ), 'Migration must encrypt existing job snapshots' );
credential_wp_expect( 'synthetic-sec005-legacy-phrase' === BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' ), 'Legacy phrase remains available only for restore' );
$backupplus->admin_menu();
wp_set_current_user( 1 );
BackupPlus_Options::admin_init();
foreach ( array( 'ftp', 'sftp', 'webdav', 's3' ) as $provider ) {
	$storage = BackupPlus_Storage_Provider_Factory::create( $provider, false );
	$value   = array_merge(
		$storage->get_default_options(),
		$fixtures[ $provider ],
		array(
			'host' => 'example.test',
			'user' => 'user',
		)
	);
	$storage->set_options( $value, true, 'test-instance' );
	// This is the same sanitize chain used by options.php when saving the form.
	$option = 'backupplus_' . $provider;
	update_option( $option, BackupPlus_Options::get_backupplus_option( $option ) );
	$raw = get_option( $isolated[ $option ] );
	credential_wp_expect( false === strpos( serialize( $raw ), 'synthetic-sec005-' ), 'Module and form writes must stay encrypted' );
	foreach ( $fixtures[ $provider ] as $field => $secret ) {
		credential_wp_expect( $secret === BackupPlus_Options::get_backupplus_option( $option )['settings']['test-instance'][ $field ], 'Form writes must preserve credential content' );
	}
}
// Legacy settings imports use the same policy as ordinary settings writes.
$import_settings = array(
	'updraft_dropbox' => array(
		'version'  => 1,
		'settings' => array(
			'imported-dropbox' => array(
				'folder'          => 'imported-backups',
				'secret'          => 'synthetic-sec005-imported-Dropbox-secret',
				'tk_access_token' => $dropbox_legacy['ciphertext'],
			),
		),
	),
	'updraft_ftp'     => array(
		'version'  => 1,
		'settings' => array(
			'test-instance' => array(
				'host' => 'import.test',
				'user' => 'user',
				'pass' => 'synthetic-sec005-import',
			),
		),
	),
);
$import          = $backupplus_admin->import_settings(
	array(
		'settings'           => json_encode( $import_settings ),
		'backupplus_version' => $backupplus->version,
	),
	true
);
credential_wp_expect( ! empty( $import['saved'] ), 'An UpdraftPlus configuration export must import successfully' );
credential_wp_expect( false === strpos( serialize( get_option( $isolated['backupplus_ftp'] ) ), 'synthetic-sec005-import' ), 'Imported credentials must be encrypted before persistence' );
credential_wp_expect( 'synthetic-sec005-import' === BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['settings']['test-instance']['pass'], 'Legacy imported credentials must remain usable' );
$imported_dropbox = get_option( $isolated['backupplus_dropbox'] )['settings']['imported-dropbox'];
$imported_token   = $imported_dropbox['tk_access_token'];
credential_wp_expect( 0 === strpos( $imported_token, BackupPlus_Credential_Encrypter::PREFIX ), 'Imported UpdraftPlus AES-CBC tokens must be replaced with authenticated envelopes before persistence' );
credential_wp_expect( base64_decode( $dropbox_legacy['plaintext'] ) === Dropbox_Encrypter::from_config( 'imported-dropbox' )->decrypt( $imported_token ), 'The imported Dropbox access and refresh tokens must retain their original values' );
credential_wp_expect( false === strpos( serialize( $imported_dropbox ), 'synthetic-sec005-' ), 'Imported Dropbox app secrets must also be encrypted' );
$import_parts = explode( ':', $imported_token );
$import_raw   = base64_decode( $import_parts[3] );
$import_keys  = BackupPlus_Credential_Key_Store::configuration();
credential_wp_expect( base64_decode( $dropbox_legacy['plaintext'] ) === openssl_decrypt( substr( $import_raw, 12, -16 ), 'chacha20-poly1305', base64_decode( $import_keys['keys'][ $import_parts[2] ] ), OPENSSL_RAW_DATA, substr( $import_raw, 0, 12 ), substr( $import_raw, -16 ), implode( ':', array_slice( $import_parts, 0, 3 ) ) . ':dropbox:' . get_current_blog_id() . ':imported-dropbox' ), 'Imported UpdraftPlus tokens must use interoperable ChaCha20-Poly1305 encryption' );
// A bad later token must abort before any imported provider settings are saved.
$before_dropbox = get_option( $isolated['backupplus_dropbox'] );
$before_ftp     = get_option( $isolated['backupplus_ftp'] );
$invalid_import = $import_settings;
$invalid_import['updraft_dropbox']['settings']['broken']            = array( 'tk_access_token' => base64_encode( 'short' ) );
$invalid_import['updraft_ftp']['settings']['test-instance']['pass'] = 'synthetic-sec005-rejected-import';
credential_wp_reject(
	function () use ( $backupplus_admin, $backupplus, $invalid_import ) {
		$backupplus_admin->import_settings(
			array(
				'settings'           => json_encode( $invalid_import ),
				'backupplus_version' => $backupplus->version,
			),
			true
		);
	},
	'A malformed UpdraftPlus CBC token must reject the configuration import'
);
credential_wp_expect( $before_dropbox === get_option( $isolated['backupplus_dropbox'] ) && $before_ftp === get_option( $isolated['backupplus_ftp'] ), 'A rejected import must preserve existing provider credentials' );
// Pre-multi-instance exports need a new instance ID before token encryption.
$flat_import                    = $import_settings;
$flat_import['updraft_dropbox'] = $import_settings['updraft_dropbox']['settings']['imported-dropbox'];
$backupplus_admin->import_settings(
	array(
		'settings'           => json_encode( $flat_import ),
		'backupplus_version' => $backupplus->version,
	),
	true
);
$flat_settings = get_option( $isolated['backupplus_dropbox'] )['settings'];
$flat_id       = key( $flat_settings );
credential_wp_expect( base64_decode( $dropbox_legacy['plaintext'] ) === Dropbox_Encrypter::from_config( $flat_id )->decrypt( $flat_settings[ $flat_id ]['tk_access_token'] ), 'Flat UpdraftPlus exports must migrate tokens using their generated storage instance ID' );
$backupplus->nonce = $job_id;
$backupplus->jobdata_reset();
$backupplus->jobdata_set( 'option_cache', array( 'backupplus_ftp' => BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' ) ) );
credential_wp_expect( false === strpos( serialize( get_option( $job_name ) ), 'synthetic-sec005-' ), 'New job snapshots must contain no plaintext secrets' );
$backupplus->jobdata_reset();
credential_wp_expect( 'synthetic-sec005-import' === $backupplus->get_job_option( 'backupplus_ftp' )['settings']['test-instance']['pass'], 'New processes must resume using their saved credentials' );
credential_wp_expect( 'synthetic-sec005-external-db' === $backupplus->jobdata_get( 'backup_database' )['external']['dbinfo']['pass'], 'External database job credentials must resume correctly' );
$download = BackupPlus_Credential_Key_Store::encode( BackupPlus_Credential_Key_Store::configuration() );
$new      = BackupPlus_Credential_Key_Store::rotate();
BackupPlus_Credential_Key_Rotation::reencrypt_credentials( $new['active'] );
credential_wp_expect( false !== strpos( get_option( $isolated['backupplus_sftp'] )['settings']['test-instance']['key'], ':' . $new['active'] . ':' ), 'Rotation must cover SFTP private identities' );
credential_wp_expect( false !== strpos( get_option( $job_name )['option_cache'], ':' . $new['active'] . ':' ), 'Rotation must cover job snapshots' );
BackupPlus_Credential_Key_Store::restore( $download );
credential_wp_expect( 'synthetic-sec005-import' === BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['settings']['test-instance']['pass'], 'Recovery retains newer keys needed by other providers' );
$current = BackupPlus_Credential_Key_Store::encode( BackupPlus_Credential_Key_Store::configuration() );
$before  = get_option( $isolated['backupplus_sftp'] );
unlink( $directory . '/credentials.php' );
$metadata['backupplus_credential_key_directory'] = '';
credential_wp_reject(
	function () {
		BackupPlus_Credential_Key_Store::configuration();
	},
	'Encrypted provider or job data must prevent key regeneration even if location metadata was lost'
);
credential_wp_expect( ! file_exists( $directory . '/credentials.php' ), 'Missing-key checks must never publish a replacement implicitly' );
credential_wp_expect( '' === BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' )['settings']['test-instance']['key'], 'Missing keys expose no credential content to providers' );
credential_wp_reject(
	function () {
		update_option(
			'backupplus_sftp',
			array(
				'version'  => 1,
				'settings' => array( 'test-instance' => array( 'key' => 'synthetic-sec005-replacement' ) ),
			)
		);
	},
	'Missing keys must reject new credential writes'
);
credential_wp_expect( $before === get_option( $isolated['backupplus_sftp'] ), 'Missing-key failures must preserve the saved ciphertext' );
BackupPlus_Credential_Key_Store::restore( $current );
credential_wp_expect( $fixtures['sftp']['key'] === BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' )['settings']['test-instance']['key'], 'Recovery must unlock SFTP identities' );
$bad = BackupPlus_Credential_Key_Store::configuration();
foreach ( $bad['keys'] as $id => $key ) {
	$bad['keys'][ $id ] = base64_encode( random_bytes( 32 ) );
}
credential_wp_reject(
	function () use ( $bad ) {
		BackupPlus_Credential_Key_Store::restore( BackupPlus_Credential_Key_Store::encode( $bad ) );
	},
	'Wrong recovery keys must fail without altering the file'
);
credential_wp_expect( $current === BackupPlus_Credential_Key_Store::encode( BackupPlus_Credential_Key_Store::configuration() ), 'Rejected recovery must retain the working key file' );
// Explicit lost-key replacement preserves old ciphertext while allowing reconnection.
unlink( $directory . '/credentials.php' );
BackupPlus_Credential_Key_Admin::perform( 'replace_missing', '', $directory );
credential_wp_expect( $before === get_option( $isolated['backupplus_sftp'] ), 'Replacement must preserve old ciphertext for later recovery' );
$storage     = BackupPlus_Storage_Provider_Factory::create( 'sftp', false );
$replacement = array_merge(
	$storage->get_default_options(),
	array(
		'host' => 'example.test',
		'user' => 'user',
		'pass' => 'synthetic-sec005-reconnected',
	)
);
$storage->set_options( $replacement, true, 'test-instance' );
credential_wp_expect( 'synthetic-sec005-reconnected' === BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' )['settings']['test-instance']['pass'], 'Storage must reconnect through the normal module API after key replacement' );
echo "PASS: real WordPress provider encryption, form/API saves, legacy imports, migration, job resumption, rotation and recovery\n";
