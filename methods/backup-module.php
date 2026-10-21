<?php

if (!defined('ABSPATH')) die('No direct access allowed.');

abstract class BackupPlus_BackupModule {

	private $_options;

	private $_instance_id;

	private $_storage;

	/**
	 * Indicates whether the connection test was successful.
	 *
	 * @var bool
	 */
	protected $is_connection_successful = false;

	/**
	 * Input and option field mappings with default values and supported contexts.
	 * N.B. This variable/property would normally be over-ridden by the child.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array();
	
	/**
	 * Store options (within this class) for this remote storage module. There is also a parameter for saving to the permanent storage (i.e. database).
	 *
	 * @param  array       $options     array of options to store
	 * @param  Boolean     $save        whether or not to also save the options to the database
	 * @param  null|String $instance_id optionally set the instance ID for this instance at the same time. This is required if you have not already set an instance ID with set_instance_id()
	 * @return void|Boolean If saving to DB, then the result of the DB save operation is returned.
	 */
	public function set_options($options, $save = false, $instance_id = null) {
	
		$this->_options = $options;
		
		// Remove any previously-stored storage object, because this is usually tied to the options
		if (!empty($this->_storage)) unset($this->_storage);

		if ($instance_id) $this->set_instance_id($instance_id);
		
		if ($save) return $this->save_options();

	}
	
