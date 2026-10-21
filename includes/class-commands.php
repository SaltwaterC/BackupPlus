<?php

if (!defined('ABSPATH')) die('No direct access allowed');

/*
	- A container for all the remote commands implemented. Commands map exactly onto method names (and hence this class should not implement anything else, beyond the constructor, and private methods)
	- Return format is either to return data (boolean, string, array), or an WP_Error object
	Commands are not allowed to begin with an underscore. So, any private methods can be prefixed with an underscore.
	TODO: Many of these just verify input, and then call back into a relevant method in BackupPlus_Admin. Once all commands have been ported over to go via this class, those methods in BackupPlus_Admin can generally be folded into the relevant method in here, and removed from BackupPlus_Admin. (Since this class is intended to become the official way of performing actions). As a bonus, we then won't need so much _load_ud(_admin) boilerplate.
*/

if (class_exists('BackupPlus_Commands')) return;

class BackupPlus_Commands {

	private $_uc_helper;

	/**
	 * Constructor
	 *
	 * @param Class $uc_helper The 'helper' needs to provide the method _backupplus_background_operation_started
	 */
	public function __construct($uc_helper) {
		$this->_uc_helper = $uc_helper;
	}

	/**
	 * Get the Advanced Tools HTMl and return to Central
	 *
	 * @param  string $options Options for advanced settings
	 * @return string
	 */
	public function get_advanced_settings($options) {
		// load global backupplus and admin
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		if (false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.

		$html = $backupplus_admin->settings_advanced_tools(true, array('options' => $options));
		
		return $html;
	}

	public function get_download_status($items) {
		// load global backupplus and admin
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
	
		if (!is_array($items)) $items = array();

		return $backupplus_admin->get_download_statuses($items);
	
	}
	
	/**
	 * Begin a download process
	 *
	 * @param Array $downloader_params - download parameters (findex, type, timestamp, stage)
	 *
	 * @return Array - as from BackupPlus_Admin::do_backupplus_download_backup() (with 'request' key added, with value $downloader_params)
	 */
	public function downloader($downloader_params) {

		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
	
		$findex = $downloader_params['findex'];
		$type = $downloader_params['type'];
		$timestamp = $downloader_params['timestamp'];
		// Valid stages: 2='spool the data'|'delete'='delete local copy'|anything else='make sure it is present'
		$stage = empty($downloader_params['stage']) ? false : $downloader_params['stage'];
	
		// This may, or may not, return, depending upon whether the files are already downloaded
		// The response is usually an array with key 'result', and values deleted|downloaded|needs_download|download_failed
		$response = $backupplus_admin->do_backupplus_download_backup($findex, $type, $timestamp, $stage, array($this->_uc_helper, '_backupplus_background_operation_started'));
	
		if (is_array($response)) {
			$response['request'] = $downloader_params;
		}
	
		return $response;
	}
	
	public function delete_downloaded($set_info) {
		$set_info['stage'] = 'delete';
		return $this->downloader($set_info);
	}
	
	/**
	 * Get backup progress (as HTML) for a particular backup
	 *
	 * @param Array $params - should have a key 'job_id' with corresponding value
	 *
	 * @return String - the HTML
	 */
	public function backup_progress($params) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		
		$request = array(
			'thisjobonly' => $params['job_id']
		);
		$activejobs_list = $backupplus_admin->get_activejobs_list($request);
		
		return $activejobs_list;
	
	}
	
	/**
	 * Perform a backup
	 *
	 * @param Array $params
	 *
	 * @uses die()
	 *
	 * @return WP_Error - or, may die()
	 */
	public function backupnow($params) {
		
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		$background_operation_started_method_name = empty($params['background_operation_started_method_name']) ? '_backupplus_background_operation_started' : $params['background_operation_started_method_name'];
		$backupplus_admin->request_backupnow($params, array($this->_uc_helper, $background_operation_started_method_name));
		
		// Control returns when the backup finished; but, the browser connection should have been closed before
		die;
	}
	
	/**
	 * Mark a backup as "do not delete"
	 *
	 * @param array $params this is an array of parameters sent via ajax it can include the following:
	 * backup_key - Integer - backup timestamp
	 * always_keep - Boolean - "Always keep" value
	 * @return array which contains rawbackup html
	 */
	public function always_keep_this_backup($params) {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		$backup_key = $params['backup_key'];
		$backup_history = BackupPlus_Backup_History::get_history();
		if (empty($params['always_keep'])) {
			unset($backup_history[$backup_key]['always_keep']);
		} else {
			$backup_history[$backup_key]['always_keep'] = true;
		}
		BackupPlus_Backup_History::save_history($backup_history);
		$nonce = $backup_history[$backup_key]['nonce'];
		$rawbackup = $backupplus_admin->raw_backup_info($backup_history, $backup_key, $nonce);
		return array(
			'rawbackup' => html_entity_decode($rawbackup),
		);
	}

