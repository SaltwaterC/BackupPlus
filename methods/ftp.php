<?php
/**
 * BackupPlus Provider: FTP
 * Provider ID: ftp
 * Provider Class: BackupPlus_BackupModule_ftp
 */

if (!defined('ABSPATH')) die('No direct access allowed.');

// Converted to array options: yes
// Converted to job_options: yes

// Migrate options to new-style storage - May 2014
if (!is_array(BackupPlus_Options::get_backupplus_option('backupplus_ftp')) && '' != BackupPlus_Options::get_backupplus_option('backupplus_server_address', '')) {
	$opts = array(
		'user' => BackupPlus_Options::get_backupplus_option('backupplus_ftp_login'),
		'pass' => BackupPlus_Options::get_backupplus_option('backupplus_ftp_pass'),
		'host' => BackupPlus_Options::get_backupplus_option('backupplus_server_address'),
		'path' => BackupPlus_Options::get_backupplus_option('backupplus_ftp_remote_path'),
		'passive' => true
	);
	BackupPlus_Options::update_backupplus_option('backupplus_ftp', $opts);
	BackupPlus_Options::delete_backupplus_option('backupplus_server_address');
	BackupPlus_Options::delete_backupplus_option('backupplus_ftp_pass');
	BackupPlus_Options::delete_backupplus_option('backupplus_ftp_remote_path');
	BackupPlus_Options::delete_backupplus_option('backupplus_ftp_login');
}

if (!class_exists('BackupPlus_BackupModule')) backupplus_try_include_file('methods/backup-module.php', 'require_once');

class BackupPlus_BackupModule_ftp extends BackupPlus_BackupModule {
	
	/**
	 * Input and option field mappings with default values and supported contexts.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array(
		'host' => array(
			'default_value' => '',
			'contexts' => array('option', 'input'),
		),
		'encryption' => array(
			'default_value' => 'none',
			'contexts' => array('option', 'input'),
		),
		'user' => array(
			'default_value' => '',
			'contexts' => array('option', 'input'),
		),
		'pass' => array(
			'default_value' => '',
			'template_property_input_mapping' => 'password',
			'contexts' => array('option', 'input'),
		),
		'path' => array(
			'default_value' => '',
			'contexts' => array('option', 'input'),
		),
		'passive' => array(
			'default_value' => 1,
			'contexts' => array('option', 'input'),
		),
	);

	/**
	 * Get FTP object with parameters set
	 *
	 * @param  String  $server 			 Specify Server
	 * @param  String  $user 			 Specify Username
	 * @param  String  $pass 			 Specify Password
	 * @param  Boolean $disable_ssl		 Indicate whether to disable SSL
	 * @param  Boolean $disable_verify	 Indicate whether to disable certificate verification
	 * @param  Boolean $use_server_certs Indicate whether to use the system CA store instead of the bundled CA file
	 * @param  Boolean $passive 		 Indicate whether to use passive FTP mode
	 * @param  String  $encryption       Connection encryption: none or tls (explicit FTPS)
	 * @return BackupPlus_ftp_wrapper|WP_Error
	 */
	private function getFTP($server, $user, $pass, $disable_ssl = false, $disable_verify = false, $use_server_certs = false, $passive = true, $encryption = 'none') {
		$settings = $this->normalise_connection_settings(array('host' => $server, 'encryption' => $encryption));
		$server = $settings['host'];
		$encryption = $settings['encryption'];

		/* translators: %s: Storage method name */
		if ('' == trim($server) || '' == trim($user) || '' == trim($pass)) return new WP_Error('no_settings', sprintf(__('No %s settings were found', 'backupplus'), 'FTP'));

		if (!in_array($encryption, array('none', 'tls'), true)) return new WP_Error('ftp_invalid_encryption', __('Unsupported FTP encryption setting.', 'backupplus'));
		if ('tls' === $encryption && $disable_ssl) return new WP_Error('ftp_ssl_disabled', __('FTPS requires SSL/TLS. Uncheck the option to disable SSL in the expert settings, then try again.', 'backupplus'));
		if (!class_exists('BackupPlus_ftp_wrapper')) backupplus_try_include_file('includes/ftp.class.php', 'include_once');
		$missing = BackupPlus_ftp_wrapper::get_support_errors('tls' === $encryption);
		/* translators: %s: Required PHP cURL functions or libcurl capabilities. */
		if ($missing) return new WP_Error('ftp_missing_support', sprintf(__('Your PHP installation needs the following to use this FTP connection: %s.', 'backupplus'), implode(', ', $missing)));

		$port = 21;
		if (preg_match('/^(.*):(\d+)$/', $server, $matches)) {
			$server = $matches[1];
			$port = $matches[2];
		}

		$ftp = new BackupPlus_ftp_wrapper($server, $user, $pass, $port);

		$ftp->ssl = ('tls' === $encryption);
		$ftp->use_server_certs = $use_server_certs;
		$ftp->disable_verify = $disable_verify;
		$ftp->passive = ($passive) ? true : false;

		return $ftp;

	}

