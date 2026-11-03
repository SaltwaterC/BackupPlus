<?php

/**
 * Chains planted in storage cannot replace the source database (BPS-03). Each one copies a real full snapshot and adds
 * one statement that changes accounts, grants or global server state. A rescan imports the chains, and source restore
 * refuses them as not captured by this site. Treated as captured, they are refused by the SQL check before anything
 * changes. In both cases the database, its accounts and the server settings stay as they were.
 */

( function () use ( $config, $directory, $id, $reader, $observer, $admin ) {
	global $backupplus;
	$archives  = $directory . '/archives';
	$state     = $archives . '/pitr/state.php';
	$statement = array(
		'create user'    => "CREATE USER 'pitr_planted'@'%' IDENTIFIED BY 'fixture-planted';",
		'alter user'     => "ALTER USER 'pitr_application'@'%' IDENTIFIED BY 'fixture-planted';",
		'drop user'      => "DROP USER 'pitr_observer'@'%';",
		'grant'          => "GRANT ALL PRIVILEGES ON *.* TO 'pitr_application'@'%' WITH GRANT OPTION;",
		'revoke'         => "REVOKE SELECT ON `" . DB_NAME . "`.* FROM 'pitr_observer'@'%';",
		'set global'     => 'SET GLOBAL max_connections=4321;',
		'set password'   => "SET PASSWORD FOR 'pitr_application'@'%' = 'fixture-planted';",
		'install plugin' => "INSTALL PLUGIN planted SONAME 'planted.so';",
		'other database' => "INSERT INTO mysql.db VALUES ('%','planted','pitr_application','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y','Y');",
		'use mysql'      => "USE mysql;\nCREATE TABLE planted (id int) ENGINE=InnoDB;",
	);
	$snapshot  = function () use ( $observer, $admin ) {
		return array(
			$observer->query( 'SELECT id, HEX(value) FROM `' . DB_NAME . '`.pitr_a ORDER BY id' ),
			$observer->query( 'SELECT id, value FROM `' . DB_NAME . '`.pitr_b ORDER BY id' ),
			$observer->query( 'SHOW TABLES FROM `' . DB_NAME . '`' ),
			$admin->query( 'SELECT User, Host FROM mysql.user ORDER BY User, Host' ),
			$admin->query( "SELECT Db, User FROM mysql.db WHERE Db='planted'" ),
			$admin->query( "SHOW GRANTS FOR 'pitr_application'@'%'" ),
			$admin->query( 'SELECT @@global.max_connections' ),
			$admin->query( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME='planted'" ),
			file_exists( ABSPATH . '.maintenance' ),
		);
	};
	$refused   = function ( $callback, $expected ) use ( $backupplus ) {
		$nonce = $backupplus->nonce;
		try {
			$callback();
		} catch ( RuntimeException $error ) {
			return false !== strpos( $error->getMessage(), $expected ) ? true : $error->getMessage();
		} finally {
			// A refused restore that got as far as starting its job leaves no job data behind.
			if ( $backupplus->nonce && $nonce !== $backupplus->nonce ) { delete_site_option( 'backupplus_jobdata_' . $backupplus->nonce ); }
		}
		return 'accepted';
	};
	$rewrite   = function ( $change ) use ( $state ) {
		$data = json_decode( substr( file_get_contents( $state ), strlen( '<?php exit; ?>' ) ), true );
		$data = $change( $data );
		unlink( $state );
		BackupPlus_PITR_Process::private_file( $state, '<?php exit; ?>' . json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	};

	$worker = new BackupPlus_PITR( $config );
	$entry  = $worker->storage_state()['chains'][ $id ]['entries'][0];
	unset( $worker );
	pitr_check( isset( $entry['archive'] ) && is_file( $archives . '/' . $entry['archive']['name'] ), 'The planted chains need the original full point archive.' );
	$stage    = BackupPlus_PITR_Process::directory( $directory . '/planted' );
	$artifact = $entry['manifest']['artifacts'][0];
	$packed   = BackupPlus_PITR_Archive::extract( $archives . '/' . $entry['archive']['name'], $artifact, $stage . '/' . $artifact['name'] );
	$baseline = $stage . '/baseline.sql';
	if ( isset( $artifact['compression'] ) && 'zstd' === $artifact['compression'] ) {
		BackupPlus_PITR_Compression::decompress_zstd( $packed, $baseline, $stage, $artifact['raw_size'] );
	} else {
		BackupPlus_PITR_Process::private_file( $baseline, gzdecode( file_get_contents( $packed ) ) );
	}

	// Each point is built as an attacker with write access to storage would: its manifest names this database.
	$planted = array();
	foreach ( $statement as $label => $sql ) {
		$chain  = bin2hex( random_bytes( 16 ) );
		$backup = bin2hex( random_bytes( 16 ) );
		$prefix = 'backup_pitr_' . $chain . '_' . $backup . '_';
		$work   = BackupPlus_PITR_Process::directory( $stage . '/' . $chain );
		$input  = $work . '/input.sql';
		BackupPlus_PITR_Process::private_file( $input, file_get_contents( $baseline ) . "\n" . $sql . "\n" );
		$storage               = new BackupPlus_PITR_Storage( $work, array( 'mode' => 'none' ), array() );
		$data                  = $storage->artifact( $input, $prefix . 'full.sql.gz', true, true );
		$manifest              = $entry['manifest'];
		$manifest['chain_id']  = $chain;
		$manifest['backup_id'] = $backup;
		$manifest['artifacts'] = array( $data );
		BackupPlus_PITR_Process::private_file( $work . '/manifest.json', json_encode( BackupPlus_PITR_Manifest::validate( $manifest ), JSON_UNESCAPED_SLASHES ) );
		$described = $storage->artifact( $work . '/manifest.json', $prefix . 'manifest.json', false, true );
		$locator   = $work . '/' . $prefix . 'locator.json';
		BackupPlus_PITR_Process::private_file(
			$locator,
			json_encode(
				array(
					'manifest_version' => 1,
					'chain_id'         => $chain,
					'backup_id'        => $backup,
					'manifest'         => array_intersect_key( $described, array_flip( array( 'name', 'sha256', 'size' ) ) ),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$files = array();
		foreach ( array( $data, $described ) as $member ) { $files[] = $member + array( 'path' => $work . '/' . $member['name'] ); }
		$files[] = array(
			'name'   => basename( $locator ),
			'path'   => $locator,
			'size'   => filesize( $locator ),
			'sha256' => hash_file( 'sha256', $locator ),
		);
		BackupPlus_PITR_Archive::create( $archives . '/' . $prefix . 'point.tar', $files );
		$planted[ $chain ] = array( $label, $archives . '/' . $prefix . 'point.tar' );
	}

	$before = $snapshot();
	$worker = new BackupPlus_PITR( $config );
	$worker->rescan( $reader );
	$count = count( $worker->storage_state()['chains'] );
	foreach ( $planted as $chain => $point ) {
		pitr_check( ! empty( $worker->storage_state()['chains'][ $chain ]['imported'] ), 'Rescan did not import the planted chain (' . $point[0] . ').' );
		foreach (
			array(
				'direct'       => function () use ( $worker, $chain, $reader ) {
					$worker->restore( $chain, null, '', array(), $reader, false, true );
				},
				'staged'       => function () use ( $worker, $chain, $reader, $directory ) {
					$worker->restore( $chain, null, $directory . '/recovery.cnf', array(), $reader, true );
				},
				'verification' => function () use ( $worker, $chain, $reader ) {
					$worker->verify_source_restore( $chain, null, $reader );
				},
			) as $mode => $attempt
		) {
			$result = $refused( $attempt, 'found by a storage rescan' );
			pitr_check( true === $result, 'Source restore (' . $mode . ') did not refuse the planted chain (' . $point[0] . '): ' . $result );
		}
	}
	// The last closure holds the worker, and with it the PITR lock.
	unset( $attempt );
	pitr_check( $before === $snapshot() && count( $worker->storage_state()['chains'] ) === $count, 'Refusing a planted chain changed the database, its accounts or the chain index.' );
	unset( $worker );

	// The same chains as if this site had captured them: the SQL check refuses each before replacement starts.
	$rewrite(
		function ( $data ) use ( $planted ) {
			foreach ( array_keys( $planted ) as $chain ) { $data['chains'][ $chain ]['imported'] = false; }
			return $data;
		}
	);
	$worker = new BackupPlus_PITR( $config );
	foreach ( $planted as $chain => $point ) {
		$result = $refused(
			function () use ( $worker, $chain, $reader ) {
				$worker->restore( $chain, null, '', array(), $reader, false, true );
			},
			'which a database dump of this site does not need'
		);
		pitr_check( true === $result, 'Direct source restore did not refuse the planted statement (' . $point[0] . '): ' . $result );
		pitr_check( $before === $snapshot() && empty( $worker->storage_state()['restore_chain'] ), 'A refused planted statement (' . $point[0] . ') changed the database, its accounts or the restore state.' );
	}
	pitr_check( count( $worker->storage_state()['chains'] ) === $count, 'A refused restore captured a pre-restore snapshot.' );
	unset( $worker );

	// Leave the index and history as the later checks expect them.
	$rewrite(
		function ( $data ) use ( $planted ) {
			foreach ( array_keys( $planted ) as $chain ) { unset( $data['chains'][ $chain ] ); }
			return $data;
		}
	);
	$history = BackupPlus_Backup_History::get_history();
	foreach ( $history as $time => $row ) {
		if ( isset( $row['pitr']['chain_id'], $planted[ $row['pitr']['chain_id'] ] ) ) { unset( $history[ $time ] ); }
	}
	BackupPlus_Backup_History::save_history( $history );
	foreach ( $planted as $point ) { unlink( $point[1] ); }
	echo "PITR planted chains were refused for source restore, both as imported and by the SQL check.\n";
} )();
