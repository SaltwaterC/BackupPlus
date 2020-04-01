<?php
	/**
	 * ----------------------------------------
	 * Backup.php functions
	 * ----------------------------------------
	 */

	/**
	 * This function will scan the current working directory e.g where the restore wizard script is, to find any backups the user may have uploaded then saves the details in the session.
	 *
	 * @return void
	 */
	function check_backup(){
		global $log;
		
		$log[] = "Searching for backup";

		$files = list_files_copy(ROOT, 1);
		
		$backups = array();

		$decryption = empty($_POST['updraft_encryptionphrase']) ? '' : $_POST['updraft_encryptionphrase'];

		foreach ($files as $file) {
			$file_name = str_replace(ROOT, '', $file);
			if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?+(\.(zip|gz|gz\.crypt))?$/i', $file_name, $matches)) {

				if ('gz.crypt' == $matches[6] && '' == $decryption) response('error', 'Encrypted DB found but no decryption phrase provided.');

				$nonce = $matches[2];
				
				if (!isset($backups[$nonce])) {
					$backups[$nonce] = array();
					$backups[$nonce]['date'] = $matches[1];
				}
				
				$content = $matches[3];

				$backups[$nonce][$content][] = array(
					"file" => $matches[0],
					"type" => $matches[6]
				);
			}
		}


		if (count($backups) === 0) {
			response('error', 'No Backup sets found');
		}

		if (count($backups) > 1) {
			response('error', 'More than one backup set found');
		}

		$backup_codes = array_keys($backups);
		$nonce = $backup_codes[0];

		if (count($backups[$nonce]['db']) === 0) {
			response('error', 'No database backup found');
		}

		$_SESSION["backup"] = $backups[$nonce];
		$log[] = 'Backup looks ok';
	}

	/**
	 * This function will scan the working directory for backup files, any that are found will then be moved to the UpdraftPlus backup directory. Note this function needs UpdraftPlus, do not call it without having loaded WordPress and UpdraftPlus first.
	 *
	 * @return void
	 */
	function move_backups(){
		global $updraftplus;

		$updraft_dir = $updraftplus->backups_dir_location();
		$path = ROOT;
		if ($dh = opendir($path)) {
			$path = rtrim($path, DIRECTORY_SEPARATOR);

			$skip_paths = array(".", "..");

			while (($value = readdir($dh)) !== false) {
				if (!in_array($value, $skip_paths)) {
					if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|gz|gz\.crypt))?$/i', $value, $matches)) {
						copy($path . DIRECTORY_SEPARATOR . $value, $updraft_dir . '/' . $value);
					}
				}
			}
		}
	}

	/**
	 * This function will call UpdraftPlus_Backup_History::rebuild() in order to scan the UpdraftPlus backup directory and create a backup history of any backups found, so that we can use this data later on to get the options needed to restore. Note this function needs UpdraftPlus, do not call it without having loaded WordPress and UpdraftPlus first.
	 *
	 * @return void
	 */
	function build_backup_history() {
		UpdraftPlus_Backup_History::rebuild();
	}

	/**
	 * This function will call UpdraftPlus_Backup_History::get_history() so we can grab the needed information for the restore process. Note this function needs UpdraftPlus, do not call it without having loaded WordPress and UpdraftPlus first.
	 *
	 * @return array - returns the UpdraftPlus backup history
	 */
	function get_backup_history() {
		return UpdraftPlus_Backup_History::get_history();
	}
