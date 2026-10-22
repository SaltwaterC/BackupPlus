<?php
/** Disposable WordPress integration coverage for pre-update backup coordination. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
$test_complete = false;
register_shutdown_function(
	function () use ( &$test_complete ) {
		if ( ! $test_complete ) { fwrite( STDERR, "Pre-update integration checks did not reach completion.\n" );
			register_shutdown_function(
				function () {
					exit( 1 );
				}
			);
		} }
);
function pre_update_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$directory = get_temp_dir() . 'backupplus-pre-update-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory );
mkdir( $directory . '/plugins' );
mkdir( $directory . '/themes' );
mkdir( $directory . '/archives' );
file_put_contents( $directory . '/plugins/site.php', '<?php // original plugin' );
file_put_contents( $directory . '/themes/style.css', '/* original theme */' );
$history      = array();
$cron         = array();
$batch_option = array();
$services     = array();
$encryption   = array( 'mode' => 'none' );
$finished     = array();
$jobs         = array();
$original     = array();
foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_restore_in_progress', 'doing_cron' ) as $name ) {
	$original[ $name ] = get_option( $name, null );
}
foreach ( array( 'backupplus_backup_history', 'cron', 'backupplus_pre_update_batch' ) as $name ) {
	if ( 'backupplus_backup_history' === $name ) {
		add_filter(
			'pre_option_' . $name,
			function () use ( &$history ) {
				return $history;
			},
			100
		);
		add_filter(
			'pre_update_option_' . $name,
			function ( $new, $old ) use ( &$history ) {
				$history = $new;
				return $old;
			},
			100,
			2
		);
	} elseif ( 'cron' === $name ) {
		add_filter(
			'pre_option_' . $name,
			function () use ( &$cron ) {
				return $cron;
			},
			100
		);
		add_filter(
			'pre_update_option_' . $name,
			function ( $new, $old ) use ( &$cron ) {
				$cron = $new;
				return $old;
			},
			100,
			2
		);
	} else {
		add_filter(
			'pre_option_' . $name,
			function () use ( &$batch_option ) {
				return $batch_option;
			},
			100
		);
		add_filter(
			'pre_update_option_' . $name,
			function ( $new, $old ) use ( &$batch_option ) {
				$batch_option = $new;
				return $old;
			},
			100,
			2
		);
	}
}
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory . '/archives';
	},
	100
);
$offline = function () {
	return new WP_Error( 'test_offline', 'External requests disabled in this test.' );
};
add_filter( 'pre_http_request', $offline, 100 );
add_filter(
	'backupplus_get_option',
	function ( $value, $name ) use ( &$services, &$encryption ) {
		$options = array(
			'backupplus_service'             => $services,
			'backupplus_delete_local'        => 0,
			'backupplus_debug_mode'          => false,
			'backupplus_email'               => '',
			'backupplus_database_encryption' => $encryption,
			'backupplus_interval'            => 'manual',
			'backupplus_interval_database'   => 'manual',
			'backupplus_retain'              => 2,
			'backupplus_retain_db'           => 3,
		);
		return array_key_exists( $name, $options ) ? $options[ $name ] : $value;
	},
	100,
	2
);
add_filter(
	'backupplus_backupable_file_entities_final',
	function ( $entities, $full_info ) use ( $directory ) {
		return $full_info ? array(
			'plugins' => array(
				'path'        => $directory . '/plugins',
				'description' => 'Test plugins',
			),
			'themes'  => array(
				'path'        => $directory . '/themes',
				'description' => 'Test themes',
			),
		) : array(
			'plugins' => $directory . '/plugins',
			'themes'  => $directory . '/themes',
		);
	},
	100,
	2
);
$plugin_offers = (object) array(
	'response' => array(
		'sample/sample.php' => (object) array(
			'plugin'      => 'sample/sample.php',
			'new_version' => '2.0',
			'package'     => 'https://example.invalid/plugin-2.zip',
		),
	),
);
$theme_offers  = (object) array(
	'response' => array(
		'sample' => array(
			'theme'       => 'sample',
			'new_version' => '2.0',
			'package'     => 'https://example.invalid/theme-2.zip',
		),
	),
);
$core_offers   = (object) array(
	'updates' => array(
		(object) array(
			'response' => 'upgrade',
			'current'  => '99.0',
			'locale'   => 'en_US',
			'packages' => (object) array(
				'full'     => 'https://example.invalid/core.zip',
				'rollback' => 'https://example.invalid/rollback.zip',
			),
		),
	),
);
add_filter(
	'pre_site_transient_update_plugins',
	function () use ( &$plugin_offers ) {
		return $plugin_offers;
	}
);
add_filter(
	'pre_site_transient_update_themes',
	function () use ( &$theme_offers ) {
		return $theme_offers;
	}
);
add_filter(
	'pre_site_transient_update_core',
	function () use ( &$core_offers ) {
		return $core_offers;
	}
);
$remove_tree = function ( $path ) use ( &$remove_tree ) {
	if ( is_file( $path ) ) { unlink( $path );
		return; }
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) { $remove_tree( $path . '/' . $entry ); }
	}
	rmdir( $path );
};
register_shutdown_function(
	function () use ( &$jobs, $directory, $remove_tree, $original ) {
		foreach ( $jobs as $job ) { delete_site_option( 'backupplus_jobdata_' . $job ); }
		foreach ( $original as $name => $value ) {
			if ( null === $value ) { delete_option( $name );
			} else { update_option( $name, $value ); }
		}
		$remove_tree( $directory );
	}
);
global $backupplus;
require dirname( __DIR__ ) . '/backupplus.php';
class Pre_Update_Test_BackupPlus extends BackupPlus {
	public function get_semaphore_lock( $files, $database ) {
		return true; }
}
$backupplus  = new Pre_Update_Test_BackupPlus();
$coordinator = BackupPlus_Pre_Update_Backups::instance();
$admin       = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admin[0]->ID );
add_action(
	'backupplus_backup_finished',
	function ( $id, $outcome, $job ) use ( &$finished ) {
		$finished = array( $id, $outcome, $job );
		pre_update_expect( is_array( get_site_option( 'backupplus_jobdata_' . $id ) ), 'Terminal action must run before job cleanup' );
	},
	20,
	3
);
$keys  = array( 'plugin:sample/sample.php', 'theme:sample', 'core:99.0:en_US' );
$batch = $coordinator->prepare( $keys );
pre_update_expect( ! is_wp_error( $batch ) && 'queued' === $batch['state'], 'Manual batch must queue' );
$jobs[] = $batch['job'];
pre_update_expect( $coordinator->prepare( $keys )['id'] === $batch['id'], 'Repeated requests must share one job' );
pre_update_expect( is_wp_error( $coordinator->prepare( array( $keys[0] ) ) ), 'Competing batch must be blocked' );
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$plugin_upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
$extra           = array(
	'type'   => 'plugin',
	'action' => 'update',
	'plugin' => 'sample/sample.php',
);
$package         = $plugin_offers->response['sample/sample.php']->package;
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Unverified download must be blocked' );
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, new WP_Upgrader( new Automatic_Upgrader_Skin() ), $extra ) ), 'Generic upgraders with update metadata must also be guarded' );
$coordinator->run_backup( $batch['id'] );
$batch = $coordinator->batch();
pre_update_expect( 'ready' === $batch['state'], 'Real database and site archive backup must authorize updates: ' . json_encode( array( $batch, $finished, $backupplus->errors ) ) );
pre_update_expect( 'success' === $finished[1], 'Terminal success hook must fire' );
$restore_point = $history[ $batch['backup_time'] ];
pre_update_expect( ! empty( $restore_point['pre_update']['complete'] ) && empty( $restore_point['incremental'] ), 'Restore point must be complete and outside incremental chains' );
pre_update_expect( ! empty( $restore_point['db'] ) && ! empty( $restore_point['plugins'] ) && ! empty( $restore_point['themes'] ), 'Restore point must contain database, plugins and themes' );
pre_update_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins', 'themes' ), $finished[2]['incremental_configuration'] ), 'Pre-update backups must never seed incremental chains' );
$zip = new ZipArchive();
pre_update_expect( true === $zip->open( $directory . '/archives/' . $restore_point['plugins'][0] ), 'Restore archive must open' );
pre_update_expect( '<?php // original plugin' === $zip->getFromName( 'plugins/site.php' ), 'Restore archive must contain original site files' );
$zip->close();

