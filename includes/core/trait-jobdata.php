<?php

/** Reading and writing the persistent state of a backup job. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
require_once dirname( __DIR__ ) . '/class-jobdata-store.php';

trait BackupPlus_Core_Jobdata {

	/**
	 * Get all the job data in a single array
	 *
	 * @param String $job_id - the job identifier (nonce) for the job whose data is to be retrieved
	 *
	 * @return Array
	 */
	public function jobdata_getarray( $job_id ) {
		return $this->read_jobdata( $job_id );
	}

	private function read_jobdata( $job_id ) {
		$data = get_site_option( 'backupplus_jobdata_' . $job_id, array() );
		$data = BackupPlus_Jobdata_Store::unpack( $job_id, $data );
		return class_exists( 'BackupPlus_Credential_Storage' ) ? BackupPlus_Credential_Storage::job( $data, $job_id, 'decrypt' ) : $data;
	}

	private function save_jobdata() {
		$previous = get_site_option( 'backupplus_jobdata_' . $this->nonce, array() );
		$data     = class_exists( 'BackupPlus_Credential_Storage' ) ? BackupPlus_Credential_Storage::job( $this->jobdata, $this->nonce, 'encrypt', null, $previous ) : $this->jobdata;
		$data     = BackupPlus_Jobdata_Store::pack( $this->nonce, $data, $previous );
		if ( $data === $previous ) { return false; }
		$changed = update_site_option( 'backupplus_jobdata_' . $this->nonce, $data );
		if ( ! $changed && get_site_option( 'backupplus_jobdata_' . $this->nonce, array() ) !== $data ) { throw new RuntimeException( 'Could not persist the backup job checkpoint.' ); }
		return $changed;
	}

	public function jobdata_set_from_array( $array ) {
		$this->jobdata = $array;
		if ( ! empty( $this->nonce ) ) {
			$this->save_jobdata();
		}
	}

	/**
	 * This works with any amount of settings, but we provide also a jobdata_set for efficiency as normally there's only one setting
	 * You can list the keys/values (keys must be strings) as consecutive/alternating parameters, or send them all in as an array (with no other parameters)
	 *
	 * @return null
	 */
	public function jobdata_set_multi() {
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
		}
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = array();
		}

		$args = func_num_args();

		// func_get_arg() could not be used in parameter lists prior to PHP 5.3, so, we get it as a variable
		if ( 1 == $args && null !== ( $first_arg = func_get_arg( 0 ) ) && is_array( $first_arg ) ) {
			foreach ( $first_arg as $key => $value ) {
				$this->jobdata[ $key ] = $value;
			}
		} else {

			for ( $i = 1; $i <= $args / 2; $i++ ) {
				$key                   = func_get_arg( $i * 2 - 2 );
				$value                 = func_get_arg( $i * 2 - 1 );
				$this->jobdata[ $key ] = $value;
			}
		}
		if ( ! empty( $this->nonce ) ) {
			$this->save_jobdata();
		}
	}

	/**
	 * Set a job-data key/value pair for the current job
	 *
	 * @param String $key   - the key
	 * @param Mixed  $value - needs to be serializable
	 *
	 * @uses update_site_option()
	 */
	public function jobdata_set( $key, $value ) {
		if ( empty( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				$this->jobdata = array();
			}
		}
		$this->jobdata[ $key ] = $value;
		if ( $this->nonce ) {
			$this->save_jobdata();
		}
	}

	/**
	 * Delete a jobdata item, by key
	 *
	 * @param String $key
	 */
	public function jobdata_delete( $key ) {
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				$this->jobdata = array();
			}
		}
		unset( $this->jobdata[ $key ] );
		if ( $this->nonce ) {
			$this->save_jobdata();
		}
	}

	public function get_job_option( $opt ) {
		// These are meant to be read-only
		if ( empty( $this->jobdata['option_cache'] ) || ! is_array( $this->jobdata['option_cache'] ) ) {
			if ( ! is_array( $this->jobdata ) && $this->nonce ) {
				$this->jobdata = $this->read_jobdata( $this->nonce );
			}
			if ( empty( $this->jobdata['option_cache'] ) || ! is_array( $this->jobdata['option_cache'] ) ) {
				$this->jobdata['option_cache'] = array();
			}
		}
		return isset( $this->jobdata['option_cache'][ $opt ] ) ? $this->jobdata['option_cache'][ $opt ] : BackupPlus_Options::get_backupplus_option( $opt );
	}

	/**
	 * Get a job data item, or the specified default if it is not yet set
	 *
	 * @param String $key
	 * @param Mixed  $default
	 *
	 * @return Mixed
	 */
	public function jobdata_get( $key, $default = null ) {
		if ( empty( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				return $default;
			}
		}
		return isset( $this->jobdata[ $key ] ) ? $this->jobdata[ $key ] : $default;
	}

	/**
	 * Reset the job data for the currently active job (forcing a re-fetch from the database, if there is any)
	 */
	public function jobdata_reset() {
		$this->jobdata = null;
	}

	/**
	 * This function will update the database backup jobdata and set each entity to finished or encrypted to prevent that entity from being backed up again. This will also return the blog name that the database backup belongs to, just in case it's from another site.
	 *
	 * @param array $db_backups - the database backup jobdata
	 * @param array $backup     - the backup history for this backup
	 *
	 * @return array            - an array that contains the updated database backup jobdata and the blog name
	 */
	public function update_database_jobdata( $db_backups, $backup ) {

		$backup_database_info = array(
			'blog_name'  => '',
			'db_backups' => $db_backups,
		);

		if ( ! is_array( $db_backups ) ) {
			return $backup_database_info;
		}

		/*
			We need to tweak the database array here by setting each database entity to finished or encrypted if it's an encrypted archive.
			I also grab the backups blog name here ready to be used later, just in case this backup set is from another site.
		*/
		foreach ( $db_backups as $key => $db_info ) {
			$status   = 'finished';
			$db_index = ( 'wp' == $key ) ? '' : $key;

			if ( isset( $backup[ 'db' . $db_index ] ) ) {
				$db_backup_name = $backup[ 'db' . $db_index ];

				if ( preg_match( '/^backup_([\-0-9]{15})_(.*)_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|zip\.crypt|gz|gz\.crypt|tar\.zst|tar\.zst\.crypt|sql\.zst|sql\.zst\.crypt))?$/i', $db_backup_name, $matches ) ) {
					$backup_database_info['blog_name'] = $matches[2];
				}

				if ( BackupPlus_Encryption::is_file_encrypted( $db_backup_name ) ) {
					$status = 'encrypted';
				}

				if ( is_array( $db_info ) && isset( $db_info['status'] ) ) {
					$db_backups[ $key ]['status'] = $status;
				} else {
					$db_backups[ $key ] = $status;
				}
			} else {
				unset( $db_backups[ $key ] );
			}
		}

		$backup_database_info['db_backups'] = $db_backups;

		return $backup_database_info;
	}

	/**
	 * This function will update the files backup jobdata by constructing the backup entities and their sizes from the backup
	 *
	 * @param array $backup        - the backup array
	 * @param array $skip_entities - an array of entities to skip
	 *
	 * @return array - the files backup array
	 */
	public function update_files_jobdata( $backup, $skip_entities = array() ) {

		$file_backups        = array();
		$backupable_entities = $this->get_backupable_file_entities( true );

		// We need to construct the expected files array here, this gets added to the jobdata much later in the backup process but we need this before we start
		foreach ( $backupable_entities as $entity => $path ) {
			if ( in_array( $entity, $skip_entities ) ) {
				continue;
			}
			if ( isset( $backup[ $entity ] ) ) {
				$file_backups[ $entity ] = $backup[ $entity ];
			}
			if ( isset( $backup[ $entity . '-size' ] ) ) {
				$file_backups[ $entity . '-size' ] = $backup[ $entity . '-size' ];
			}
		}

		return $file_backups;
	}
}
