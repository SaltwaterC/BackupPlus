<?php
/**
 * BackupPlus Provider: SFTP / SCP
 * Provider ID: sftp
 * Provider Class: BackupPlus_BackupModule_sftp
 */

if (!defined('ABSPATH')) die('No direct access allowed.');

// Preserve settings from installations using the original add-on option name.
if (!is_array(BackupPlus_Options::get_backupplus_option('backupplus_sftp')) && '' != BackupPlus_Options::get_backupplus_option('backupplus_sftp_settings', '')) {
	BackupPlus_Options::update_backupplus_option('backupplus_sftp', BackupPlus_Options::get_backupplus_option('backupplus_sftp_settings'));
	BackupPlus_Options::delete_backupplus_option('backupplus_sftp_settings');
}

if (!class_exists('BackupPlus_BackupModule')) backupplus_try_include_file('methods/backup-module.php', 'require_once');

class BackupPlus_BackupModule_sftp extends BackupPlus_BackupModule {
	public function normalize_imported_settings($settings) {
		foreach (array('updraft_sftp_settings', 'backupplus_sftp_settings') as $old_key) {
			if (!array_key_exists($old_key, $settings)) continue;
			if (!array_key_exists('backupplus_sftp', $settings)) $settings['backupplus_sftp'] = $settings[$old_key];
			unset($settings[$old_key]);
		}
		return $settings;
	}

	protected $input_option_field_mappings = array(
		'host' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'port' => array('default_value' => 22, 'contexts' => array('option', 'input')),
		'protocol' => array('default_value' => 'sftp', 'contexts' => array('option', 'input')),
		'user' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'pass' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'key' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'key_passphrase' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'host_key' => array('default_value' => '', 'contexts' => array('option', 'input')),
		'path' => array('default_value' => '', 'contexts' => array('option', 'input')),
	);

	public function get_supported_features() {
		return array('phpseclib', 'multi_options', 'config_templates', 'multi_storage', 'conditional_logic');
	}

	public function options_exist($opts) {
		return is_array($opts) && !empty($opts['host']) && isset($opts['user']) && '' !== $opts['user'];
	}