// Receipts from every configured remote instance are mandatory, even after local deletion.
$remote                 = $batch;
$remote['destinations'] = array(
	's3'  => array( 'one', 'two' ),
	'ftp' => array( '' ),
);
$job                    = $finished[2];
pre_update_expect( ! $coordinator->verified( $remote, $job ), 'Missing remote receipts must block updates' );
foreach ( array( 'db', 'plugins', 'themes' ) as $entity ) {
	foreach ( (array) $restore_point[ $entity ] as $file ) {
		foreach ( $remote['destinations'] as $service => $instances ) {
			foreach ( $instances as $instance ) { $job[ 'uploaded_' . $service . '-' . ( '' === $instance ? '' : $instance . '-' ) . md5( $file ) ] = 'yes'; }
		}
	}
}
pre_update_expect( $coordinator->verified( $remote, $job ), 'All required remote receipts must pass' );
$incomplete                                    = $job;
$incomplete['backup_database']['wp']['status'] = 'begun';
pre_update_expect( ! $coordinator->verified( $remote, $incomplete ), 'Incomplete database must block' );
$incomplete                 = $job;
$incomplete['backup_files'] = 'begun';
pre_update_expect( ! $coordinator->verified( $remote, $incomplete ), 'Incomplete file creation must block' );

$_REQUEST['backupplus_batch'] = $batch['id'];
$db_path                      = $directory . '/archives/' . $restore_point['db'];
rename( $db_path, $db_path . '.unavailable' );
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Deleted restore archive must revoke unused authorization' );
rename( $db_path . '.unavailable', $db_path );
clearstatcache();
$original_version = $plugin_offers->response['sample/sample.php']->new_version;
$plugin_offers->response['sample/sample.php']->new_version = '3.0';
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Changed version must block' );
$plugin_offers->response['sample/sample.php']->new_version = $original_version;
$batch_option['completed']                                 = time() - HOUR_IN_SECONDS;
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Authorization expires after one hour' );
$batch_option['completed'] = time();
wp_set_current_user( 0 );
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Update capabilities are mandatory' );
wp_set_current_user( $admin[0]->ID );
pre_update_expect( false === $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ), 'Verified plugin download must pass' );
pre_update_expect( is_wp_error( $coordinator->pre_download( false, $package, $plugin_upgrader, $extra ) ), 'Used plugin authorization must not replay' );
$other = ( new ReflectionClass( 'BackupPlus_Pre_Update_Backups' ) )->newInstanceWithoutConstructor();
pre_update_expect(
	is_wp_error(
		$other->pre_download(
			false,
			$theme_offers->response['sample']['package'],
			new Theme_Upgrader(),
			array(
				'type'   => 'theme',
				'action' => 'update',
				'theme'  => 'sample',
			)
		)
	),
	'Concurrent updater requests must serialize'
);
$coordinator->release_claims();
$core_upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
pre_update_expect( false === $coordinator->pre_download( false, 'https://example.invalid/core.zip', $core_upgrader ), 'Verified core download must pass without hook_extra' );
pre_update_expect( false === $coordinator->pre_download( false, 'https://example.invalid/rollback.zip', $core_upgrader ), 'Native core rollback must remain authorized' );
$coordinator->release_claims();
pre_update_expect(
	false === $coordinator->pre_download(
		false,
		$theme_offers->response['sample']['package'],
		new Theme_Upgrader(),
		array(
			'type'   => 'theme',
			'action' => 'update',
			'theme'  => 'sample',
		)
	),
	'Verified theme update must pass'
);
$coordinator->release_claims();
pre_update_expect( 'complete' === $coordinator->batch()['state'], 'All selected targets must finish the batch' );
pre_update_expect( false === $coordinator->pre_download( false, 'https://example.invalid/new.zip', new Plugin_Upgrader( new Plugin_Installer_Skin() ), array( 'action' => 'install' ) ), 'New installation must bypass backups' );
pre_update_expect( false === $coordinator->pre_download( false, 'https://example.invalid/language.zip', new Language_Pack_Upgrader() ), 'Translations must bypass backups' );

