<?php

/** Listing remote backups and deleting them for retention. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Dropbox_Listing_Retention {

	/**
	 * This method gets a list of files from the remote storage that match the string passed in and returns an array of backups
	 *
	 * @param  String $match a substring to require (tested via strpos() !== false)
	 * @return Array
	 */
	public function listfiles( $match = 'backup_' ) {

		$opts = $this->get_options();

		if ( empty( $opts['tk_access_token'] ) ) {
			return new WP_Error( 'no_settings', __( 'No settings were found', 'backupplus' ) . ' (dropbox)' );
		}

		try {
			$dropbox = $this->bootstrap();
		} catch ( Exception $e ) {
			$this->log( 'access error: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			return new WP_Error( 'access_error', $e->getMessage() );
		}

		$searchpath = '/' . untrailingslashit( apply_filters( 'backupplus_dropbox_modpath', '', $this ) );

		try {
			/* Some users could have a large amount of backups, the max search is 1000 entries we should continue to search until there are no more entries to bring back. */
			$cursor  = '';
			$matches = array();

			while ( true ) {
				$search = $dropbox->search( $match, $searchpath, 1000, $cursor );
				/* translators: 1: Service name, 2: HTTP response code */
				if ( empty( $search['code'] ) || 200 != $search['code'] ) {
					return new WP_Error( 'response_error', sprintf( __( '%1$s returned an unexpected HTTP response: %2$s', 'backupplus' ), 'Dropbox', $search['code'] ), $search['body'] );
				}

				if ( empty( $search['body'] ) ) {
					return new WP_Error( 'listing_incomplete', 'Dropbox returned an incomplete listing.' );
				}

				if ( isset( $search['body']->matches ) && is_array( $search['body']->matches ) ) {
					$matches = array_merge( $matches, $search['body']->matches );
				} elseif ( is_array( $search['body'] ) ) {
					$matches = $search['body'];
				} else {
					return new WP_Error( 'listing_incomplete', 'Dropbox returned an incomplete listing.' );
				}

				if ( ! empty( $search['body']->has_more ) && empty( $search['body']->cursor ) ) { return new WP_Error( 'listing_incomplete', 'Dropbox listing cursor is missing.' ); }
				if ( isset( $search['body']->has_more ) && true == $search['body']->has_more && isset( $search['body']->cursor ) ) {
					$cursor = $search['body']->cursor;
				} else {
					break;
				}
			}
		} catch ( Exception $e ) {
			$this->log( $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			// The most likely cause of a search_error is specifying a non-existent path, which should just result in an empty result set.
			return new WP_Error( 'search_error', $e->getMessage() );
		}

		$results = array();

		foreach ( $matches as $item ) {
			$item = $item->metadata;
			if ( ! is_object( $item ) ) {
				continue;
			}
			if ( isset( $item->metadata ) ) {
				$item = $item->metadata; // 2/files/search_v2 has a slightly different output structure compared to 2/files/search model
			}

			if ( ( ! isset( $item->size ) || $item->size > 0 ) && 'folder' != $item->{'.tag'} && ! empty( $item->path_display ) && 0 === strpos( $item->path_display, $searchpath ) ) {

				$path = substr( $item->path_display, strlen( $searchpath ) );
				if ( '/' == substr( $path, 0, 1 ) ) {
					$path = substr( $path, 1 );
				}

				// Ones in subfolders are not wanted
				if ( false !== strpos( $path, '/' ) ) {
					continue;
				}

				$result = array( 'name' => $path );
				if ( ! empty( $item->size ) ) {
					$result['size'] = $item->size;
				}

				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Delete files from the service using the Dropbox API
	 *
	 * @param Array $files    - array of filenames to delete
	 * @param Array $data     - unused here
	 * @param Array $sizeinfo - unused here
	 * @return Boolean|String - either a boolean true or an error code string
	 */
	public function delete( $files, $data = null, $sizeinfo = array() ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $data and $sizeinfo unused

		if ( is_string( $files ) ) {
			$files = array( $files );
		}

		$opts = $this->get_options();

		if ( empty( $opts['tk_access_token'] ) ) {
			$this->log( 'You are not authenticated with Dropbox (3)' );
			/* translators: %s: Service name */
			$this->log( sprintf( __( 'You are not authenticated with %s (whilst deleting)', 'backupplus' ), 'Dropbox' ), 'warning' );
			return 'authentication_fail';
		}

		try {
			$dropbox = $this->bootstrap();
		} catch ( Exception $e ) {
			$this->log( $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			/* translators: %s: Service name */
			$this->log( sprintf( __( 'Failed to access %s when deleting (see log file for more)', 'backupplus' ), 'Dropbox' ), 'warning' );
			return 'service_unavailable';
		}
		if ( false === $dropbox ) {
			return false;
		}

		$any_failures = false;

		foreach ( $files as $file ) {
			$ufile = apply_filters( 'backupplus_dropbox_modpath', $file, $this );
			$this->log( "request deletion: $ufile" );

			try {
				$dropbox->delete( $ufile );
				$file_success = 1;
			} catch ( Exception $e ) {
				$this->log( $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			}

			if ( isset( $file_success ) ) {
				$this->log( 'deletion succeeded' );
			} else {
				$this->log( 'deletion failed' );
				$any_failures = true;
			}
		}

		return $any_failures ? 'file_delete_error' : true;
	}
}
