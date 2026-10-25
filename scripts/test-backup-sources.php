<?php
/** Exact selections, streaming SQL, namespace stability and confined local restores. */
define( 'ABSPATH', '/wordpress/' );
require dirname( __DIR__ ) . '/includes/class-backup-sources.php';
require dirname( __DIR__ ) . '/includes/class-sql-stream.php';
require dirname( __DIR__ ) . '/includes/class-source-restore.php';
function sources_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function sources_rejects( $callback, $message ) {
	try { $callback();
	} catch ( Exception $e ) { return; }
	throw new RuntimeException( $message );
}
class BackupPlus_Options {
	public static function get_backupplus_option( $name, $default = false ) {
		return $default; } }
class BackupPlus_Credential_Key_Store {
	const BACKUP_ENTITY = 'credential-keys';
	public static function exclude( $excluded, $path ) {
		return $excluded; } }
$backupplus = new class { public function backups_dir_location() {
		return '/no-backups-here';
} public function jobdata_get( $name, $default = false ) {
	return $default;
} };
$tables     = array( 'wp_posts', 'wp_options', 'unrelated', 'WP_posts' );
sources_expect( array( 'wp_posts', 'wp_options' ) === BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), $tables, 'wp_' ), 'Main eligibility preserves the exact prefix.' );
sources_expect( $tables === BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), $tables, '' ), 'External tables are eligible without a prefix.' );
sources_expect( array( 'WP_only' ) === BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), array( 'WP_only' ), 'wp_' ), 'All-eligible mode keeps the legacy case-insensitive prefix default when no case-distinct duplicates exist.' );
sources_expect(
	array( 'unrelated' ) === BackupPlus_Backup_Sources::resolve(
		array(
			'mode'   => 'selected',
			'tables' => array( 'unrelated' ),
		),
		$tables,
		'wp_'
	),
	'Exact selection bypasses prefix and never includes related/core tables.'
);
sources_expect(
	array() === BackupPlus_Backup_Sources::resolve(
		array(
			'enabled' => false,
			'mode'    => 'selected',
		),
		$tables,
		''
	),
	'Disabled databases can retain empty selections.'
);
sources_rejects(
	function () use ( $tables ) {
		BackupPlus_Backup_Sources::resolve( array( 'mode' => 'selected' ), $tables, '' );
	},
	'Empty selection must fail.'
);
sources_rejects(
	function () use ( $tables ) {
		BackupPlus_Backup_Sources::resolve(
			array(
				'mode'   => 'selected',
				'tables' => array( 'missing' ),
			),
			$tables,
			''
		);
	},
	'Missing selection must fail.'
);
sources_expect( BackupPlus_Backup_Sources::source_id( '/a/data' ) !== BackupPlus_Backup_Sources::source_id( '/b/data' ), 'Identical basenames have separate namespaces.' );
sources_expect( BackupPlus_Backup_Sources::custom( BackupPlus_Backup_Sources::source_id( '/a/data' ) ), 'Namespaces remain compatible with the archive filename parser.' );
$pitr_backup = array(
	'db'   => 'backup_pitr_full.sql.gz',
	'pitr' => array(
		'state'  => 'active',
		'points' => array(
			array(
				'backup_id' => str_repeat( 'a', 32 ),
				'increment' => 0,
			),
			array(
				'backup_id' => str_repeat( 'b', 32 ),
				'increment' => 1,
			),
		),
	),
);
BackupPlus_Source_Restore::preflight( array( 'db' => true ), $pitr_backup, array( 'backupplus_pitr_restore_point' => str_repeat( 'a', 32 ) ) );
foreach ( array( array(), array( 'backupplus_pitr_restore_point' => str_repeat( 'b', 32 ) ), array( 'backupplus_pitr_restore_point' => str_repeat( 'c', 32 ) ) ) as $options ) {
	sources_rejects(
		function () use ( $pitr_backup, $options ) {
			BackupPlus_Source_Restore::preflight( array( 'db' => true ), $pitr_backup, $options );
		},
		'Ordinary PITR preflight must reject missing, incremental and forged recovery-point selections.'
	);
}
foreach ( array( 'deleting', 'unavailable' ) as $state ) {
	$pitr_backup['pitr']['state'] = $state;
	sources_rejects(
		function () use ( $pitr_backup ) {
			BackupPlus_Source_Restore::preflight( array( 'db' => true ), $pitr_backup, array( 'backupplus_pitr_restore_point' => str_repeat( 'a', 32 ) ) );
		},
		'An unavailable PITR chain must not bypass preflight through its full snapshot.'
	);
}
sources_rejects(
	function () {
		BackupPlus_Backup_Sources::paths( array( '/a/../secret' ) );
	},
	'Dot traversal rejected.'
);
sources_rejects(
	function () {
		BackupPlus_Backup_Sources::paths( array( 'https://example.test/file' ) );
	},
	'Remote wrappers rejected.'
);
if ( '/' === DIRECTORY_SEPARATOR ) {
	sources_rejects(
		function () {
			BackupPlus_Source_Restore::destination( 'C:/foreign/windows/path', 'directory' );
		},
		'Foreign drive-letter paths require a local restore mapping on Unix.'
	);
}
$directory = sys_get_temp_dir() . '/bp-sources-' . bin2hex( random_bytes( 5 ) );
mkdir( $directory );
try {
	$sql = "# Header\nDROP TABLE IF EXISTS `unrelated`;\nCREATE TABLE `unrelated` (`value` text);\nINSERT INTO `unrelated` VALUES ('semi;colon'),('quote\\' and newline\nkept'),('doubled''quote');\n/*!40101 SET NAMES utf8mb4 */;\nDELIMITER ;;\nCREATE TRIGGER `test` BEFORE INSERT ON `unrelated` FOR EACH ROW BEGIN SET @x=';;'; SET @y=2; END;;\nDELIMITER ;\n";
	file_put_contents( $directory . '/dump.sql', $sql );
	$handle = gzopen( $directory . '/dump.gz', 'wb' );
	gzwrite( $handle, $sql );
	gzclose( $handle );
	$plain      = iterator_to_array( BackupPlus_SQL_Stream::statements( $directory . '/dump.sql' ) );
	$compressed = iterator_to_array( BackupPlus_SQL_Stream::statements( $directory . '/dump.gz' ) );
	sources_expect( $plain === $compressed && 5 === count( $plain ), 'Plain/gzip statement readers preserve delimiters, quoted semicolons and triggers.' );
	sources_expect( false !== strpos( $plain[2], "newline\nkept" ), 'Newlines in values are preserved.' );
	file_put_contents( $directory . '/bad.sql', "INSERT INTO t VALUES ('unterminated" );
	sources_rejects(
		function () use ( $directory ) {
			iterator_to_array( BackupPlus_SQL_Stream::statements( $directory . '/bad.sql' ) );
		},
		'Truncated SQL rejected.'
	);
	mkdir( $directory . '/source' );
	mkdir( $directory . '/target' );
	file_put_contents( $directory . '/source/replaced', 'new' );
	file_put_contents( $directory . '/target/keep', 'unselected' );
	BackupPlus_Source_Restore::merge( $directory . '/source', $directory . '/target' );
	sources_expect( 'new' === file_get_contents( $directory . '/target/replaced' ) && 'unselected' === file_get_contents( $directory . '/target/keep' ), 'Directory merging preserves unselected content.' );
	symlink( $directory . '/source', $directory . '/target/link' );
	sources_rejects(
		function () use ( $directory ) {
			BackupPlus_Source_Restore::merge( $directory . '/source', $directory . '/target/link' );
		},
		'Restore cannot follow destination symlinks.'
	);
} finally { BackupPlus_Source_Restore::remove( $directory ); }
echo "Backup source unit checks passed.\n";
