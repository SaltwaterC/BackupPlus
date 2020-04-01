<?php
	/**
	 * ----------------------------------------
	 * Database.php functions
	 * ----------------------------------------
	 */

	/**
	 * This function will look at the posted options and try to find the database connection details, if found it will return a connection details array otherwise it will return a empty connection details array.
	 *
	 * @return array - an array that includes the database connection details
	 */
	function get_database_credentials() {
		global $log;
		$log[] = 'Getting database info';

		if (isset($_POST['host']) || isset($_POST['username']) || isset($_POST['password']) || isset($_POST['name'])) {

			return array(
				"host" => $_POST['host'],
				"username" => $_POST['username'],
				"password" => $_POST['password'],
				"name" => $_POST['name'],
			);
		} else {
			return array(
				"host" => 'host',
				"username" => 'username',
				"password" => 'password',
				"name" => 'name',
			);
		}
	}
