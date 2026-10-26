<?php

/** The manifests that record what each archive holds, for incremental backups. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Zip_Manifest {

	private function unserialize_gz_cache_file( $file ) {
		if ( ! $whandle = gzopen( $file, 'r' ) ) {
			return false;
		}
		global $backupplus;
		$emptimes = 0;
		$var      = '';
		while ( ! gzeof( $whandle ) ) {
			$bytes = @gzread( $whandle, 1048576 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( empty( $bytes ) ) {
				++$emptimes;
				$backupplus->log( "Got empty gzread ($emptimes times)" );
				if ( $emptimes > 2 ) {
					return false;
				}
			} else {
				$var .= $bytes;
			}
		}
		gzclose( $whandle );
		return $backupplus->unserialize( $var );
	}

	/**
	 * This method will add a manifest file to the backup zip
	 *
	 * @param  String $whichone - the type of backup (e.g. 'plugins', 'themes')
	 *
	 * @return Boolean - success/failure status
	 */
	private function backupplus_include_manifest( $whichone ) {
		global $backupplus;

		if ( $this->incremental_manifest ) {
			$manifest = $this->backupplus_dir . '/manifest-' . $backupplus->nonce . '-' . $whichone . '.tmp';
			$data     = json_encode( $this->incremental_manifest );
			if ( false === $data || false === file_put_contents( $manifest, $data ) ) {
				throw new RuntimeException( 'Could not write the incremental restore manifest.' );
			}
			$this->manifest_path                 = $manifest;
			$this->zipfiles_batched[ $manifest ] = 'backupplus-manifest.json';
			// Always replace a manifest left by a previous resumption, even if its size is unchanged.
			unset( $this->existing_files['backupplus-manifest.json'] );
			return true;
		}

		$manifest_name = 'backupplus-manifest.json';
		$manifest      = trailingslashit( $this->backupplus_dir ) . $manifest_name;

		$backupplus->log( sprintf( "Creating file manifest ($manifest_name) for incremental backup (included: %d, skipped: %d)", count( $this->zipfiles_batched ), count( $this->zipfiles_skipped_notaltered ) ) );

		if ( false === ( $handle = fopen( $manifest, 'w+' ) ) ) {
			return $backupplus->log( "Failed to open manifest file ($manifest_name)" );
		}

		$this->manifest_path = $manifest;

		$version = 1;

		$go_to_levels = array(
			'plugins' => 2,
			'themes'  => 2,
			'uploads' => 3,
			'others'  => 3,
		);

		$go_to_levels = apply_filters( 'backupplus_manifest_go_to_level', $go_to_levels, $whichone );

		$go_to_level = isset( $go_to_levels[ $whichone ] ) ? $go_to_levels[ $whichone ] : 'all';

		$directory = '';

		if ( 'more' == $whichone ) {
			foreach ( $this->zipfiles_batched as $index => $dir ) {
				$directory = '"directory":"' . dirname( $index ) . '",';
			}
		}

		if ( false === fwrite( $handle, '{"version":' . $version . ',"type":"' . $whichone . '",' . $directory . '"listed_levels":"' . $go_to_level . '","contents":{"directories":[' ) ) {
			$backupplus->log( "First write to manifest file failed ($manifest_name)" );
		}

		// First loop: find out which is the last entry, so that we don't write the comma after it
		$last_dir_index = false;
		foreach ( $this->zipfiles_dirbatched as $index => $dir ) {
			if ( 'all' !== $go_to_level && substr_count( $dir, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			$last_dir_index = $index;
		}

		// Second loop: write out the entry
		foreach ( $this->zipfiles_dirbatched as $index => $dir ) {
			if ( 'all' !== $go_to_level && substr_count( $dir, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			fwrite( $handle, json_encode( $dir ) . ( ( $index != $last_dir_index ) ? ',' : '' ) );
		}

		// Now do the same for files
		fwrite( $handle, '],"files":[' );

		$last_file_index = false;
		foreach ( $this->zipfiles_batched as $store_as ) {
			if ( 'all' !== $go_to_level && substr_count( $store_as, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			$last_file_index = $store_as;
		}
		foreach ( $this->zipfiles_skipped_notaltered as $store_as ) {
			if ( 'all' !== $go_to_level && substr_count( $store_as, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			$last_file_index = $store_as;
		}

		foreach ( $this->zipfiles_batched as $store_as ) {
			if ( 'all' !== $go_to_level && substr_count( $store_as, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			fwrite( $handle, json_encode( $store_as ) . ( ( $store_as != $last_file_index ) ? ',' : '' ) );
		}

		foreach ( $this->zipfiles_skipped_notaltered as $store_as ) {
			if ( 'all' !== $go_to_level && substr_count( $store_as, '/' ) > $go_to_level - 1 ) {
				continue;
			}
			fwrite( $handle, json_encode( $store_as ) . ( ( $store_as != $last_file_index ) ? ',' : '' ) );
		}

		fwrite( $handle, ']}}' );
		fclose( $handle );

		$this->zipfiles_batched[ $manifest ] = $manifest_name;

		$backupplus->log( 'Successfully created file manifest (size: ' . filesize( $manifest ) . ')' );

		return true;
	}

	/**
	 * This function will populate class variables (given below) with a list of files found inside the passed in zip
	 *
	 * @param string  $zip_path           - the zip file name we want to list files for; must end in .tmp
	 * @param boolean $read_from_manifest - a boolean to indicate if we should try to read from the manifest or not
	 *
	 * @uses self::$existing_files
	 * @uses self::$existing_files_rawsize
	 * @uses self::$existing_zipfiles_size
	 *
	 * @return void
	 */
	private function populate_existing_files_list_from_zip( $zip_path, $read_from_manifest ) {
		global $backupplus;

		// Get the name of the final manifest file
		if ( preg_match( '/\.tmp$/', $zip_path ) ) {
			$manifest = preg_replace( '/\.tmp$/', '.list.tmp', $zip_path );
		} else {
			$manifest = $zip_path . '.list.tmp';
		}

		if ( $read_from_manifest && file_exists( $manifest ) ) {
			$manifest_contents = json_decode( file_get_contents( $manifest ), true );

			if ( empty( $manifest_contents ) ) {
				$backupplus->log( 'Zip manifest file found, but reading failed: ' . basename( $manifest ) );
			} elseif ( ! empty( $manifest_contents['files'] ) ) {
				$this->existing_files = array_merge( $this->existing_files, $manifest_contents['files'][0] );
				$backupplus->log( 'Successfully read zip manifest file contents (' . basename( $manifest ) . ')' );
				return;
			} else {
				$backupplus->log( 'Zip manifest file found, but no files contents were found: ' . basename( $manifest ) );
			}
		} elseif ( $read_from_manifest ) {
			$backupplus->log( 'No zip manifest file found; will create one' );
		}

		$zip = new $this->use_zip_object();
		if ( true !== $zip->open( $zip_path ) ) {
			$backupplus->log( 'Could not open zip file to examine (' . $zip->last_error . '); will remove: ' . basename( $zip_path ) );
			unlink( $zip_path );
		} else {

			$this->existing_zipfiles_size += filesize( $zip_path );

			// Don't put this in the for loop, or the magic __get() method which accessing the property invokes gets repeatedly called every time the loop goes round
			$numfiles = $zip->numFiles;

			if ( false === $numfiles ) {
				$backupplus->log( 'Could not read any files from the zip (' . $zip->last_error . '): ' . basename( $zip_path ) );
				$zip->close();
				return;
			}

			for ( $i = 0; $i < $numfiles; $i++ ) {
				$si   = $zip->statIndex( $i );
				$name = $si['name'];
				// Exclude folders
				if ( '/' == substr( $name, -1 ) ) {
					continue;
				}
				if ( ! isset( $this->existing_files[ $name ] ) ) {
					$this->existing_files[ $name ] = $si['size'];
					$this->existing_files_rawsize += $si['size'];
				}
			}

			@$zip->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.

			$backupplus->log( basename( $zip_path ) . ': Zip file already exists, with ' . count( $this->existing_files ) . ' files' );

			// If this is a .tmp file (partial zip) then return we do not want to create an incomplete manifest file
			if ( preg_match( '/\.tmp$/', $zip_path ) ) {
				return;
			}

			$manifest = $zip_path . '.list-temp.tmp';

			$this->write_zip_manifest_from_list( $manifest, $this->existing_files );
		}
	}

	/**
	 * This function will get a list of files found inside the passed in zip and call the function to create the zip manifest, returns true on success and false on failure
	 *
	 * @uses self::write_zip_manifest_from_list()
	 * @param string $zip_path - the zip file path; must end in .tmp
	 *
	 * @return boolean - returns true on success and false on failure
	 */
	private function write_zip_manifest_from_zip( $zip_path ) {
		global $backupplus;

		$zip_files = array();

		$zip = new $this->use_zip_object();
		if ( true !== $zip->open( $zip_path ) ) {
			$backupplus->log( 'Could not open zip file to examine (' . $zip->last_error . '); file: ' . basename( $zip_path ) );
			return false;
		} else {
			// Don't put this in the for loop, or the magic __get() method gets repeatedly called every time the loop goes round
			$numfiles = $zip->numFiles;

			if ( false === $numfiles ) {
				$backupplus->log( 'write_zip_manifest_from_zip(): Could not read any files from the zip: (' . basename( $zip_path ) . ') Zip error: (' . $zip->last_error . ')' );
			}

			for ( $i = 0; $i < $numfiles; $i++ ) {
				$si   = $zip->statIndex( $i );
				$name = $si['name'];
				// Exclude folders
				if ( '/' == substr( $name, -1 ) ) {
					continue;
				}
				$zip_files[ $name ] = $si['size'];
			}

			@$zip->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
		}

		if ( empty( $zip_files ) ) {
			$backupplus->log( 'Did not find any files in the zip: ' . basename( $zip_path ) );
			return false;
		}

		if ( preg_match( '/\.tmp$/', $zip_path ) ) {
			$manifest = preg_replace( '/\.tmp$/', '.list-temp.tmp', $zip_path );
		} else {
			$manifest = $zip_path . '.list-temp.tmp';
		}

		$this->write_zip_manifest_from_list( $manifest, $zip_files );

		return true;
	}

	/**
	 * This function will create and write the contents of the zip manifest
	 *
	 * @param string $manifest  - path of the manifest file
	 * @param array  $zip_files - an array of files and their sizes
	 *
	 * @return boolean - returns false on failure to write
	 */
	private function write_zip_manifest_from_list( $manifest, $zip_files ) {
		global $backupplus;

		$backupplus->log( 'Creating zip file manifest (' . basename( $manifest ) . ')' );

		if ( false === ( $handle = fopen( $manifest, 'w+' ) ) ) {
			$backupplus->log( 'Failed to open zip manifest file (' . basename( $manifest ) . ')' );
			return false;
		}

		$version = 1;

		if ( false === fwrite( $handle, '{"version":' . $version . ', "files":[{' ) ) {
			$backupplus->log( 'First write to manifest file failed (' . basename( $manifest ) . ')' );
			return false;
		}

		$last_dir_index = key( array_slice( $zip_files, -1, 1, true ) );

		foreach ( $zip_files as $name => $size ) {
			fwrite( $handle, json_encode( $name ) . ' : ' . $size . ( ( $name != $last_dir_index ) ? ',' : '' ) );
		}

		fwrite( $handle, '}]}' );
		fclose( $handle );

		$backupplus->log( 'Successfully created zip file manifest (size: ' . filesize( $manifest ) . ')' );

		$final_manifest = preg_replace( '/\.list-temp.tmp$/', '.list.tmp', $manifest );
		rename( $manifest, $final_manifest );
	}
}
