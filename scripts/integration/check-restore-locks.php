<?php
/**
 * A restored options table keeps the locks held when the restore ran, not those in the backup: the backup's own semaphore
 * was held while its database was dumped, and restoring it used to stop new backups for minutes after a restore.
 */
require __DIR__ . '/lib/support.php';

$runtime   = getenv( 'PODMAN' ) ? getenv( 'PODMAN' ) : 'podman';
$container = getenv( 'WP_TEST_CONTAINER' ) ? getenv( 'WP_TEST_CONTAINER' ) : 'backupplus-dev-wordpress';
$tag       = bin2hex( random_bytes( 3 ) );
$backups   = array();

function locks_wp( $arguments, $must_succeed = true ) {
	global $runtime, $container;
	$result = integration_run( array_merge( array( $runtime, 'exec', $container, 'wp', '--allow-root' ), $arguments ), '', null, 600 );
	if ( $must_succeed ) {
		integration_expect( 0 === $result['status'], 'wp ' . implode( ' ', $arguments ) . " failed:\n" . $result['stderr'] . substr( $result['stdout'], -2000 ) );
	}
	return $result;
}

try {
	// The files and database semaphore (fd) is held while this backup dumps the options table
	$backups[] = (int) trim( locks_wp( array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=locks-' . $tag, '--porcelain' ) )['stdout'] );
	integration_expect( 0 !== locks_wp( array( 'option', 'get', 'backupplus_locked_fd' ), false )['status'], 'A finished backup releases its semaphore' );

	locks_wp( array( 'backupplus', 'restore', (string) $backups[0], '--type=database', '--yes' ) );
	integration_expect( 0 !== locks_wp( array( 'option', 'get', 'backupplus_locked_fd' ), false )['status'], "The restore keeps the released semaphore instead of the backup's held one" );
	integration_expect( 0 !== locks_wp( array( 'option', 'get', 'backupplus_cli_restore.lock' ), false )['status'], 'The restore releases its own lock' );

	$backups[] = (int) trim( locks_wp( array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=locks-after-' . $tag, '--porcelain' ) )['stdout'] );
	integration_expect( $backups[1] > 0, 'A backup can start straight after a restore' );
	echo "Restore lock checks passed.\n";
} finally {
	foreach ( $backups as $timestamp ) {
		if ( $timestamp ) {
			locks_wp( array( 'backupplus', 'delete', (string) $timestamp, '--yes' ), false );
		}
	}
}