	/** Normalise saved settings and unsaved connection tests identically. */
	private function normalise_options($options) {
		$options = array_merge($this->get_default_options(), $options);
		foreach ($this->get_default_options() as $field => $default) {
			if (!is_scalar($options[$field])) $options[$field] = $default;
			$options[$field] = (string) $options[$field];
		}
		$options['host'] = trim($options['host']);
		if (preg_match('#^(sftp|scp)://(.*)$#i', $options['host'], $matches)) {
			$options['protocol'] = strtolower($matches[1]);
			$options['host'] = rtrim($matches[2], '/');
		}
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $options['host'], $matches) || preg_match('/^([^:]+):([0-9]+)$/', $options['host'], $matches)) {
			$options['host'] = $matches[1];
			if (isset($matches[2])) $options['port'] = $matches[2];
		}
		$options['host_key'] = trim($options['host_key']);
		return $options;
	}

	public function options_filter($settings) {
		if (is_array($settings) && !empty($settings['version']) && !empty($settings['settings']) && is_array($settings['settings'])) {
			foreach ($settings['settings'] as $id => $options) {
				if (is_array($options)) $settings['settings'][$id] = $this->normalise_options($options);
			}
		}
		return $settings;
	}

	/** Return a connection tied to this settings instance. set_options() clears it. */
	private function connect($options = null) {
		if (null === $options && $this->get_storage()) return $this->get_storage();
		backupplus_try_include_file('methods/sftp/client.php', 'require_once');
		$client = BackupPlus_SFTP_Client::open($this->normalise_options(null === $options ? $this->get_options() : $options));
		if (null === $options && !is_wp_error($client)) $this->set_storage($client);
		return $client;
	}

	/** Only basenames may cross the boundary between remote and local storage. */
	private function valid_filename($file) {
		return is_string($file) && '' !== $file && '.' !== $file && '..' !== $file && !preg_match('/[\x00-\x1f\x7f\/\\\\]/', $file);
	}

	public function backup($backup_array) {
		global $backupplus;
		$client = $this->connect();
		if (is_wp_error($client)) {
			$this->log($client->get_error_message(), 'error');
			return false;
		}
		$success = true;
		foreach ($backup_array as $file) {
			if (!$this->valid_filename($file)) {
				$this->log(__('Invalid backup filename.', 'backupplus'), 'error');
				$success = false;
				continue;
			}
			$local = $backupplus->backups_dir_location().'/'.$file;
			$size = is_file($local) ? filesize($local) : false;
			if (false === $size || !is_readable($local)) {
				$this->log(sprintf(__('Cannot read backup file: %s', 'backupplus'), $file), 'error');
				$success = false;
				continue;
			}
			$progress = function($sent) use ($backupplus, $file, $size, $local) {
				$backupplus->record_uploaded_chunk($size ? min(100, 100 * $sent / $size) : 100, $file, $local);
			};
			if ($client->upload($file, $local, !empty($backupplus->current_resumption), $progress)) {
				$backupplus->uploaded_file($file);
			} else {
				$this->log(sprintf(__('Upload failed: %s', 'backupplus'), $file), 'error');
				$success = false;
			}
		}
		return $success ? array('sftp' => true) : false;
	}

	public function listfiles($match = 'backup_') {
		$client = $this->connect();
		if (is_wp_error($client)) return $client;
		$files = $client->listing();
		if (false === $files) return new WP_Error('sftp_list_failed', __('Unable to list the remote directory.', 'backupplus'));
		$result = array();
		foreach ($files as $file) {
			if ($this->valid_filename($file['name']) && false !== strpos($file['name'], $match)) $result[] = $file;
		}
		return $result;
	}

	public function download($file) {
		global $backupplus;
		if (!$this->valid_filename($file)) return false;
		$client = $this->connect();
		if (is_wp_error($client)) {
			$this->log($client->get_error_message(), 'error');
			return false;
		}
		$result = $client->download($file, $backupplus->backups_dir_location().'/'.$file);
		if (!$result) $this->log(sprintf(__('Download failed: %s', 'backupplus'), $file), 'error');
		return $result;
	}

	public function delete($files, $data = false, $sizeinfo = array()) {
		$client = $this->connect();
		if (is_wp_error($client)) {
			$this->log($client->get_error_message(), 'error');
			return false;
		}
		$success = true;
		foreach ((array) $files as $file) {
			if (!$this->valid_filename($file) || !$client->delete($file)) {
				$this->log(__('Unable to delete a remote backup file.', 'backupplus'), 'error');
				$success = false;
			}
		}
		return $success;
	}

	/** Test upload, readback and removal using a disposable file. */
	public function credentials_test($posted_settings) {
		$this->set_connection_status(false);
		$client = $this->connect($posted_settings);
		if (is_wp_error($client)) {
			echo esc_html(__('Failure:', 'backupplus').' '.$client->get_error_message());
			return;
		}
		$name = 'backupplus-test-'.wp_generate_password(24, false).'.tmp';
		$local = wp_tempnam($name);
		if (!$local) {
			echo esc_html(__('Failure: Unable to create a local test file.', 'backupplus'));
			return;
		}
		try {
			$contents = 'BackupPlus storage test: '.$name;
			$written = false !== file_put_contents($local, $contents) && $client->upload($name, $local);
			$read = $written && $client->download($name, $local, false) && $contents === file_get_contents($local);
			$deleted = $client->delete($name);
			if ($written && $read && $deleted) {
				$this->set_connection_status(true);
				echo esc_html(__('Success: Connected and verified file upload, download and deletion.', 'backupplus'));
			} else {
				echo esc_html(__('Failure: Connected, but could not upload, read back and delete a test file. Check the remote path and permissions.', 'backupplus'));
			}
		} finally {
			unlink($local);
		}
	}

	public function get_template_properties() {
		$properties = array(
			'input_host_label' => __('SSH server', 'backupplus'),
			'input_port_label' => __('Port', 'backupplus'),
			'input_port_type' => 'number',
			'input_port_min_value' => 1,
			'input_port_max_value' => 65535,
			'input_protocol_label' => __('Protocol', 'backupplus'),
			'input_protocol_option_labels' => array('sftp' => 'SFTP', 'scp' => 'SCP'),
			'input_user_label' => __('Username', 'backupplus'),
			'input_pass_label' => __('Password', 'backupplus'),
			'input_pass_type' => apply_filters('backupplus_admin_secret_field_type', 'password'),
			'input_key_label' => __('Private key (RSA or Ed25519, optional)', 'backupplus'),
			'input_key_passphrase_label' => __('Private key passphrase', 'backupplus'),
			'input_key_passphrase_type' => apply_filters('backupplus_admin_secret_field_type', 'password'),
			'input_host_key_label' => __('Server host key fingerprint', 'backupplus'),
			'input_host_key_placeholder' => 'SHA256:...',
			'input_host_key_tooltip' => __('Enter the SHA256 fingerprint supplied by your server administrator. Test settings with this field empty to view the server fingerprint, then verify it independently before saving.', 'backupplus'),
			'input_path_label' => __('Remote path', 'backupplus'),
			'input_path_tooltip' => __('The directory must already exist. Leave empty to use the login directory. SCP requires a POSIX shell and the scp command on the server.', 'backupplus'),
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
				<?php if ('protocol' === $field) { ?>
					<select data-backupplus_settings_test="protocol" id="{{get_template_input_attribute_value "id" "protocol"}}" name="{{get_template_input_attribute_value "name" "protocol"}}">
					{{#each input_protocol_option_labels}}<option value="{{@key}}" {{#ifeq @key ../protocol}}selected="selected"{{/ifeq}}>{{this}}</option>{{/each}}
					</select>
				<?php } elseif ('key' === $field) { ?>
					<textarea class="backupplus_input--wide" rows="5" autocomplete="off" spellcheck="false" data-backupplus_settings_test="key" id="{{get_template_input_attribute_value "id" "key"}}" name="{{get_template_input_attribute_value "name" "key"}}">{{key}}</textarea>
				<?php } else {
					$type = in_array($field, array('pass', 'key_passphrase'), true) ? '{{input_'.$field.'_type}}' : ('port' === $field ? 'number' : 'text');
					?>
					<input class="backupplus_input--wide" type="<?php echo esc_attr($type); ?>" data-backupplus_settings_test="<?php echo esc_attr($field); ?>" id="{{get_template_input_attribute_value "id" "<?php echo esc_attr($field); ?>"}}" name="{{get_template_input_attribute_value "name" "<?php echo esc_attr($field); ?>"}}" value="{{<?php echo esc_html($field); ?>}}" autocomplete="off" <?php if ('port' === $field) echo 'min="1" max="65535"'; ?> />
				<?php } ?>
				<?php if (in_array($field, array('host_key', 'path'), true)) { ?><p><em>{{input_<?php echo esc_html($field); ?>_tooltip}}</em></p><?php } ?>
				</td>
			</tr>
			<?php
		}
		echo '{{{get_template_test_button_html "SFTP / SCP"}}}';
		return ob_get_clean();
	}
}