	/**
	 * Normalise host prefixes for saved settings, job options and unsaved tests.
	 * A secure prefix always requests TLS, even when the encryption field is absent.
	 *
	 * @param Array $settings - connection settings
	 * @return Array
	 */
	private function normalise_connection_settings($settings) {
		if (!empty($settings['host']) && preg_match('#^ftp(es|s)?://(.*)$#i', trim($settings['host']), $matches)) {
			$settings['host'] = rtrim($matches[2], "/ \t\n\r\0x0B");
			if (!empty($matches[1])) $settings['encryption'] = 'tls';
		}
		if (!isset($settings['encryption'])) $settings['encryption'] = 'none';
		return $settings;
	}
	
	/**
	 * WordPress options filter, sanitising the FTP options saved from the options page
	 *
	 * @param Array $settings - the options, prior to sanitisation
	 *
	 * @return Array - the sanitised options for saving
	 */
	public function options_filter($settings) {
		if (is_array($settings) && !empty($settings['version']) && !empty($settings['settings'])) {
			foreach ($settings['settings'] as $instance_id => $instance_settings) {
				$settings['settings'][$instance_id] = $this->normalise_connection_settings($instance_settings);
				if (isset($instance_settings['pass'])) {
					$settings['settings'][$instance_id]['pass'] = trim($instance_settings['pass'], "\n\r\0\x0B");
				}
			}
		}
		return $settings;
	}
	
	public function get_supported_features() {
		// The 'multi_options' options format is handled via only accessing options via $this->get_options()
		return array('multi_options', 'config_templates', 'multi_storage', 'conditional_logic');
	}

