<?php

/** Listing and preparing the recovery points of a backup chain for download. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_WPAdmin_Commands_Chain_Downloads {

	/** One page of a chain's recovery points for an entity, newest first. */
	public function get_chain_download_points( $params ) {
		backupplus_try_include_file( 'includes/class-chain-downloads.php', 'include_once' );
		$history   = BackupPlus_Backup_History::get_history();
		$timestamp = isset( $params['timestamp'] ) ? (int) $params['timestamp'] : 0;
		$type      = isset( $params['type'] ) && is_string( $params['type'] ) ? $params['type'] : '';
		if ( ! isset( $history[ $timestamp ] ) || ! BackupPlus_Chain_Downloads::supported( $history[ $timestamp ], $type ) ) {
			return array( 'error' => __( 'This backup has no recovery points to download.', 'backupplus' ) );
		}
		$limit          = isset( $params['limit'] ) ? min( BackupPlus_Chain_Downloads::MAX_PAGE, max( 1, (int) $params['limit'] ) ) : 50;
		$offset         = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;
		$page           = BackupPlus_Chain_Downloads::page( $history[ $timestamp ], $type, isset( $params['search'] ) ? $params['search'] : '', $offset, $limit );
		$page['remote'] = BackupPlus_Chain_Downloads::has_remote( $history[ $timestamp ] );
		return $page;
	}

	/** Retrieve any remote-only files and confirm the chosen points fit one archive before the browser asks for it. */
	public function prepare_chain_download( $params ) {
		backupplus_try_include_file( 'includes/class-chain-downloads.php', 'include_once' );
		backupplus_try_include_file( 'includes/class-stored-zip.php', 'include_once' );
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 900 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
			return BackupPlus_Chain_Downloads::prepare( isset( $params['timestamp'] ) ? (int) $params['timestamp'] : 0, isset( $params['type'] ) && is_string( $params['type'] ) ? $params['type'] : '', isset( $params['points'] ) ? $params['points'] : '', empty( $params['archive'] ) ? false : true );
		} catch ( RuntimeException $error ) {
			return array( 'error' => $error->getMessage() );
		}
	}

	/** Delete the chosen points of one entity from this server or from remote storage; with `dry` only report what that involves. */
	public function delete_chain_files( $params ) {
		backupplus_try_include_file( 'includes/class-chain-downloads.php', 'include_once' );
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 900 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
			return BackupPlus_Chain_Downloads::delete(
				isset( $params['timestamp'] ) ? (int) $params['timestamp'] : 0,
				isset( $params['type'] ) && is_string( $params['type'] ) ? $params['type'] : '',
				isset( $params['points'] ) ? $params['points'] : '',
				isset( $params['where'] ) && 'remote' === $params['where'] ? 'remote' : 'local',
				! empty( $params['dry'] )
			);
		} catch ( RuntimeException $error ) {
			return array( 'error' => $error->getMessage() );
		}
	}
}
