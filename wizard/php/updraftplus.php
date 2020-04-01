<?php
	/**
	 * ----------------------------------------
	 * UpdraftPlus.php functions
	 * ----------------------------------------
	 */

	/**
	 * This function will check the users plugins backup file for UpdraftPlus and if found use that version rather than download the free version from WordPress.
	 *
	 * @return boolean - returns a boolean to indicate if UpdraftPlus was found or not.
	 */
	function find_local_updraftplus() {
		global $log;

		if (!isset($_SESSION['backup']) || !isset($_SESSION['backup']['plugins'])) return false;
		
		$file_path = ROOT . $_SESSION['backup']['plugins'][0]['file'];
		$log[] = "plugins file path: {$file_path}";

		extract_folder($file_path,  ROOT . 'wordpress/wp-content/');

		$plugin_path = ROOT . 'wordpress/wp-content/plugins/';

		$files = list_files_copy($plugin_path, 1);
		
		foreach ($files as $file) {
			$file_name = str_replace($plugin_path, '', $file);
			
			if (file_exists($plugin_path . $file_name . 'updraftplus.php')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * This function will get the UpdraftPlus version from the users database backup.
	 *
	 * @return string - the version number of UpdraftPlus
	 */
	function get_updraftplus_version(){

		global $log;

		$matches = scan_db_file("/# Created by UpdraftPlus version (\d+\.\d+\.\d+)/");

		if (false === $matches) response('error', 'No UpdraftPlus Version found');

		if (empty($matches)) return '';

		$log[] = 'UpdraftPlus version: ' . $matches[1];

		return $matches[1];
	}

	/**
	 * This function will download the version of UpdraftPlus passed in.
	 *
	 * @param string $version - the version number
	 * @return void
	 */
	function download_updraftplus($version = ''){

		$file = 'updraftplus.zip';
		
		if ('' == $version) {
			$url = "https://downloads.wordpress.org/plugin/updraftplus.zip";
		} else {
			$url = "https://downloads.wordpress.org/plugin/updraftplus.{$version}.zip";
		}

		$path = ROOT . $file;
		
		download_file($file, $url, $path);
	}

	/**
	 * This function will extract the downloaded copy of UpdraftPlus.
	 *
	 * @return void
	 */
	function extract_updraftplus() {
		if (file_exists(ROOT . 'updraftplus.zip')) {
			extract_folder(ROOT . 'updraftplus.zip',  ROOT . '/wordpress/wp-content/plugins/');
		} else {
			response('success', 'updraftplus.zip not found but the download stage was successful so local copy must be present.');
		}
	}

	/**
	 * This function will prepare an array that includes all the needed restore options so that we can proceed with the restore.
	 *
	 * @return array - returns an array of options needed for the restore to be carried out
	 */
	function prepare_restore() {
		global $updraftplus;

		$backup_history = get_backup_history();
		$backupable_entities = array_keys($updraftplus->get_backupable_file_entities(true, false));
		$restore_options = array();
		$updraft_restore = array();
		$timestamp = false;
		foreach ($backup_history as $btime => $bdata) {
			$timestamp = $btime;
			foreach ($backupable_entities as $entity) {
				if (isset($bdata[$entity])) $updraft_restore[] = $entity;
			}

			if (isset($bdata['db'])) $updraft_restore[] = 'db';
		}

		$restore_options['action'] = 'updraft_restore';
		$restore_options['backup_timestamp'] = $timestamp;
		$restore_options['meta_foreign'] = 0;
		$restore_options['updraft_restorer_restore_options'] = 'updraft_restorer_replacesiteurl=1';
		$restore_options['updraft_restore'] = $updraft_restore;

		return $restore_options;
	}
	
	/**
	 * This function will kick off the restore process.
	 *
	 * @return void
	 */
	function restore_backup() {
		global $updraftplus_admin;
		if (empty($updraftplus_admin)) include_once(UPDRAFTPLUS_DIR.'/admin.php');

		$updraftplus_admin->settings_output();
	}
