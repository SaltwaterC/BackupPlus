<?php
/**
 * Disposable source/recovery pairs. The existing WordPress database is not a test source.
 * Starts database containers next to the development container and runs check-pitr.php there.
 *
 * Environment: PODMAN, WP_TEST_CONTAINER, MYSQL_IMAGE, MYSQL_CLIENT_DIRECTORY, HOST_CA_BUNDLE,
 * PITR_CASE (index of one matrix entry), PITR_EXTERNAL_ONLY=1, PITR_ISOLATION_ONLY=1 and PITR_BROWSER_FULL_ONLY=1.
 */
require __DIR__ . '/lib/support.php';

$runtime    = getenv( 'PODMAN' ) ? getenv( 'PODMAN' ) : 'podman';
$worker     = getenv( 'WP_TEST_CONTAINER' ) ? getenv( 'WP_TEST_CONTAINER' ) : 'backupplus-dev-wordpress';
$root       = dirname( __DIR__, 2 );
$token      = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
$containers = array();

/** Run the container runtime; output is shown unless captured. */
function pitr_command( $arguments, $capture = false, $check = true ) {
	global $runtime;
	$command = array_merge( array( $runtime ), array_map( 'strval', $arguments ) );
	if ( $capture ) {
		$result = integration_run( $command, '', null, 600 );
		$status = $result['status'];
	} else {
		$result = array(
			'stdout' => '',
			'stderr' => '',
		);
		$status = integration_stream( $command );
	}
	integration_expect( ! $check || 0 === $status, 'Command failed (' . $status . '): ' . implode( ' ', $command ) . ( $capture ? "\n" . $result['stderr'] : '' ) );
	$result['status'] = $status;
	return $result;
}

function pitr_port() {
	global $worker;
	$code = '$s=stream_socket_server("tcp://127.0.0.1:0");echo substr(strrchr(stream_socket_get_name($s,false),":"),1);';
	return (int) pitr_command( array( 'exec', $worker, 'php', '-r', $code ), true )['stdout'];
}

function pitr_mysql_clients( $directory ) {
	global $root, $worker, $token, $containers;
	$supplied = getenv( 'MYSQL_CLIENT_DIRECTORY' );
	if ( $supplied ) {
		return $supplied;
	}
	$image   = getenv( 'MYSQL_IMAGE' ) ? getenv( 'MYSQL_IMAGE' ) : 'docker.io/library/mysql:8.4';
	$context = $directory . '/build';
	mkdir( $context );
	copy( $root . '/scripts/fixtures/pitr-mysql-tools.Containerfile', $context . '/Containerfile' );
	copy( getenv( 'HOST_CA_BUNDLE' ) ? getenv( 'HOST_CA_BUNDLE' ) : '/etc/ssl/certs/ca-certificates.crt', $context . '/ca-bundle.pem' );
	file_put_contents(
		$context . '/mysql-community.repo',
		"[mysql-community]\nname=MySQL Community\n" .
		'baseurl=https://repo.mysql.com/yum/mysql-8.4-community/el/9/$basearch/' . "\n" .
		"enabled=1\ngpgcheck=1\ngpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-mysql\n"
	);
	$tools_image = 'localhost/backupplus-pitr-mysql-clients:local';
	pitr_command( array( 'build', '--build-arg', 'MYSQL_IMAGE=' . $image, '-t', $tools_image, $context ) );
	$name = 'backupplus-pitr-tools-' . $token;
	pitr_command( array( 'create', '--name', $name, '--entrypoint', '/bin/true', $tools_image ) );
	$containers[] = $name;
	$target       = '/tmp/backupplus-pitr-clients-' . $token;
	pitr_command( array( 'exec', $worker, 'mkdir', '-m', '0700', $target ) );
	foreach ( array( 'mysql', 'mysqldump', 'mysqlbinlog' ) as $binary ) {
		$local = $directory . '/' . $binary;
		pitr_command( array( 'cp', $name . ':/usr/bin/' . $binary, $local ) );
		pitr_command( array( 'cp', $local, $worker . ':' . $target . '/' . $binary ) );
		pitr_command( array( 'exec', $worker, $target . '/' . $binary, '--no-defaults', '--version' ) );
	}
	return $target;
}

function pitr_start( $image, $engine, $role, $number ) {
	global $worker, $token, $containers;
	$name   = 'backupplus-pitr-' . $token . '-' . $role;
	$source = 'source' === $role;
	$prefix = 'mysql' === $engine ? 'MYSQL' : 'MARIADB';
	$allow  = 'mysql' === $engine ? 'MYSQL_ALLOW_EMPTY_PASSWORD=yes' : 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1';
	// The data of a disposable server lives in memory: the checks need the behavior of the server, not its durability.
	$run = array( 'run', '--detach', '--name', $name, '--network', 'container:' . $worker, '--tmpfs', '/var/lib/mysql', '--env', $allow );
	if ( $source ) {
		array_push( $run, '--env', $prefix . '_DATABASE=backupplus' );
	}
	array_push( $run, $image, '--port=' . $number, '--bind-address=127.0.0.1', '--server-id=' . $number, '--event-scheduler=OFF', '--innodb-flush-log-at-trx-commit=0', '--innodb-doublewrite=OFF' );
	if ( $source ) {
		array_push( $run, '--log-bin=binlog', '--sync-binlog=0', '--binlog-format=ROW', '--binlog-checksum=CRC32', '--binlog-expire-logs-seconds=172800' );
	} else {
		$run[] = '--skip-log-bin';
	}
	pitr_command( $run );
	$containers[] = $name;
	return $name;
}

