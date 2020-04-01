<?php
	/**
	 * ----------------------------------------
	 * Functions.php functions
	 * ----------------------------------------
	 */

	/**
	 * This function will clean up the current working directory e.g where the restore wizard script is, it will remove any files created or that are part of the restore wizard, so that the user is only left with the working WordPress install.
	 *
	 * @param string $path - the path to clean
	 *
	 * @return void
	 */
	function clean($path){
		global $log;
		
		$files = list_files_copy($path, 1);
		
		foreach ($files as $file) {
			$file_name = str_replace($path, '', $file);
			if (preg_match('/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|gz|gz\.crypt|sql))?$/i', $file_name, $matches) || preg_match('/updraftplus-restore-wizard.php/', $file_name, $matches)) {
				$log[] = "Removing file: " . $file_name;
				unlink($file);
			}
		}
	}

	/**
	 * This function will scan the users database backup for the passed in regex and return any matches found.
	 *
	 * @param string $regex - the regex you want to use to search the file
	 *
	 * @return array        - returns an array of matches
	 */
	function scan_db_file($regex) {
		global $log;

		if (!isset($_SESSION['backup']) || !isset($_SESSION['backup']['db'])) return false;

		$file_path = ROOT . $_SESSION['backup']['db'][0]['file'];
		$log[] = "db file path: {$file_path}";

		if (strpos($file_path, '.crypt') !== false) {
			$log[] = "db file encrypted, can't scan the file.";
			return array();
		}

		$sql_path = str_replace('.gz', '.sql', $file_path);
		$log[] = "SQL file path: {$sql_path}";

		if (!file_exists($sql_path)) {
			$log[] = 'SQL file not found, extracting';
			extract_gz($file_path, $sql_path);
		}

		$log[] = 'SQL file found';

		if (filesize($sql_path) === 0) {
			response('error', 'SQL file is empty');
		}

		$handle = fopen($sql_path, "r");
		$found = 0;
		$matches = false;

		if (false === $handle) {
			response('error', 'Failed to open SQL file');
		}

		$log[] = 'SQL file opened';

		while (0 === $found) {

			$line = fgets($handle);
			if (false === $line) {
				$log[] = "End of SQL file";
				break;
			}
			$found = preg_match($regex, $line, $matches);
		}
		fclose($handle);

		return $matches;
	}
	
	/**
	 * This function will call do_extract_folder to start the extract folder process for the passed in parameters.
	 *
	 * @param string $source - the path to the source file
	 * @param string $target - the path to the directory we want to extract the source file to
	 */
	function extract_folder($source, $target) {
		global $log;
		
		$log[] = "Extracting {$source} to {$target}";
		
		if (!do_extract_folder($source,  $target)) {
			response("error", "Could not extract {$source} file");
		}
		
		if (unlink($source) === false) {
			response("error", "Could not delete {$source} file");
		}
	}

	/**
	 * This function will extract the source file to the target location using the UpdraftPlus class-zip.php file that is found in the UpdraftPlus project. This brings with it a dependancy on the WordPress file class-pclzip.php.
	 *
	 * @param string $source - the path to the source file
	 * @param string $target - the path to the directory we want to extract the source file to
	 *
	 * @return boolean       - returns a boolean value to indicate success or failure
	 */
	function do_extract_folder($source, $target) {

		$zip_object = 'UpdraftPlus_ZipArchive';
		
		// In tests, PclZip was found to be 25% slower than ZipArchive
		if (((defined('UPDRAFTPLUS_PREFERPCLZIP') && UPDRAFTPLUS_PREFERPCLZIP == true) || !class_exists('ZipArchive') || !class_exists('UpdraftPlus_ZipArchive') || (!extension_loaded('zip') && !method_exists('ZipArchive', 'AddFile')))) {
			$zip_object = 'UpdraftPlus_PclZip';
		}

		$zip = new $zip_object;
		
		if ($zip->open($source) === true) {
			
			if ('UpdraftPlus_PclZip' == $zip_object) {
				$extracted = $zip->extract($source, $target);
			} else {
				$extracted = $zip->extractTo($target);
			}
			
			$zip->close();

			return $extracted ? true : false;
		} else {
			return false;
		}
	}

	/**
	 * This function will extract the source file to the target location, this is used to extract gz files.
	 *
	 * TODO: is this function needed e.g can we reuse code from UpdraftPlus?
	 *
	 * @param string $source - the path to the source file
	 * @param string $target - the path to the directory we want to extract the source file to
	 *
	 * @return boolean       - returns a boolean value to indicate success or failure
	 */
	function extract_gz($source, $target) {
		global $log;

		if (file_exists($target)) {
			$log[] = "Traget file '{$target}' already exists, deleting";
			if (unlink($target) === false) {
				$log[] = 'Failed to delete the target file';
				return false;
			}
		}

		if (!file_exists($source)) {
			$log[] = "Source file '{$source}' file done not exist";
			return false;
		}

		$in_file = @gzopen($source, 'rb'); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		if (false === $in_file) {
			$log[] = error_get_last();
			return false;
		}

		$out_file = @fopen($target, 'wb'); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		if (false === $out_file) {
			$log[] = error_get_last();
			return false;
		}

		while (!gzeof($in_file)) {
			$line = gzread($in_file, 4096);
			$write = @fwrite($out_file, $line); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			if (false === $write) {
				$log[] = error_get_last();
				return false;
			}
		}

		if (@fclose($out_file) === false) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$log[] = error_get_last();
		}
		if (@gzclose($in_file) === false) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$log[] = error_get_last();
		}

		if (filesize($target) === 0) {
			unlink($target);
			$log[] = 'Extracted file is empty';
			return false;
		}

		return true;
	}

	/**
	 * This function will call do_download_file to start the download process for the passed in parameters.
	 *
	 * @param string $file - the name of the file we want to download
	 * @param string $url  - the URL to the file we want to download
	 * @param string $path - the path to where we want to save the downloaded file
	 *
	 * @return boolean     - returns a boolean to indicate success or failure
	 */
	function download_file($file, $url, $path) {
		global $log;

		if (file_exists($path)) {
			$log[] = "Exsisting {$file} file already found, deleting.";
			if (unlink($path) === false) {
				response("error", "Error deleting previous {$file}");
			}
		}

		$log[] = $url;
		
		if (!do_download_file($url, $path)) {
			response("error", "Error downloading {$file}");
		}
	}

	/**
	 * This function will download a file to the given path from the passed in URL.
	 *
	 * @param string $url  - the URL to the file we want to download
	 * @param string $path - the path to where we want to save the downloaded file
	 *
	 * @return boolean     - returns a boolean to indicate success or failure
	 */
	function do_download_file($url, $path) {
		
		if ('' == $url || '' == $path) {
			return false;
		}

		if (function_exists("curl_init") && function_exists('curl_exec')) {
			$fp = fopen($path, 'w+');
			
			if (false === $fp) {
				return false;
			}
			
			$ch = curl_init($url);
			
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_TIMEOUT, 90);
			
			curl_exec($ch);
			
			if (curl_errno($ch)) {
				return false;
			}
			
			$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			
			curl_close($ch);
			fclose($fp);

			return 200 == $status_code ? true : false;
			
		} else {
			
			$remote_file = fopen($url, 'rb');

			if ($remote_file) {
				$file = fopen($path, 'wb');
				if ($file) {
					while (!feof($remote_file)) {
						fwrite($file, fread($remote_file, 1024 * 8), 1024 * 8);
					}
				}
			} else {
				return false;
			}

			if ($remote_file) fclose($remote_file);
			
			if ($file) fclose($file);

			return true;
		}
	}

	/**
	 * This function returns a response to vue.js, the response can contain a state, data and a status code.
	 *
	 * @param string       $state - the state e.g success, error
	 * @param string|array $data  - the data you want to return to vue.js could be a string or an array
	 * @param integer      $code  - a status code
	 *
	 * @return void
	 */
	function response($state = '', $data = '', $code = 200) {
		global $log;

		echo json_encode(array(
			"state" => $state,
			"info" => $data,
			"log" => $log
		));
		die();
	}

	/**
	 * This function will list all the files in a given directory.
	 * Direct copy of WordPress function needed before we download and use WordPress
	 *
	 * @param string  $folder - the path to the folder we want to scan
	 * @param integer $levels - how many levels deep we want to scan the given folder
	 *
	 * @return array          - returns an array of files
	 */
	function list_files_copy($folder = '', $levels = 100) {
		
		if (empty($folder)) return false;
		
		if (!$levels) return false;
		
		$files = array();
		
		if ($dir = @opendir($folder)) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			while (($file = readdir($dir)) !== false) {
				if (in_array($file, array('.', '..'))) continue;
				
				if (is_dir($folder . $file)) {
					$files2 = list_files_copy($folder . $file, $levels - 1);
					if ($files2) {
						$files = array_merge($files, $files2);
					} else {
						$files[] = $folder . $file . '/';
					}
				} else {
					$files[] = $folder .  $file;
				}
			}
		}
		@closedir($dir); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		return $files;
	}
