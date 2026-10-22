<?php
/**
 * BackupPlus Provider: WebDAV
 * Provider ID: webdav
 * Provider Class: BackupPlus_BackupModule_webdav
 */

if (!defined('ABSPATH')) die('No direct access allowed.');

// Preserve settings from installations using the original add-on option name.
if (!is_array(BackupPlus_Options::get_backupplus_option('backupplus_webdav')) && '' != BackupPlus_Options::get_backupplus_option('backupplus_webdav_settings', '')) {
	BackupPlus_Options::update_backupplus_option('backupplus_webdav', BackupPlus_Options::get_backupplus_option('backupplus_webdav_settings'));
	BackupPlus_Options::delete_backupplus_option('backupplus_webdav_settings');
}

if (!class_exists('BackupPlus_BackupModule')) backupplus_try_include_file('methods/backup-module.php', 'require_once');

class BackupPlus_BackupModule_webdav extends BackupPlus_BackupModule {
	protected $input_option_field_mappings = array(
		'webdav' => array('default_value' => 'https://', 'contexts' => array('option', 'input')),
		'host' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'port' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'user' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'pass' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'path' => array('default_value' => '', 'contexts' => array('option', 'input')),
	);

	public function get_supported_features() {
		return array('multi_options', 'config_templates', 'multi_storage', 'conditional_logic');
	}

	private function normalise_options($options) {
		backupplus_try_include_file('includes/webdav.class.php', 'require_once');
		$options = BackupPlus_WebDAV_Client::normalise_options($options);
		$options['webdav'] = str_replace(array('webdavs', 'webdav'), array('https', 'http'), strtolower($options['webdav']));
		if (in_array($options['webdav'], array('http', 'https'), true)) $options['webdav'] .= '://';
		return $options;
	}

	public function options_exist($opts) {
		$opts = $this->normalise_options($opts);
		return '' !== $opts['host'];
	}

	public function options_filter($settings) {
		if (is_array($settings) && !empty($settings['version']) && !empty($settings['settings']) && is_array($settings['settings'])) {
			foreach ($settings['settings'] as $id => $options) {
				if (is_array($options)) $settings['settings'][$id] = $this->normalise_options($options);
			}
		}
		return $settings;
	}

	public function transform_options_for_template($opts) {
		return parent::transform_options_for_template($this->normalise_options($opts));
	}

	/** Use job TLS settings during backups and unsaved form settings during tests. */
	private function connect($options = null) {
		global $backupplus;
		$saved_options = null === $options;
		if ($saved_options && $this->get_storage()) return $this->get_storage();
		$tls = array();
		foreach (array('nossl', 'disableverify', 'useservercerts') as $key) {
			$tls[$key] = null !== $options && isset($options[$key]) ? $options[$key] : $backupplus->get_job_option('backupplus_ssl_'.$key);
		}
		$options = $this->normalise_options(null === $options ? $this->get_options() : $options);
		$client = BackupPlus_WebDAV_Client::open($options, $tls);
		if ($saved_options && !is_wp_error($client)) $this->set_storage($client);
		return $client;
	}

	/** Log storage errors and expose the boolean contract used by backup/restore. */
	private function succeeded($result) {
		if (!is_wp_error($result)) return true;
		$this->log($result->get_error_message(), 'error');
		return false;
	}

	public function backup($backup_array) {
		global $backupplus;
		$client = $this->connect();
		if (!$this->succeeded($client)) return false;
		$success = true;
		foreach ($backup_array as $file) {
			if (!BackupPlus_WebDAV_Client::valid_filename($file)) {
				$this->log(__('Invalid backup filename.', 'backupplus'), 'error');
				$success = false;
				continue;
			}
			$local = $backupplus->backups_dir_location().'/'.$file;
			clearstatcache(true, $local);
			$size = is_file($local) && is_readable($local) ? filesize($local) : false;
			if (false === $size) {
				$this->log(sprintf(__('Cannot read backup file: %s', 'backupplus'), $file), 'error');
				$success = false;
				continue;
			}
			$last_percent = -1;
			$progress = function($sent) use ($backupplus, $file, $size, &$last_percent) {
				$percent = $size ? min(100, (int) floor(100 * $sent / $size)) : 100;
				if ($percent > $last_percent) {
					$backupplus->record_uploaded_chunk($percent, $file, $this->get_id());
					$last_percent = $percent;
				}
			};
			if ($this->succeeded($client->upload($file, $local, $progress))) {
				$backupplus->uploaded_file($file);
			} else {
				$success = false;
			}
		}
		return $success ? array('webdav' => true) : false;
	}

	public function listfiles($match = 'backup_') {
		$client = $this->connect();
		if (is_wp_error($client)) return $client;
		$files = $client->listing();
		if (is_wp_error($files)) return $files;
		return array_values(array_filter($files, function($file) use ($match) {
			return false !== strpos($file['name'], $match);
		}));
	}

	public function download($file) {
		global $backupplus;
		$client = $this->connect();
		if (!$this->succeeded($client)) return false;
		if (!BackupPlus_WebDAV_Client::valid_filename($file)) return false;
		return $this->succeeded($client->download($file, $backupplus->backups_dir_location().'/'.$file));
	}