	/**
	 * Saves the current options to the database. This is a private function; external callers should use set_options().
	 *
	 * @throws Exception if trying to save options without indicating an instance_id, or if the remote storage module does not have the multi-option capability
	 */
	private function save_options() {
	
		if (!$this->supports_feature('multi_options')) {
			throw new Exception('save_options() can only be called on a storage method which supports multi_options (this module, '.$this->get_id().', does not)'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}
	
		if (!$this->_instance_id) {
			throw new Exception('save_options() requires an instance ID, but was called without setting one (either directly or via set_instance_id())');
		}
		
		$current_db_options = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format($this->get_id());

		if (is_wp_error($current_db_options)) {
			throw new Exception('save_options(): options fetch/update failed ('.$current_db_options->get_error_code().': '.$current_db_options->get_error_message().')'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}

		$current_db_options['settings'][$this->_instance_id] = $this->_options;

		$updated = BackupPlus_Options::update_backupplus_option('backupplus_'.$this->get_id(), $current_db_options);
		return $updated || BackupPlus_Options::get_backupplus_option('backupplus_'.$this->get_id()) === $current_db_options;
	
	}
	
	/**
	 * Retrieve default options for this remote storage module.
	 *
	 * @return array - an array of options
	 */
	public function get_default_options() {
		$options = $this->get_input_option_mappings('option');

		$defaults = array();

		foreach ($options as $key => $option) {
			$defaults[$key] = $option['default_value'];
		}

		return $defaults;
	}

	/**
	 * Retrieve persistent class variables and/or methods (the ones that don't get changed during runtime) and transform them into a list of template properties
	 *
	 * @return Array an associative array keyed by names of the corresponding variables/methods, the keys might not exactly be the same with the name of the variables/methods
	 */
	protected function get_persistent_variables_and_methods() {
		global $backupplus;
		return array(
			'css_class' => 'backupplusmethod',
			'is_multi_options_feature_supported' => $this->supports_feature('multi_options'),
			'is_config_templates_feature_supported' => $this->supports_feature('config_templates'),
			'is_conditional_logic_feature_supported' => $this->supports_feature('conditional_logic'),
			'is_multi_servers_feature_supported' => $this->supports_feature('multi_servers'),
			'method_id' => $this->get_id(),
			'_instance_id' => $this->_instance_id,
			'method_display_name' => $backupplus->backup_methods[$this->get_id()],
			'admin_page_url' => BackupPlus_Options::admin_page_url(),
			'storage_auth_nonce' =>wp_create_nonce('storage_auth_nonce'),
			'input_select_folder_label' => __('Select existing folder', 'backupplus'),
			'input_confirm_label' => __('Confirm', 'backupplus'),
			'input_cancel_label' => __('Cancel', 'backupplus'),
			'hostname_error_label' => __('Error:', 'backupplus').' '.__('A host name cannot be an URL or contain a slash.', 'backupplus'),
		);
	}

	/**
	 * Get all persistent variables and methods across the modules (this could mean the child including its parent), also the necessary required HTML element attributes and texts which are unique to each child
	 * NOTE: Since this method would normally be over-ridden by the child, please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses())
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		return array();
	}

	/**
	 * List all allowed HTML tags for content sanitisation
	 *
	 * @return Array an associatve array keyed by name of the allowed HTML tags
	 */
	protected function allowed_html_for_content_sanitisation() {
		return array(
			'a' => array(
				'href' => array(),
				'title' => array(),
				'target' => array(),
			),
			'br' => array(),
			'em' => array(),
			'strong' => array(),
			'p' => array(),
			'div' => array(
				'class' => array(),
			),
			'kbd' => array(),
		);
	}

	/**
	 * Get partial templates associated to the corresponding backup module (remote storage object)
	 * N.B. This method would normally be over-ridden by the child.
	 *
	 * @return Array an associative array keyed by name of the partial templates
	 */
	public function get_partial_templates() {
		return array();
	}
	
	/**
	 * Check whether options have been set up by the user, or not
	 * This method would normally be over-ridden by the child.
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist($opts) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the caller uses 1 argument and normally this method will be over-ridden by the child class.
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
	 * This method should only be called if the feature 'multi storage' is supported. In that case, it returns a template with information about the remote storage. The code below is a placeholder, and methods supporting the feature should always over-ride it.
	 *
	 * @return String - HTML template
	 */
	public function get_pre_configuration_template() {
		return $this->get_id().": called, but not implemented in the child class (coding error)";
	}

	/**
	 * This method should only be called if the feature 'config templates' is supported. In that case, it returns a template with appropriate placeholders for specific settings. The code below is a placeholder, and methods supporting the feature should always over-ride it.
	 *
	 * @return String - HTML template
	 */
	public function get_configuration_template() {
		return $this->get_id().": called, but not implemented in the child class (coding error)";
	}

	/**
	 * This method will set the stored storage object to that indicated
	 *
	 * @param Object $storage - the storage client
	 */
	public function set_storage($storage) {
		$this->_storage = $storage;
	}

	/**
	 * This method will return the stored storage client
	 *
	 * @return Object - the stored remote storage client
	 */
	public function get_storage() {
		if (!empty($this->_storage)) return $this->_storage;
	}
	
	/**
	 * Outputs id and name fields, as if currently within an input tag
	 *
	 * This assumes standardised options handling (i.e. that the options array is backupplus_(method-id))
	 *
	 * @param Array|String $field                  - the field identifiers
	 * @param Boolean      $return_instead_of_echo - tells the method if it should return the output or echo it to page
	 */
	public function output_settings_field_name_and_id($field, $return_instead_of_echo = false) {
	
		$method_id = $this->get_id();
		
		$instance_id = $this->supports_feature('config_templates') ? '{{instance_id}}' : $this->_instance_id;
		
		$id = '';
		$name = '';

		if (is_array($field)) {
			foreach ($field as $value) {
				$id .= '_'.$value;
				$name .= '['.$value.']';
			}
		} else {
			$id .= '_'.$field;
			$name = '['.$field.']';
		}
		
		$output = "id=\"backupplus_{$method_id}{$id}_{$instance_id}\" name=\"backupplus_{$method_id}[settings][{$instance_id}]{$name}\" ";

		if ($return_instead_of_echo) {
			return $output;
		} else {
			echo wp_kses_post($output);
		}
	}
	
	/**
	 * Get the CSS ID
	 *
	 * @param String $field - the field identifier to return a CSS ID for
	 *
	 * @return String
	 */
	public function get_css_id($field) {
		$method_id = $this->get_id();
		$instance_id = $this->supports_feature('config_templates') ? '{{instance_id}}' : $this->_instance_id;
		return "backupplus_{$method_id}_{$field}_{$instance_id}";
	}
	
	/**
	 * Get handlebarsjs template
	 * This deals with any boiler-plate, prior to calling config_print()
	 *
	 * @uses self::config_print()
	 * @uses self::get_configuration_template()
	 *
	 * return handlebarsjs template or html
	 */
	public function get_template() {
		ob_start();
		// Allow methods to not use this hidden field, if they do not output any settings (to prevent their saved settings being over-written by just this hidden field)
		if ($this->print_shared_settings_fields()) {
			?>
		}
		
		if ($this->supports_feature('config_templates')) {
			?>
			{{#if first_instance}}
			<?php
				
				$this->get_pre_configuration_template();
				
				if ($this->supports_feature('multi_storage')) {
				do_action('backupplus_config_print_add_multi_storage', $this->get_id(), $this);
				}
				
			?>
			{{/if}}
			<?php
			do_action('backupplus_config_print_before_storage', $this->get_id(), $this);
			do_action('backupplus_config_print_add_conditional_logic', $this->get_id(), $this);
			if ($this->supports_feature('multi_storage')) {
				do_action('backupplus_config_print_add_instance_label', $this->get_id(), $this);
			}

			$template = ob_get_clean();
			$template .= $this->get_configuration_template();
		} else {
			do_action('backupplus_config_print_before_storage', $this->get_id(), $this);
			do_action('backupplus_config_print_add_conditional_logic', $this->get_id(), $this);
			// N.B. These are mutually exclusive: config_print() is not used if config_templates is supported. So, even during transition, the BackupPlus_BackupModule instance only needs to support one of the two, not both.
			$this->config_print();
			$template = ob_get_clean();
		}
		return $template;
	}
	
	/**
	 * Modifies handerbar template options. Other child class can extend it.
	 *
	 * @param array $opts
	 * @return Array - Modified handerbar template options
	 */
	public function transform_options_for_template($opts) {
		return $opts;
	}
	
	/**
	 * Gives settings keys which values should not passed to handlebarsjs context.
	 * The settings stored in UD in the database sometimes also include internal information that it would be best not to send to the front-end (so that it can't be stolen by a man-in-the-middle attacker)
	 *
	 * @return Array - Settings array keys which should be filtered
	 */
	public function filter_frontend_settings_keys() {
		return array();
	}

	/**
	 * Over-ride this to allow methods to not use the hidden version field, if they do not output any settings (to prevent their saved settings being over-written by just this hidden field
	 *
	 * @return [boolean] - return true to output the version field or false to not output the field
	 */
	public function print_shared_settings_fields() {
		return true;
	}

	/**
	 * Prints out the configuration section for a particular module. This is now (Sep 2017) considered deprecated; things are being ported over to get_configuration_template(), indicated via the feature 'config_templates'.
	 */
	public function config_print() {
		echo esc_html($this->get_id()).": module neither declares config_templates support, nor has a config_print() method (coding bug)";
	}

	/**
	 * Supplies the list of keys for options to be saved in the backup job.
	 *
	 * @return Array
	 */
	public function get_credentials() {
		$keys = array('backupplus_ssl_disableverify', 'backupplus_ssl_nossl', 'backupplus_ssl_useservercerts');
		if (!$this->supports_feature('multi_servers')) $keys[] = 'backupplus_'.$this->get_id();
		return $keys;
	}
	
	/**
	 * Returns a space-separated list of CSS classes suitable for rows in the configuration section
	 *
	 * @param Boolean $include_instance - a boolean value to indicate if we want to include the instance_id in the css class, we may not want to include the instance if it's for a UI element that we don't want to be removed along with other UI elements that do include a instance id.
	 *
	 * @returns String - the list of CSS classes
	 */
	public function get_css_classes($include_instance = true) {
		$classes = 'backupplusmethod '.$this->get_id();
		if (!$include_instance) return $classes;
		if ($this->supports_feature('multi_options')) {
			if ($this->supports_feature('config_templates')) {
				$classes .= ' '.$this->get_id().'-{{instance_id}}';
			} else {
				$classes .= ' '.$this->get_id().'-'.$this->_instance_id;
			}
		}
		return $classes;
	}
	
	/**
	 *
	 * Returns HTML for a row for a test button
	 *
	 * @param String $title - The text to be used in the button
	 *
	 * @returns String - The HTML to be inserted into the settings page
	 */
	protected function get_test_button_html($title) {
		ob_start();
		$instance_id = $this->supports_feature('config_templates') ? '{{instance_id}}' : $this->_instance_id;
		?>
		<tr class="<?php echo esc_attr($this->get_css_classes()); ?>">
			<th></th>
			<?php /* translators: %s: Remote storage method */ ?>
			<td><p><button id="backupplus-<?php echo esc_attr($this->get_id());?>-test-<?php echo esc_attr($instance_id);?>" type="button" class="button-primary backupplus-test-button backupplus-<?php echo esc_attr($this->get_id());?>-test" data-instance_id="<?php echo esc_attr($instance_id);?>" data-method="<?php echo esc_attr($this->get_id());?>" data-method_label="<?php echo esc_attr($title);?>"><?php echo esc_html(sprintf(__('Test %s Settings', 'backupplus'), $title));?></button></p></td>
		</tr>
		<?php
		return ob_get_clean();
	}
	
	/**
	 * Get the backup method identifier for this class
	 *
	 * @return String - the identifier
	 */
	public function get_id() {
		$class = get_class($this);
		return substr($class, strlen(__CLASS__) + 1);
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

		return isset($methods[$id]) ? $methods[$id] : $id;
	}

	/**
	 * Sets the instance ID - for supporting multi_options
	 *
	 * @param String $instance_id - the instance ID
	 */
	public function set_instance_id($instance_id) {
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
	public function supports_feature($feature) {
		return in_array($feature, $this->get_supported_features());
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
	
		$supports_multi_options = $this->supports_feature('multi_options');

		if (is_array($this->_options)) {
			// First, prioritise any options that were explicitly set. This is the eventual goal for all storage modules.
			$options = $this->_options;
			
		} elseif (is_callable(array($this, 'get_opts'))) {
			// Next, get any options available via a legacy / over-ride method.
		
			if ($supports_multi_options) {
				// This is forbidden, because get_opts() is legacy and is for methods that do not support multi-options. Supporting multi-options leads to the array format being updated, which will then break get_opts().
				die('Fatal error: method '.esc_html($this->get_id()).' both supports multi_options and provides a get_opts method');
			}
			
			$options = $this->get_opts();
			
		} else {

			// Next, look for job options (which in turn, falls back to saved settings if no job options were set)
	
			$options = $backupplus->get_job_option('backupplus_'.$this->get_id());
			if (!is_array($options)) $options = array();

			if ($supports_multi_options) {

				if (!isset($options['version'])) {
					$options_full = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format($this->get_id());
					
					if (is_wp_error($options_full)) {
						$backupplus->log("Options retrieval failure: ".$options_full->get_error_code().": ".$options_full->get_error_message()." (".json_encode($options_full->get_error_data()).")");
						return array();
					}
					
				} else {
					$options_full = $options;
				}
				
				// BackupPlus_BackupModule::get_options() is for getting the current instance's options. So, this branch (going via the job option) is a legacy route, and hence we just give back the first one. The non-legacy route is to call the set_options() method externally.
				$options = (isset($options_full['settings']) && is_array($options_full['settings'])) ? reset($options_full['settings']) : false;

				if (false === $options) {
					$backupplus->log("Options retrieval failure (no options set)");
					return array();
				}
				$instance_id = key($options_full['settings']);
				$this->set_options($options, false, $instance_id);
				
			}
			
		}

		$options = apply_filters(
			'backupplus_backupmodule_get_options',
			wp_parse_args($options, $this->get_default_options()),
			$this
		);
		
		return $options;
		
	}
	
	/**
	 * Set job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_set()
	 *
	 * @param String $key	- the key for the job data
	 * @param Mixed  $value - the data to be stored
	 */
	public function jobdata_set($key, $value) {
	
		$instance_key = $this->get_id().'-'.($this->_instance_id ? $this->_instance_id : 'no_instance');
		
		global $backupplus;
		
		$instance_data = $backupplus->jobdata_get($instance_key);
		
		if (!is_array($instance_data)) $instance_data = array();
		
		$instance_data[$key] = $value;
		
		$backupplus->jobdata_set($instance_key, $instance_data);
		
	}

	/**
	 * Get job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_get()
	 *
	 * @param String	  $key		  - the key for the job data
	 * @param Mixed		  $default	  - the default to return if nothing was set
	 * @param String|Null $legacy_key - the previous name of the key, prior to instance-specific job data (so that upgrades across versions whilst a backup is in progress can still find its data). In future, support for this can be removed.
	 */
	public function jobdata_get($key, $default = null, $legacy_key = null) {
	
		$instance_key = $this->get_id().'-'.($this->_instance_id ? $this->_instance_id : 'no_instance');
		
		global $backupplus;
		
		$instance_data = $backupplus->jobdata_get($instance_key);
		
		if (is_array($instance_data) && isset($instance_data[$key])) return $instance_data[$key];
		
		return is_string($legacy_key) ? $backupplus->jobdata_get($legacy_key, $default) : $default;
		
	}
	
	/**
	 * Delete job data that is local to this storage instance
	 * (i.e. the key does not need to be unique across instances)
	 *
	 * @uses BackupPlus::jobdata_set()
	 *
	 * @param String	  $key		  - the key for the job data
	 * @param String|Null $legacy_key - the previous name of the key, prior to instance-specific job data (so that upgrades across versions whilst a backup is in progress can still find its data)
	 */
	public function jobdata_delete($key, $legacy_key = null) {
	
		$instance_key = $this->get_id().'-'.($this->_instance_id ? $this->_instance_id : 'no_instance');
		
		global $backupplus;
		
		$instance_data = $backupplus->jobdata_get($instance_key);
		
		if (is_array($instance_data) && isset($instance_data[$key])) {
			unset($instance_data[$key]);
			$backupplus->jobdata_set($instance_key, $instance_data);
		}
		
		if (is_string($legacy_key)) $backupplus->jobdata_delete($legacy_key);
		
	}

	/**
	 * This method will either return or echo the constructed auth link for the remote storage method
	 *
	 * @param Boolean $echo_instead_of_return     - a boolean to indicate if the authentication link should be echo or returned
	 * @param Boolean $template_instead_of_notice - a boolean to indicate if the authentication link is for a template or a notice
	 * @return Void|String                        - returns a string or nothing depending on the parameters
	 */
	public function get_authentication_link($echo_instead_of_return = true, $template_instead_of_notice = true) {
		if (!$echo_instead_of_return) {
			ob_start();
		}

		$account_warning = '';
		$description = $this->get_description();

		if ($this->output_account_warning()) {
			$account_warning = __('Ensure you are logged into the correct account before continuing.', 'backupplus');
		}

		if ($template_instead_of_notice) {
			$instance_id = "{{instance_id}}";
			/* translators: %s: Description */
			$text = sprintf(__("<strong>After</strong> you have saved your settings (by clicking 'Save Changes' below), then come back here and follow this link to complete authentication with %s.", 'backupplus'), $description);
		} else {
			$instance_id = $this->get_instance_id();
			/* translators: 1: Description or remote storage name (e.g. Google Drive, OneDrive, etc.), 2: Description or remote storage name */
			$text = sprintf(__('Follow this link to authorize access to your %1$s account (you will not be able to backup to %2$s without it).', 'backupplus'), $description, $description);
		}

		echo esc_html($account_warning) . ' ' . wp_kses_post($this->build_authentication_link($instance_id, $text));

		if (!$echo_instead_of_return) {
			return ob_get_clean();
		}
	}

	/**
	 * This function will build and return the authentication link
	 *
	 * @param String $instance_id - the instance id
	 * @param String $text        - the link text
	 *
	 * @return String - the authentication link
	 */
	public function build_authentication_link($instance_id, $text) {
		
		$id = $this->get_id();
		
		if (!preg_match('/^[-A-Z0-9]+$/i', $instance_id)) return '';
		
		return '<a class="backupplus_authlink" href="'.BackupPlus_Options::admin_page_url().'?&action=backupplusmethod-'.$id.'-auth&page=backupplus&backupplus_'.$id.'auth=doit&nonce='.wp_create_nonce('storage_auth_nonce').'&backupplus_instance='.$instance_id.'" data-instance_id="'.$instance_id.'" data-remote_method="'.$id.'">'.$text.'</a>';
	}
	
	/**
	 * Check the authentication is valid before proceeding to call the authentication method
	 */
	public function action_authenticate_storage() {
		$backupplus_auth = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'BackupPlus_'.$this->get_id().'auth');
		$backupplus_instance = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'backupplus_instance');
		$nonce = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'nonce');
		
		if (isset($backupplus_auth) && 'doit' == $backupplus_auth && !empty($backupplus_instance) && preg_match('/^[-A-Z0-9]+$/i', $backupplus_instance) && isset($nonce) && wp_verify_nonce($nonce, 'storage_auth_nonce')) {
			$this->set_connection_status(false);
			$this->authenticate_storage((string) $backupplus_instance);
		}
	}
	
	/**
	 * Authenticate the remote storage and save settings
	 *
	 * @param String $instance_id - The remote storage instance id
	 */
	public function authenticate_storage($instance_id) {
		if (method_exists($this, 'do_authenticate_storage')) {
			$this->do_authenticate_storage($instance_id);
		} else {
			BackupPlus_Manipulation_Functions::error_log($this->get_id().": module does not have an authenticate storage method (coding bug)");
		}
	}
	
	/**
	 * This method will either return or echo the constructed deauth link for the remote storage method
	 *
	 * @param  Boolean $echo_instead_of_return - a boolean to indicate if the deauthentication link should be echo or returned
	 * @return Void|String                     - returns a string or nothing depending on the parameters
	 */
	public function get_deauthentication_link($echo_instead_of_return = true) {
		if (!$echo_instead_of_return) {
			ob_start();
		}
		
		$id = $this->get_id();
		$description = $this->get_description();

		/* translators: %s: Description */
		echo ' <a class="backupplus_deauthlink" href="'.esc_url(BackupPlus_Options::admin_page_url().'?action=backupplusmethod-'.$id.'-auth&page=backupplus&backupplus_'.$id.'auth=deauth&nonce='.wp_create_nonce($id.'_deauth_nonce').'&backupplus_instance={{instance_id}}').'" data-instance_id="{{instance_id}}" data-remote_method="'.esc_attr($id).'">'.esc_html(sprintf(__("Follow this link to remove these settings for %s.", 'backupplus'), $description)).'</a>';

		if (!$echo_instead_of_return) {
			return ob_get_clean();
		}
	}
	
	/**
	 * Check the deauthentication is valid before proceeding to call the deauthentication method
	 */
	public function action_deauthenticate_storage() {
		$backupplus_auth = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'BackupPlus_'.$this->get_id().'auth');
		$backupplus_instance = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'backupplus_instance');
		$nonce = BackupPlus_Manipulation_Functions::fetch_superglobal('get', 'nonce');

		if (isset($backupplus_auth) && 'deauth' == $backupplus_auth && !empty($nonce) && !empty($backupplus_instance) && preg_match('/^[-A-Z0-9]+$/i', $backupplus_instance) && wp_verify_nonce($nonce, $this->get_id().'_deauth_nonce')) {
			$this->deauthenticate_storage($backupplus_instance);
		}
	}
	
