<?php
/**
 * BPS-04 in real WordPress: names and text that come from remote storage, archives and dumps reach the administrator's browser
 * as text, never as markup. Each server-side sink is rendered with a payload and the output is checked for the raw tag.
 *
 * Sinks covered: raw backup info and the raw history dump, the download status message, the restore preflight (archive names, the
 * database scan of a dump header) and the rescan messages. The browser side (jstree force_text, the restore progress labels) is
 * covered by tests/Unit/Backup/AdminMarkupEscapingTest.php, which reads the shipped scripts.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-xss-sinks.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
function xss_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); } }

/** The payload must be present only as text: no tag, no attribute that could run, and the escaped form must show. */
function xss_escaped( $html, $where ) {
	xss_expect( false === stripos( $html, '<img' ) && false === stripos( $html, '<svg' ) && false === stripos( $html, '<script' ), "$where renders the payload as markup: " . substr( $html, max( 0, (int) stripos( $html, '<img' ) - 150 ), 300 ) );
	xss_expect( false !== strpos( $html, '&lt;img src=x onerror=' ) || false !== strpos( $html, '&lt;img src=x onerror&#061;' ), "$where lost the payload instead of escaping it: " . substr( $html, 0, 300 ) );
}

$payload   = '<img src=x onerror=alert(1)>';
$root      = get_temp_dir() . 'backupplus-xss-' . bin2hex( random_bytes( 8 ) );
$directory = $root . '/backups';
mkdir( $directory, 0700, true );