// Whole-set retention: keep the larger configured count, pinned and pending sets.
for ( $i = 1; $i <= 5; ++$i ) {
	$copy          = $restore_point;
	$copy['nonce'] = str_pad( dechex( $i ), 12, '0', STR_PAD_LEFT );
	foreach ( array( 'db', 'plugins', 'themes' ) as $entity ) {
		$copy[ $entity ] = array( 'prune-' . $i . '-' . $entity );
		file_put_contents( $directory . '/archives/' . $copy[ $entity ][0], 'archive' );
	}
	$copy['always_keep']                   = 5 === $i;
	$copy['pre_update']['snapshots']       = array();
	$history[ $batch['backup_time'] - $i ] = $copy;
}
$coordinator->prune();
pre_update_expect( 4 === count( $history ), 'Retention must keep three complete sets plus a pinned older set' );
pre_update_expect( isset( $history[ $batch['backup_time'] - 5 ] ) && ! isset( $history[ $batch['backup_time'] - 3 ] ), 'Pinned set must survive; excess sets must be removed as a whole' );

// Exercise WordPress's actual automatic updater with a disposable plugin and local package.
$auto_slug = 'backupplus-pre-update-fixture-' . basename( $directory );
$auto_path = WP_PLUGIN_DIR . '/' . $auto_slug;
mkdir( $auto_path );
$plugin_header = "<?php\n/*\nPlugin Name: Pre-update integration fixture\nVersion: 1.0\n*/\n";
file_put_contents( $auto_path . '/fixture.php', $plugin_header );
register_shutdown_function(
	function () use ( $auto_path, $remove_tree ) {
		if ( is_dir( $auto_path ) ) { $remove_tree( $auto_path );
		} }
);
$package_path = $directory . '/update.zip';
$zip          = new ZipArchive();
$zip->open( $package_path, ZipArchive::CREATE );
$zip->addFromString( $auto_slug . '/fixture.php', str_replace( 'Version: 1.0', 'Version: 2.0', $plugin_header ) );
$zip->close();
$plugin_offers->last_checked  = time();
$plugin_offers->checked       = array( $auto_slug . '/fixture.php' => '1.0' );
$theme_offers->last_checked   = time();
$core_offers->last_checked    = time();
$core_offers->version_checked = $GLOBALS['wp_version'];
$plugin_offers->response      = array(
	$auto_slug . '/fixture.php' => (object) array(
		'plugin'      => $auto_slug . '/fixture.php',
		'slug'        => $auto_slug,
		'new_version' => '2.0',
		'package'     => $package_path,
		'autoupdate'  => true,
	),
);
$theme_offers->response       = array();
$core_offers->updates         = array();
$batch_option                 = array();
$backupplus                   = new Pre_Update_Test_BackupPlus();
$coordinator->automatic();
$auto_batch = $coordinator->batch();
$jobs[]     = $auto_batch['job'];
pre_update_expect( 'automatic' === $auto_batch['mode'] && 'queued' === $auto_batch['state'], 'Eligible native automatic update must wait before installation' );
pre_update_expect( false === strpos( file_get_contents( $auto_path . '/fixture.php' ), 'Version: 2.0' ), 'Automatic update must not run before backup' );
$coordinator->run_backup( $auto_batch['id'] );
pre_update_expect( 'ready' === $coordinator->batch()['state'], 'Automatic backup must become ready' );
$plugin_offers->response[ $auto_slug . '/fixture.php' ]->new_version = '3.0';
$coordinator->continue_automatic( $auto_batch['id'] );
pre_update_expect( false === strpos( file_get_contents( $auto_path . '/fixture.php' ), 'Version: 2.0' ), 'Changed automatic target must not install with old authorization' );
$plugin_offers->response[ $auto_slug . '/fixture.php' ]->new_version = '2.0';
$batch_option = array();
$backupplus   = new Pre_Update_Test_BackupPlus();
$coordinator->automatic();
$auto_batch = $coordinator->batch();
$jobs[]     = $auto_batch['job'];
$coordinator->run_backup( $auto_batch['id'] );
$auto_results = array();
add_action(
	'automatic_updates_complete',
	function ( $results ) use ( &$auto_results ) {
		$auto_results = $results;
	}
);
$coordinator->continue_automatic( $auto_batch['id'] );
pre_update_expect( false !== strpos( file_get_contents( $auto_path . '/fixture.php' ), 'Version: 2.0' ), 'Native automatic updater must resume after verified backup: ' . json_encode( array( $coordinator->batch(), $auto_results ) ) );
pre_update_expect( 'complete' === $coordinator->batch()['state'], 'Native automatic updater must consume its batch' );
$plugin_offers->response = array(
	'sample/sample.php' => (object) array(
		'plugin'      => 'sample/sample.php',
		'new_version' => '2.0',
		'package'     => 'https://example.invalid/plugin-2.zip',
	),
);