	/**
	 * Deauthenticate the remote storage and remove the saved settings
	 *
	 * @param String $instance_id - The remote storage instance id
	 */
	public function deauthenticate_storage($instance_id) {
		if (method_exists($this, 'do_deauthenticate_storage')) {
			$this->do_deauthenticate_storage($instance_id);
		}
		$opts = $this->get_default_options();
		$this->set_options($opts, true, $instance_id);
	}

	/**
	 * Get the manual authorisation template
	 *
	 * @return String - the template
	 */
	public function get_manual_authorisation_template() {

		$id = $this->get_id();
		$description = $this->get_description();

		$template = "<div id='backupplus_manual_authorisation_template_{$id}'>";
		/* translators: %s: Description */
		$template .= "<strong>".sprintf(__('%s authentication:', 'backupplus'), $description)."</strong>";
		/* translators: %s: Description */
		$template .= "<p>".sprintf(__('If you are having problems authenticating with %s you can manually authorize here.', 'backupplus'), $description)."</p>";
		$template .= "<p>".__('To complete manual authentication, at the orange BackupPlus authentication screen select the "Having problems authenticating?" link, then copy and paste the code given here.', 'backupplus')."</p>";
		/* translators: %s: Description */
		$template .= "<label for='backupplus_manual_authentication_data_{$id}'>".sprintf(__('%s authentication code:', 'backupplus'), $description)."</label> <input type='text' id='backupplus_manual_authentication_data_{$id}' name='backupplus_manual_authentication_data_{$id}'>";
		$template .= "<p id='backupplus_manual_authentication_error_{$id}'></p>";
		$template .= "<button type='button' data-method='{$id}' class='button button-primary' id='backupplus_manual_authorisation_submit_{$id}'>".__('Complete manual authentication', 'backupplus')."</button>";
		$template .= '<span class="backupplus_spinner spinner">' . __('Processing', 'backupplus') . '...</span>';
		$template .= "</div>";

		return $template;
	}