$history = array();
foreach ( array(
	'backupplus_dir'            => function () use ( $directory ) {
		return $directory;
	},
	'backupplus_backup_history' => function () use ( &$history ) {
		return $history;
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
backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'require_once' );

$jobs = array();
try {
	// The name of an archive that a rescan accepts: it has no path separator, so the payload is legal in a history entry.
	$name                  = 'backup_2024-01-01-0000_' . $payload . '_abcdef012345-db.gz';
	$timestamp             = 1704067200;
	$history[ $timestamp ] = array(
		'db'          => $name,
		'db-size'     => 1234,
		'nonce'       => 'abcdef012345',
		'label'       => $payload,
		'service'     => array( $payload ),
		'checksums'   => array( 'sha1' => array( 'db0' => $payload ) ),
		'always_keep' => 0,
	);

	// (b) The raw backup info shown when the administrator double-clicks the backup date.
	$info = $backupplus_admin->raw_backup_info( $history, $timestamp, 'abcdef012345', array() );
	xss_escaped( $info, 'The raw backup info' );
	xss_expect( false === strpos( $info, '&amp;lt;' ), 'The raw backup info must be escaped once, not twice.' );
	$commands = new BackupPlus_WPAdmin_Commands( $backupplus_admin );
	$response = $commands->rawbackup_history(
		array(
			'timestamp' => $timestamp,
			'nonce'     => 'abcdef012345',
		)
	);
	xss_escaped( $response['rawbackup'], 'The rawbackup_history response' );
	$response = $commands->always_keep_this_backup(
		array(
			'backup_key'  => $timestamp,
			'always_keep' => 1,
		)
	);
	xss_escaped( $response['rawbackup'], 'The always_keep_this_backup response' );

	// (a) The raw history dump of the Advanced Tools.
	$raw = $backupplus_admin->show_raw_backups();
	xss_escaped( $raw['html'], 'The raw history dump' );
	$raw_plain = $backupplus_admin->show_raw_backups( true );
	xss_escaped( $raw_plain['html'], 'The raw history dump without <pre> tags' );
	// A file in the backup directory is listed by name.
	file_put_contents( $directory . '/' . $payload . '.txt', 'x' );
	$raw = $backupplus_admin->show_raw_backups();
	xss_escaped( $raw['html'], 'The backup directory listing' );

	// (c) The log line of a download job is the status message the browser renders with .html().
	$job = 'abcdef012345';
	$backupplus->nonce = $job;
	$backupplus->jobdata_reset();
	$jobs[] = $job;
	$backupplus->jobdata_set( 'dlmessage_' . $timestamp . '_db_0', 'Requested file from remote service: s3: ' . $name );
	$status = new ReflectionMethod( $backupplus_admin, 'download_status' );
	$download = $status->invoke( $backupplus_admin, (string) $timestamp, 'db', '0' );
	xss_escaped( $download['m'], 'The download status message' );
	$backupplus->jobdata_set( 'dlfile_' . $timestamp . '_db_0', 'failed' );
	$backupplus->jobdata_set( 'dlerrors_' . $timestamp . '_db_0', array( $payload ) );
	$download = $status->invoke( $backupplus_admin, (string) $timestamp, 'db', '0' );
	xss_escaped( $download['e'], 'The download failure list' );

	// (d) Restore preflight: archive names in errors and warnings.
	$restore = function ( $type_name ) use ( $commands, $timestamp ) {
		return $commands->restore_alldownloaded(
			array(
				'timestamp'   => $timestamp,
				'restoreopts' => http_build_query( array( 'backupplus_restore' => array( 'db' ) ) ),
			)
		);
	};
	$result = $restore( $name );
	xss_expect( is_array( $result ), 'The preflight must answer.' );
	xss_escaped( $result['e'], 'The preflight "File not found" error' );
	file_put_contents( $directory . '/' . $name, '' );
	$result = $restore( $name );
	xss_escaped( $result['e'], 'The preflight "zero-sized" error' );
	// The size differs from the recorded one, so this is a warning, and the dump is then scanned.
	$dump = "# BackupPlus Backup\n# Backup of: http://example.test/" . $payload . "\n# WordPress Version: 6.0\n# Table prefix: wp_\n# Label: " . $payload . "\n# Skipped tables: wp_" . $payload . ",wp_x\n" . str_repeat( "-- padding\n", 120 ) . "/*!40101 SET NAMES " . $payload . " */;\nCREATE TABLE `wp_options` (\n  `id` int\n) ENGINE=InnoDB;\n";
	file_put_contents( $directory . '/' . $name, $dump );
	$history[ $timestamp ]['db-size'] = strlen( $dump ) + 1;
	$result                           = $restore( $name );
	xss_escaped( $result['w'], 'The preflight warnings' );

	// (d) The database scan on its own: the label, the old site URL, the skipped tables and the character set.
	$plain = $directory . '/dump-' . bin2hex( random_bytes( 4 ) ) . '.sql';
	file_put_contents( $plain, $dump );
	list( $mess, $warn, $err ) = $backupplus->analyse_db_file( $timestamp, array(), $plain );
	xss_escaped( implode( '', $mess ), 'The database scan messages' );
	xss_escaped( implode( '', $warn ) . implode( '', $err ), 'The database scan warnings' );
	foreach ( array_merge( $mess, $warn, $err ) as $line ) {
		xss_expect( false === stripos( $line, '<img' ), 'No database scan line may carry the tag: ' . $line );
	}
	unlink( $plain );

	// (h) Rescan errors: a remote module that fails with attacker-chosen text.
	BackupPlus_Storage_Provider_Factory::create( 's3' );
	$s3 = new class() extends BackupPlus_BackupModule_s3 {
		public static $text = '';
		public function get_id() {
			return 's3'; }
		public function listfiles( $match = 'backup_' ) {
			return new WP_Error( 'remote_failure', self::$text ); }
		public function get_description() {
			return 'Test storage'; }
	};
	$s3::$text = 'Access denied ' . $payload;
	( new ReflectionProperty( 'BackupPlus_Storage_Provider_Factory', 'objects' ) )->setValue( null, array( 's3' => $s3 ) );
	$backupplus->backup_methods = array( 's3' => 'Amazon S3' );
	$settings                   = BackupPlus_Credential_Storage::saving(
		array(
			'version'  => 1,
			'settings' => array( 's-xss-test' => array_merge( $s3->get_default_options(), array( 'accesskey' => 'k', 'secretkey' => 's', 'path' => 'bucket/site' ) ) ),
		),
		'backupplus_s3'
	);
	add_filter(
		'pre_option_backupplus_s3',
		function () use ( $settings ) {
			return $settings;
		},
		100
	);
	$status = $backupplus_admin->get_history_status( true, true );
	xss_expect( false !== strpos( $status['t'], 'Access denied' ), 'The rescan error must be shown.' );
	xss_escaped( $status['t'], 'The rescan messages' );

	WP_CLI::success( 'Names and messages from remote storage, archives and dumps are escaped in every server-side sink.' );
} finally {
	foreach ( array_unique( array_filter( $jobs ) ) as $job ) {
		delete_site_option( 'backupplus_jobdata_' . $job );
	}
	foreach ( array_diff( scandir( $directory ), array( '.', '..' ) ) as $entry ) {
		unlink( $directory . '/' . $entry );
	}
	rmdir( $directory );
	rmdir( $root );
}
