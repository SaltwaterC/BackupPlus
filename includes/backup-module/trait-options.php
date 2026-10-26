<?php

/** A storage provider instance: its saved options, identity, storage handle and connection status. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_BackupModule_Options {

	/**
	 * Store options (within this class) for this remote storage module. There is also a parameter for saving to the permanent storage (i.e. database).
	 *
	 * @param  array       $options     array of options to store
	 * @param  Boolean     $save        whether or not to also save the options to the database
	 * @param  null|String $instance_id optionally set the instance ID for this instance at the same time. This is required if you have not already set an instance ID with set_instance_id()
	 * @return void|Boolean If saving to DB, then the result of the DB save operation is returned.
	 */
	public function set_options( $options, $save = false, $instance_id = null ) {

		$this->_options = $options;

		// Remove any previously-stored storage object, because this is usually tied to the options
		if ( ! empty( $this->_storage ) ) {
			unset( $this->_storage );
		}

		if ( $instance_id ) {
			$this->set_instance_id( $instance_id );
		}

		if ( $save ) {
			return $this->save_options();
		}
	}

	/**
	 * Saves the current options to the database. This is a private function; external callers should use set_options().
	 *
	 * @throws Exception if trying to save options without indicating an instance_id, or if the remote storage module does not have the multi-option capability
	 */
	private function save_options() {

		if ( ! $this->supports_feature( 'multi_options' ) ) {
			throw new Exception( 'save_options() can only be called on a storage method which supports multi_options (this module, ' . $this->get_id() . ', does not)' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}

		if ( ! $this->_instance_id ) {
			throw new Exception( 'save_options() requires an instance ID, but was called without setting one (either directly or via set_instance_id())' );
		}

		$current_db_options = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format( $this->get_id() );

		if ( is_wp_error( $current_db_options ) ) {
			throw new Exception( 'save_options(): options fetch/update failed (' . $current_db_options->get_error_code() . ': ' . $current_db_options->get_error_message() . ')' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}

		$current_db_options['settings'][ $this->_instance_id ] = $this->_options;

		$updated = BackupPlus_Options::update_backupplus_option( 'backupplus_' . $this->get_id(), $current_db_options );
		return $updated || BackupPlus_Options::get_backupplus_option( 'backupplus_' . $this->get_id() ) === $current_db_options;
	}

	/**
	 * Retrieve default options for this remote storage module.
	 *
	 * @return array - an array of options
	 */
	public function get_default_options() {
		$options = $this->get_input_option_mappings( 'option' );

		$defaults = array();

		foreach ( $options as $key => $option ) {
			$defaults[ $key ] = $option['default_value'];
		}

		return $defaults;
	}

	/**
	 * Check whether options have been set up by the user, or not
	 * This method would normally be over-ridden by the child.
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist( $opts ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the caller uses 1 argument and normally this method will be over-ridden by the child class.
		return false;
	}

	/**
	 * Retrieve a list of supported features for this storage method
	 * This method should be over-ridden by methods supporting new
	 * features.
	 *
	 * Keys are strings, and values are booleans.
	 *
	 * Currently known features:
	 *
	 * - multi_options : indicates that the remote storage module
	 * can handle its options being in the Feb-2017 multi-options
	 * format. N.B. This only indicates options handling, not any
	 * other multi-destination options.
	 *
	 * - multi_servers : not implemented yet: indicates that the
	 * remote storage module can handle multiple servers at backup
	 * time. This should not be specified without multi_options.
	 * multi_options without multi_servers is fine - it will just
	 * cause only the first entry in the options array to be used.
	 *
	 * - config_templates : not implemented yet: indicates that
	 * the remote storage module can output its configuration in
	 * Handlebars format via the get_configuration_template() method.
	 *
	 * - conditional_logic : indicates that the remote storage module
	 * can handle predefined logics regarding how backups should be
	 * sent to the remote storage
	 *
	 * @return Array - an array of supported features (any features not
	 * mentioned are assumed to not be supported)
	 */
	public function get_supported_features() {
		return array();
	}

	/**
	 * This method will set the stored storage object to that indicated
	 *
	 * @param Object $storage - the storage client
	 */
	public function set_storage( $storage ) {
		$this->_storage = $storage;
	}

	/**
	 * This method will return the stored storage client
	 *
	 * @return Object - the stored remote storage client
	 */
	public function get_storage() {
		if ( ! empty( $this->_storage ) ) {
			return $this->_storage;
		}
	}

	/**
	 * Supplies the list of keys for options to be saved in the backup job.
	 *
	 * @return Array
	 */
	public function get_credentials() {
		$keys = array( 'backupplus_ssl_disableverify', 'backupplus_ssl_nossl', 'backupplus_ssl_useservercerts' );
		if ( ! $this->supports_feature( 'multi_servers' ) ) {
			$keys[] = 'backupplus_' . $this->get_id();
		}
		return $keys;
	}

	/**
	 * Get the backup method identifier for this class
	 *
	 * @return String - the identifier
	 */
	public function get_id() {
		$class = get_class( $this );
		return substr( $class, strlen( __CLASS__ ) + 1 );
	}

	/**
	 * Get the backup method description for this class
	 *
	 * @return String - the identifier
	 */
	public function get_description() {
		global $backupplus;

		$methods = $backupplus->backup_methods;

		$id = $this->get_id();

		return isset( $methods[ $id ] ) ? $methods[ $id ] : $id;
	}

	/**
	 * Sets the instance ID - for supporting multi_options
	 *
	 * @param String $instance_id - the instance ID
	 */
	public function set_instance_id( $instance_id ) {
		$this->_instance_id = $instance_id;
	}

	/**
	 * Sets the instance ID - for supporting multi_options
	 *
	 * @returns String the instance ID
	 */
	public function get_instance_id() {
		return $this->_instance_id;
	}

	/**
	 * Check whether this storage module supports a mentioned feature
	 *
	 * @param String $feature - the feature concerned
	 *
	 * @returns Boolean
	 */
	public function supports_feature( $feature ) {
		return in_array( $feature, $this->get_supported_features() );
	}

	/**
	 * Retrieve options for this remote storage module.
	 * N.B. The option name instance_id is reserved and should not be used.
	 *
	 * @uses get_default_options
	 *
	 * @return Array - array of options. This will include default values for any options not set.
	 */
	public function get_options() {

		global $backupplus;

		$supports_multi_options = $this->supports_feature( 'multi_options' );

		if ( is_array( $this->_options ) ) {
			// First, prioritise any options that were explicitly set. This is the eventual goal for all storage modules.
			$options = $this->_options;

		} elseif ( is_callable( array( $this, 'get_opts' ) ) ) {
			// Next, get any options available via a legacy / over-ride method.

			if ( $supports_multi_options ) {
				// This is forbidden, because get_opts() is legacy and is for methods that do not support multi-options. Supporting multi-options leads to the array format being updated, which will then break get_opts().
				die( 'Fatal error: method ' . esc_html( $this->get_id() ) . ' both supports multi_options and provides a get_opts method' );
			}

			$options = $this->get_opts();

		} else {

			// Next, look for job options (which in turn, falls back to saved settings if no job options were set)

			$options = $backupplus->get_job_option( 'backupplus_' . $this->get_id() );
			if ( ! is_array( $options ) ) {
				$options = array();
			}

			if ( $supports_multi_options ) {

				if ( ! isset( $options['version'] ) ) {
					$options_full = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format( $this->get_id() );

					if ( is_wp_error( $options_full ) ) {
						$backupplus->log( 'Options retrieval failure: ' . $options_full->get_error_code() . ': ' . $options_full->get_error_message() . ' (' . json_encode( $options_full->get_error_data() ) . ')' );
						return array();
					}
				} else {
					$options_full = $options;
				}

				// BackupPlus_BackupModule::get_options() is for getting the current instance's options. So, this branch (going via the job option) is a legacy route, and hence we just give back the first one. The non-legacy route is to call the set_options() method externally.
				$options = ( isset( $options_full['settings'] ) && is_array( $options_full['settings'] ) ) ? reset( $options_full['settings'] ) : false;

				if ( false === $options ) {
					$backupplus->log( 'Options retrieval failure (no options set)' );
					return array();
				}
				$instance_id = key( $options_full['settings'] );
				$this->set_options( $options, false, $instance_id );

			}
		}

		$options = apply_filters(
			'backupplus_backupmodule_get_options',
			wp_parse_args( $options, $this->get_default_options() ),
			$this
		);

		return $options;
	}

	/**
	 * Set the remote storage connection status.
	 *
	 * @param bool $status True if connection is successful, false otherwise.
	 *
	 * @return void
	 */
	public function set_connection_status( $status ) {
		$this->is_connection_successful = (bool) $status;
		do_action( 'backupplus_remote_storage_connection_status_changed', $status, $this );
	}

	/**
	 * Check if the connection was successful.
	 *
	 * @return bool True if connected successfully, false otherwise.
	 */
	public function is_connection_successful() {
		return $this->is_connection_successful;
	}

	/**
	 * Retrieve field mappings, optionally filters mappings by context.
	 *
	 * @param string $context Optional. Context name used to filter mappings.
	 *
	 * @return array
	 */
	public function get_input_option_mappings( $context = '' ) {
		if ( empty( $context ) ) {
			return $this->input_option_field_mappings;
		}
		$filtered_mappings = array();

		foreach ( $this->input_option_field_mappings as $key => $mapping ) {
			if ( in_array( $context, $mapping['contexts'] ) ) {
				$filtered_mappings[ $key ] = $mapping;
			}
		}

		return $filtered_mappings;
	}
}
