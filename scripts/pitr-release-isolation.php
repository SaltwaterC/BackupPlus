<?php

/** Emergency release after repairing a failed restore; credentials are prompted and never saved. */
if ( 'cli' !== PHP_SAPI || 3 !== count( $argv ) || '--confirm-database-recovered' !== $argv[2] ) {
	fwrite( STDERR, "Usage: php scripts/pitr-release-isolation.php /absolute/path/pitr/isolation.php --confirm-database-recovered\nOnly use after repairing the database or restoring the pinned pre-restore backup. Restore credentials are prompted securely.\n" );
	exit( 1 );
}
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../includes/class-pitr-process.php';
require_once __DIR__ . '/../includes/class-pitr-manifest.php';
require_once __DIR__ . '/../includes/class-pitr-database.php';
require_once __DIR__ . '/../includes/class-cli-restore.php';
$directory  = null;
$connection = null;
$exit_code  = 0;
try {
	$path = $argv[1];
	if ( is_link( $path ) || ! is_file( $path ) || ( fileperms( $path ) & 0077 ) || filesize( $path ) > 262144 ) { throw new RuntimeException( 'Use the original owner-only recovery protection record.' ); }
	$raw = file_get_contents( $path );
	if ( '<?php exit; ?>' !== substr( $raw, 0, 14 ) ) { throw new RuntimeException( 'Invalid recovery protection record.' ); }
	$record = json_decode( substr( $raw, 14 ), true );
	if ( ! is_array( $record ) || ! isset( $record['source']['engine'], $record['source']['identity'], $record['database'], $record['bin_directory'], $record['connection'], $record['maintenance'] ) || 'application-accounts-v1' !== $record['protection'] ) { throw new RuntimeException( 'Incomplete or unsupported recovery protection record.' ); }
	$directory           = BackupPlus_PITR_Process::directory( dirname( $path ) . '/release-' . bin2hex( random_bytes( 12 ) ) );
	$options             = $record['connection'];
	$options['user']     = BackupPlus_CLI_Secret::read( 'Restore database username: ' );
	$options['password'] = BackupPlus_CLI_Secret::read( 'Restore database password: ' );
	$connection          = BackupPlus_PITR_Process::memory_connection( $options );
	unset( $options );
	$adapter = new BackupPlus_PITR_Database(
		array(
			'engine'        => $record['source']['engine'],
			'bin_directory' => $record['bin_directory'],
			'source_config' => $connection,
		),
		$directory
	);
	$source  = $adapter->source( $record['database'] );
	if ( ! hash_equals( $record['source']['identity'], $source['identity'] ) ) { throw new RuntimeException( 'The restore account points to a different server. Protection was not released.' ); }
	if ( ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $record['database'] ) ) { throw new RuntimeException( 'Invalid recovery database.' ); }
	foreach ( $record['events'] as $event ) {
		if ( ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $event[0] ) || ! preg_match( '/\A([A-Za-z0-9_.$-]+)@([A-Za-z0-9_.:%\/-]+)\z/D', $event[2], $m ) ) { throw new RuntimeException( 'Invalid protected event.' ); }
		if ( 'ENABLED' === $event[1] && $adapter->query( "SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA='" . $record['database'] . "' AND EVENT_NAME='" . $event[0] . "'" ) ) { $adapter->query( "ALTER DEFINER='" . $m[1] . "'@'" . $m[2] . "' EVENT `" . $record['database'] . '`.`' . $event[0] . '` ENABLE', true ); }
	}
	foreach ( $record['accounts'] as $account ) {
		if ( ! preg_match( "/\A'[A-Za-z0-9_.$-]+'@'[A-Za-z0-9_.:%\/-]+'\z/D", $account['account'] ) ) { throw new RuntimeException( 'Invalid protected account record.' ); }
		if ( ! $account['locked'] ) { $adapter->query( 'ALTER USER ' . $account['account'] . ' ACCOUNT UNLOCK', true ); }
	}
	if ( is_file( $record['maintenance'] ) ) { unlink( $record['maintenance'] ); }
	unlink( $path );
	echo "Application database access restored. PITR capture remains paused.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( $connection ) { BackupPlus_PITR_Process::forget_connection( $connection ); }
	if ( $directory ) { foreach ( new DirectoryIterator( $directory ) as $file ) { if ( $file->isFile() ) { unlink( $file->getPathname() );
	}
	} rmdir( $directory ); }
}
exit( $exit_code );
