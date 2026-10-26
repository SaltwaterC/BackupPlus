<?php

/** Deciding which files and directories are left out of an archive. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Exclusions {

	/**
	 * Get a list of excluded extensions
	 *
	 * @param Array $exclude - settings passed in
	 *
	 * @return Array
	 */
	private function get_excluded_extensions( $exclude ) {
		if ( ! is_array( $exclude ) ) {
			$exclude = array();
		}
		$exclude_extensions = array();
		foreach ( $exclude as $ex ) {
			if ( preg_match( '/^ext:(.+)$/i', $ex, $matches ) ) {
				$exclude_extensions[] = strtolower( $matches[1] );
			}
		}

		if ( defined( 'BACKUPPLUS_EXCLUDE_EXTENSIONS' ) ) {
			$exclude_from_define = explode( ',', BACKUPPLUS_EXCLUDE_EXTENSIONS );
			foreach ( $exclude_from_define as $ex ) {
				$exclude_extensions[] = strtolower( trim( $ex ) );
			}
		}

		return $exclude_extensions;
	}

	/**
	 * Get a list of excluded prefixes
	 *
	 * @param Array $exclude - settings passed in
	 *
	 * @return Array - each is listed in lower case
	 */
	private function get_excluded_prefixes( $exclude ) {
		if ( ! is_array( $exclude ) ) {
			$exclude = array();
		}
		$exclude_prefixes = array();
		foreach ( $exclude as $pref ) {
			if ( preg_match( '/^prefix:(.+)$/i', $pref, $matches ) ) {
				$exclude_prefixes[] = strtolower( $matches[1] );
			}
		}
		return $exclude_prefixes;
	}

	/**
	 * List all the wildcard patterns from the given excluded items
	 *
	 * @param Array $exclude the list of excluded items which may contain not just wildcard patterns but also specific file/directory names as well
	 *
	 * $exclude argument may contains data in an array format like below:
	 *     [
	 *         "snapshots" // definitely not a wildcard parttern, this could be directories/files named `snapshots` which are located in the root/parent directory
	 *         "2021/03/image.jpg", // not a wildcard parttern, this could be files/directories named `image.jpg` which are located in the 2021/03/ directory
	 *         "ext:zip", // not a wildcard pattern, this is to exclude all files that end with `zip` extension
	 *         "prefix:file-", // not a wildcard pattern, this is to exclude all files that begin with `file-` prefix
	 *         "2021/04", // not a wildcard pattern, this is to exclude all files/directories which are located in the 2021/04 directory
	 *         "backup*", // wildcard pattern that excludes all files/directories beginning with `backup` in the root/parent directory
	 *         "2021/*optimise*", // wildcard pattern that excludes all files/directories that have `optimise` anywhere in their names in the `2021` directory
	 *         "2021/04/*.tmp" // wildcard pattern that excludes all files/directories ending with `optimise` anywhere in their names in the `2021/04` directory
	 *     ]
	 *
	 * @return Array an array of wildcard patterns
	 *
	 * After the $exclude has gone through the regex parsing step, only excluded items containing valid wildcard patterns got captured and will return them in an array in a format like below:
	 *
	 *     [
	 *         [
	 *             "directory_path" => "",
	 *             "pattern" => "backup*"
	 *         ],
	 *         [
	 *             "directory_path" => "2021\",
	 *             "pattern" => "*optimise*"
	 *         ],
	 *         [
	 *             "directory_path" => "2021\04\",
	 *             "pattern" => "*.tmp"
	 *         ]
	 *     ]
	 */
	private function get_excluded_wildcards( $exclude ) {
		if ( ! is_array( $exclude ) ) {
			$exclude = array();
		}
		$excluded_wildcards = array();
		foreach ( $exclude as $wch ) {
			// https://regex101.com/r/dMFI0P/1/
			if ( preg_match( '#(.*(?<!\\\)/)?(.*?(?<!\\\)\*.*)#i', $wch, $matches ) ) {
				// the regex will make sure only excluded items containing valid wildcard patterns get captured, it will lookup for asterisk char(s) at the very end of the string right after the last path separator (if any). e.g. foo/bar/b*a*z
				$excluded_wildcards[] = array(
					// in case the excluded item has doubled separators (e.g. dir1//dir2//file) or if the user added a directory separator at the beginning then trim and/or replace them
					'directory_path' => preg_replace( array( '/^[\/\s]*/', '/\/\/*/', '/[\/\s]*$/' ), array( '', '/', '' ), $matches[1] ),
					'pattern'        => $matches[2],
				);
			}
		}
		return $excluded_wildcards;
	}

	/**
	 * Check whether or not the given entity(file/directory) is excluded from the backup by matching it against a set of wildcard patterns
	 *
	 * @param String $entity the file/directory's stripped path
	 * @return Boolean true if the entity is excluded, false otherwise
	 */
	private function is_entity_excluded_by_wildcards( $entity ) {
		$entity_basename = untrailingslashit( $entity );
		$entity_basename = substr_replace( $entity_basename, '', 0, ( false === strrpos( $entity_basename, '/' ) ? 0 : strrpos( $entity_basename, '/' ) + 1 ) );
		foreach ( $this->excluded_wildcards as $wch ) {
			if ( ! is_array( $wch ) || empty( $wch ) ) {
				continue;
			}
			if ( substr_replace( $entity, '', (int) strrpos( $entity, '/' ), strlen( $entity ) - (int) strrpos( $entity, '/' ) ) !== $wch['directory_path'] ) {
				continue;
			}
			if ( '*' == substr( $wch['pattern'], -1, 1 ) && '*' == substr( $wch['pattern'], 0, 1 ) && strlen( $wch['pattern'] ) > 2 ) {
				$wch['pattern'] = substr( $wch['pattern'], 1, strlen( $wch['pattern'] ) - 2 );
				$wch['pattern'] = str_replace( '\*', '*', $wch['pattern'] );
				if ( strpos( $entity_basename, $wch['pattern'] ) !== false ) {
					return true;
				}
			} elseif ( '*' == substr( $wch['pattern'], -1, 1 ) && strlen( $wch['pattern'] ) > 1 ) {
				$wch['pattern'] = substr( $wch['pattern'], 0, strlen( $wch['pattern'] ) - 1 );
				$wch['pattern'] = str_replace( '\*', '*', $wch['pattern'] );
				if ( substr( $entity_basename, 0, strlen( $wch['pattern'] ) ) == $wch['pattern'] ) {
					return true;
				}
			} elseif ( '*' == substr( $wch['pattern'], 0, 1 ) && strlen( $wch['pattern'] ) > 1 ) {
				$wch['pattern'] = substr( $wch['pattern'], 1 );
				$wch['pattern'] = str_replace( '\*', '*', $wch['pattern'] );
				if ( strlen( $entity_basename ) >= strlen( $wch['pattern'] ) && substr( $entity_basename, strlen( $wch['pattern'] ) * -1 ) == $wch['pattern'] ) {
					return true;
				}
			}
		}
		return false;
	}

	private function is_entity_excluded_by_extension( $entity ) {
		foreach ( $this->excluded_extensions as $ext ) {
			if ( ! $ext ) {
				continue;
			}
			$eln = strlen( $ext );
			if ( strtolower( substr( $entity, -$eln, $eln ) ) == $ext ) {
				return true;
			}
		}
		return false;
	}

	private function is_entity_excluded_by_prefix( $entity ) {
		$entity = basename( $entity );
		foreach ( $this->excluded_prefixes as $pref ) {
			if ( ! $pref ) {
				continue;
			}
			$eln = strlen( $pref );
			if ( strtolower( substr( $entity, 0, $eln ) ) == $pref ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the member of the array with key (int)0, as a new array. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - with keys 'name' and 'type'
	 */
	private function cb_get_name_base_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => 'BASE TABLE',
		);
	}

	/**
	 * Returns the members of the array with keys (int)0 and (int)1, as part of a new array.
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - keys are 'name' and 'type'
	 */
	private function cb_get_name_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => $a[1],
		);
	}

	/**
	 * Returns the member of the array with key (string)'name'. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Mixed - the value with key (string)'name'
	 */
	private function cb_get_name( $a ) {
		return $a['name'];
	}

	/**
	 * Exclude files from backup
	 *
	 * @param Boolean $filter initial boolean value of whether the given file is excluded or not
	 * @param String  $file   the full path of the filename to be checked
	 * @return Boolean true if the specified file will be excluded, false otherwise
	 */
	public function backup_exclude_file( $filter, $file ) {
		foreach ( $this->backup_excluded_patterns as $pattern ) {
			if ( 0 === stripos( $file, $pattern['directory'] ) && preg_match( $pattern['regex'], $file ) ) {
				return true;
			}
		}
		return $filter;
	}
}