function pitr_ready( $binary, $number, $container ) {
	global $worker;
	$deadline = microtime( true ) + 120;
	while ( microtime( true ) < $deadline ) {
		$result = pitr_command( array( 'exec', $worker, $binary, '--no-defaults', '--host=127.0.0.1', '--port=' . $number, '--user=root', '--execute=SELECT 1' ), true, false );
		if ( 0 === $result['status'] ) {
			return;
		}
		usleep( 250000 );
	}
	pitr_command( array( 'logs', '--tail', '50', $container ) );
	throw new RuntimeException( 'Disposable database did not become ready' );
}

pitr_command( array( 'exec', $worker, 'wp', '--skip-plugins=backupplus', 'core', 'is-installed' ) );
$client_directory = null;
$temporary        = integration_temp_dir( 'backupplus-pitr-native-' );
try {
	$mysql_image = getenv( 'MYSQL_IMAGE' ) ? getenv( 'MYSQL_IMAGE' ) : 'docker.io/library/mysql:8.4';
	$matrix      = array(
		array( 'mysql', $mysql_image, false ),
		array( 'mysql', $mysql_image, true ),
		array( 'mariadb', 'docker.io/library/mariadb:10.11', false ),
		array( 'mariadb', 'docker.io/library/mariadb:11.4', false ),
	);
	if ( false !== getenv( 'PITR_CASE' ) ) {
		$matrix = array( $matrix[ (int) getenv( 'PITR_CASE' ) ] );
	}
	// MariaDB uses the clients that come with the site container.
	if ( in_array( 'mysql', array_column( $matrix, 0 ), true ) ) {
		$client_directory = pitr_mysql_clients( $temporary );
	}
	foreach ( $matrix as list( $engine, $image, $gtid ) ) {
		echo 'Testing ', $image, ' GTID=', $gtid ? 'True' : 'False', "\n";
		$source_port   = pitr_port();
		$recovery_port = pitr_port();
		while ( $recovery_port === $source_port ) {
			$recovery_port = pitr_port();
		}
		$source           = pitr_start( $image, $engine, 'source', $source_port );
		$recovery         = pitr_start( $image, $engine, 'recovery', $recovery_port );
		$binary_directory = 'mysql' === $engine ? $client_directory : '/usr/bin';
		$binary           = $binary_directory . ( 'mysql' === $engine ? '/mysql' : '/mariadb' );
		pitr_ready( $binary, $source_port, $source );
		pitr_ready( $binary, $recovery_port, $recovery );
		if ( $gtid ) {
			pitr_command( array( 'exec', $worker, $binary, '--no-defaults', '--host=127.0.0.1', '--port=' . $source_port, '--user=root', '--execute=SET GLOBAL enforce_gtid_consistency=ON; SET GLOBAL gtid_mode=OFF_PERMISSIVE; SET GLOBAL gtid_mode=ON_PERMISSIVE; SET GLOBAL gtid_mode=ON' ) );
		}
		$checks = '1' === getenv( 'PITR_BROWSER_FULL_ONLY' ) ? array( 'browser-full-only' ) : ( '1' === getenv( 'PITR_EXTERNAL_ONLY' ) ? array( 'external-only' ) : ( '1' === getenv( 'PITR_ISOLATION_ONLY' ) ? array( 'isolation-only' ) : array() ) );
		pitr_command( array_merge( array( 'exec', $worker, 'wp', '--skip-plugins=backupplus', 'eval-file', 'wp-content/plugins/backupplus/scripts/check-pitr.php', $engine, $source_port, $recovery_port, $binary_directory ), $checks ) );
		foreach ( array( $source, $recovery ) as $name ) {
			pitr_command( array( 'rm', '--force', '--volumes', $name ) );
			unset( $containers[ array_search( $name, $containers, true ) ] );
		}
	}
} finally {
	foreach ( array_reverse( $containers ) as $name ) {
		pitr_command( array( 'rm', '--force', '--volumes', $name ), false, false );
	}
	if ( $client_directory && ! getenv( 'MYSQL_CLIENT_DIRECTORY' ) ) {
		pitr_command( array( 'exec', $worker, 'rm', '-rf', $client_directory ), false, false );
	}
	integration_remove_tree( $temporary );
}
if ( false !== getenv( 'PITR_CASE' ) ) {
	echo "Selected PITR native case passed.\n";
} elseif ( '1' === getenv( 'PITR_BROWSER_FULL_ONLY' ) ) {
	echo "PITR encrypted full-snapshot browser job matrix passed.\n";
} elseif ( '1' === getenv( 'PITR_EXTERNAL_ONLY' ) ) {
	echo "PITR account/shared-server capture matrix passed.\n";
} elseif ( '1' === getenv( 'PITR_ISOLATION_ONLY' ) ) {
	echo "PITR automatic isolation matrix passed.\n";
} else {
	echo "PITR MySQL GTID off/on and MariaDB version matrix passed.\n";
}