	/**
	 * This will call the remote storage methods complete authentication function
	 *
	 * @param string $state - the remote storage authentication state
	 * @param string $code  - the remote storage authentication code
	 *
	 * @return String - returns a string response
	 */
	public function complete_authentication($state, $code) {
		if (method_exists($this, 'do_complete_authentication')) {
			return $this->do_complete_authentication($state, $code, true);
		} else {
			$message = $this->get_id().": module does not have an complete authentication method (coding bug)";
			BackupPlus_Manipulation_Functions::error_log($message);
			return $message;
		}
	}

	/**
	 * Over-ride this to allow methods to output extra information about using the correct account for OAuth storage methods
	 *
	 * @return Boolean - return false so that no extra information is output
	 */
	public function output_account_warning() {
		return false;
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
	public function log($line, $level = 'notice', $uniq_id = false, $skip_dblog = false) {
		global $backupplus;

		$prefix = $this->get_storage_label();

		$backupplus->log("$prefix: $line", $level, $uniq_id, $skip_dblog);
	}

	/**
	 * Log appropriate messages for a multi-delete response.
	 *
	 * @param Array $files
	 * @param Array $responses - using the same keys as $files
	 *
	 * @return Boolean - true if no errors were found, otherwise false
	 */
	protected function process_multi_delete_responses($files, $responses) {
		global $backupplus;
		$ret = true;
		if (is_array($responses)) {
			foreach ($responses as $key => $response) {
				if ('success' == $response) {
					$backupplus->log("$files[$key]: Delete succeeded");
				} elseif (is_array($response)) {
					$ret = false;
					if (isset($response['error']) && isset($response['error']['code']) && isset($response['error']['message'])) {
						$backupplus->log("Delete failed for file: $files[$key] with error code: ".$response['error']['code']." message: ".$response['error']['message']);
					} else {
						$backupplus->log("Delete failed for file: $files[$key]");
					}
				}
			}
		} elseif (!$responses) {
			$ret = false;
			$backupplus->log("Delete failed for files: ".implode($files));
		}
		return $ret;
	}
	
	/**
	 * This function will build and return the remote storage instance label
	 *
	 * @return String - the remote storage instance label
	 */
	private function get_storage_label() {
		
		$opts = $this->get_options();
		$label = isset($opts['instance_label']) ? $opts['instance_label'] : '';

		$description = $this->get_description();

		if (!empty($label)) {
			$prefix = (false !== strpos($label, $description)) ? $label : "$description: $label";
		} else {
			$prefix = $description;
		}

		return $prefix;
	}

	/**
	 * This method will output any needed js for the JSTree.
	 *
	 * @return void
	 */
	public function admin_footer_jstree() {
		static $script_output = array(); // Static array to store script output status.

		$id = $this->get_id();
	
		// Check if the script has already been output for this ID.
		if (isset($script_output[$id])) return;

		// wp_add_inline_script() is available on WP 4.5+
		if (function_exists('wp_add_inline_script')) {
			wp_add_inline_script('backupplus-admin-common', "var js_tree_".esc_js($id)." = new backupplus_js_tree('".esc_js($id)."'); js_tree_".esc_js($id).".init();", 'after');
		} elseif (wp_script_is('backupplus-admin-common', 'done')) {
		"'''"?>
			<script>
				var js_tree_<?php echo esc_js($id); ?> = new backupplus_js_tree('<?php echo esc_js($id); ?>');
				js_tree_<?php echo esc_js($id); ?>.init();
			</script>
		<?php
		}
		// Mark the script as output for this ID.
		$script_output[$id] = true;
	}

	/**
	 * Set the remote storage connection status.
	 *
	 * @param bool $status True if connection is successful, false otherwise.
	 *
	 * @return void
	 */
	public function set_connection_status($status) {
		$this->is_connection_successful = (bool) $status;
		do_action('backupplus_remote_storage_connection_status_changed', $status, $this);
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
	 * Transform template properties into field array structures.
	 *
	 * Builds an array of field definitions based on the template properties
	 * and configured fields. Supports text, password, and dropdown field
	 * types, including placeholders, tooltips, and options.
	 *
	 * This method is already onboarding-oriented; it processes all properties in the template that were added using the naming convention starting with the 'input_' prefix.
	 * Format: "input_{fieldName}_{attribute}"
	 * fieldName or inputName refers to data added in the $input_option_field_mappings class variable in each remote storage module
	 * attribute is essential metadata accompanying a field, defining its characteristics or behaviour. The list of supported attributes:
	 * id, default, type, label, placeholder, prefix, tooltip, option_labels
	 *
	 * NOTE: Not all variables in the template properties consistently follow the naming convention/format, so mapping might be necessary to avoid introducing new translation strings and/or redundancies.
	 *
	 * @return array List of field definitions.
	 */
	public function transform_template_properties_to_fields_structure() {
		$fields = array();
		$template_property_input_mappings = $this->get_input_option_mappings('input');

		if (empty($template_property_input_mappings)) return $fields;

		$template_properties = $this->get_template_properties();

		foreach ($template_property_input_mappings as $field_name => $option) {
			$field = array(
				'id' => $field_name,
				'default' => $option['default_value'],
				'type' => 'text',
			);

			if (!isset($option['template_property_input_mapping'])) $option['template_property_input_mapping'] = $field_name;
			$prefix = 'input_'.$option['template_property_input_mapping'].'_';

			if (isset($template_properties[$prefix.'type'])) $field['type'] = $template_properties[$prefix.'type'];

			switch ($field['type']) {
				case 'password':
					$field['show_forgot_password'] = false;
					break;
				case 'number':
					if (isset($template_properties[$prefix.'min_value'])) $field['min'] = $template_properties[$prefix.'min_value'];
					if (isset($template_properties[$prefix.'max_value'])) $field['max'] = $template_properties[$prefix.'max_value'];
					break;
			}

			if (isset($template_properties[$prefix.'label'])) $field['label'] = $template_properties[$prefix.'label'];

			if (isset($template_properties[$prefix.'placeholder'])) $field['placeholder'] = $template_properties[$prefix.'placeholder'];

			if (isset($template_properties[$prefix.'prefix'])) $field['prefix'] = $template_properties[$prefix.'prefix'];

			if (isset($template_properties[$prefix.'tooltip'])) $field['tooltip'] = array('text' => wp_kses_post($template_properties[$prefix.'tooltip']));

			if (isset($template_properties[$prefix.'option_labels'])) {
				$field['type'] = 'dropdown';
				$options = array();
				foreach ($template_properties[$prefix.'option_labels'] as $key => $value) {
					$options[] = array(
						'value' => $key,
						'label' => $value,
					);
				}
				$field['options'] = $options;
			}

			if (method_exists($this, 'configure_field_from_legacy')) $field = $this->configure_field_from_legacy($field, $template_properties, $field_name, $option);

			if ($field) $fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Retrieve field mappings, optionally filters mappings by context.
	 *
	 * @param string $context Optional. Context name used to filter mappings.
	 *
	 * @return array
	 */
	public function get_input_option_mappings($context = '') {
		if (empty($context)) return $this->input_option_field_mappings;
		$filtered_mappings = array();

		foreach ($this->input_option_field_mappings as $key => $mapping) {
			if (in_array($context, $mapping['contexts'])) $filtered_mappings[$key] = $mapping;
		}

		return $filtered_mappings;
	}
}
