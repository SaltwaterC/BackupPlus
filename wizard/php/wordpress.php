<?php
	/**
	 * ----------------------------------------
	 * WordPress.php functions
	 * ----------------------------------------
	 */

	/**
	 * This function will get the WordPress version from the users database backup.
	 *
	 * @return string - the version number of WordPress
	 */
	function get_wordpress_version() {

		global $log;

		$matches = scan_db_file("/WordPress Version: (\d+.\d+.\d+)/");

		if (false === $matches) response('error', 'No WordPress Version found');

		if (empty($matches)) return '';

		$log[] = 'Wordpress version: ' . $matches[1];

		return $matches[1];
	}

	/**
	 * This function will download the version of WordPress passed in.
	 *
	 * @param string $version - the version number
	 * @return void
	 */
	function download_wordpress($version = '') {

		$file = 'wordpress.zip';
		
		if ('' == $version) {
			$url = "https://wordpress.org/latest.zip";
		} else {
			$url = "https://wordpress.org/wordpress-{$version}.zip";
		}

		$path = ROOT . $file;
		
		download_file($file, $url, $path);
	}

	/**
	 * This function will extract the downloaded copy of WordPress.
	 *
	 * @return void
	 */
	function extract_wordpress() {
		extract_folder(ROOT . 'wordpress.zip',  ROOT);
	}

	/**
	 * This function will configure the downloaded copy of WordPress e.g adding salts and database connection details, it will then run the WordPress installer to setup the WordPress site.
	 *
	 * @return void
	 */
	function config_wordpress() {
		global $log;
		$db = get_database_credentials();

		$log[] = "Reading wp-config.php file";
		$sample = file_get_contents(ROOT . 'wordpress/wp-config-sample.php');
		
		if (false === $sample) {
			response('error', 'Failed to read wp-config.php');
		}

		$secret_key_url = 'https://api.wordpress.org/secret-key/1.1/salt/';
		$secret_keys = array();

		if (function_exists("curl_init") && function_exists('curl_exec')) {
			$ch = curl_init();
			
			curl_setopt($ch, CURLOPT_URL, $secret_key_url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
			
			$data = curl_exec($ch);
			
			curl_close($ch);

			$secret_keys = explode("\n", $data);
		} else {
			$secret_keys = explode("\n", file_get_contents('https://api.wordpress.org/secret-key/1.1/salt/'));
		}

		if (empty($secret_keys)) {
			response('error', 'Failed to read secret keys from response');
		}

		foreach ($secret_keys as $key => $value) {
			$secret_keys[$key] = substr($value, 28, 64);
		}

		$secret_key_index = 0;
			
		$config = $sample;

		$log[] = "Replacing Values in wp-config.php";
		if (preg_match_all('/define\([ ]*\'([A-Z_]+)\'[ ]*,[ ]*\'([A-z_ ]+)\'[ ]*\);/', $sample, $matches)) {
			$lines = $matches[0];
			$keys = $matches[1];

			foreach ($lines as $key => $value) {
				switch ($keys[$key]) {
					case 'DB_NAME':
					$line = "define('DB_NAME', '" . $db['name'] . "');";
					$config = str_replace($value, $line, $config);
						break;
					case 'DB_USER':
					$line = "define('DB_USER', '" . $db['username'] . "');";
					$config = str_replace($value, $line, $config);
						break;
					case 'DB_PASSWORD':
					$line = "define('DB_PASSWORD', '" . $db['password'] . "');";
					$config = str_replace($value, $line, $config);
						break;
					case 'DB_HOST':
					$line = "define('DB_HOST', '" . $db['host'] . "');";
					$config = str_replace($value, $line, $config);
						break;
					case 'AUTH_KEY':
					case 'SECURE_AUTH_KEY':
					case 'LOGGED_IN_KEY':
					case 'NONCE_KEY':
					case 'AUTH_SALT':
					case 'SECURE_AUTH_SALT':
					case 'LOGGED_IN_SALT':
					case 'NONCE_SALT':
					$line = "define('" . $keys[$key] . "', '" . $secret_keys[$secret_key_index++] . "');\r\n";
					$config = str_replace($value, $line, $config);
						break;
				}
			}
		}

		$log[] = "Writing wp-config.php file";
		$file = file_put_contents(ROOT . 'wordpress/wp-config.php', $config);
		if (false === $file) {
			response('error', 'Failed to write wp-config.php');
		}

		chmod(ROOT . '/wordpress/' . 'wp-config.php', 0666);

		define('WP_INSTALLING', true);

		load_wordpress();

		// WordPress installation
		wp_install("UpdraftPlus Wizard", "UpdraftPlus", "UpdraftPlus@example.com", 0, '', "UpdraftPlus_Wizard_Password");

		// We update the options with the right siteurl and homeurl value
		$protocol = !is_ssl() ? 'http' : 'https';
		$link = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$url = str_replace(basename(__FILE__), '', $link);

		update_option('siteurl', $url);
		update_option('home', $url);
	}

	/**
	 * This function will bootstrap WordPress so that it's functions can be called.
	 *
	 * @return void
	 */
	function load_wordpress() {
		// Load WordPress Bootstrap
		include_once(ROOT . 'wordpress/wp-load.php');
		// Load WordPress Administration Upgrade API
		include_once(ROOT . 'wordpress/wp-admin/includes/upgrade.php');
	}

	/**
	 * This function will activate any plugins found inside the WordPress plugin folder.
	 *
	 * @return void
	 */
	function install_plugins() {
		load_wordpress();
		activate_plugins(array_keys(get_plugins()));
	}

	/**
	 * This function will load any filters added to this function.
	 *
	 * @return void
	 */
	function load_filters() {
		add_filter('updraftplus_logline', 'suppress_browser_log');
	}

	/**
	 * This function is called via the filter 'updraftplus_logline' it will return false so that no log lines are output to the browser. The reason for this is that any browser output that is not json will cause vue.js to break.
	 *
	 * @return boolean - returns false so that browser logs are surpressed
	 */
	function suppress_browser_log() {
		return false;
	}

	/**
	 * This function will move WordPress from it's extracted folder location to the root working directory e.g where the restore wizard script is located.
	 *
	 * @return void
	 */
	function move_wordpress() {
		load_wordpress();

		global $log, $wp_filesystem;

		$log[] = "Moving WordPress to the root directory";
			
		if (empty($wp_filesystem)) WP_Filesystem();

		$root = ROOT;
		// this copies the contents of the directory to another
		if (copy_dir("{$root}/wordpress/", $root)) {
			$wp_filesystem->rmdir($root . 'wordpress', true);
		} else {
			response('error', 'failed to move the wordpress Dir to the root directory');
		}
	}