	/**
	 * Function to retrieve raw backup history given a timestamp and nonce
	 *
	 * @param Array $data - Data parameter; keys: timestamp, nonce
	 *
	 * @return Array - An array that contains the raw backup history
	 */
	public function rawbackup_history($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		$history = BackupPlus_Backup_History::get_history();

		$rawbackup = $backupplus_admin->raw_backup_info($history, $data['timestamp'], $data['nonce'], null);

		return array(
			'rawbackup' => html_entity_decode($rawbackup),
		);
	}

	/**
	 * Function to retrieve list of existing backups with all of their data.
	 *
	 * @return Array - Array of existing backup data.
	 */
	public function get_existing_backups_data() {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === $this->_load_ud()) return new WP_Error('no_backupplus');
		$history = BackupPlus_Backup_History::get_history();

		return array(
			'history' => $history,
			'download_data' => $backupplus_admin->get_download_buttons_data($history),
		);
	}
	
	private function _load_ud() {
		global $backupplus;
		return is_a($backupplus, 'BackupPlus') ? $backupplus : false;
	}
	
	private function _load_ud_admin() {
		if (!defined('BACKUPPLUS_DIR') || !is_file(BACKUPPLUS_DIR.'/admin.php')) return false;
		backupplus_try_include_file('admin.php', 'include_once');
		global $backupplus_admin;
		return $backupplus_admin;
	}
	
	public function get_log($job_id = '') {

		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
	
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		if ('' != $job_id && !preg_match("/^[0-9a-f]{12}$/", $job_id)) return new WP_Error('backupplus_permission_invalid_jobid');
		
		return $backupplus_admin->fetch_log($job_id);
	
	}
	
	public function activejobs_delete($job_id) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		return $backupplus_admin->activejobs_delete((string) $job_id);

	}
	
	public function deleteset($what) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.

		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
	
		$results = $backupplus_admin->delete_set($what);
	
		$get_history_opts = isset($what['get_history_opts']) ? $what['get_history_opts'] : array();
	
		$backup_history = BackupPlus_Backup_History::get_history();
	
		$results['history'] = $backupplus_admin->settings_downloading_and_restoring($backup_history, true, $get_history_opts);

		$results['backupnow_file_entities'] = apply_filters('backupplus_backupnow_file_entities', array());
		$results['modal_afterfileoptions'] = apply_filters('backupplus_backupnow_modal_afterfileoptions', '', '');
		
		$results['count_backups'] = count($backup_history);

		return $results;
	
	}
	
	/**
	 * Slightly misnamed - this doesn't always rescan, but it does always return the history status (possibly after a rescan)
	 *
	 * @param  Array|String $data - with keys 'operation' and 'debug'; or, if a string (backwards compatibility), just the value of the 'operation' key (with debug assumed as 0)
	 *
	 * @return Array - returns an array of history statuses
	 */
	public function rescan($data) {

		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		
		$backup_count = 0;

		if (is_array($data)) {
			$operation = empty($data['operation']) ? '' : $data['operation'];
			$debug = !empty($data['debug']);
			$backup_count = empty($data['backup_count']) ? 0 : $data['backup_count'];
		} else {
			$operation = $data;
			$debug = false;
		}
	
		$remotescan = ('remotescan' == $operation);
		$rescan = ($remotescan || 'rescan' == $operation);
		
		$history_status = $backupplus_admin->get_history_status($rescan, $remotescan, $debug, $backup_count);
		$history_status['backupnow_file_entities'] = apply_filters('backupplus_backupnow_file_entities', array());
		$history_status['modal_afterfileoptions'] = apply_filters('backupplus_backupnow_modal_afterfileoptions', '', '');

		return $history_status;
		
	}
	
	public function get_settings($options) {
		global $backupplus;
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		
		ob_start();
		$backupplus_admin->settings_formcontents($options);
		$output = ob_get_contents();
		ob_end_clean();
		
		$remote_storage_options_and_templates = BackupPlus_Storage_Methods_Interface::get_remote_storage_options_and_templates();
		
		return array(
			'settings' => $output,
			'remote_storage_options' => $remote_storage_options_and_templates['options'],
			'remote_storage_templates' => $remote_storage_options_and_templates['templates'],
			'remote_storage_partial_templates' => $remote_storage_options_and_templates['partial_templates'],
			'meta' => apply_filters('backupplus_get_settings_meta', array()),
			'backupplus_version' => $backupplus->version,
			'backup_methods' => $backupplus->backup_methods,
			'active_instances' => $backupplus_admin->get_active_remote_storages(),
		);
		
	}
	
	/**
	 * Run a credentials test
	 *
	 * @param Array $test_data - test configuration
	 *
	 * @return WP_Error|Array - test results (keys: results, (optional)data), or an error
	 */
	public function test_storage_settings($test_data) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
	
		$results = $backupplus_admin->do_credentials_test($test_data, true);
	
		return $results;
	
	}
	
	/**
	 * Perform a connection test on a database
	 *
	 * @param Array $info - test parameters
	 *
	 * @return Array - test results
	 */
	public function extradb_testconnection($info) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
	
		$results = apply_filters('backupplus_extradb_testconnection_go', array(), $info);
	
		return $results;
	
	}
	
	/**
	 * A handler method to call the BackupPlus admin save settings method. It will check if the settings passed to it are in the format of a string if so it converts it to an array otherwise just pass the array
	 *
		 * @param  String/Array $settings Settings to be saved to BackupPlus either in the form of a string ready to be converted to an array or already an array ready to be passed to the settings handler.
	 * @return Array An Array response to be sent back
	 */
	public function save_settings($settings) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		if (!empty($settings)) {

			if (is_string($settings)) {
				parse_str($settings, $settings_as_array);
			} elseif (is_array($settings)) {
				$settings_as_array = $settings;
			} else {
				return new WP_Error('invalid_settings');
			}
		}
		
		$results = $backupplus_admin->save_settings($settings_as_array);

		return $results;
	
	}
	
	public function s3_newuser($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		$results = apply_filters('backupplus_s3_newuser_go', array(), $data);
		
		return $results;
	}
	
	public function cloudfiles_newuser($data) {
	
		global $backupplus_addon_cloudfilesenhanced;
		if (!is_a($backupplus_addon_cloudfilesenhanced, 'BackupPlus_Addon_CloudFilesEnhanced')) {
			// translators: %s: The name of the missing add-on.
			$data = array('e' => 1, 'm' => sprintf(__('%s add-on not found', 'backupplus'), 'Rackspace Cloud Files'));
		} else {
			$data = $backupplus_addon_cloudfilesenhanced->create_api_user($data);
		}
		
		if (0 === $data['e']) {
			return $data;
		} else {
			return new WP_Error('error', '', $data);
		}
	}
	
	/**
	 * Get an HTML fragment
	 *
	 * @param String|Array $fragment - what fragment to fetch. If an array, the fragment identifier is in 'fragment' (and 'data' is associated data)
	 *
	 * @return Array|WP_Error
	 */
	public function get_fragment($fragment) {
	
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		if (is_array($fragment)) {
			$data = $fragment['data'];
			$fragment = $fragment['fragment'];
		}
		
		$error = false;
		
		switch ($fragment) {
		
			case 'last_backup_html':
					$output = $backupplus_admin->last_backup_html();
				break;
			
			case 's3_new_api_user_form':
				ob_start();
				do_action('backupplus_s3_print_new_api_user_form', false);
				$output = ob_get_contents();
				ob_end_clean();
				break;
				
			case 'cloudfiles_new_api_user_form':
				global $backupplus_addon_cloudfilesenhanced;
				if (!is_a($backupplus_addon_cloudfilesenhanced, 'BackupPlus_Addon_CloudFilesEnhanced')) {
						$error = true;
						$output = 'cloudfiles_addon_not_found';
				} else {
					$output = array(
						'accounts' => $backupplus_addon_cloudfilesenhanced->account_options(),
						'regions' => $backupplus_addon_cloudfilesenhanced->region_options(),
					);
				}
				break;
			
			case 'backupnow_modal_contents':
				$backupplus_dir = $backupplus->backups_dir_location();
				if (!BackupPlus_Filesystem_Functions::really_is_writable($backupplus_dir)) {
					$output = array('error' => true, 'html' => __("The 'Backup Now' button is disabled as your backup directory is not writable (go to the 'Settings' tab and find the relevant option).", 'backupplus'));
				} else {
					$output = array('html' => $backupplus_admin->backupnow_modal_contents(), 'backupnow_file_entities' => apply_filters('backupplus_backupnow_file_entities', array()), 'incremental_installed' => apply_filters('backupplus_incremental_addon_installed', false));
				}
				break;
			
			case 'panel_download_and_restore':
				$backup_history = BackupPlus_Backup_History::get_history();
				$output = $backupplus_admin->settings_downloading_and_restoring($backup_history, true, $data);
				break;
			
			case 'disk_usage':
				$output = BackupPlus_Filesystem_Functions::get_disk_space_used($data);
				break;
			default:
				// We just return a code - translation is done on the other side
				$output = 'ud_get_fragment_could_not_return';
				$error = true;
				break;
		}
		
		if (!$error) {
			return array(
				'output' => $output,
			);
		} else {
			return new WP_Error('get_fragment_error', '', $output);
		}
		
	}
	
	/**
	 * This gets the http_get function from admin to grab information on a url
	 *
	 * @param  string $uri URL to be used
	 * @return array returns response from specific URL
	 */
	public function http_get($uri) {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		if (empty($uri)) {
			return new WP_Error('error', '', 'no_uri');
		}
		
		$response = $backupplus_admin->http_get($uri, false);
		$response_decode = json_decode($response);

		if (isset($response_decode->e)) {
		  return new WP_Error('error', '', htmlspecialchars($response_decode->e));
		}

		return array('status' => $response_decode->code, 'response' => $response_decode->html_response);
	}

	/**
	 * This gets the http_get function from admin to grab cURL information on a url
	 *
	 * @param  string $uri URL to be used
	 * @return array
	 */
	public function http_get_curl($uri) {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		if (empty($uri)) {
			return new WP_Error('error', '', 'no_uri');
		}
		
		if (!function_exists('curl_exec')) {
			return new WP_Error('error', '', 'no_curl');
		}
		
		$response_encode = $backupplus_admin->http_get($uri, true);
		$response_decode = json_decode($response_encode);

		$response = 'Curl Info: ' . $response_decode->verb
					.'Response: ' . $response_decode->response;

		if (false === $response_decode->response) {
			return new WP_Error('error', '', array(
				'error' => htmlspecialchars($response_decode->e),
				"status" => $response_decode->status,
				"log" => htmlspecialchars($response_decode->verb)
			));
		}
		
		return array(
			'response'=> htmlspecialchars(substr($response, 0, 2048)),
			'status'=> $response_decode->status,
			'log'=> htmlspecialchars($response_decode->verb)
		);
	}

	/**
	 * Display raw backup and file list
	 *
	 * @return string
	 */
	public function show_raw_backup_and_file_list() {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		/*
			Need to remove the pre tags as the modal assumes a <pre> is for a new box.
			This cause issues specifically with fetch log events. Do this by passing true
			to the method show_raw_backups
		 */
		
		$response = $backupplus_admin->show_raw_backups(true);

		return $response['html'];
	}

	public function search_replace($query) {

		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		$_POST = $query;
		
		ob_start();

		do_action('backupplus_adminaction_searchreplace', $query);
		
		$response = array('log' => ob_get_clean());
		
		return $response;
	}

	/**
	 * A handler method to call the BackupPlus admin auth_remote_method
	 *
	 * @param Array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return Array An Array response to be sent back
	 */
	public function auth_remote_method($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		$response = $backupplus_admin->auth_remote_method($data);
		return $response;
	}

	/**
	 * A handler method to call the BackupPlus admin deauth_remote_method
	 *
	 * @param Array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return Array An Array response to be sent back
	 */
	public function deauth_remote_method($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');
		$response = $backupplus_admin->deauth_remote_method($data);
		return $response;
	}

	/**
	 * A handler method to call the relevant remote storage manual authentication methods and return the authentication result
	 *
	 * @param array $data - an array of authentication data, normally includes the state and auth code
	 *
	 * @return array - an array response to be sent back to the frontend
	 */
	public function manual_remote_storage_authentication($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.

		$response = array(
			'result' => 'success'
		);

		$method = $data['method'];

		$enabled_services = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids(array($method));
		
		if (empty($enabled_services[$method]['object']) || empty($enabled_services[$method]['instance_settings']) || !$enabled_services[$method]['object']->supports_feature('manual_authentication')) {
			$response['result'] = 'error';
			$response['data'] = __('Manual authentication is not available for this remote storage method', 'backupplus') . '(' . $method . ')';
			return $response;
		}

		$backup_obj = $enabled_services[$method]['object'];

		$auth_data = json_decode(base64_decode($data['auth_data']), true);
		$instance_id = '';

		$state = isset($auth_data['state']) ? urldecode($auth_data['state']) : '';
		$code = isset($auth_data['code']) ? urldecode($auth_data['code']) : '';

		if (empty($code) && isset($auth_data['access_token']) && isset($auth_data['user_id'])) {
			// If there is no code, but the access_token and user_id is set then this is for Google Drive so create a code array using these values
			$access_token = urldecode($auth_data['access_token']);
			$user_id = urldecode($auth_data['user_id']);
			$code = array(
				'access_token' => $access_token,
				'user_id' => $user_id
			);
		} elseif (empty($code) && isset($auth_data['token'])) {
			// If there is no code, but a token is set then this is for OneDrive so assign token to code
			$encoded_token = stripslashes($auth_data['token']);
			$token = json_decode($encoded_token);
			$code = $token;
		}

		if (empty($state) || empty($code)) {
			$response['result'] = 'error';
			$response['data'] = __('Missing authentication data:', 'backupplus') . " ({$state}) ({$code})";
			return $response;
		}

		if (false !== strpos($state, ':')) {
			$parts = explode(':', $state);
			$instance_id = $parts[1];
		}

		if (empty($instance_id)) {
			$response['result'] = 'error';
			$response['data'] = __('Missing instance id:', 'backupplus') . " ($state)";
			return $response;
		}

		if (isset($enabled_services[$method]['instance_settings'][$instance_id])) {
			$opts = $enabled_services[$method]['instance_settings'][$instance_id];
			$backup_obj->set_options($opts, false, $instance_id);
		}

		$result = $backup_obj->complete_authentication($state, $code, true);
		
		$response['data'] = $result;

		return $response;
	}
	
	/**
	 * This function will add some needed filters in order to be able to send a local backup to remote storage it will then boot the backup process.
	 *
	 * @param array $data - data sent from the front end, it includes the backup timestamp and nonce
	 *
	 * @return array      - the response to be sent back to the front end
	 */
	public function upload_local_backup($data) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');
		
		add_filter('backupplus_initial_jobdata', array($backupplus_admin, 'upload_local_backup_jobdata'), 10, 3);
		add_filter('backupplus_get_backup_file_basename_from_time', array($backupplus_admin, 'upload_local_backup_name'), 10, 3);
		
		$background_operation_started_method_name = empty($data['background_operation_started_method_name']) ? '_backupplus_background_operation_started' : $data['background_operation_started_method_name'];

		$msg = array(
			'nonce' => $data['use_nonce'],
			'm' => apply_filters('backupplus_backupnow_start_message', '<strong>'.__('Start backup', 'backupplus').':</strong> '.htmlspecialchars(__('OK.', 'backupplus').' '.__('You should soon see activity in the "Last log message" field below.', 'backupplus')), $data['use_nonce'])
		);

		$close_connection_callable = array($this->_uc_helper, $background_operation_started_method_name);

		if (is_callable($close_connection_callable)) {
			call_user_func($close_connection_callable, $msg);
		} else {
			$backupplus->close_browser_connection(json_encode($msg));
		}

		do_action('backupplus_backupnow_backup_all', apply_filters('backupplus_backupnow_options', $data, array()));

		// Control returns when the backup finished; but, the browser connection should have been closed before
		die;
	}

	/**
	 * This function will get the restore resume notice
	 *
	 * @param array $params - the parameters for the call
	 *
	 * @return array|WP_Error - the response array that includes the restore resume notice
	 */
	public function get_restore_resume_notice($params) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		$job_id = empty($params['job_id']) ? '' : $params['job_id'];

		$response = array(
			'status' => 'success',
		);

		if (empty($job_id)) return new WP_Error('missing_parameter', 'Missing parameters.');

		$html = $backupplus_admin->get_restore_resume_notice($job_id);

		if (is_wp_error($html)) return $html;

		$response['html'] = $html;

		return $response;
	}

	/**
	 * This function will add backupplus_dismiss_admin_warning_litespeed option to hide litespeed admin warning after dismissed
	 *
	 * @return array - an empty array
	 */
	public function dismiss_admin_warning_litespeed() {
		BackupPlus_Options::update_backupplus_option('backupplus_dismiss_admin_warning_litespeed', true);
		return array();
	}
	
	/**
	 * This function will add backupplus_dismiss_admin_warning_pclzip option to hide pclzip admin warning after dismissed
	 *
	 * @return array - an empty array
	 */
	public function dismiss_admin_warning_pclzip() {
		BackupPlus_Options::update_backupplus_option('backupplus_dismiss_admin_warning_pclzip', true);
		return array();
	}

	/**
	 * This function is for importing settings via RPC
	 *
	 * @param  Array $settings - The settings data to be imported
	 * @return Array An Array response to be sent back
	 */
	public function import_settings($settings) {
		if (false === ($backupplus_admin = $this->_load_ud_admin()) || false === ($backupplus = $this->_load_ud())) return new WP_Error('no_backupplus');
		
		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		if (empty($settings) || !is_array($settings)) {
			return new WP_Error('invalid_settings', 'Invalid settings data provided');
		}

		try {
			$result = $backupplus_admin->import_settings($settings, true);

			if (is_array($result)) {
				if (isset($result['saved']) && !$result['saved'] && !empty($result['error_message'])) {
					return new WP_Error('import_failed', $result['error_message']);
				}
				
				return $result;
			} else {
				return new WP_Error('unexpected_response', 'Unexpected response format from import_settings');
			}
		} catch (Exception $e) {
			return new WP_Error('import_failed', $e->getMessage());
		}
	}

	/**
	 * This function is for updating site information
	 *
	 * @param array $params Parameters containing site info to update
	 * @return array|WP_Error Response or error
	 */
	public function update_site_info($params) {
		if (false === $this->_load_ud_admin()) return new WP_Error('no_backupplus');

		if (!BackupPlus_Options::user_can_manage()) return new WP_Error('backupplus_permission_denied');

		// Update site title
		if (isset($params['site_title'])) {
			$site_title = sanitize_text_field($params['site_title']);
			update_option('blogname', $site_title);
		}

		// Update tagline
		if (isset($params['tagline'])) {
			$tagline = sanitize_text_field($params['tagline']);
			update_option('blogdescription', $tagline);
		}

		// Update admin email
		if (isset($params['admin_email'])) {
			$admin_email = sanitize_email($params['admin_email']);
			
			// Validate email format
			if (!is_email($admin_email)) {
				return new WP_Error('invalid_email', __('Invalid email address format', 'backupplus'));
			}
			
			update_option('admin_email', $admin_email);
		}

		return array(
			'message' => __('Site information updated successfully', 'backupplus')
		);
	}

	/**
	 * Get advanced tools data in a structured format
	 *
	 * @param array $param The form data.
	 *
	 * @return array|WP_Error Structured data or error
	 */
	public function get_structured_data($param) {
		if (false === ($backupplus_admin = $this->_load_ud_admin())) return new WP_Error('no_backupplus');

		$tool_type = $param['tool_type'];

		// Site Info
		if ('site-information' === $tool_type) {
			$site_info = $backupplus_admin->get_site_info_data();
			$site_info['site_title'] = get_bloginfo('name');
			$site_info['tagline'] = get_bloginfo('description');
			$site_info['admin_email'] = get_bloginfo('admin_email');

			return array('site_info' => $site_info);
		}

		// Directory Sizes
		if ('site-size' === $tool_type) {
			$site_size = array();
			if (false !== ($backupplus = $this->_load_ud())) {
				$backupable_entities = $backupplus->get_backupable_file_entities(true, true);
				foreach ($backupable_entities as $entity => $info) {
					$size = BackupPlus_Filesystem_Functions::get_disk_space_used($entity, 'numeric');

					$size                 = is_numeric($size) ? $size : 0;
					$site_size[$entity] = array(
						'size'           => $size,
						'size_formatted' => size_format($size)
					);
				}
			}

			return array('site_size' => $site_size);
		}

		// Database Size Information
		if ('db-size' === $tool_type) {
			backupplus_try_include_file('includes/class-wpadmin-commands.php', 'include_once');

			$db_size = array('size' => '0 B');
			if (class_exists('BackupPlus_WPAdmin_Commands')) {
				$wpadmin_commands = new BackupPlus_WPAdmin_Commands($this->_uc_helper);
				$db_size_result   = $wpadmin_commands->db_size(true);
				if (!is_wp_error($db_size_result)) {
					$db_size = $db_size_result;
				}
			}

			return array('db_size' => $db_size);
		}

		return new WP_Error('invalid_tool_type', __('Invalid tool type', 'backupplus'));
	}
}