$batch_option = array();
$services     = array( 'missing-provider' );
$backupplus   = new Pre_Update_Test_BackupPlus();
$batch        = $coordinator->prepare( array( $keys[0] ) );
$jobs[]       = $batch['job'];
$coordinator->run_backup( $batch['id'] );
pre_update_expect( 'failed' === $coordinator->batch()['state'], 'Unavailable provider must block even if engine would omit it' );
$services              = array();
$batch_option          = array();
$batch                 = $coordinator->prepare( array( $keys[0] ) );
$jobs[]                = $batch['job'];
$batch_option['state'] = 'cancelled';
$coordinator->run_backup( $batch['id'] );
pre_update_expect( 'cancelled' === $coordinator->batch()['state'], 'Cancellation must stop queued work' );
$coordinator->backup_finished( $batch['job'], 'success', $finished[2] );
pre_update_expect( 'cancelled' === $coordinator->batch()['state'], 'Late completion must never revive a cancelled batch' );
$coordinator->wrap_automatic_updates();
pre_update_expect( false === has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ) && false !== has_action( 'wp_maybe_auto_update', array( $coordinator, 'automatic' ) ), 'Cron must wrap the native updater before updates start' );
// A real resumed backup must retain every configured service and instance.
require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
class Pre_Update_Test_Storage extends BackupPlus_BackupModule {
	public static $fail = true;
	private $fixture_id;
	public function __construct( $id ) {
		$this->fixture_id = $id; }
	public function get_id() {
		return $this->fixture_id; }
	public function supports_feature( $feature ) {
		return 'multi_options' === $feature && 's3' === $this->fixture_id; }
	public function get_default_options() {
		return array(); }
	public function backup( $files ) {
		global $backupplus;
		if ( self::$fail && 'ftp' === $this->fixture_id ) { $backupplus->log( 'Simulated upload outage.', 'error' );
			return false; }
		foreach ( $files as $file ) { $backupplus->uploaded_file( $file ); }
		return true;
	}
}
$objects = new ReflectionProperty( 'BackupPlus_Storage_Provider_Factory', 'objects' );
if ( PHP_VERSION_ID < 80100 ) { $objects->setAccessible( true ); }
$old_objects = $objects->getValue();
$objects->setValue(
	null,
	array_merge(
		$old_objects,
		array(
			's3'  => new Pre_Update_Test_Storage( 's3' ),
			'ftp' => new Pre_Update_Test_Storage( 'ftp' ),
		)
	)
);
$s3_settings = function ( $value, $name ) {
	return 'backupplus_s3' === $name ? array(
		'version'  => 1,
		'settings' => array(
			'first'  => array( 'instance_enabled' => 1 ),
			'second' => array( 'instance_enabled' => 1 ),
		),
	) : $value;
};
add_filter( 'backupplus_get_option', $s3_settings, 110, 2 );
$services     = array( 's3', 'ftp' );
$batch_option = array();
$backupplus   = new Pre_Update_Test_BackupPlus();
$remote_batch = $coordinator->prepare( array( 'plugin:sample/sample.php' ) );
$jobs[]       = $remote_batch['job'];
$coordinator->run_backup( $remote_batch['id'] );
pre_update_expect( 'backing_up' === $coordinator->batch()['state'], 'Upload failure with a scheduled resumption must keep the batch waiting' );
$remote_job = $backupplus->jobdata_getarray( $remote_batch['job'] );
pre_update_expect( array( 's3', 'ftp' ) === $remote_job['service'], 'All configured services must reach the engine' );
pre_update_expect( array( 'first', 'second' ) === $remote_job['remote_storage_instances']['s3'], 'All enabled instances must reach the engine' );
pre_update_expect( ! $coordinator->verified( $coordinator->batch(), $remote_job ), 'Finished archives with unfinished uploads must not authorize updates' );
$remote_job['runs_started'] = array( time() - 400 );
$remote_job['run_times']    = array( 1 );
update_site_option( 'backupplus_jobdata_' . $remote_batch['job'], BackupPlus_Credential_Storage::job( $remote_job, $remote_batch['job'], 'encrypt' ) );
Pre_Update_Test_Storage::$fail = false;
$backupplus                    = new Pre_Update_Test_BackupPlus();
foreach ( glob( $directory . '/archives/*' ) as $archive ) { touch( $archive, time() - 400 ); }
clearstatcache();
$backupplus->backup_resume( 1, $remote_batch['job'] );
pre_update_expect( 'ready' === $coordinator->batch()['state'], 'A later request must authorize the same batch after its remaining uploads finish: ' . json_encode( array( $coordinator->batch(), $backupplus->errors, $finished ) ) );
$objects->setValue( null, $old_objects );
remove_filter( 'backupplus_get_option', $s3_settings, 110 );
$services     = array();
$batch_option = array();

