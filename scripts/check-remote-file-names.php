<?php
/**
 * BPS-02 in real WordPress: a remote object whose name traverses out of the backup directory is not added to backup history by
 * "Rescan remote storage", and no download, restore check or deletion that joins a history name to the backup directory writes,
 * reads or unlinks outside it, even when history was already poisoned before the fix.
 *
 * The real Amazon S3 module lists a bucket sub-path through a fake transport, so the listing, rescan and download code under test
 * is the shipped code; only the network boundary is replaced.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-remote-file-names.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
function names_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); } }

$root      = get_temp_dir() . 'backupplus-names-' . bin2hex( random_bytes( 8 ) );
$directory = $root . '/backups';
$outside   = $root . '/outside';
mkdir( $directory, 0700, true );
mkdir( $outside, 0700 );

$legit     = 'backup_2024-01-01-0000_site_abcdef012345-db.gz';
$traversal = 'backup_2024-01-02-0000_x/../../outside/evil.php._0123456789ab-db.gz';
$backslash = 'backup_2024-01-03-0000_x\\..\\..\\outside\\evil.php._123456789abc-db.gz';
$nested    = 'nested/backup_2024-01-04-0000_site_fedcba987654-db.gz';
$target    = $outside . '/evil.php._0123456789ab-db.gz';
$contents  = 'attacker bytes';

$history  = array();
$settings = array();
foreach ( array(
	'backupplus_dir'            => function () use ( $directory ) {
		return $directory;
	},
	'backupplus_backup_history' => function () use ( &$history ) {
		return $history;
	},
	'backupplus_s3'             => function () use ( &$settings ) {
		return $settings;
	},
	'backupplus_service'        => function () {
		return array( 's3' );
	},
	'backupplus_debug_mode'     => function () {
		return 0;
	},
) as $option => $callback ) {
	add_filter( 'pre_option_' . $option, $callback, 100 );
}
add_filter(
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$history ) {
		$history = $value;
		return $old;
	},
	100,
	2
);
// Nothing in this check may reach the network.
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'test_offline' );
	}
);

global $backupplus, $backupplus_admin;
require dirname( __DIR__ ) . '/backupplus.php';
require_once BACKUPPLUS_DIR . '/admin.php';
require_once BACKUPPLUS_DIR . '/methods/s3/S3.php';

/** The real S3 client, with the calls that would leave the machine answered from memory. */
class BackupPlus_Names_Test_Transport extends BackupPlus_S3 {
	public static $objects = array();
	public static $gets    = array();
	public function getBucketLocation( $bucket ) {
		return 'us-east-1'; }
	public function putBucket( $bucket, $acl = 'private', $location = false ) {
		return true; }
	public function getBucket( $bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = null, $returnCommonPrefixes = false ) {
		$result = array();
		foreach ( self::$objects[ $bucket ] ?? array() as $name => $data ) {
			if ( 0 === strpos( $name, (string) $prefix ) ) {
				$result[] = array(
					'name' => $name,
					'size' => strlen( $data ),
				);
			}
		}
		return $result;
	}
	public function getObject( $bucket, $uri, $saveTo = false, $resume = false ) {
		self::$gets[] = $uri;
		$data         = self::$objects[ $bucket ][ $uri ];
		if ( $resume && preg_match( '/bytes=(\d+)-(\d+)/', $resume, $matches ) ) {
			$data = substr( $data, $matches[1], $matches[2] - $matches[1] + 1 );
		}
		fwrite( $saveTo, $data );
		return true;
	}
}

// The shipped Amazon S3 module, except that its client is the fake transport. The factory hands out this shared instance.
BackupPlus_Storage_Provider_Factory::create( 's3' );
$s3 = new class() extends BackupPlus_BackupModule_s3 {
	public function get_id() {
		return 's3'; }
	protected function indicate_s3_class() {
		return 'BackupPlus_Names_Test_Transport'; }
};
( new ReflectionProperty( 'BackupPlus_Storage_Provider_Factory', 'objects' ) )->setValue( null, array( 's3' => $s3 ) );
// Only this destination is scanned, whatever else the site has configured.
$backupplus->backup_methods = array( 's3' => 'Amazon S3' );
// Stored as a settings save would store it: the provider refuses plaintext secrets.
$settings = BackupPlus_Credential_Storage::saving(
	array(
		'version'  => 1,
		'settings' => array(
			's-names-test' => array_merge(
				$s3->get_default_options(),
				array(
					'accesskey' => 'test-key',
					'secretkey' => 'test-secret',
					'path'      => 'test-bucket/site',
				)
			),
		),
	),
	'backupplus_s3'
);

