<?php

/** The admin AJAX entry points and their helpers. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Ajax {

	/**
	 * This is used as a callback
	 *
	 * @param  Mixed $msg The data to be JSON encoded and sent back
	 */
	public function _backupplus_background_operation_started( $msg ) {
		global $backupplus;
		// The extra spaces are because of a bug seen on one server in handling of non-ASCII characters; see HS#11739
		$backupplus->close_browser_connection( json_encode( $msg ) . '        ' );
	}

	public function backupplus_ajax_handler() {
		$request_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'nonce' );
		$subaction     = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'subaction' );

		$nonce = empty( $request_nonce ) ? '' : $request_nonce;

		if ( ! wp_verify_nonce( $nonce, 'backupplus-credentialtest-nonce' ) || empty( $subaction ) ) {
			die( 'Security check' );
		}

		// Mitigation in case the nonce leaked to an unauthorised user
		if ( 'dismissautobackup' == $subaction ) {
			if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
				return;
			}
		} elseif ( 'dismissexpiry' == $subaction || 'dismissdashnotice' == $subaction ) {
			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}
		} else {
			if ( ! BackupPlus_Options::user_can_manage() ) {
				return;
			}
		}

		// All others use _POST
		$data_in_get = array( 'get_log', 'get_fragment' );

		// BackupPlus_WPAdmin_Commands extends BackupPlus_Commands - i.e. all commands are in there
		if ( ! class_exists( 'BackupPlus_WPAdmin_Commands' ) ) {
			backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'include_once' );
		}
		$commands = new BackupPlus_WPAdmin_Commands( $this );

		if ( method_exists( $commands, $subaction ) ) {

			$data = in_array( $subaction, $data_in_get ) ? $_GET : $_POST;

			// Undo WP's slashing of GET/POST data
			$data = BackupPlus_Manipulation_Functions::wp_unslash( $data );

			// TODO: Once all commands come through here and through backupplus_send_command(), the data should always come from this attribute (once backupplus_send_command() is modified appropriately).
			if ( isset( $data['action_data'] ) ) {
				$data = $data['action_data'];
			}
			try {
				$results = call_user_func( array( $commands, $subaction ), $data );
			} catch ( Exception $e ) {
				$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during ' . $subaction . ' subaction. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
				die;
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during ' . $subaction . ' subaction. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
				die;
			}
			if ( is_wp_error( $results ) ) {
				$results = array(
					'result'        => false,
					'error_code'    => $results->get_error_code(),
					'error_message' => $results->get_error_message(),
					'error_data'    => $results->get_error_data(),
				);
			}

			if ( is_string( $results ) ) {
				// A handful of legacy methods, and some which are directly the source for iframes, for which JSON is not appropriate.
				echo $results; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped --the escaping should take place at the time the caller of this ajax handler receives a response from its request possibly after calling wp_remote_*() or JS jQuery.ajax()
			} else {
				echo json_encode( $results );
			}
			die;
		}

		// Below are all the commands not ported over into class-commands.php or class-wpadmin-commands.php
		$request_subsubaction = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'subsubaction' );
		if ( 'activejobs_list' == $subaction ) {
			try {
				// N.B. Also called from autobackup.php
				// TODO: This should go into BackupPlus_Commands, once the add-ons have been ported to use backupplus_send_command()
				echo json_encode( $this->get_activejobs_list( BackupPlus_Manipulation_Functions::wp_unslash( $_GET ) ) );
			} catch ( Exception $e ) {
				$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during get active job list. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during get active job list. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
			}
		} elseif ( 'httpget' == $subaction ) {
			try {
				// httpget
				$curl        = empty( $_REQUEST['curl'] ) ? false : true;
				$request_uri = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'uri' );
				echo $this->http_get( BackupPlus_Manipulation_Functions::wp_unslash( $request_uri ), $curl ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- It's not HTML content; the output is in JSON format which can't be escaped
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during http get. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
			} catch ( Exception $e ) {
				$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during http get. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
			}
		} elseif ( 'doaction' == $subaction && ! empty( $request_subsubaction ) && 'backupplus_' == substr( $request_subsubaction, 0, 8 ) ) {
			$subsubaction = $request_subsubaction;
			try {
					// These generally echo and die - they will need further work to port to one of the command classes.
				do_action( BackupPlus_Manipulation_Functions::wp_unslash( $subsubaction ), $_REQUEST );
			} catch ( Exception $e ) {
				$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during doaction subaction with ' . $subsubaction . ' subsubaction. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
				die;
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during doaction subaction with ' . $subsubaction . ' subsubaction. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				echo json_encode(
					array(
						'fatal_error'         => true,
						'fatal_error_message' => $log_message,
					)
				);
				die;
			}
		}

		die;
	}

	/**
	 * Run a credentials test for the indicated remote storage module
	 *
	 * @param Array   $test_settings          The test parameters, including the method itself indicated in the key 'method'
	 * @param Boolean $return_instead_of_echo Whether to return or echo the results. N.B. More than just the results to echo will be returned
	 * @return Array|Void - the results, if they are being returned (rather than echoed). Keys: 'output' (the output), 'data' (other data)
	 */
	public function do_credentials_test( $test_settings, $return_instead_of_echo = false ) {

		$method = ( ! empty( $test_settings['method'] ) && preg_match( '/^[a-z0-9]+$/', $test_settings['method'] ) ) ? $test_settings['method'] : '';

		$this->logged = array();
		// TODO: Add action for WP HTTP SSL stuff
		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		set_error_handler( array( $this, 'get_php_errors' ), $error_levels );

		$obj = BackupPlus_Storage_Provider_Factory::create( $method, false );

		$ret  = '';
		$data = null;

		// TODO: Add action for WP HTTP SSL stuff
		if ( is_wp_error( $obj ) ) {
			$ret  = esc_html( $obj->get_error_message() );
			$data = array( 'success' => false );
			if ( ! $return_instead_of_echo ) {
				echo $ret;
			}
		} elseif ( is_callable( array( $obj, 'credentials_test' ) ) ) {
			$obj->set_connection_status( false );
			if ( $return_instead_of_echo ) {
				ob_start();
			}
			$data = $obj->credentials_test( $test_settings );
			if ( $return_instead_of_echo ) {
				$ret .= ob_get_clean();
			}
		}

		if ( ! is_wp_error( $obj ) && $obj->is_connection_successful() ) {
			if ( is_array( $data ) ) {
				$data['success'] = true;
			} else {
				$data = array( 'success' => true );
			}
		}

		if ( count( $this->logged ) > 0 ) {
			$ret .= "\n\n" . esc_html__( 'Messages:', 'backupplus' ) . "\n";
			foreach ( $this->logged as $err ) {
				$ret .= "* $err\n";
			}
			if ( ! $return_instead_of_echo ) {
				echo $ret; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all remote storage which provide credentials testing have already escaped their own output; so no escape late here
			}
		}
		restore_error_handler();

		if ( $return_instead_of_echo ) {
			return array(
				'output' => $ret,
				'data'   => $data,
			);
		}
	}

	/**
	 * Start a new backup
	 *
	 * @param Array            $request
	 * @param Boolean|Callable $close_connection_callable
	 */
	public function request_backupnow( $request, $close_connection_callable = false ) {
		global $backupplus;

		$pitr_capture = ! empty( $request['incremental'] ) && empty( $request['backupnow_nodb'] );
		if ( $pitr_capture ) {
			$config = BackupPlus_PITR::config();
			if ( empty( $config['enabled'] ) ) {
				return new WP_Error( 'pitr_disabled', __( 'Enable Database point-in-time recovery in Settings before taking an incremental database backup.', 'backupplus' ) );
			}
			// PITR always covers the complete database, independently of logical dump selections.
			unset( $request['database_selection'], $request['onlythesetableentities'] );
		}
		try {
			$source_options = BackupPlus_Backup_Sources::request_options( array(), $request );
			if ( ! $pitr_capture && empty( $request['backupnow_nodb'] ) ) { BackupPlus_Backup_Sources::plan_databases( $source_options ); }
		} catch ( Exception $e ) { return new WP_Error( 'invalid_backup_selection', $e->getMessage() ); }

		$backupnow_nocloud = ! empty( $request['backupnow_nocloud'] );

		$request['incremental'] = ! empty( $request['incremental'] );

		$remote_storage_instances = array();

		// if only_these_cloud_services is not an array then all connected remote storage locations are being backed up to and we don't need to do this
		if ( ! empty( $request['only_these_cloud_services'] ) && is_array( $request['only_these_cloud_services'] ) ) {
			$remote_storage_locations = $request['only_these_cloud_services'];

			foreach ( $remote_storage_locations as $key => $value ) {
				/*
					This name key inside the value array is the remote storage method name prefixed by 31 characters (backupplus_include_remote_service_) so we need to remove them to get the actual name, then the value key inside the value array has the instance id.
				*/
				$remote_storage_instances[ substr( $value['name'], 31 ) ][ $key ] = $value['value'];
			}
		}

		// The call to backup_time_nonce() allows us to know the nonce in advance, and return it
		$nonce = $backupplus->backup_time_nonce();

		$options = array(
			'nocloud'   => $backupnow_nocloud,
			'use_nonce' => $nonce,
		);
		if ( ! empty( $request['onlythisfileentity'] ) && is_string( $request['onlythisfileentity'] ) ) {
			// Something to see in the 'last log' field when it first appears, before the backup actually starts
			$backupplus->log( __( 'Start backup', 'backupplus' ) );
			$options['restrict_files_to_override'] = explode( ',', $request['onlythisfileentity'] );
		}

		$options['incremental'] = $request['incremental'];
		if ( $pitr_capture ) {
			$options['pitr_capture'] = true;
		}

		if ( ! empty( $request['extradata'] ) ) {
			$options['extradata'] = $request['extradata'];
		}

		if ( ! empty( $remote_storage_instances ) ) {
			$options['remote_storage_instances'] = $remote_storage_instances;
		}

		$options['always_keep'] = ! empty( $request['always_keep'] );

		$event   = empty( $request['backupnow_nofiles'] ) ? ( empty( $request['backupnow_nodb'] ) ? 'backupplus_backupnow_backup_all' : 'backupplus_backupnow_backup' ) : 'backupplus_backupnow_backup_database';
		$options = apply_filters( 'backupplus_backupnow_options', $options, $request );
		if ( ! is_array( $options ) ) {
			return new WP_Error( 'invalid_backup_options', __( 'The backup options could not be prepared.', 'backupplus' ) );
		}

		// Keep the event arguments unique so separate clicks are not merged by WP-Cron's duplicate-event check.
		$queue_id = bin2hex( random_bytes( 16 ) );
		if ( ! wp_schedule_single_event( time(), 'backupplus_backupnow_start', array( $event, $options, $queue_id ) ) ) {
			return new WP_Error( 'backup_queue_failed', __( 'The backup could not be queued. Please try again.', 'backupplus' ) );
		}

		$msg = array(
			'nonce' => $nonce,
			'm'     => apply_filters( 'backupplus_backupnow_start_message', '<strong>' . __( 'Backup queued', 'backupplus' ) . ':</strong> ' . htmlspecialchars( __( 'You should soon see activity in the "Last log message" field below.', 'backupplus' ) ), $nonce ),
		);

		if ( ! empty( $request['backup_nonce'] ) && 'current' != $request['backup_nonce'] ) {
			$msg['nonce'] = $request['backup_nonce'];
		}

		if ( $close_connection_callable && is_callable( $close_connection_callable ) ) {
			call_user_func( $close_connection_callable, $msg );
		} else {
			$backupplus->close_browser_connection( json_encode( $msg ) );
		}

		spawn_cron();

		return true;
	}

	public function get_php_errors( $errno, $errstr, $errfile, $errline ) {
		global $backupplus;
		if ( 0 == error_reporting() ) {
			return true; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- The error_reporting() function is used to get the current PHP error level.
		}       $logline = $backupplus->php_error_to_logline( $errno, $errstr, $errfile, $errline );
		if ( false !== $logline ) {
			$this->logged[] = $logline;
		}
		// Don't pass it up the chain (since it's going to be output to the user always)
		return true;
	}

	/**
	 * Ensure that what is returned is an array. Used as a WP options filter.
	 *
	 * @param Array $input - input
	 *
	 * @return Array
	 */
	public function return_array( $input ) {
		return is_array( $input ) ? $input : array();
	}

	/**
	 * Called upon the WP action wp_ajax_backupplus_savesettings. Will die().
	 */
	public function backupplus_ajax_savesettings() {
		try {
			$post_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'nonce' );
			if ( empty( $_POST ) || empty( $_POST['subaction'] ) || 'savesettings' != $_POST['subaction'] || ! isset( $post_nonce ) || ! is_user_logged_in() || ! BackupPlus_Options::user_can_manage() || ! wp_verify_nonce( $post_nonce, 'backupplus-settings-nonce' ) ) {
				die( 'Security check' );
			}
			$post_settings = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'settings' );
			if ( empty( $post_settings ) || ! is_string( $post_settings ) ) {
				die( 'Invalid data' );
			}

			parse_str( stripslashes( $post_settings ), $posted_settings );
			// We now have $posted_settings as an array
			$backupplus_version = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'backupplus_version' );
			if ( ! empty( $backupplus_version ) ) {
				$posted_settings['backupplus_version'] = $backupplus_version;
			}

			echo json_encode( $this->save_settings( $posted_settings ) );
		} catch ( Exception $e ) {
			$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during save settings. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
			$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during save settings. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		}
		die;
	}

	public function backupplus_ajax_importsettings() {
		try {
			$post_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'nonce' );
			if ( empty( $_POST ) || empty( $_POST['subaction'] ) || 'importsettings' != $_POST['subaction'] || ! isset( $post_nonce ) || ! is_user_logged_in() || ! BackupPlus_Options::user_can_manage() || ! wp_verify_nonce( $post_nonce, 'backupplus-settings-nonce' ) ) {
				die( 'Security check' );
			}

			if ( empty( $_POST['settings'] ) || ! is_string( $_POST['settings'] ) ) {
				die( 'Invalid data' );
			}

			$this->import_settings( $_POST );
		} catch ( Exception $e ) {
			$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during import settings. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
			$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during import settings. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		}
	}

	/**
	 * This will call any wp_action
	 *
	 * @param  Array|Null       $data                      The array of data with the values for wpaction
	 * @param  Callable|Boolean $close_connection_callable A callable to call to close the browser connection, or true for a default suitable for internal use, or false for none
	 * @return Array - results
	 */
	public function call_wp_action( $data = null, $close_connection_callable = false ) {
		global $backupplus;

		ob_start();

		$res = '<em>Request received: </em>';

		if ( preg_match( '/^([^:]+)+:(.*)$/', $data['wpaction'], $matches ) ) {
			$action = $matches[1];
			if ( null === ( $args = json_decode( $matches[2], true ) ) ) {
				$res   .= 'The parameters (should be JSON) could not be decoded';
				$action = false;
			} else {
				if ( is_string( $args ) ) {
					$args = array( $args );
				}
				$res .= 'Will despatch action: ' . htmlspecialchars( $action ) . ', parameters: ' . htmlspecialchars( implode( ',', $args ) );
			}
		} else {
			$action = $data['wpaction'];
			$res   .= 'Will despatch action: ' . htmlspecialchars( $action ) . ', no parameters';
		}

		ob_get_clean();

		// Need to add this as the close browser should only work for UDP
		if ( $close_connection_callable ) {
			if ( is_callable( $close_connection_callable ) ) {
				call_user_func( $close_connection_callable, array( 'r' => $res ) );
			} else {
				$backupplus->close_browser_connection( json_encode( array( 'r' => $res ) ) );
			}
		}

		if ( ! empty( $action ) ) {
			if ( ! empty( $args ) ) {
				ob_start();
				$returned = do_action_ref_array( $action, $args );
				$output   = ob_get_clean();
				$res     .= ' - do_action_ref_array Trigger ';
			} else {
				ob_start();
				do_action( $action );
				$output = ob_get_contents();
				ob_end_clean();
				$res .= ' - do_action Trigger ';
			}
		}
		$response             = array();
		$response['response'] = $res;
		$response['log']      = $output;

		// Check if response is empty
		if ( ! empty( $returned ) ) {
			$response['status'] = $returned;
		}

		return $response;
	}
}