// Encryption configuration is applied to every archive; failed/aborted jobs never authorize.
$batch_option   = array();
$encryption     = array(
	'mode'       => 'age',
	'recipients' => array( 'invalid-recipient' ),
);
$backupplus     = new Pre_Update_Test_BackupPlus();
$bad_encryption = $coordinator->prepare( array( 'plugin:sample/sample.php' ) );
$jobs[]         = $bad_encryption['job'];
$coordinator->run_backup( $bad_encryption['id'] );
pre_update_expect( 'ready' !== $coordinator->batch()['state'], 'Encryption errors must block updates' );
$coordinator->backup_finished( $bad_encryption['job'], 'aborted', array( 'pre_update_batch' => $bad_encryption['id'] ) );
pre_update_expect( 'failed' === $coordinator->batch()['state'], 'Aborted jobs must fail closed' );
$keys_fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
$encryption   = array(
	'mode'       => 'age',
	'recipients' => array( $keys_fixture['keys']['native']['recipient'] ),
);
$batch_option = array();
$backupplus   = new Pre_Update_Test_BackupPlus();
$encrypted    = $coordinator->prepare( array( 'plugin:sample/sample.php' ) );
$jobs[]       = $encrypted['job'];
$coordinator->run_backup( $encrypted['id'] );
pre_update_expect( 'ready' === $coordinator->batch()['state'], 'Encrypted pre-update backup must complete' );
$encrypted_set = $history[ $coordinator->batch()['backup_time'] ];
foreach ( array( 'db', 'plugins', 'themes' ) as $entity ) {
	foreach ( (array) $encrypted_set[ $entity ] as $file ) { pre_update_expect( '.crypt' === substr( $file, -6 ), 'All pre-update archives must honor encryption' ); }
}
$encryption = array( 'mode' => 'none' );
// Finalization survives a contending status request even after the engine cleans up its job.
$batch_option['state'] = 'backing_up';
pre_update_expect( WP_Upgrader::create_lock( 'backupplus_pre_update', 60 ), 'Finalization contention fixture must acquire the mutex' );
$coordinator->backup_finished( $encrypted['job'], 'success', $finished[2] );
WP_Upgrader::release_lock( 'backupplus_pre_update' );
$finalize = null;
foreach ( $cron as $events ) {
	foreach ( isset( $events['backupplus_pre_update_finalize'] ) ? $events['backupplus_pre_update_finalize'] : array() as $event ) { $finalize = $event['args']; }
}
pre_update_expect( ! empty( $finalize ) && ! isset( $finalize[2]['option_cache'] ) && ! isset( $finalize[2]['backup_database']['wp']['dbinfo'] ), 'Deferred completion must retain evidence without storage or database credentials' );
do_action_ref_array( 'backupplus_pre_update_finalize', $finalize );
pre_update_expect( 'ready' === $coordinator->batch()['state'], 'Deferred completion must verify and authorize the cleaned-up job' );