	public function delete($files, $data = false, $sizeinfo = array()) {
		$client = $this->connect();
		if (!$this->succeeded($client)) return false;
		$success = true;
		foreach ((array) $files as $file) {
			if (!$this->succeeded($client->delete($file))) $success = false;
		}
		return $success;
	}

	/** Verify listing, upload, readback and cleanup at the unsaved destination. */
	public function credentials_test($posted_settings) {
		$this->set_connection_status(false);
		$client = $this->connect($posted_settings);
		$result = is_wp_error($client) ? $client : $client->listing();
		if (is_wp_error($result)) {
			echo esc_html(__('Failure:', 'backupplus').' '.$result->get_error_message());
			return;
		}
		$name = 'backupplus-test-'.wp_generate_password(24, false).'.tmp';
		$local = wp_tempnam($name);
		if (!$local) {
			echo esc_html(__('Failure: Unable to create a local test file.', 'backupplus'));
			return;
		}
		try {
			$contents = 'BackupPlus WebDAV test: '.$name;
			$result = false === file_put_contents($local, $contents) ? new WP_Error('webdav_test_write', __('Unable to write the local test file.', 'backupplus')) : $client->upload($name, $local);
			if (!is_wp_error($result)) $result = $client->download($name, $local);
			if (!is_wp_error($result) && $contents !== file_get_contents($local)) $result = new WP_Error('webdav_test_content', __('The WebDAV test file contents did not match.', 'backupplus'));
			$cleanup = $client->delete($name);
			if (!is_wp_error($result)) $result = $cleanup;
			if (is_wp_error($result)) {
				echo esc_html(__('Failure:', 'backupplus').' '.$result->get_error_message());
			} else {
				$this->set_connection_status(true);
				echo esc_html(__('Success: Connected and verified directory listing, upload, download and deletion.', 'backupplus'));
			}
		} finally {
			unlink($local);
		}
	}

	public function get_template_properties() {
		$properties = array(
			/* translators: %s: Storage method name. */
			'input_test_label' => sprintf(__('Test %s Settings', 'backupplus'), $this->get_description()),
			'input_webdav_label' => __('Protocol', 'backupplus'),
			'input_webdav_option_labels' => array('https://' => 'HTTPS', 'http://' => 'HTTP'),
			'input_host_label' => __('WebDAV server', 'backupplus'),
			'input_host_placeholder' => 'cloud.example.com',
			'input_port_label' => __('Port (optional)', 'backupplus'),
			'input_port_type' => 'number',
			'input_port_min_value' => 1,
			'input_port_max_value' => 65535,
			'input_user_label' => __('Username', 'backupplus'),
			'input_pass_label' => __('Password', 'backupplus'),
			'input_pass_type' => apply_filters('backupplus_admin_secret_field_type', 'password'),
			'input_path_label' => __('Remote path', 'backupplus'),
			'input_path_placeholder' => 'remote.php/dav/files/username/backups',
			'input_path_tooltip' => __('The directory must already exist. Enter the path as text, without URL encoding. Leave empty to use the server root.', 'backupplus'),
		);
		return wp_parse_args($properties, $this->get_persistent_variables_and_methods());
	}

	public function get_pre_configuration_template() {
		echo '<tr class="{{get_template_css_classes false}}"><td colspan="2"><h3>{{method_display_name}}</h3></td></tr>';
	}

	public function get_configuration_template() {
		ob_start();
		foreach ($this->get_input_option_mappings('input') as $field => $mapping) {
			?>
			<tr class="{{get_template_css_classes true}}">
				<th><label for="{{get_template_input_attribute_value "id" "<?php echo esc_attr($field); ?>"}}">{{input_<?php echo esc_html($field); ?>_label}}:</label></th>
				<td>
				<?php if ('webdav' === $field) { ?>
					<select data-backupplus_settings_test="webdav" id="{{get_template_input_attribute_value "id" "webdav"}}" name="{{get_template_input_attribute_value "name" "webdav"}}">
					{{#each input_webdav_option_labels}}<option value="{{@key}}" {{#ifeq @key ../webdav}}selected="selected"{{/ifeq}}>{{this}}</option>{{/each}}
					</select>
				<?php } else {
					$type = 'pass' === $field ? '{{input_pass_type}}' : ('port' === $field ? 'number' : 'text');
					?>
					<input class="backupplus_input--wide" type="<?php echo esc_attr($type); ?>" data-backupplus_settings_test="<?php echo esc_attr($field); ?>" id="{{get_template_input_attribute_value "id" "<?php echo esc_attr($field); ?>"}}" name="{{get_template_input_attribute_value "name" "<?php echo esc_attr($field); ?>"}}" value="{{<?php echo esc_html($field); ?>}}" placeholder="{{input_<?php echo esc_attr($field); ?>_placeholder}}" autocomplete="off" <?php if ('port' === $field) echo 'min="1" max="65535"'; ?> />
				<?php } ?>
				<?php if ('path' === $field) { ?><p><em>{{input_path_tooltip}}</em></p><?php } ?>
				</td>
			</tr>
			<?php
		}
		echo '{{{get_template_test_button_html "WebDAV"}}}';
		return ob_get_clean();
	}
}