	/**
	 * Retrieve a list of template properties by taking all the persistent variables and methods of the parent class and combining them with the ones that are unique to this module, also the necessary HTML element attributes and texts which are also unique only to this backup module
	 * NOTE: Please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses())
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		global $backupplus;
		$possible = $this->ftp_possible();
		$ftp_not_possible = array();
		if (is_array($possible)) {
			$trans = array(
				'ftp' => __('regular non-encrypted FTP', 'backupplus'),
				'ftpsslimplicit' => __('encrypted FTP (implicit encryption)', 'backupplus'),
				'ftpsslexplicit' => __('encrypted FTP (explicit encryption)', 'backupplus')
			);
			foreach ($possible as $type => $missing) {
				$ftp_not_possible[] = wp_kses(
					'<strong>'.__('Warning', 'backupplus').':</strong> '.sprintf(
						/* translators: %s: Missing PHP cURL functions or libcurl capabilities. */
						__('Your PHP installation is missing the following: %s.', 'backupplus'),
						implode(', ', $missing)
					).' '.
					sprintf(
						/* translators: %s: Storage method name requiring enabled functions */
						__('Your hosting company must enable this support before %s can work.', 'backupplus'),
						$trans[$type]
					),
					$this->allowed_html_for_content_sanitisation()
				);
			}
		}
		$properties = array(
			'backupplus_sftp_ftps_notice' => wp_kses(apply_filters('backupplus_sftp_ftps_notice', __('FTP and FTPS with explicit TLS are supported. FTPS server certificates are verified unless verification is disabled in the expert settings. Implicit FTPS is not supported.', 'backupplus')), $this->allowed_html_for_content_sanitisation()),
			'ftp_not_possible_warnings' => $ftp_not_possible,
			'input_host_label' => __('FTP server', 'backupplus'),
			'input_host_placeholder' => __('Example: ftp.example.com', 'backupplus'),
			'input_encryption_label' => __('Encryption', 'backupplus'),
			'input_encryption_option_labels' => array(
				'none' => __('None (plain FTP)', 'backupplus'),
				'tls' => __('FTPS (explicit TLS)', 'backupplus'),
			),
			'input_user_label' => __('FTP login', 'backupplus'),
			'input_user_placeholder' => __('Enter your username', 'backupplus'),
			'input_password_label' => __('FTP password', 'backupplus'),
			'input_password_type' => apply_filters('backupplus_admin_secret_field_type', 'password'),
			'input_path_label' => __('Remote path', 'backupplus'),
			'input_path_title' => __('Needs to already exist', 'backupplus'),
			'input_path_placeholder' => __('Example: /public_html/backups', 'backupplus'),
			'input_passive_label' => __('Passive mode', 'backupplus'),
			'input_passive_title' => __('Almost all FTP servers will want passive mode; but if you need active mode, then uncheck this.', 'backupplus'),
			'input_passive_type' => 'checkbox',
			/* translators: %s: Backup method name */
			'input_test_label' => sprintf(__('Test %s Settings', 'backupplus'), $backupplus->backup_methods[$this->get_id()])
		);
		return wp_parse_args($properties, $this->get_persistent_variables_and_methods());
	}
	
	public function backup($backup_array) {

		global $backupplus;

		$opts = $this->get_options();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$backupplus->get_job_option('backupplus_ssl_nossl'),
			$backupplus->get_job_option('backupplus_ssl_disableverify'),
			$backupplus->get_job_option('backupplus_ssl_useservercerts'),
			$opts['passive'],
			$opts['encryption']
		);

		if (is_wp_error($ftp) || !$ftp->connect()) {
			if (is_wp_error($ftp)) {
				$backupplus->log_wp_error($ftp);
			} else {
				$this->log('FTP connection failed: '.$ftp->get_error());
			}
			$this->log(__("login failure", 'backupplus'), 'error');
			return false;
		}

		// $ftp->make_dir(); we may need to recursively create dirs? TODO

		$backupplus_dir = $backupplus->backups_dir_location().'/';

		$ftp_remote_path = '' === $opts['path'] ? '' : trailingslashit($opts['path']);
		foreach ($backup_array as $file) {
			$fullpath = $backupplus_dir.$file;
			$this->log("upload attempt: $file -> ".($ftp->ssl ? 'ftps' : 'ftp')."://".$opts['user']."@".$opts['host']."/{$ftp_remote_path}{$file}");
			$timer_start = microtime(true);
			$size_k = round(filesize($fullpath)/1024, 1);
			// Note :Setting $resume to true unnecessarily is not meant to be a problem. Only ever (Feb 2014) seen one weird FTP server where calling SIZE on a non-existent file did create a problem. So, this code just helps that case. (the check for non-empty upload_status[p] is being cautious.
			$upload_status = $backupplus->jobdata_get('uploading_substatus');
			if (0 == $backupplus->current_resumption || (is_array($upload_status) && !empty($upload_status['p']) && 0 == $upload_status['p'])) {
				$resume = false;
			} else {
				$resume = true;
			}

			if ($ftp->put($fullpath, $ftp_remote_path.$file, BackupPlus_ftp_wrapper::BINARY, $resume, $backupplus)) {
				$this->log("upload attempt successful (".$size_k."KB in ".(round(microtime(true)-$timer_start, 2)).'s)');
				$backupplus->uploaded_file($file);
			} else {
				$this->log("ERROR: FTP upload failed");
				$this->log(__("upload failed", 'backupplus'), 'error');
			}
		}

		return array('ftp_object' => $ftp, 'ftp_remote_path' => $ftp_remote_path);
	}

	public function listfiles($match = 'backup_') {
		global $backupplus;

		$opts = $this->get_options();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$backupplus->get_job_option('backupplus_ssl_nossl'),
			$backupplus->get_job_option('backupplus_ssl_disableverify'),
			$backupplus->get_job_option('backupplus_ssl_useservercerts'),
			$opts['passive'],
			$opts['encryption']
		);

		if (is_wp_error($ftp)) return $ftp;

		/* translators: %s: Storage method name */
		if (!$ftp->connect()) return new WP_Error('ftp_login_failed', sprintf(__("%s connection failure", 'backupplus'), 'FTP').': '.$ftp->get_error());

		$ftp_remote_path = $opts['path'];
		if ($ftp_remote_path) $ftp_remote_path = trailingslashit($ftp_remote_path);

		$dirlist = $ftp->dir_list($ftp_remote_path);
		if (!is_array($dirlist)) return new WP_Error('ftp_list_failed', __('FTP directory listing failed.', 'backupplus').' '.$ftp->get_error());

		$results = array();

		foreach ($dirlist as $k => $path) {

			if ($ftp_remote_path) {
				// Feb 2015 - found a case where the directory path was not prefixed on
				if (0 !== strpos($path, $ftp_remote_path) && (false !== strpos('/', $ftp_remote_path) && false !== strpos('\\', $ftp_remote_path))) continue;
				if (0 === strpos($path, $ftp_remote_path)) $path = substr($path, strlen($ftp_remote_path));
				// if (0 !== strpos($path, $ftp_remote_path)) continue;
				// $path = substr($path, strlen($ftp_remote_path));
				if (0 === strpos($path, $match)) $results[]['name'] = $path;
			} else {
				if ('/' == substr($path, 0, 1)) $path = substr($path, 1);
				if (false !== strpos($path, '/')) continue;
				if (0 === strpos($path, $match)) $results[]['name'] = $path;
			}

			unset($dirlist[$k]);
		}

		// NLST does not return file sizes, so retrieve them separately.
		foreach ($results as $ind => $name) {
			$size = $ftp->size($ftp_remote_path.$name['name']);
			if (0 === $size) {
				unset($results[$ind]);
			} elseif ($size>0) {
				$results[$ind]['size'] = $size;
			}
		}

		return $results;

	}

	/**
	 * Delete a single file from the service using FTP protocols
	 *
	 * @param Array $files    - array of file names to delete
	 * @param Array $ftparr   - FTP details/credentials
	 * @param Array $sizeinfo - unused here
	 * @return Boolean|String - either a boolean true or an error code string
	 */
	public function delete($files, $ftparr = array(), $sizeinfo = array()) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $sizeinfo unused

		global $backupplus;
		if (is_string($files)) $files = array($files);

		$opts = $this->get_options();

		if (is_array($ftparr) && isset($ftparr['ftp_object'])) {
			$ftp = $ftparr['ftp_object'];
		} else {
			$ftp = $this->getFTP(
				$opts['host'],
				$opts['user'],
				$opts['pass'],
				$backupplus->get_job_option('backupplus_ssl_nossl'),
				$backupplus->get_job_option('backupplus_ssl_disableverify'),
				$backupplus->get_job_option('backupplus_ssl_useservercerts'),
				$opts['passive'],
				$opts['encryption']
			);

			if (is_wp_error($ftp) || !$ftp->connect()) {
				if (is_wp_error($ftp)) {
					$backupplus->log_wp_error($ftp);
				} else {
					$this->log('FTP connection failed: '.$ftp->get_error());
				}
				$this->log("Failure: we did not successfully log in with those credentials (host=".$opts['host'].").");
				return 'authentication_fail';
			}

		}

		$ftp_remote_path = isset($ftparr['ftp_remote_path']) ? $ftparr['ftp_remote_path'] : ('' === $opts['path'] ? '' : trailingslashit($opts['path']));

		$ret = true;
		foreach ($files as $file) {
			if (@$ftp->delete($ftp_remote_path.$file)) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				$this->log("delete: succeeded ({$ftp_remote_path}{$file})");
			} else {
				$this->log("delete: failed ({$ftp_remote_path}{$file})");
				$ret = 'file_delete_error';
			}
		}
		return $ret;

	}

	public function download($file) {

		global $backupplus;

		$opts = $this->get_options();

		$ftp = $this->getFTP(
			$opts['host'],
			$opts['user'],
			$opts['pass'],
			$backupplus->get_job_option('backupplus_ssl_nossl'),
			$backupplus->get_job_option('backupplus_ssl_disableverify'),
			$backupplus->get_job_option('backupplus_ssl_useservercerts'),
			$opts['passive'],
			$opts['encryption']
		);

		if (is_wp_error($ftp)) {
			$this->log('Failure to get FTP object: '.$ftp->get_error_code().': '.$ftp->get_error_message());
			$this->log($ftp->get_error_message().' ('.$ftp->get_error_code().')', 'error');
			return false;
		}

		if (!$ftp->connect()) {
			$this->log('FTP connection failed: '.$ftp->get_error());
			$this->log(__('login failure', 'backupplus'), 'error');
			return false;
		}

		// $ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = '' === $opts['path'] ? '' : trailingslashit($opts['path']);
		$fullpath = $backupplus->backups_dir_location().'/'.$file;

		$resume = false;
		if (file_exists($fullpath)) {
			$resume = true;
			$this->log("File already exists locally; will resume: size: ".filesize($fullpath));
		}

		return $ftp->get($fullpath, $ftp_remote_path.$file, BackupPlus_ftp_wrapper::BINARY, $resume, $backupplus);
	}

	private function ftp_possible() {
		if (!class_exists('BackupPlus_ftp_wrapper')) backupplus_try_include_file('includes/ftp.class.php', 'include_once');
		$funcs_disabled = array();
		$missing = BackupPlus_ftp_wrapper::get_support_errors();
		if ($missing) $funcs_disabled['ftp'] = $missing;
		$missing = BackupPlus_ftp_wrapper::get_support_errors(true);
		if ($missing) $funcs_disabled['ftpsslexplicit'] = $missing;
		$funcs_disabled = apply_filters('backupplus_ftp_possible', $funcs_disabled);
		return (0 == count($funcs_disabled)) ? true : $funcs_disabled;
	}

	/**
	 * Get the pre configuration template
	 *
	 * @return String - the template
	 */
	public function get_pre_configuration_template() {
		?>
		<tr class="{{get_template_css_classes false}} ftp_pre_config_container">
			<td colspan="2">
				<h3>{{method_display_name}}</h3>
				{{#each ftp_not_possible_warnings}}
					<div class="error backupplusmethod ftp"><p>{{{this}}}</p></div>
					<div class="notice error below-h2"><p>{{{this}}}</p></div>
				{{/each}} 
				{{#ifCond "undefined" "not_typeof" backupplus_sftp_ftps_notice}}
				<em><p>{{{backupplus_sftp_ftps_notice}}}</p></em>
				{{/ifCond}}
			</td>
		</tr>
		<?php
	}

	/**
	 * Get the configuration template
	 *
	 * @return String - the template, ready for substitutions to be carried out
	 */
	public function get_configuration_template() {

		ob_start();
	
		?>
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_host_label}}:</th>
			<td><input class="backupplus_ftp_host_settings backupplus_input--wide" type="text" size="40" data-backupplus_settings_test="host" id="{{get_template_input_attribute_value "id" "host"}}" name="{{get_template_input_attribute_value "name" "host"}}" value="{{host}}" />
			<br>
			<em class="backupplus_ftp_host_error" style="display: none;">{{hostname_error_label}}</em>
			</td>
		</tr>
		
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_encryption_label}}:</th>
			<td><select data-backupplus_settings_test="encryption" id="{{get_template_input_attribute_value "id" "encryption"}}" name="{{get_template_input_attribute_value "name" "encryption"}}">
				{{#each input_encryption_option_labels}}
				<option value="{{@key}}" {{#ifeq @key ../encryption}}selected="selected"{{/ifeq}}>{{this}}</option>
				{{/each}}
			</select></td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th>{{input_user_label}}:</th>
			<td><input class="backupplus_input--wide" type="text" size="40" data-backupplus_settings_test="user" id="{{get_template_input_attribute_value "id" "user"}}" name="{{get_template_input_attribute_value "name" "user"}}" value="{{user}}" /></td>
		</tr>
		
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_password_label}}:</th>
			<td><input class="backupplus_input--wide" type="{{input_password_type}}" size="40" data-backupplus_settings_test="pass" id="{{get_template_input_attribute_value "id" "pass"}}" name="{{get_template_input_attribute_value "name" "pass"}}" value="{{pass}}" /></td>
		</tr>
		
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_path_label}}:</th>
			<td><input title="{{input_path_title}}" class="backupplus_input--wide" type="text" size="64" data-backupplus_settings_test="path" id="{{get_template_input_attribute_value "id" "path"}}" name="{{get_template_input_attribute_value "name" "path"}}" value="{{path}}" /> <em>{{input_path_title}}</em></td>
		</tr>
		
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_passive_label}}:</th>
			<td>
			<input title="{{input_passive_title}}" type="checkbox" data-backupplus_settings_test="passive" id="{{get_template_input_attribute_value "id" "passive"}}" name="{{get_template_input_attribute_value "name" "passive"}}" value="1" {{#ifeq '1' passive}}checked="checked"{{/ifeq}}> <br><em>{{input_passive_title}}</em></td>
		</tr>
		
		{{{get_template_test_button_html "FTP"}}}
		<?php
		return ob_get_clean();
		
	}

	/**
	 * Perform a test of user-supplied credentials, and echo the result
	 *
	 * @param Array $posted_settings - settings to test
	 */
	public function credentials_test($posted_settings) {

		$server = $posted_settings['host'];
		$login = $posted_settings['user'];
		$pass = $posted_settings['pass'];
		$path = $posted_settings['path'];
		$nossl = $posted_settings['nossl'];
		$passive = empty($posted_settings['passive']) ? false : true;
		
		$disable_verify = $posted_settings['disableverify'];
		$use_server_certs = $posted_settings['useservercerts'];

		if (empty($server)) {
			esc_html_e('Failure: No server details were given.', 'backupplus');
			return;
		}
		if (empty($login)) {
			/* translators: %s: Missing credential type (e.g., login) */
			echo esc_html(sprintf(__('Failure: No %s was given.', 'backupplus'), __('login', 'backupplus')));
			return;
		}
		if (empty($pass)) {
			/* translators: %s: Missing credential type (e.g., password) */
			echo esc_html(sprintf(__('Failure: No %s was given.', 'backupplus'), __('password', 'backupplus')));
			return;
		}

		$encryption = isset($posted_settings['encryption']) ? $posted_settings['encryption'] : 'none';
		$ftp = $this->getFTP($server, $login, $pass, $nossl, $disable_verify, $use_server_certs, $passive, $encryption);
		if (is_wp_error($ftp)) {
			echo esc_html($ftp->get_error_message());
			return;
		}

		if (!$ftp->connect()) {
			echo esc_html(__('Failure: unable to connect to the FTP server.', 'backupplus').' '.$ftp->get_error());
			return;
		}
		// $ftp->make_dir(); we may need to recursively create dirs? TODO

		$file = md5(wp_rand(0, 99999999)).'.tmp';
		$fullpath = ('' === $path ? '' : trailingslashit($path)).$file;
		
		if ($ftp->put(ABSPATH.WPINC.'/version.php', $fullpath, BackupPlus_ftp_wrapper::BINARY, false, true)) {
			echo esc_html(__("Success: we successfully logged in, and confirmed our ability to create a file in the given directory (login type:", 'backupplus')." ".$ftp->login_type.')');
			@$ftp->delete($fullpath);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			$this->set_connection_status(true);
		} else {
			esc_html_e('Failure: we successfully logged in, but were not able to create a file in the given directory.', 'backupplus');
			echo ' '.esc_html($ftp->get_error());
			if (!empty($ftp->ssl)) {
				echo ' '.esc_html__('Check that your FTP server and firewall allow encrypted data connections.', 'backupplus');
			}
		}
	}

	/**
	 * Check whether options have been set up by the user, or not
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist($opts) {
		if (is_array($opts) && !empty($opts['host']) && isset($opts['user']) && '' != $opts['user']) return true;
		return false;
	}
	
	/**
	 * Customize generated field data using legacy mapping values.
	 *
	 * Used by transform_template_properties_to_fields_structure()
	 * to allow child classes to adjust the generated field structure
	 * based on legacy data and field mapping requirements.
	 *
	 * @param array  $field               Field data.
	 * @param array  $template_properties Template properties.
	 * @param string $field_name          Field name.
	 * @param array  $option              Field mapping option.
	 *
	 * @return array
	 */
	public function configure_field_from_legacy($field, $template_properties, $field_name, $option) {
		$prefix = 'input_'.$option['template_property_input_mapping'].'_';

		if (empty($field['tooltip']) && isset($template_properties[$prefix.'title'])) $field['tooltip'] = array('text' => $template_properties[$prefix.'title']);

		return $field;
	}
}
