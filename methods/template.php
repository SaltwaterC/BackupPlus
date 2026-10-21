<?php
/**
 * This is a bare-bones to get you started with developing an access method. The methods provided below are all ones you will want to use (though note that the provided email.php method is an
 * example of truly bare-bones for a method that cannot delete or download and has no configuration).
 *
 * Read the existing methods for help. There is no hard-and-fast need to put all your code in this file; it is just for increasing convenience and maintainability; there are no bonus points for 100% elegance. If you need access to some part of WordPress that you can only reach through the main plugin file (backupplus.php), then go right ahead and patch that.
 *
 * Some handy tips:
 * - Search-and-replace "template" for the name of your access method
 * - You can also add the methods config_print_javascript_onready and credentials_test if you like
 * - Name your file accordingly (it is now template.php)
 * - Add these headers below the opening PHP tag when ready (leave them out of helper files):
 *     `BackupPlus Provider: Your display name`
 *     `Provider ID: template`
 *     `Provider Class: BackupPlus_BackupModule_template`
 * - The factory discovers these headers automatically; use get_storage_object('template')
 *   to access the provider. The ID must match get_id() (or override get_id()).
 * - Keep shared implementation in helper files so removing an entry point cannot
 *   remove a dependency of another provider.
 * - Use the constant BACKUPPLUS_DIR to reach BackupPlus's plugin directory
 * - Call $backupplus->log("my log message") to log things, which greatly helps debugging
 * - BackupPlus is licensed under the GPL version 3 or later. Any distributed combined work must be licensed in a way compatible with the GPL.
 */
if (!defined('ABSPATH')) die('No direct access allowed.');

if (!class_exists('BackupPlus_BackupModule')) backupplus_try_include_file('methods/backup-module.php', 'require_once');

class BackupPlus_BackupModule_template extends BackupPlus_BackupModule {

	/**
	 * backup method: takes an array, and shovels them off to the cloud storage
	 *
	 * @param  Array $backup_array Array of files (basenames) to sent to remote storage
	 * @return Mixed - (boolean)false to indicate failure; otherwise, something to be passed back when deleting files
	 */
	public function backup($backup_array) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

		global $backupplus;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

		// foreach ($backup_array as $file) {

		// Do our uploading stuff...

		// If successful, then you must do this:
		// $backupplus->uploaded_file($file);

		// }

	}

	/**
	 * This function lists the files found in the configured storage location
	 *
	 * @param  String $match a substring to require (tested via strpos() !== false)
	 *
	 * @return Array - each file is represented by an array with entries 'name' and (optional) 'size'
	 */
	public function listfiles($match = 'backup_') {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored
		// This function needs to return an array of arrays. The keys for the sub-arrays are name (a path-less filename, i.e. a basename), (optional)size, and should be a list of matching files from the storage backend. A WP_Error object can also be returned; and the error code should be no_settings if that is relevant.
		return array();
	}

	/**
	 * delete method: takes an array of file names (base name) or a single string, and removes them from the cloud storage
	 *
	 * @param string $files    The specific files
	 * @param mixed  $data     Anything passed back from self::backup()
	 * @param array  $sizeinfo Size information
	 * @return Boolean - whether the operation succeeded or not
	 */
	public function delete($files, $data = false, $sizeinfo = array()) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

		global $backupplus;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

		if (is_string($files)) $files = array($files);

	}

	/**
	 * download method: takes a file name (base name), and brings it back from cloud storage into BackupPlus's directory
	 * You can register errors with $backupplus->log("my error message", 'error')
	 *
	 * @param String $file The specific file to be downloaded from the Cloud Storage
	 *
	 * @return Boolean - success or failure state
	 */
	public function download($file) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

		global $backupplus;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This is a template file and can be ignored

	}

	public function get_supported_features() {
		// This options format is handled via only accessing options via $this->get_options()
		return array('multi_options', 'config_templates');
	}

	/**
	 * Get the configuration template, in Handlebars format.
	 * Note that logging is not available from this context; it will do nothing.
	 *
	 * @return String - the template, ready for substitutions to be carried out
	 */
	public function get_configuration_template() {

		ob_start();
	
		$classes = $this->get_css_classes();
	
		?>
			<tr class="backupplusmethod <?php echo esc_attr($classes);?>">
				<th>My Method:</th>
				<td>
					
				</td>
			</tr>

		<?php

		return ob_get_clean();
	}
}
