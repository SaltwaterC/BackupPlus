<?php

/** Job data, logging and response handling used while a provider transfers files. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_BackupModule_Runtime {

	/**
	 * Set job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_set()
	 *
	 * @param String $key   - the key for the job data
	 * @param Mixed  $value - the data to be stored
	 */
	public function jobdata_set( $key, $value ) {

		$instance_key = $this->get_id() . '-' . ( $this->_instance_id ? $this->_instance_id : 'no_instance' );

		global $backupplus;

		$instance_data = $backupplus->jobdata_get( $instance_key );

		if ( ! is_array( $instance_data ) ) {
			$instance_data = array();
		}

		$instance_data[ $key ] = $value;

		$backupplus->jobdata_set( $instance_key, $instance_data );
	}

	/**
	 * Get job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_get()
	 *
	 * @param String      $key        - the key for the job data
	 * @param Mixed       $default    - the default to return if nothing was set
	 * @param String|Null $legacy_key - the previous name of the key, prior to instance-specific job data (so that upgrades across versions whilst a backup is in progress can still find its data). In future, support for this can be removed.
	 */
	public function jobdata_get( $key, $default = null, $legacy_key = null ) {

		$instance_key = $this->get_id() . '-' . ( $this->_instance_id ? $this->_instance_id : 'no_instance' );

		global $backupplus;

		$instance_data = $backupplus->jobdata_get( $instance_key );

		if ( is_array( $instance_data ) && isset( $instance_data[ $key ] ) ) {
			return $instance_data[ $key ];
		}

		return is_string( $legacy_key ) ? $backupplus->jobdata_get( $legacy_key, $default ) : $default;
	}

	/**
	 * Delete job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_set()
	 *
	 * @param String      $key        - the key for the job data
	 * @param String|Null $legacy_key - the previous name of the key, prior to instance-specific job data (so that upgrades across versions whilst a backup is in progress can still find its data)
	 */
	public function jobdata_delete( $key, $legacy_key = null ) {

		$instance_key = $this->get_id() . '-' . ( $this->_instance_id ? $this->_instance_id : 'no_instance' );

		global $backupplus;

		$instance_data = $backupplus->jobdata_get( $instance_key );

		if ( is_array( $instance_data ) && isset( $instance_data[ $key ] ) ) {
			unset( $instance_data[ $key ] );
			$backupplus->jobdata_set( $instance_key, $instance_data );
		}

		if ( is_string( $legacy_key ) ) {
			$backupplus->jobdata_delete( $legacy_key );
		}
	}

	/**
	 * This function is a wrapper and will call $backupplus->log(), the backup modules should use this so we can add information to the log lines to do with the remote storage and instance settings.
	 *
	 * @param string  $line       - the log line
	 * @param string  $level      - the log level: notice, warning, error. If suffixed with a hyphen and a destination, then the default destination is changed too.
	 * @param boolean $uniq_id    - each of these will only be logged once
	 * @param boolean $skip_dblog - if true, then do not write to the database
	 *
	 * @return void
	 */
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {
		global $backupplus;

		$prefix = $this->get_storage_label();

		$backupplus->log( "$prefix: $line", $level, $uniq_id, $skip_dblog );
	}

	/**
	 * Log appropriate messages for a multi-delete response.
	 *
	 * @param Array $files
	 * @param Array $responses - using the same keys as $files
	 *
	 * @return Boolean - true if no errors were found, otherwise false
	 */
	protected function process_multi_delete_responses( $files, $responses ) {
		global $backupplus;
		$ret = true;
		if ( is_array( $responses ) ) {
			foreach ( $responses as $key => $response ) {
				if ( 'success' == $response ) {
					$backupplus->log( "$files[$key]: Delete succeeded" );
				} elseif ( is_array( $response ) ) {
					$ret = false;
					if ( isset( $response['error'] ) && isset( $response['error']['code'] ) && isset( $response['error']['message'] ) ) {
						$backupplus->log( "Delete failed for file: $files[$key] with error code: " . $response['error']['code'] . ' message: ' . $response['error']['message'] );
					} else {
						$backupplus->log( "Delete failed for file: $files[$key]" );
					}
				}
			}
		} elseif ( ! $responses ) {
			$ret = false;
			$backupplus->log( 'Delete failed for files: ' . implode( $files ) );
		}
		return $ret;
	}

	/**
	 * This function will build and return the remote storage instance label
	 *
	 * @return String - the remote storage instance label
	 */
	private function get_storage_label() {

		$opts  = $this->get_options();
		$label = isset( $opts['instance_label'] ) ? $opts['instance_label'] : '';

		$description = $this->get_description();

		if ( ! empty( $label ) ) {
			$prefix = ( false !== strpos( $label, $description ) ) ? $label : "$description: $label";
		} else {
			$prefix = $description;
		}

		return $prefix;
	}
}
