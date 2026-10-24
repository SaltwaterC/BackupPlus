<?php

/** Binary metadata/event validation and native process failure checks without WordPress. */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-pitr-process.php';
require dirname( __DIR__ ) . '/includes/class-pitr-manifest.php';
require dirname( __DIR__ ) . '/includes/class-pitr-binlog.php';

function pitr_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function pitr_reject( $callback, $message ) {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return;
	} throw new RuntimeException( $message );
}
function pitr_event( $type, $body, &$position, $timestamp = 100 ) {
	$size      = 23 + strlen( $body );
	$event     = pack( 'VCVVVv', $timestamp, $type, 7, $size, $position + $size, 0 ) . $body;
	$position += $size;
	return $event . strrev( hash( 'crc32b', $event, true ) );
}
function pitr_query( $sql, $database = 'wordpress' ) {
	return pack( 'VVCvv', 1, 0, strlen( $database ), 0, 0 ) . $database . "\x00" . $sql;
}

$directory = sys_get_temp_dir() . '/backupplus-pitr-unit-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
try {
	foreach (
		array(
			array(
				'file'     => '../binlog.000001',
				'position' => 4,
			),
			array(
				'file'     => 'binlog.000001',
				'position' => '4',
			),
			array(
				'file'     => 'binlog.000001',
				'position' => 3,
			),
			array( 'file' => 'binlog.000001' ),
		) as $bad
	) {
		pitr_reject(
			function () use ( $bad ) {
				BackupPlus_PITR_Manifest::coordinate( $bad );
			},
			'Unsafe coordinate accepted.'
		);
	}
	$position  = 4;
	$format    = pitr_event( 15, pack( 'v', 4 ) . str_pad( '11.4.0-MariaDB', 50, "\x00" ) . pack( 'VC', 100, 19 ) . str_repeat( "\x00", 40 ) . "\x01", $position );
	$start     = $position;
	$data      = "\xfebin" . $format;
	$data     .= pitr_event( 162, str_repeat( "\x00", 13 ), $position );
	$data     .= pitr_event( 19, str_repeat( "\x00", 8 ) . chr( 9 ) . "wordpress\x00", $position );
	$data     .= pitr_event( 30, str_repeat( "\x00", 12 ), $position );
	$data     .= pitr_event( 16, str_repeat( "\x00", 8 ), $position );
	$committed = $position;
	$data     .= pitr_event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $position );
	$path      = $directory . '/binlog.000001';
	file_put_contents( $path, $data );
	$inspection = BackupPlus_PITR_Binlog::inspect( $path, $start, 'wordpress', 'mariadb' );
	pitr_expect(
		array(
			array(
				'position'  => $committed,
				'timestamp' => 100,
			),
		) === $inspection['boundaries'],
		'Commit boundary differs from XID end position.'
	);
	pitr_expect(
		array(
			'file'     => 'binlog.000002',
			'position' => 4,
		) === $inspection['next'],
		'Rotation coordinates were lost.'
	);
	pitr_reject(
		function () use ( $path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $path, $start + 1, 'wordpress', 'mariadb' );
		},
		'Mid-event replay accepted.'
	);
	pitr_reject(
		function () use ( $path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $path, $start, 'other', 'mariadb' );
		},
		'Cross-schema row event accepted.'
	);
	$shared_position = 4 + strlen( $format );
	$shared          = "\xfebin" . $format;
	$shared         .= pitr_event( 162, str_repeat( "\x00", 12 ) . "\x01", $shared_position );
	$shared         .= pitr_event( 2, pitr_query( "CREATE USER 'outside'@'%' IDENTIFIED BY 'opaque'" ), $shared_position );
	$shared         .= pitr_event( 162, str_repeat( "\x00", 12 ) . "\x01", $shared_position );
	$shared         .= pitr_event( 2, pitr_query( "CREATE PROCEDURE unrelated() SELECT 'wordpress.example'", 'neighbor' ), $shared_position );
	$shared         .= pitr_event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $shared_position );
	$shared_path     = $directory . '/shared.bin';
	file_put_contents( $shared_path, $shared );
	$shared_result = BackupPlus_PITR_Binlog::inspect( $shared_path, $start, 'wordpress', 'mariadb', false, null, true );
	pitr_expect( 2 === count( $shared_result['boundaries'] ), 'Shared capture lost control or neighboring transaction boundaries.' );
	pitr_reject(
		function () use ( $shared_path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $shared_path, $start, 'wordpress', 'mariadb' );
		},
		'The native backend accepted an shared-server scope.'
	);
	$copy_path = $directory . '/replay-copy.bin';
	BackupPlus_PITR_Binlog::scoped_replay_copy( $shared_path, $copy_path, 'wordpress' );
	$copy = file_get_contents( $copy_path );
	pitr_expect( strlen( $copy ) === strlen( $shared ) && false === strpos( $copy, 'CREATE USER' ) && false !== strpos( $copy, 'DO 0' ), 'Account administration was replayed or source coordinates changed.' );
	for ( $event_offset = 4; $event_offset < strlen( $copy ); ) {
		$event_size  = unpack( 'V', substr( $copy, $event_offset + 9, 4 ) )[1];
		$event_bytes = substr( $copy, $event_offset, $event_size - 4 );
		pitr_expect( strrev( hash( 'crc32b', $event_bytes, true ) ) === substr( $copy, $event_offset + $event_size - 4, 4 ), 'Isolated account-event rewriting broke a native checksum.' );
		$event_offset += $event_size;
	}

	$event_position = 4 + strlen( $format );
	$event_log      = "\xfebin" . $format;
	$event_log     .= pitr_event( 162, str_repeat( "\x00", 12 ) . "\x01", $event_position );
	$event_log     .= pitr_event( 2, pitr_query( "ALTER\nEVENT\npitr_event ENABLE" ), $event_position );
	$event_path     = $directory . '/event-change.bin';
	file_put_contents( $event_path, $event_log );
	pitr_reject(
		function () use ( $event_path, $directory ) {
			BackupPlus_PITR_Binlog::scoped_replay_copy( $event_path, $directory . '/event-copy.bin', 'wordpress', true );
		},
		'A multiline event activation was accepted for direct source replay.'
	);
	$mixed_position = 4 + strlen( $format );
	$mixed          = "\xfebin" . $format . pitr_event( 162, str_repeat( "\x00", 13 ), $mixed_position );
	foreach ( array( 'wordpress', 'neighbor' ) as $scope ) {
		$mixed .= pitr_event( 19, str_repeat( "\x00", 8 ) . chr( strlen( $scope ) ) . $scope . "\x00", $mixed_position );
		$mixed .= pitr_event( 30, str_repeat( "\x00", 12 ), $mixed_position );
	}
	$mixed     .= pitr_event( 16, str_repeat( "\x00", 8 ), $mixed_position );
	$mixed     .= pitr_event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $mixed_position );
	$mixed_path = $directory . '/mixed.bin';
	file_put_contents( $mixed_path, $mixed );
	pitr_reject(
		function () use ( $mixed_path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $mixed_path, $start, 'wordpress', 'mariadb', false, null, true );
		},
		'A cross-schema transaction was silently split into a tenant-only history.'
	);
	file_put_contents( $path, substr( $data, 0, -1 ) );
	pitr_reject(
		function () use ( $path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $path, $start, 'wordpress', 'mariadb' );
		},
		'Truncation accepted.'
	);
	$corrupt      = $data;
	$corrupt[120] = chr( ord( $corrupt[120] ) ^ 1 );
	file_put_contents( $path, $corrupt );
	pitr_reject(
		function () use ( $path, $start ) {
			BackupPlus_PITR_Binlog::inspect( $path, $start, 'wordpress', 'mariadb' );
		},
		'Corrupt checksum accepted.'
	);
	file_put_contents( $path, $data );
	$hash                  = BackupPlus_PITR_Binlog::prefix_hash( $path, $start );
	$changed               = $data;
	$changed[21]           = "\x01";
	$changed[ $start - 1 ] = "\xff";
	file_put_contents( $path, $changed );
	pitr_expect( $hash === BackupPlus_PITR_Binlog::prefix_hash( $path, $start ), 'Mutable FDE flags/checksum changed the source anchor.' );
	foreach ( array( 'CREATE TABLE `wordpress`.`new` (id int) ENGINE=InnoDB', 'ALTER TABLE `new` ADD `note` varchar(30) DEFAULT \'other.table\'', 'DROP TABLE `new`' ) as $sql ) {
		pitr_expect( 'DDL' === BackupPlus_PITR_Binlog::query( pitr_query( $sql ), 'wordpress' ), 'Safe DDL rejected.' );
	}
	foreach ( array( 'CREATE TABLE `other`.`new` (id int)', 'CREATE TABLE new AS SELECT * FROM old', 'INSERT INTO new VALUES (1)', 'CREATE TABLE new (id int) ENGINE=MyISAM', 'CREATE TABLE new (id int) DATA DIRECTORY=\'/tmp\'', '/*!50000 DROP DATABASE wordpress */' ) as $sql ) {
		pitr_reject(
			function () use ( $sql ) {
				BackupPlus_PITR_Binlog::query( pitr_query( $sql ), 'wordpress' );
			},
			'Unsafe or unsupported query accepted: ' . $sql
		);
	}

	$full_id          = str_repeat( 'a', 32 );
	$chain_id         = str_repeat( 'b', 32 );
	$increment_id     = str_repeat( 'c', 32 );
	$full_file        = 'backup_pitr_' . $chain_id . '_' . $full_id . '_full.sql.gz';
	$log_file         = 'backup_pitr_' . $chain_id . '_' . $increment_id . '_log0.bin.gz';
	$source           = array(
		'engine'         => 'mariadb',
		'server_version' => '11.4.0-MariaDB',
		'identity'       => str_repeat( 'd', 64 ),
		'log_epoch'      => str_repeat( 'e', 32 ),
	);
	$coordinate       = array(
		'file'     => 'binlog.000001',
		'position' => $start,
	);
	$anchor           = array(
		'coordinate' => $coordinate,
		'sha256'     => str_repeat( 'f', 64 ),
	);
	$artifact         = array(
		'name'         => $full_file,
		'sha256'       => str_repeat( '1', 64 ),
		'plain_sha256' => str_repeat( '2', 64 ),
		'size'         => 100,
	);
	$full             = array(
		'manifest_version'    => 1,
		'kind'                => 'database-full',
		'backup_id'           => $full_id,
		'chain_id'            => $chain_id,
		'source'              => $source,
		'database'            => 'wordpress',
		'created_at'          => 1,
		'snapshot_coordinate' => $coordinate,
		'anchor'              => $anchor,
		'artifacts'           => array( $artifact ),
	);
	$artifact['name'] = $log_file;
	$increment        = array(
		'manifest_version'         => 1,
		'kind'                     => 'database-binlog',
		'backup_id'                => $increment_id,
		'chain_id'                 => $chain_id,
		'source'                   => $source,
		'database'                 => 'wordpress',
		'created_at'               => 2,
		'baseline_id'              => $full_id,
		'previous_backup_id'       => $full_id,
		'previous_manifest_sha256' => str_repeat( '3', 64 ),
		'start_coordinate'         => $coordinate,
		'end_coordinate'           => $inspection['next'],
		'anchor'                   => $anchor,
		'artifacts'                => array( $artifact ),
		'segments'                 => array(
			array_merge(
				array(
					'start'    => $coordinate,
					'artifact' => $log_file,
				),
				$inspection
			),
		),
	);
	$entries          = array(
		array(
			'manifest' => $full,
			'sha256'   => str_repeat( '3', 64 ),
		),
		array(
			'manifest' => $increment,
			'sha256'   => str_repeat( '4', 64 ),
		),
	);
	pitr_expect( $inspection['next'] === BackupPlus_PITR_Manifest::chain( $entries ), 'Valid portable chain failed continuity.' );
	foreach ( array( 'coordinate', 'source', 'hash', 'baseline' ) as $case ) {
		$bad = $entries;
		if ( 'coordinate' === $case ) {
			++$bad[1]['manifest']['start_coordinate']['position'];
		}
		if ( 'source' === $case ) {
			$bad[1]['manifest']['source']['log_epoch'] = str_repeat( '9', 32 );
		}
		if ( 'hash' === $case ) {
			$bad[1]['manifest']['previous_manifest_sha256'] = str_repeat( '9', 64 );
		}
		if ( 'baseline' === $case ) {
			$bad[1]['manifest']['baseline_id'] = str_repeat( '9', 32 );
		}
		pitr_reject(
			function () use ( $bad ) {
				BackupPlus_PITR_Manifest::chain( $bad );
			},
			'Chain with mismatched ' . $case . ' accepted.'
		);
	}
	$sql = $directory . '/full.sql';
	file_put_contents( $sql, "-- CHANGE MASTER TO MASTER_LOG_FILE='binlog.000001', MASTER_LOG_POS=789;\n" );
	pitr_expect( 789 === BackupPlus_PITR_Manifest::snapshot_coordinate( $sql )['position'], 'MariaDB dump coordinate not parsed.' );
	file_put_contents( $sql, "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000001', SOURCE_LOG_POS=987;\n" );
	pitr_expect( 987 === BackupPlus_PITR_Manifest::snapshot_coordinate( $sql )['position'], 'MySQL dump coordinate not parsed.' );
	file_put_contents( $sql, "CHANGE MASTER TO MASTER_LOG_FILE='binlog.000001', MASTER_LOG_POS=789;\n" );
	pitr_reject(
		function () use ( $sql ) {
			BackupPlus_PITR_Manifest::snapshot_coordinate( $sql );
		},
		'Executable replication setup accepted as a baseline.'
	);


	$pipe_password = bin2hex( random_bytes( 16 ) ) . '\\"special';
	$memory        = BackupPlus_PITR_Process::memory_connection(
		array(
			'user'     => 'restore_test',
			'password' => $pipe_password,
		)
	);
	pitr_expect( $pipe_password === BackupPlus_PITR_Process::connection_options( $memory )['password'], 'Memory credentials changed password escaping.' );
	$before_pipe = glob( $directory . '/*' );
	$pipe_hash   = BackupPlus_PITR_Process::run( array( '/bin/sh', '-c', 'sha256sum <&3', '--defaults-file=' . $memory ), $directory );
	pitr_expect( preg_match( '/\A[a-f0-9]{64}\s/', $pipe_hash ) && $before_pipe === glob( $directory . '/*' ), 'Memory credential transport created persistent files.' );
	BackupPlus_PITR_Process::forget_connection( $memory );
	pitr_reject(
		function () use ( $memory, $directory ) {
			BackupPlus_PITR_Process::run( array( '/bin/sh', '-c', 'sha256sum <&3', '--defaults-file=' . $memory ), $directory );
		},
		'Forgotten restore credentials remained reusable.'
	);
	$connection = $directory . '/source.cnf';
	file_put_contents( $connection, "[client]\nuser=root\nhost=127.0.0.1\n" );
	chmod( $connection, 0644 );
	pitr_reject(
		function () use ( $connection, $directory ) {
			BackupPlus_PITR_Process::connection_file( $connection, $directory );
		},
		'World-readable credentials accepted.'
	);
	chmod( $connection, 0600 );
	BackupPlus_PITR_Process::connection_file( $connection, $directory );
	file_put_contents( $connection, "[client]\nuser=root\nhost=remote.example\n" );
	pitr_reject(
		function () use ( $connection, $directory ) {
			BackupPlus_PITR_Process::connection_file( $connection, $directory );
		},
		'Unverified remote transport accepted.'
	);
	file_put_contents( $connection, "[client]\nuser=root\nhost=127.0.0.1\ninit-command=DROP DATABASE wordpress\n" );
	pitr_reject(
		function () use ( $connection, $directory ) {
			BackupPlus_PITR_Process::connection_file( $connection, $directory );
		},
		'Client behavior override accepted.'
	);
	pitr_expect( 'done' === BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'echo "done";' ), $directory ), 'Native output was lost.' );
	foreach (
		array(
			'ERROR 2026 (HY000): TLS/SSL error: self-signed certificate' => 'issuing CA is not trusted',
			'ERROR 2026 (HY000): SSL connection error: hostname does not match certificate' => 'does not match the database hostname',
			'ERROR 2026 (HY000): TLS/SSL error: certificate has expired' => 'expired or not yet valid',
			'ERROR 2026 (HY000): TLS/SSL error: unsupported protocol' => 'TLS connection failed',
			'ERROR 1045 (28000): Access denied'      => 'authentication failed',
			'ERROR 1227 (42000): Access denied; RELOAD required' => 'needs the RELOAD privilege',
			'ERROR 1419 (HY000): stored program creation denied' => 'restore account needs SUPER',
			'ERROR 1064 (42000): malformed query'    => 'Database error 1064',
			'ERROR 2002 (HY000): Connection refused' => 'Could not reach the database',
			'ERROR 2013 (HY000): Lost connection'    => 'connection was lost',
			'Flush logs or delete master logs failure in server' => 'Database log rotation failed',
		) as $diagnostic => $expected
	) {
		try {
			$child = 'fwrite(STDERR, ' . var_export( $diagnostic . "\npassword=private-secret; SELECT private_data FROM customer_table", true ) . '); exit(1);';
			BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', $child ), $directory );
			throw new RuntimeException( 'A failed native client was accepted.' );
		} catch ( RuntimeException $error ) {
			$message = $error->getMessage();
			pitr_expect( false !== strpos( $message, $expected ), 'Native diagnostics hid the actionable failure: ' . $expected );
			pitr_expect( false === strpos( $message, 'private-secret' ) && false === strpos( $message, 'customer_table' ), 'Native diagnostics leaked credentials or SQL.' );
		}
	}
	pitr_reject(
		function () use ( $directory ) {
			BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'exit(7);' ), $directory );
		},
		'Native nonzero exit accepted.'
	);
	pitr_reject(
		function () use ( $directory ) {
			BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'sleep(5);' ), $directory, null, null, 0.1 );
		},
		'Timed-out child accepted.'
	);
	echo "PITR coordinate, chain, event, transport and native-process checks passed.\n";
} finally {
	foreach ( glob( $directory . '/*' ) as $file ) {
		unlink( $file );
	} rmdir( $directory );
}