// Actual authenticated HTTP endpoints and classic progress forms, isolated across requests.
$http_token  = bin2hex( random_bytes( 12 ) );
$http_prefix = 'backupplus_pre_update_test_' . $http_token;
$http_mu     = WPMU_PLUGIN_DIR . '/' . $http_prefix . '.php';
file_put_contents( $http_mu, '<?php define("BACKUPPLUS_PRE_UPDATE_TEST_TOKEN", ' . var_export( $http_token, true ) . '); require ' . var_export( BACKUPPLUS_DIR . '/scripts/fixtures/pre-update-http.php', true ) . ';' );
$session                     = WP_Session_Tokens::get_instance( $admin[0]->ID )->create( time() + 600 );
$auth                        = wp_generate_auth_cookie( $admin[0]->ID, time() + 600, 'auth', $session );
$logged_in                   = wp_generate_auth_cookie( $admin[0]->ID, time() + 600, 'logged_in', $session );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
$headers                     = array(
	'X-BackupPlus-Pre-Update-Test' => $http_token,
	'Cookie'                       => AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in ),
);
register_shutdown_function(
	function () use ( $http_mu, $http_prefix, $session, $admin ) {
		if ( is_file( $http_mu ) ) { unlink( $http_mu ); }
		foreach ( array( '_batch', '_history', '_cron' ) as $suffix ) { delete_option( $http_prefix . $suffix ); }
		WP_Session_Tokens::get_instance( $admin[0]->ID )->destroy( $session );
	}
);
remove_filter( 'pre_http_request', $offline, 100 );
$http_request = function ( $operation, $extra = array() ) use ( $headers ) {
	$response = wp_remote_post(
		admin_url( 'admin-ajax.php' ),
		array(
			'headers' => $headers,
			'timeout' => 15,
			'body'    => array_merge(
				array(
					'action'    => 'backupplus_pre_update',
					'operation' => $operation,
					'nonce'     => wp_create_nonce( 'backupplus_pre_update' ),
				),
				$extra
			),
		)
	);
	pre_update_expect( ! is_wp_error( $response ), 'HTTP endpoint must respond' );
	return array( wp_remote_retrieve_response_code( $response ), json_decode( wp_remote_retrieve_body( $response ), true ) );
};
$response     = $http_request( 'prepare', array( 'targets' => array( 'plugin:sample/sample.php' ) ) );
pre_update_expect( 200 === $response[0] && ! empty( $response[1]['success'] ), 'Authenticated prepare must return a batch: ' . json_encode( $response ) );
$http_id  = $response[1]['data']['id'];
$response = $http_request( 'status', array( 'batch' => $http_id ) );
pre_update_expect( 'queued' === $response[1]['data']['state'] && ! empty( $response[1]['data']['log'] ), 'Status must expose safe progress and an authorized log link' );
$response = $http_request( 'cancel', array( 'batch' => $http_id ) );
pre_update_expect( 'cancelled' === $response[1]['data']['state'], 'HTTP cancel must prevent continuation' );
$response = $http_request( 'retry', array( 'batch' => $http_id ) );
pre_update_expect( 'queued' === $response[1]['data']['state'], 'HTTP retry must create a fresh backup job' );
$response = $http_request( 'status', array( 'batch' => 'not-the-authorized-batch' ) );
pre_update_expect( 403 === $response[0], 'Unrelated batch identity must be rejected' );
$response = $http_request(
	'status',
	array(
		'batch' => $http_id,
		'nonce' => 'invalid',
	)
);
pre_update_expect( 403 === $response[0], 'Invalid AJAX nonce must be rejected' );
$url      = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=sample%2Fsample.php' ), 'upgrade-plugin_sample/sample.php' );
$response = wp_remote_get(
	str_replace( '&amp;', '&', $url ),
	array(
		'headers' => $headers,
		'timeout' => 15,
	)
);
pre_update_expect( ! is_wp_error( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'backupplus-pre-update-classic' ), 'Classic plugin request must pause on the progress screen' );
foreach ( array(
	array( 'update.php?action=update-selected', 'bulk-update-plugins', array( 'checked' => array( 'sample/sample.php' ) ) ),
	array( 'update.php?action=upgrade-theme&theme=sample', 'upgrade-theme_sample', array() ),
	array( 'update.php?action=update-selected-themes', 'bulk-update-themes', array( 'checked' => array( 'sample' ) ) ),
	array( 'update-core.php?action=do-plugin-upgrade', 'upgrade-core', array( 'checked' => array( 'sample/sample.php' ) ) ),
	array( 'update-core.php?action=do-theme-upgrade', 'upgrade-core', array( 'checked' => array( 'sample' ) ) ),
	array(
		'update-core.php?action=do-core-upgrade',
		'upgrade-core',
		array(
			'upgrade' => 1,
			'version' => '99.0',
			'locale'  => 'en_US',
		),
	),
	array(
		'update-core.php?action=do-core-reinstall',
		'upgrade-core',
		array(
			'upgrade' => 1,
			'version' => '99.0',
			'locale'  => 'en_US',
		),
	),
) as $flow ) {
	$response = wp_remote_post(
		self_admin_url( $flow[0] ),
		array(
			'headers' => $headers,
			'timeout' => 15,
			'body'    => array_merge( $flow[2], array( '_wpnonce' => wp_create_nonce( $flow[1] ) ) ),
		)
	);
	pre_update_expect( ! is_wp_error( $response ), 'Classic update screen must respond: ' . $flow[0] );
	$html = wp_remote_retrieve_body( $response );
	pre_update_expect( false !== strpos( $html, 'backupplus-pre-update-classic' ), 'Classic update must pause before installation: ' . $flow[0] );
	if ( isset( $flow[2]['upgrade'] ) ) {
		pre_update_expect( false !== strpos( $html, 'name="upgrade" value="1"' ), 'Core resume form must retain the native upgrade trigger' );
	}
}
$stored                    = get_option( $http_prefix . '_batch' );
$stored['state']           = 'updating';
$stored['completed']       = time() - HOUR_IN_SECONDS - 1;
$stored['request']         = 'terminated-worker';
$stored['request_started'] = time() - HOUR_IN_SECONDS - 1;
update_option( $http_prefix . '_batch', $stored );
$response = $http_request( 'status', array( 'batch' => $http_id ) );
pre_update_expect( 'expired' === $response[1]['data']['state'], 'An expired authorization must release an abandoned updater request' );
$response = $http_request( 'retry', array( 'batch' => $http_id ) );
pre_update_expect( 'queued' === $response[1]['data']['state'], 'Abandoned updates must recover through a fresh backup' );
$stored         = get_option( $http_prefix . '_batch' );
$stored['user'] = $admin[0]->ID + 10000;
update_option( $http_prefix . '_batch', $stored );
$response = $http_request( 'status', array( 'batch' => $http_id ) );
pre_update_expect( 403 === $response[0], 'An administrator cannot inspect another manual batch owner' );

$test_complete = true;
WP_CLI::success( 'Pre-update full backups, remote receipts, permissions, concurrency, expiry, rollback, cancellation, retention and incremental isolation passed.' );