BackupPlus_Names_Test_Transport::$objects['test-bucket'] = array(
	'site/' . $legit     => 'database dump',
	'site/' . $traversal => $contents,
	'site/' . $backslash => $contents,
	'site/' . $nested    => $contents,
);

/** No file may exist anywhere under $root but the backup directory. */
function names_outside_is_empty( $outside ) {
	clearstatcache();
	return array() === array_diff( scandir( $outside ), array( '.', '..' ) );
}

// The download and deletion jobs these checks start; their job data is removed afterwards.
$jobs = array();
try {
	// 1. A remote rescan only adds the plain archive name.
	BackupPlus_Backup_History::rebuild( true );
	$names = array();
	foreach ( $history as $set ) {
		foreach ( array( 'db', 'plugins', 'themes', 'uploads', 'others' ) as $type ) {
			if ( isset( $set[ $type ] ) ) {
				$names = array_merge( $names, (array) $set[ $type ] );
			}
		}
	}
	names_expect( array( $legit ) === $names, 'A rescan must add the plain archive and nothing else, found: ' . wp_json_encode( $names ) );
	$timestamp = key( $history );
	names_expect( array( 's3' ) === $history[ $timestamp ]['service'], 'The rescanned archive must be recorded against the remote destination.' );

	// 2. The plain archive downloads through the same path, so the refusals below are not just a broken fixture.
	BackupPlus_Storage_Methods_Interface::get_remote_file( array( 's3' ), $legit, $timestamp );
	names_expect( 'database dump' === @file_get_contents( $directory . '/' . $legit ), 'The rescanned archive must download into the backup directory.' );

	// 3. History poisoned before the fix: every sink refuses the traversing names.
	foreach ( array( $traversal, $backslash, $nested ) as $index => $name ) {
		$poisoned                              = 1700000000 + $index;
		$history[ $poisoned ]                  = array(
			'db'      => $name,
			'db-size' => strlen( $contents ),
			'nonce'   => '0123456789ab',
			'service' => array( 's3' ),
		);
		BackupPlus_Names_Test_Transport::$gets = array();

		BackupPlus_Storage_Methods_Interface::get_remote_file( array( 's3' ), $name, $poisoned );
		BackupPlus_Storage_Methods_Interface::get_remote_file( array( 's3' ), $name, $poisoned, true );
		names_expect( array() === BackupPlus_Names_Test_Transport::$gets && names_outside_is_empty( $outside ), "Fetching a poisoned history entry must not download it: $name" );

		names_expect( false === $backupplus->chunked_download( $name, $s3, strlen( $contents ), false, new BackupPlus_Names_Test_Transport( 'test-key', 'test-secret' ) ) && names_outside_is_empty( $outside ), "A chunked download must refuse a name that is not a basename: $name" );

		$result = $backupplus_admin->do_backupplus_download_backup( 0, 'db', $poisoned, false, function () {} );
		$jobs[] = $backupplus->nonce;
		names_expect( isset( $result['code'] ) && 'invalid_file' === $result['code'] && names_outside_is_empty( $outside ), "The download handler must refuse a poisoned entry: $name" );
	}

	// 4. The delete stages must not unlink a file outside the backup directory that the poisoned name points to. is_file() and
	// unlink() resolve through the file system rather than textually, so the directory in the name exists here.
	file_put_contents( $target, 'site file' );
	mkdir( $directory . '/' . strtok( $traversal, '/' ) );
	$result = $backupplus_admin->do_backupplus_download_backup( 0, 'db', 1700000000, 'delete' );
	$jobs[] = $backupplus->nonce;
	names_expect( 'invalid_file' === $result['code'] && is_file( $target ), 'The download handler delete stage must not unlink outside the backup directory.' );
	$backupplus_admin->delete_set(
		array(
			'backup_timestamp' => '1700000000',
			'delete_remote'    => 0,
			'is_continuation'  => false,
		)
	);
	$jobs[] = $backupplus->nonce;
	names_expect( is_file( $target ), 'Deleting a poisoned backup set must not unlink outside the backup directory.' );
	names_expect( 'site file' === file_get_contents( $target ), 'Files outside the backup directory must be left unchanged.' );

	WP_CLI::success( 'Remote names that are not plain basenames are dropped from rescans and refused by downloads and deletions.' );
} finally {
	foreach ( array_unique( array_filter( $jobs ) ) as $job ) {
		delete_site_option( 'backupplus_jobdata_' . $job );
	}
	foreach ( array( $directory, $outside ) as $path ) {
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			is_dir( $path . '/' . $entry ) ? rmdir( $path . '/' . $entry ) : unlink( $path . '/' . $entry );
		}
		rmdir( $path );
	}
	rmdir( $root );
}
