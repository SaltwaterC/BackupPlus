<?php

/** Saved capture credentials and request-only restore credentials. No account provisioning. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_PITR_Setup {
	const OPTION = 'backupplus_pitr_capture';

	public static function connection_file( $config, $directory ) {
		return isset( $config['restore_connection'] ) ? $config['restore_connection'] : '';
	}

	public static function capture_connection( $config ) {
		$options = BackupPlus_PITR_Process::connection_options( $config['source_config'] );
		$raw     = get_option( self::OPTION, null );
		if ( is_array( $raw ) && ! empty( $raw['credentials'] ) ) {
			$record = BackupPlus_Credential_Storage::reading( $raw, self::OPTION, true );
			if ( ! isset( $record['credentials']['user'], $record['credentials']['password'] ) ) { throw new RuntimeException( 'Reconnect the PITR backup account in Settings.' ); }
			$options['user']     = $record['credentials']['user'];
			$options['password'] = $record['credentials']['password'];
		}
		return BackupPlus_PITR_Process::memory_connection( $options );
	}

	public static function require_secure_admin() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
		if ( ! is_ssl() && ! in_array( wp_parse_url( admin_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) { throw new RuntimeException( 'Use HTTPS for WordPress administration before sending database credentials.' ); }
	}

	public static function supplied_connection( $config, $user, $password ) {
		if ( ! is_string( $user ) || '' === $user || strlen( $user ) > 128 || ! is_string( $password ) || strlen( $password ) > 4096 ) { throw new RuntimeException( 'Enter the database username and password.' ); }
		self::require_secure_admin();
		$options             = BackupPlus_PITR_Process::connection_options( $config['source_config'] );
		$options['user']     = $user;
		$options['password'] = $password;
		return BackupPlus_PITR_Process::memory_connection( $options );
	}

	public static function request( $params ) {
		if ( ! BackupPlus_Options::user_can_manage() ) { return array( 'error' => 'Not authorized.' ); }
		$operation = isset( $params['operation'] ) ? $params['operation'] : '';
		if ( ! in_array( $operation, array( 'status', 'connect', 'wordpress' ), true ) ) { return array( 'error' => 'Choose a valid backup-account action.' ); }
		$directory  = null;
		$connection = null;
		$lock       = null;
		$checked    = '';
		$unsaved    = false;
		$typed      = false;
		try {
			global $backupplus;
			$config = BackupPlus_PITR::config();
			$root   = BackupPlus_PITR_Admin::private_directory( $backupplus );
			$lock   = fopen( $root . '/lock', 'c+b' );
			if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { throw new RuntimeException( 'A PITR backup or restore is running. Retry when it finishes.' ); }
			$directory = BackupPlus_PITR_Process::directory( $root . '/work-setup-' . bin2hex( random_bytes( 12 ) ) );
			$config    = array_merge( $config, BackupPlus_PITR_Admin::wordpress_configuration( isset( $config['bin_directory'] ) ? $config['bin_directory'] : null, $directory ) );
			// A status check of typed credentials tests them without saving; otherwise it tests the account capture would use.
			$typed = 'status' === $operation && isset( $params['username'] ) && '' !== $params['username'];
			if ( 'connect' === $operation || $typed ) {
				$connection = self::supplied_connection( $config, isset( $params['username'] ) ? $params['username'] : null, isset( $params['password'] ) ? $params['password'] : null );
			} elseif ( 'wordpress' === $operation ) {
				$connection = BackupPlus_PITR_Process::memory_connection( BackupPlus_PITR_Process::connection_options( $config['source_config'] ) );
			} else {
				$saved      = get_option( self::OPTION, null );
				$unsaved    = ! is_array( $saved ) || empty( $saved['credentials'] );
				$connection = self::capture_connection( $config );
			}
			// Name the login that was tested: without typed or saved backup credentials, Check access tests the WordPress account.
			$user                      = BackupPlus_PITR_Process::connection_options( $connection )['user'];
			$checked                   = ( 'connect' === $operation || $typed || ( 'status' === $operation && ! $unsaved ) ? 'Backup account ' : 'WordPress database account ' ) . $user . ( $typed ? ' (not saved)' : '' );
			$config['source_config']   = $connection;
			$config['capture_account'] = false;
			$adapter                   = new BackupPlus_PITR_Database( $config, $directory );
			$adapter->source( DB_NAME );
			$guard = $adapter->guard();
			$guard->close();
			if ( 'connect' === $operation ) {
				$record = array(
					'credentials' => array(
						'user'     => $params['username'],
						'password' => $params['password'],
					),
				);
				BackupPlus_Options::update_backupplus_option( self::OPTION, $record, true, 'no' );
				$raw = get_option( self::OPTION, null );
				if ( ! is_array( $raw ) || ! is_string( $raw['credentials'] ) || 0 !== strpos( $raw['credentials'], 'bpcred:' ) || BackupPlus_Credential_Storage::reading( $raw, self::OPTION, true ) !== $record ) { throw new RuntimeException( 'Could not securely save the backup account. Check credential encryption in Settings.' ); }
			} elseif ( 'wordpress' === $operation ) { delete_option( self::OPTION ); }
			if ( 'status' !== $operation ) {
				$saved = array_merge( BackupPlus_PITR::config(), BackupPlus_PITR_Admin::wordpress_configuration( $config['bin_directory'] ) );
				BackupPlus_PITR::save_configuration( $saved );
			}
			return array(
				'ready'   => true,
				'message' => $checked . ': PITR backup access verified.' . ( $typed ? ' Choose Verify and save backup account to use it.' : ' Restore credentials will be requested only when restoring.' ),
			);
		} catch ( Throwable $error ) {
			$hint = $unsaved ? ' No backup account is saved; enter one above and choose Verify and save backup account.' : '';
			return array( 'error' => ( '' === $checked ? '' : $checked . ': ' ) . $error->getMessage() . $hint );
		}
		finally {
			if ( $connection ) { BackupPlus_PITR_Process::forget_connection( $connection ); }
			if ( $directory && is_dir( $directory ) ) { foreach ( new DirectoryIterator( $directory ) as $file ) { if ( $file->isFile() ) { unlink( $file->getPathname() );
			}
			} rmdir( $directory ); }
			if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN );
				fclose( $lock ); }
		}
	}
}
