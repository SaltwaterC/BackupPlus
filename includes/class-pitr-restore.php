<?php

/** Browser recovery uses prepared plaintext references; private keys stay in the browser. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_PITR_Restore {
	private static function selection( $timestamp, $point ) {
		if ( ! BackupPlus_Options::user_can_manage() ) { throw new RuntimeException( 'Not authorized.' ); }
		$backup = BackupPlus_Backup_History::get_history( $timestamp );
		if ( empty( $backup['pitr']['points'] ) || in_array( $backup['pitr']['state'], array( 'deleting', 'unavailable' ), true ) ) { throw new RuntimeException( 'The database recovery chain is unavailable. Rescan and verify its artifacts before restoring.' ); }
		BackupPlus_PITR_Manifest::id( $point );
		foreach ( $backup['pitr']['points'] as $candidate ) {
			if ( $candidate['backup_id'] === $point ) { return array( $backup['pitr']['chain_id'], $candidate['coordinate'], 0 === $candidate['increment'] ); }
		}
		throw new RuntimeException( 'The selected point is not part of this database recovery chain.' );
	}

	public static function manifest( $timestamp, $point, $ticket ) {
		list( $id, $target, $baseline ) = self::selection( $timestamp, $point );
		if ( $baseline ) {
			return BackupPlus_Age_Restore::manifest( BackupPlus_Backup_History::get_history( $timestamp ), $timestamp, array( 'db' ), $ticket );
		}
		$worker = new BackupPlus_PITR();
		return $worker->browser_manifest( $id, $target, $timestamp, $ticket );
	}

	public static function request( $params ) {
		$timestamp           = isset( $params['timestamp'] ) ? (int) $params['timestamp'] : 0;
		$point               = isset( $params['point'] ) ? $params['point'] : '';
		list( $id, $target ) = self::selection( $timestamp, $point );
		$ticket              = isset( $params['ticket'] ) ? $params['ticket'] : '';
		$reader              = array(
			'prepared' => function ( $path ) use ( $ticket, $timestamp ) {
						return BackupPlus_Age_Restore::file( $path, $ticket, '', $timestamp );
			},
		);
		require_once __DIR__ . '/class-pitr-setup.php';
		$config     = BackupPlus_PITR::config();
		$connection = BackupPlus_PITR_Setup::supplied_connection( $config, isset( $params['username'] ) ? $params['username'] : null, isset( $params['password'] ) ? $params['password'] : null );
		unset( $params['username'], $params['password'] );
		$config['restore_connection'] = $connection;
		try {
			$worker = new BackupPlus_PITR( $config );
			if ( isset( $params['operation'] ) && 'verify' === $params['operation'] ) {
				try {
					$plan = $worker->verify_source_restore( $id, $target, $reader );
					return array(
						'verified'   => true,
						'chain_id'   => $id,
						'point'      => $point,
						'coordinate' => $plan['target_coordinate'],
						'increments' => count(
							array_unique(
								array_map(
									function ( $selected ) {
										return $selected['entry']['manifest']['backup_id'];
									},
									$plan['segments']
								)
							)
						),
					);
				} finally { global $backupplus;
					delete_site_option( 'backupplus_jobdata_' . $backupplus->nonce ); }
			}
			if ( ! isset( $params['operation'] ) || 'restore' !== $params['operation'] ) { throw new RuntimeException( 'Choose a valid database recovery operation.' ); }
			$reader['authenticated'] = function () use ( $ticket ) {
				if ( $ticket ) { BackupPlus_Age_Restore::discard( $ticket );
				} };
			return $worker->restore( $id, $target, '', array(), $reader, false, true, true );
		} finally { BackupPlus_PITR_Process::forget_connection( $connection ); }
	}
}
