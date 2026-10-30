<?php

/** Table level work during a database restore: creation, renaming, locking and dropping. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Database_Tables {

	/**
	 * Prepare the create table statement before sending it to the query execution
	 *
	 * @param String $create_table_statement an SQL create table statement in which some part of the SQL is going to be parsed and/or replaced
	 * @param String $import_table_prefix    table prefix to use
	 * @param Array  $supported_engines      the list of supported DB engines
	 * @param Array  $supported_charsets     the list of supported DB charsets
	 * @param Array  $supported_collations   the list of supported DB collations
	 * @return String the processed create table statement that may have been transformed, sanitised or cleaned
	 */
	private function prepare_create_table( $create_table_statement, $import_table_prefix, $supported_engines, $supported_charsets, $supported_collations ) {

		global $backupplus, $wpdb;

		$backupplus_restorer_collate = isset( $this->restore_options['backupplus_restorer_collate'] ) ? $this->restore_options['backupplus_restorer_collate'] : '';

		$non_wp_table = false;

		// Legacy, less reliable - in case it was not caught before. We added it in here (CREATE) as well as in DROP because of SQL dumps which lack DROP statements.
		if ( null === $this->old_table_prefix && preg_match( '/^([a-z0-9]+)_.*$/i', $this->table_name, $tmatches ) ) {
			$this->old_table_prefix = $tmatches[1] . '_';
			$backupplus->log( __( 'Old table prefix:', 'backupplus' ) . ' ' . $this->old_table_prefix, 'notice-restore', 'old-table-prefix' );
			$backupplus->log( 'Old table prefix (detected from creating first table): ' . $this->old_table_prefix );
		}

		// MySQL 4.1 outputs TYPE=, but accepts ENGINE=; 5.1 onwards accept *only* ENGINE=
		$create_table_statement = BackupPlus_Manipulation_Functions::str_lreplace( 'TYPE=', 'ENGINE=', $create_table_statement );

		if ( '' === $this->old_table_prefix ) {
			$this->new_table_name = $import_table_prefix . $this->table_name;
		} else {
			$this->new_table_name = $this->old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;
			// if we have a different prefix but the table name has not changed after the replace then we are dealing with a table that does not use the WordPress table prefix, in order for an Atomic restore to work on this table we need to attach our temporary prefix
			if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
				$non_wp_table         = true;
				$this->new_table_name = $import_table_prefix . $this->table_name;
			}
		}

		// This CREATE TABLE command may be the de-facto mark for the end of processing a previous table (which is so if this is not the first table in the SQL dump)
		if ( $this->restoring_table ) {

			// Attempt to reconnect if the DB connection dropped (may not succeed, of course - but that will soon become evident)
			$backupplus->check_db_connection( $this->wpdb_obj );

			// After restoring the options table, we can set old_siteurl if on legacy (i.e. not already set)
			if ( $this->restoring_table == $import_table_prefix . 'options' ) {
				if ( '' == $this->old_siteurl || '' == $this->old_home || '' == $this->old_content ) {
					$options_table = $import_table_prefix . 'options';

					if ( '' == $this->old_siteurl ) {
						$this->old_siteurl = untrailingslashit( BackupPlus_SQL::wpdb()->row( "SELECT option_value FROM %i WHERE option_name='siteurl'", array( $options_table ) )->option_value );
						do_action( 'backupplus_restore_db_record_old_siteurl', $this->old_siteurl );
					}
					if ( '' == $this->old_home ) {
						$this->old_home = untrailingslashit( BackupPlus_SQL::wpdb()->row( "SELECT option_value FROM %i WHERE option_name='home'", array( $options_table ) )->option_value );
						do_action( 'backupplus_restore_db_record_old_home', $this->old_home );
					}
					if ( '' == $this->old_content ) {
						$this->old_content = $this->old_siteurl . '/wp-content';
						do_action( 'backupplus_restore_db_record_old_content', $this->old_content );
					}
				}
			}

			if ( '' !== $this->restoring_table && $this->restoring_table != $this->new_table_name ) {
				$final_table_name = $this->maybe_rename_restored_table();
				$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
			}
		}

		// Detect this as early as possible so we can turn off atomic restores if needed. If the table prefix has changed and key constraints are found, make sure they are updated
		$constraint_change_message = '';
		$constraints               = array();
		$constraint_found          = false;
		if ( preg_match_all( '/CONSTRAINT ([\a-zA-Z0-9_\']+) FOREIGN KEY \([a-zA-z0-9_\', ]+\) REFERENCES \'?([a-zA-z0-9_]+)\'? /i', $create_table_statement, $constraint_matches ) ) {
			$constraints      = $constraint_matches;
			$constraint_found = true;
		} elseif ( preg_match_all( '/ FOREIGN KEY \([a-zA-z0-9_\', ]+\) REFERENCES \'?([a-zA-z0-9_]+)\'? /i', $create_table_statement, $constraint_matches ) ) {
			$constraints      = $constraint_matches;
			$constraint_found = true;
		}

		// Constraints were found so we need to disable the atomic restore for this table, which means resetting the import table prefix and current table name and finally dropping the original table if it exists
		if ( $constraint_found && empty( $this->db_permissons_forbidden['rename'] ) && ! $this->is_dummy_db_restore ) {

			$import_table_prefix                   = $this->final_import_table_prefix;
			$this->disable_atomic_on_current_table = true;

			if ( '' === $this->old_table_prefix ) {
				$this->new_table_name = $import_table_prefix . $this->table_name;
			} else {
				$this->new_table_name = $this->old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;
			}

			$backupplus->log( 'Constraints found, will disable atomic restore for current table (' . $this->table_name . ')', 'notice-restore' );

			$this->drop_tables( array( $this->new_table_name ) );

		}

		$this->table_engine    = '(?)';
		$engine_change_message = '';
		if ( preg_match( '/ENGINE=([^\s;]+)/', $create_table_statement, $eng_match ) ) {
			$this->table_engine = $eng_match[1];
			if ( isset( $supported_engines[ strtolower( $this->table_engine ) ] ) ) {
				if ( 'myisam' == strtolower( $this->table_engine ) ) {
					$create_table_statement = preg_replace( '/PAGE_CHECKSUM=\d\s?/', '', $create_table_statement, 1 );
				}
			} else {
				/* translators: %s: Table engine name */
				$engine_change_message  = sprintf( __( 'Requested table engine (%s) is not present - changing to MyISAM.', 'backupplus' ), $this->table_engine ) . '<br>';
				$create_table_statement = BackupPlus_Manipulation_Functions::str_lreplace( "ENGINE=$this->table_engine", 'ENGINE=MyISAM', $create_table_statement );
				$this->table_engine     = 'MyISAM';
				// Remove (M)aria options
				if ( 'maria' == strtolower( $this->table_engine ) || 'aria' == strtolower( $this->table_engine ) || 'myisam' == strtolower( $this->table_engine ) ) {
					$create_table_statement = preg_replace( '/PAGE_CHECKSUM=\d\s?/', '', $create_table_statement, 1 );
					$create_table_statement = preg_replace( '/TRANSACTIONAL=\d\s?/', '', $create_table_statement, 1 );
				}
			}
		}
		$charset_change_message = '';
		if ( preg_match_all( '/\b(CHARSET|CHARACTER SET)(\s*=?\s*)([^\s;,]+)(?:\s+(COLLATE)(\s*=?\s*)([^\s;,]+))?/i', $create_table_statement, $matches, PREG_SET_ORDER ) ) {
			$charset_replaced = array();

			$table_definitions = end( $matches );
			// Extract the character set from the CREATE TABLE statement
			$table_charset = ! empty( $table_definitions[3] ) ? $table_definitions[3] : '';
			// Extract the collation from the CREATE TABLE statement
			$table_collation = ! empty( $table_definitions[6] ) ? $table_definitions[6] : '';

			foreach ( $matches as $match ) {
				$full_match        = $match[0];
				$charset_keyword   = $match[1];
				$charset_separator = $match[2];
				$charset_original  = $match[3];
				$collate_keyword   = isset( $match[4] ) ? $match[4] : 'COLLATE';
				$collate_separator = isset( $match[5] ) ? $match[5] : ' ';

				if ( ! isset( $supported_charsets[ strtolower( $charset_original ) ] ) && ! in_array( $full_match, $charset_replaced ) ) {
					if ( ! empty( $this->restore_options['backupplus_restorer_charset'] ) ) {
						// Use the character set from the restoration settings
						$change_charset_to = $charset_keyword . $charset_separator . $this->restore_options['backupplus_restorer_charset'];

						if ( ! empty( $backupplus_restorer_collate ) && 'choose_a_default_for_each_table' != $backupplus_restorer_collate ) {
							$change_charset_to .= ' ' . $collate_keyword . $collate_separator . $backupplus_restorer_collate;
						}
					} elseif ( ! empty( $table_charset ) && isset( $supported_charsets[ strtolower( $table_charset ) ] ) ) {
						// Use the character set from the CREATE TABLE statement if no override is provided
						$change_charset_to = $charset_keyword . $charset_separator . $table_charset;

						// Use the collation from the CREATE TABLE statement if no override is provided
						if ( ! empty( $table_collation ) && isset( $supported_collations[ strtolower( $table_collation ) ] ) ) {
							$change_charset_to .= ' ' . $collate_keyword . $collate_separator . $table_collation;
						}
					} else {
						// Remove character set
						$change_charset_to = '';
					}

					$charset_replaced[] = $full_match;

					/* translators: 1: Requested character set, 2: Fallback character set. */
					$charset_change_message = sprintf( __( 'Requested character set (%1$s) is not present - changing to %2$s.', 'backupplus' ), esc_html( $charset_original ), esc_html( $this->restore_options['backupplus_restorer_charset'] ) );

					$create_table_statement = str_replace( $full_match, $change_charset_to, $create_table_statement );
				}
			}
		}

		if ( ! empty( $constraints ) && $this->old_table_prefix != $this->final_import_table_prefix ) {
			foreach ( $constraints[0] as $constraint ) {
				$updated_constraint     = str_replace( $this->old_table_prefix, $this->final_import_table_prefix, $constraint );
				$create_table_statement = str_replace( $constraint, $updated_constraint, $create_table_statement );
			}
			$constraint_change_message = __( 'Found and replaced existing table foreign key constraints as the table prefix has changed.', 'backupplus' );
		}

		$collate_change_message           = '';
		$unsupported_collates_in_sql_line = array();
		if ( ! empty( $backupplus_restorer_collate ) && preg_match( '/ COLLATE=([a-zA-Z0-9._-]+)/i', $create_table_statement, $collate_match ) ) {
			$collate = $collate_match[1];
			if ( ! isset( $supported_collations[ strtolower( $collate ) ] ) ) {
				$unsupported_collates_in_sql_line[] = $collate;
				if ( 'choose_a_default_for_each_table' == $backupplus_restorer_collate ) {
					$create_table_statement = BackupPlus_Manipulation_Functions::str_lreplace( "COLLATE=$collate", '', $create_table_statement, false );
				} else {
					$create_table_statement = BackupPlus_Manipulation_Functions::str_lreplace( "COLLATE=$collate", 'COLLATE=' . $backupplus_restorer_collate, $create_table_statement, false );
				}
			}
		}
		if ( ! empty( $backupplus_restorer_collate ) && preg_match_all( '/ COLLATE ([a-zA-Z0-9._-]+) /i', $create_table_statement, $collate_matches ) ) {
			$collates = array_unique( $collate_matches[1] );
			foreach ( $collates as $collate ) {
				if ( ! isset( $supported_collations[ strtolower( $collate ) ] ) ) {
					$unsupported_collates_in_sql_line[] = $collate;
					if ( 'choose_a_default_for_each_table' == $backupplus_restorer_collate ) {
						$create_table_statement = str_ireplace( "COLLATE $collate ", '', $create_table_statement );
					} else {
						$create_table_statement = str_ireplace( "COLLATE $collate ", 'COLLATE ' . $backupplus_restorer_collate . ' ', $create_table_statement );
					}
				}
			}
		}
		if ( ! empty( $backupplus_restorer_collate ) && preg_match_all( '/ COLLATE ([a-zA-Z0-9._-]+),/i', $create_table_statement, $collate_matches ) ) {
			$collates = array_unique( $collate_matches[1] );
			foreach ( $collates as $collate ) {
				if ( ! isset( $supported_collations[ strtolower( $collate ) ] ) ) {
					$unsupported_collates_in_sql_line[] = $collate;
					if ( 'choose_a_default_for_each_table' == $backupplus_restorer_collate ) {
						$create_table_statement = str_ireplace( "COLLATE $collate,", ',', $create_table_statement );
					} else {
						$create_table_statement = str_ireplace( "COLLATE $collate,", 'COLLATE ' . $backupplus_restorer_collate . ',', $create_table_statement );
					}
				}
			}
		}
		if ( count( $unsupported_collates_in_sql_line ) > 0 ) {
			$unsupported_unique_collates_in_sql_line = array_unique( $unsupported_collates_in_sql_line );
			/* translators: 1: Table collation name that does not exist, 2: Table collation name to change to */
			$collate_change_message = sprintf( _n( 'Requested table collation (%1$s) is not present - changing to %2$s.', 'Requested table collations (%1$s) are not present - changing to %2$s.', count( $unsupported_unique_collates_in_sql_line ), 'backupplus' ), esc_html( implode( ', ', $unsupported_unique_collates_in_sql_line ) ), esc_html( $this->restore_options['backupplus_restorer_collate'] ) );
		}
		/* translators: %s: Table engine and table name */
		$print_line = sprintf( __( 'Processing table (%s)', 'backupplus' ), $this->table_engine ) . ':  ' . $this->table_name;
		$logline    = "Processing table ($this->table_engine): " . $this->table_name;
		if ( null !== $this->old_table_prefix && $import_table_prefix != $this->old_table_prefix ) {
			if ( $this->restore_this_table( $this->table_name ) ) {
				$print_line .= ' - ' . __( 'will restore as:', 'backupplus' ) . ' ' . htmlspecialchars( $this->new_table_name );
				$logline    .= ' - will restore as: ' . $this->new_table_name;
			} else {
				$logline .= ' - skipping';
			}
			if ( '' === $this->old_table_prefix || $non_wp_table ) {
				$create_table_statement = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->new_table_name, $create_table_statement );
			} else {
				$create_table_statement = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $create_table_statement );
			}

			$this->restored_table_names[] = $this->new_table_name;
		}

		if ( ! empty( $this->generated_columns[ $this->table_name ] ) && isset( $this->generated_columns[ $this->table_name ]['columns'] ) ) {

			// get all the keys definition in the create table statement if any.
			// preg_match_all('/\s*[^,]+?key\s*[^(]+\(\s*(`.+?(?:\)|`))\s*\),?/i', $create_table_statement, $key_definitions);
			// https://regex101.com/r/NEXaLy/1/
			preg_match_all( '/(?<![\S"\',])[^"\',]+?KEY\s*[^(]+\(\s*(`.+?(?:\)|`))\s*\)\s*(?:,|\))(?![\S"\',])/i', $create_table_statement, $key_definitions );

			$reversed_generated_columns = array_reverse( (array) $this->generated_columns[ $this->table_name ]['columns'] );
			foreach ( (array) $reversed_generated_columns as $generated_column ) {
				if ( empty( $generated_column ) ) {
					continue;
				}
				if ( ! isset( $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) ) {
					$this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] = BackupPlus_Database_Utility::is_generated_column_supported( $this->table_engine );
				}
				if ( $generated_column_db_info = $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) {

					$reversed_data_type_definition = array_reverse( (array) $generated_column['column_data_type_definition'] );
					foreach ( $reversed_data_type_definition as $key => &$data_type_definition ) {
						if ( in_array( $key, array( 'DATA_TYPE_TOKEN', 'GENERATED_ALWAYS_TOKEN', 'COMMENT_TOKEN' ) ) ) {
							continue; // we dont want to replace "not null" in the "generated always as" expression, neither in the comments' string as well, so we continue
						}
						if ( empty( $data_type_definition ) || 0 === strlen( trim( $data_type_definition[0] ) ) ) {
							continue;
						}
						if ( ! $generated_column_db_info['is_not_null_supported'] ) {
							// If the database server doesn't support either null or not null constraint on generated virtual/stored/persistent column then the constraints need to be removed
							$replaced_data_type_definition = preg_replace( '/\b(?:not\s+null|null)\b/i', '', $data_type_definition[0] );
							$create_table_statement        = substr_replace( $create_table_statement, $replaced_data_type_definition, $data_type_definition[1], strlen( $data_type_definition[0] ) );
							$data_type_definition[0]       = $replaced_data_type_definition;
						}
						if ( ! $generated_column['is_virtual'] && ! $generated_column_db_info['is_persistent_supported'] ) {
							// If the persistent type is not supported it likely means that the currently running db server is MySQL, Mariadb uses persistent as an alias for stored type so if the backup file is taken from MariaDB then it needs to be changed to stored
							$replaced_data_type_definition = preg_replace( '/\bpersistent\b/i', 'STORED', $data_type_definition[0] );
							$create_table_statement        = substr_replace( $create_table_statement, $replaced_data_type_definition, $data_type_definition[1], strlen( $data_type_definition[0] ) );
							$data_type_definition[0]       = $replaced_data_type_definition;
						}
					}

					if ( $generated_column['is_virtual'] && ( $generated_column_db_info['can_insert_ignore_to_generated_column'] || ( isset( $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) && false === $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) ) && ! $generated_column_db_info['is_virtual_index_supported'] && ! empty( $key_definitions ) ) {
						// MySQL doesn't support index on MyISAM's virtual generated column, in case that the restoration process is importing from MariaDB backup file which contains create index definition on virtual generated column then it needs to be removed too
						// the column can be defined as a single or composite index, so we have no choice but loop until the end
						foreach ( $key_definitions[1] as $array_index => $column_names ) {
							if ( empty( $column_names ) ) {
								continue;
							}
							if ( empty( $key_definitions[0][ $array_index ] ) ) {
								continue;
							}
							if ( is_numeric( stripos( $column_names, $generated_column['column_name'] ) ) ) {
								$replaced_key_definition            = preg_replace( '/(\s*,?[^,]+?key\s*[^(]+\(.*?)(`' . $generated_column['column_name'] . '`\s*(?:\([0-9]+\)\s*)?,|,\s*`' . $generated_column['column_name'] . '`\s*(?:\([0-9]+\)\s*)?|\s*`' . $generated_column['column_name'] . '`\s*(?:\([0-9]+\)\s*)?)(.*\))/ism', '$1$3', $key_definitions[0][ $array_index ] );
								$create_table_statement             = str_ireplace( $key_definitions[0][ $array_index ], $replaced_key_definition, $create_table_statement );
								$key_definitions[0][ $array_index ] = $replaced_key_definition;
								if ( preg_match( '/\s*,?[^,]+?key\s*[^(]+\(\s*\)\s*,?/i', $key_definitions[0][ $array_index ] ) ) {
									$create_table_statement             = preg_replace( '/\s*,?[^,]+?key\s+[^(]+\(\s*\)\s*/i', '', $create_table_statement );
									$key_definitions[0][ $array_index ] = '';
								}
							}
						}
					}

					if ( ! $generated_column_db_info['can_insert_ignore_to_generated_column'] && isset( $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) && true === $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) {
						foreach ( $reversed_data_type_definition as $key => &$data_type_definition ) {
							if ( empty( $data_type_definition ) || 0 === strlen( trim( $data_type_definition[0] ) ) ) {
								continue;
							}
							if ( 'GENERATED_ALWAYS_TOKEN' === $key ) {
								// if it's not possible to use insert ignore for the generated column even if the sql strict mode has been turned off then first we need to change the generated column to a normal/standard column
								$create_table_statement  = substr_replace( $create_table_statement, '', $data_type_definition[1], strlen( $data_type_definition[0] ) );
								$data_type_definition[0] = '';
							} elseif ( ! in_array( $key, array( 'DATA_TYPE_TOKEN', 'COMMENT_TOKEN' ) ) ) {
								// since "comments" and "generated always as" could contain a string of these keywords (virtual/stored/persistent), so we can't use preg_replace and $generated_column['column_definition'] var as the subject to replace the keyword to an empty string, but instead we lookup the keyword through column_data_type_definition that has captured data type definitions
								$replaced_data_type_definition = preg_replace( '/\b(?:virtual|stored|persistent)\b/i', '', $data_type_definition[0] );
								$create_table_statement        = substr_replace( $create_table_statement, $replaced_data_type_definition, $data_type_definition[1], strlen( $data_type_definition[0] ) );
								$data_type_definition[0]       = $replaced_data_type_definition;
							}
						}
						// once the create table and also the insert ignore statement for the corresponding table have been executed, we will use alter table statement to change back the columns to STORED type
						// this is the only way to avoid "value specified for generated column is not allowed" error, and I think it is the best we can do for now rather than checking the insert statement for virtual columns and replacing the value with DEFAULT
					}
				} else {
					// generated column is not supported but we found a virtual/stored/persistent column type, so it needs to be changed to a normal/standard column
					// we need to keep the generated column along with its single key index and composite key index so that the select statement on the upper layer of the application which selects the the virtual column does not break the application itself due to unknown column error
					$reversed_data_type_definition = array_reverse( (array) $generated_column['column_data_type_definition'] );
					foreach ( $reversed_data_type_definition as $key => &$data_type_definition ) {
						if ( empty( $data_type_definition ) || 0 === strlen( trim( $data_type_definition[0] ) ) ) {
							continue;
						}
						if ( 'GENERATED_ALWAYS_TOKEN' === $key ) {
							$create_table_statement  = substr_replace( $create_table_statement, '', $data_type_definition[1], strlen( $data_type_definition[0] ) );
							$data_type_definition[0] = '';
						} elseif ( ! in_array( $key, array( 'DATA_TYPE_TOKEN', 'COMMENT_TOKEN' ) ) ) {
							$replaced_data_type_definition = preg_replace( '/\b(?:virtual|stored|persistent)\b/i', '', $data_type_definition[0] );
							$create_table_statement        = substr_replace( $create_table_statement, $replaced_data_type_definition, $data_type_definition[1], strlen( $data_type_definition[0] ) );
							$data_type_definition[0]       = $replaced_data_type_definition;
						}
					}
				}
			}
		}

		$backupplus->log( $logline );
		$backupplus->log( $print_line, 'notice-restore' );
		// If this is a non wp table we don't want to replace our temp prefix with the final prefix, we need to drop our prefix
		if ( $non_wp_table ) {
			$this->original_table_name = BackupPlus_Manipulation_Functions::str_replace_once( $this->import_table_prefix, '', $this->new_table_name );
		} else {
			$this->original_table_name = BackupPlus_Manipulation_Functions::str_replace_once( $this->import_table_prefix, $this->final_import_table_prefix, $this->new_table_name );
		}
		$this->restoring_table = $this->new_table_name;
		if ( $charset_change_message ) {
			$backupplus->log( $charset_change_message, 'notice-restore' );
		}
		if ( $constraint_change_message ) {
			$backupplus->log( $constraint_change_message, 'notice-restore' );
		}
		if ( $collate_change_message ) {
			$backupplus->log( $collate_change_message, 'notice-restore' );
		}
		if ( $engine_change_message ) {
			$backupplus->log( $engine_change_message, 'notice-restore' );
		}
		return $create_table_statement;
	}

	/**
	 * This function will check if the passed in table should be skipped e.g it was processed on another run
	 *
	 * @param string $table_name - the name of the table we want to check
	 *
	 * @return boolean - returns true if we should skip the table, otherwise false if the table should be processed
	 */
	private function table_should_be_skipped( $table_name ) {

		global $backupplus;

		$skip_table = false;
		$last_table = isset( $this->continuation_data['last_processed_db_table'] ) ? $this->continuation_data['last_processed_db_table'] : '';

		$table_should_be_skipped = false;

		if ( ! empty( $this->tables_to_skip ) && in_array( $table_name, $this->tables_to_skip ) ) {
			$table_should_be_skipped = true;
		} elseif ( ! empty( $this->tables_to_restore ) && ! in_array( $table_name, $this->tables_to_restore ) && ! $this->include_unspecified_tables ) {
			$table_should_be_skipped = true;
		}

		if ( $table_should_be_skipped ) {
			if ( empty( $this->previous_table_name ) || $table_name != $this->previous_table_name ) {
				/* translators: %s: Table name to be excluded (user's chosen)*/
				$backupplus->log( sprintf( __( 'Skipping table %s: user has chosen not to restore this table', 'backupplus' ), $table_name ), 'notice-restore' );
			}
			$skip_table = true;
		} elseif ( ! empty( $last_table ) && ! empty( $table_name ) && $table_name != $last_table ) {
			/* translators: 1: Table name, 2: Last table name */
			if ( empty( $this->previous_table_name ) || $table_name != $this->previous_table_name ) {
				$backupplus->log( sprintf( __( 'Skipping table %1$s: already restored on a prior run; next table to restore: %2$s', 'backupplus' ), $table_name, $last_table ), 'notice-restore' );
			}
			$skip_table = true;
		} elseif ( ! empty( $last_table ) && ! empty( $table_name ) && $table_name == $last_table ) {
			unset( $this->continuation_data['last_processed_db_table'] );
			$skip_table = false;
		}

		$this->previous_table_name = $table_name;

		if ( ! $skip_table ) {
			$backupplus->log_restore_update(
				array(
					'type'  => 'state',
					'stage' => 'db',
					'data'  => array(
						'stage' => 'table',
						'table' => $table_name,
					),
				)
			);
			$backupplus->jobdata_set( 'last_processed_db_table', $table_name );
		}

		return $skip_table;
	}

	/**
	 * This function will check if we are performing an atomic restore by renaming a temporary table to the final table name and returning the final table name
	 *
	 * @return string - returns the final table name
	 */
	private function maybe_rename_restored_table() {
		global $backupplus;

		// If this is set then we do not want to attempt an atomic restore as it will remove the final table
		if ( $this->disable_atomic_on_current_table ) {
			$this->disable_atomic_on_current_table = false;
			return $this->original_table_name;
		}

		// If the table names are the same then we do not want to attempt an atomic restore as it will remove the final table
		if ( $this->original_table_name == $this->restoring_table ) {
			return $this->original_table_name;
		}

		// If we have skipped this table then we don't want to attempt the atomic restore
		if ( ! $this->restore_this_table( $this->original_table_name ) ) {
			return $this->original_table_name;
		}

		if ( empty( $this->db_permissions_forbidden['rename'] ) ) {
			$backupplus->log_e( 'Atomic restore: dropping original table (%s)', $this->original_table_name );
			$this->drop_tables( array( $this->original_table_name ) );
			$backupplus->log_e( 'Atomic restore: renaming new table (%s) to final table name (%s)', $this->restoring_table, $this->original_table_name );
			$this->rename_table( $this->restoring_table, $this->original_table_name );
		}

		return $this->original_table_name;
	}

	/**
	 * This function will try to rename a table, if successful returns true otherwise returns an error number
	 *
	 * @param string $current_table_name - the current table name
	 * @param string $new_table_name     - the new table name
	 *
	 * @return boolean|integer - returns true if successful otherwise an error number
	 */
	private function rename_table( $current_table_name, $new_table_name ) {
		return $this->sql_exec( BackupPlus_SQL::render( 'ALTER TABLE %i RENAME TO %i', array( $current_table_name, $new_table_name ) ), 14, '', false );
	}

	private function lock_table( $table ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Function isnt being used yet
		// Not yet working
		return true;
	}

	public function unlock_tables() {
		// Not yet working
	}

	/**
	 * Called when a table has been restored
	 *
	 * @param String $table               - The full table name that has been restored
	 * @param String $import_table_prefix - The table prefix being used to import
	 * @param String $old_table_prefix    - The table prefix in the backup file
	 * @param String $engine              - The database engine, if known
	 */
	private function restored_table( $table, $import_table_prefix, $old_table_prefix, $engine = '' ) {

		$table_without_prefix = substr( $table, strlen( $import_table_prefix ) );

		if ( isset( $this->restore_this_table[ $old_table_prefix . $table_without_prefix ] ) && ! $this->restore_this_table[ $old_table_prefix . $table_without_prefix ] ) {
			return;
		}

		global $wpdb, $backupplus;

		if ( $table == $import_table_prefix . BackupPlus_Options::options_table() ) {
			// This became necessary somewhere around WP 4.5 - otherwise deleting and re-saving options stopped working
			wp_cache_flush();
			$this->restore_configuration_bundle( $table );
		}

		if ( $this->is_multisite && $import_table_prefix . 'sitemeta' === $table ) {
			// The network options cached before the table was replaced are stale: the running job's data, kept there, is saved again from memory only once they are gone
			wp_cache_flush();
			// the reason why this if statement is needed is that when *_options table is not included in the backup, then backupplus_jobdata will never be wiped out from the *_sitemeta table as the below elseif statement will call wipe_state_data() only if *_options table is included
			$backupplus->wipe_state_data( false, 'sitemeta' );
		} elseif ( preg_match( '/^(\d+_)?options$/', substr( $table, strlen( $import_table_prefix ) ), $matches ) ) {
			// The second prefix here used to have a '!$this->is_multisite' on it (i.e. 'options' table on non-multisite). However, the user_roles entry exists in the main options table on multisite too.
			if ( ( $this->is_multisite && ! empty( $matches[1] ) ) || $table == $import_table_prefix . 'options' ) {

				if ( ! $this->has_table_selection() || $table === $wpdb->options ) {
					$backupplus->wipe_state_data();
				}
				if ( $table === $wpdb->options ) {
					$this->restore_lock_rows( $table );
				}

				$mprefix = empty( $matches[1] ) ? '' : $matches[1];

				$new_table_name = $import_table_prefix . $mprefix . 'options';
				$sql            = BackupPlus_SQL::wpdb();

				// WordPress has an option name predicated upon the table prefix. Yuk.
				if ( $import_table_prefix != $old_table_prefix ) {
					$backupplus->log( 'Table prefix has changed: changing options table field(s) accordingly (' . $mprefix . 'options)' );
					/* translators: %s: String 'option' for table */
					$print_line = sprintf( __( 'Table prefix has changed: changing %s table field(s) accordingly:', 'backupplus' ), 'option' ) . ' ';
					if ( false === $sql->execute( 'UPDATE %i SET option_name = ? WHERE option_name = ? LIMIT 1', array( $new_table_name ), array( $import_table_prefix . $mprefix . 'user_roles', $old_table_prefix . $mprefix . 'user_roles' ) ) ) {
						$print_line .= __( 'Error', 'backupplus' );
						$backupplus->log( 'Error when changing options table fields: ' . $sql->last_error() );
					} else {
						$backupplus->log( 'Options table fields changed OK' );
						$print_line .= __( 'OK', 'backupplus' );
					}
					$backupplus->log( $print_line, 'notice-restore' );
				}

				// Now deal with the situation where the imported database sets a new over-ride upload_path that is absolute - which may not be wanted
				$new_upload_path = $sql->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $new_table_name ), array( 'upload_path' ) );
				$new_upload_path = ( is_object( $new_upload_path ) ) ? $new_upload_path->option_value : '';
				// The danger situation is absolute and points somewhere that is now perhaps not accessible at all

				if ( ! empty( $new_upload_path ) && $new_upload_path != $this->prior_upload_path && ( strpos( $new_upload_path, '/' ) === 0 ) || preg_match( '#^[A-Za-z]:[/\\\]#', $new_upload_path ) ) {

					// $this->old_siteurl != untrailingslashit(site_url()) is not a perfect proxy for "is a migration" (other possibilities exist), but since the upload_path option should not exist since WP 3.5 anyway, the chances of other possibilities are vanishingly small
					if ( ! file_exists( $new_upload_path ) || $this->old_siteurl != $this->our_siteurl ) {

						if ( ! file_exists( $new_upload_path ) ) {
							$backupplus->log_e( 'Uploads path (%s) does not exist - resetting (%s)', $new_upload_path, $this->prior_upload_path );
						} else {
							$backupplus->log_e( 'Uploads path (%s) has changed during a migration - resetting (to: %s)', $new_upload_path, $this->prior_upload_path );
						}
						if ( false === $sql->execute( "UPDATE %i SET option_value = ? WHERE option_name = 'upload_path' LIMIT 1", array( $new_table_name ), array( $this->prior_upload_path ) ) ) {
							$backupplus->log( __( 'Error', 'backupplus' ), 'notice-restore' );
							$backupplus->log( 'Error when changing upload path: ' . $sql->last_error() );
							$backupplus->log( 'Failed' );
						}
					}
				}

				// TODO:Do on all WPMU tables
				if ( $table == $import_table_prefix . 'options' ) {
					// Bad plugin that hard-codes path references - https://wordpress.org/plugins/custom-content-type-manager/
					$cctm_data = $sql->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $new_table_name ), array( 'cctm_data' ) );
					if ( ! empty( $cctm_data->option_value ) ) {
						$cctm_data = empty( $cctm_data->option_value ) ? array() : $backupplus->unserialize( $cctm_data->option_value );
						if ( is_array( $cctm_data ) && ! empty( $cctm_data['cache'] ) && is_array( $cctm_data['cache'] ) ) {
							$cctm_data['cache'] = array();
							$backupplus->log_e( 'Custom content type manager plugin data detected: clearing option cache' );
							update_option( 'cctm_data', $cctm_data );
						}
					}
				}

				if ( $table == $import_table_prefix . $mprefix . 'options' ) {
					// Another - http://www.elegantthemes.com/gallery/elegant-builder/
					$elegant_data = $sql->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $new_table_name ), array( 'et_images_temp_folder' ) );
					if ( ! empty( $elegant_data->option_value ) ) {
						$dbase         = basename( $elegant_data->option_value );
						$wp_upload_dir = wp_upload_dir();
						$edir          = $wp_upload_dir['basedir'];
						$new_dir       = $edir . '/' . $dbase;
						if ( ! is_dir( $new_dir ) ) {
							@mkdir( $new_dir, 0775, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						}                       $backupplus->log_e( 'Elegant themes theme builder plugin data detected: resetting temporary folder' );
						$wpdb->update( $new_table_name, array( 'option_value' => $new_dir ), array( 'option_name' => 'et_images_temp_folder' ) );
					}

					// check if current restoration is a migration
					if ( ! empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) ) {
						$wp_rocket_settings = $sql->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $new_table_name ), array( 'wp_rocket_settings' ) );

						if ( ! empty( $wp_rocket_settings->option_value ) ) {
							$wp_rocket_settings = empty( $wp_rocket_settings->option_value ) ? array() : $backupplus->unserialize( $wp_rocket_settings->option_value );

							// if WP Rocket settings is found and cdn is enabled
							if ( isset( $wp_rocket_settings['cdn'] ) ) {
								unset( $wp_rocket_settings['cdn'] );
								$wpdb->update( $new_table_name, array( 'option_value' => serialize( $wp_rocket_settings ) ), array( 'option_name' => 'wp_rocket_settings' ) );

								$backupplus->log_e( 'WP Rocket CDN option detected: disabling the option' );
							}
						}
					}
				}

				// The gantry menu plugin sometimes uses too-long transient names, causing the timeout option to be missing; and hence the transient becomes permanent.
				// WP 3.4 onwards has $wpdb->delete(). But we support 3.2 onwards.
				$sql->execute( "DELETE FROM %i WHERE option_name LIKE '_transient_gantry-menu%' OR option_name LIKE '_transient_timeout_gantry-menu%'", array( $new_table_name ) );

				// Jetpack: see: https://wordpress.org/support/topic/issues-with-dev-site
				if ( $this->old_siteurl != $this->our_siteurl ) {
					$sql->execute( "DELETE FROM %i WHERE option_name = 'jetpack_options'", array( $new_table_name ) );
				}
			}
		} elseif ( $import_table_prefix != $old_table_prefix && preg_match( '/^(\d+_)?usermeta$/', substr( $table, strlen( $import_table_prefix ) ), $matches ) ) {

			// This table is not a per-site table, but per-install

			$backupplus->log( 'Table prefix has changed: changing usermeta table field(s) accordingly' );
			/* translators: %s: String 'usermeta' for table */
			$print_line = sprintf( __( 'Table prefix has changed: changing %s table field(s) accordingly:', 'backupplus' ), 'usermeta' ) . ' ';

			$errors_occurred = false;

			$usermeta_table = $import_table_prefix . 'usermeta';
			$old_keys       = BackupPlus_Database_Utility::esc_like( $old_table_prefix ) . '%';
			$sql            = BackupPlus_SQL::wpdb();

			if ( false === strpos( $old_table_prefix, '_' ) ) {
				// Old, slow way: do it row-by-row
				// A large database can take several minutes to process on a slow test machine.
				$old_prefix_length = strlen( $old_table_prefix );

				$meta_keys = $sql->rows( 'SELECT umeta_id, meta_key FROM %i WHERE meta_key LIKE ?', array( $usermeta_table ), array( $old_keys ) );
				if ( false === $meta_keys ) {
					$errors_occurred = true;
					$meta_keys       = array();
				}

				foreach ( $meta_keys as $meta_key ) {
					// Create new meta key
					$new_meta_key = $import_table_prefix . substr( $meta_key->meta_key, $old_prefix_length );

					if ( false === $sql->execute( 'UPDATE %i SET meta_key = ? WHERE umeta_id = ?', array( $usermeta_table ), array( $new_meta_key, $meta_key->umeta_id ) ) ) {
						$errors_occurred = true;
					}
				}
			} else {
				// New, fast way: do it in a single query
				if ( false === $sql->execute( 'UPDATE %i SET meta_key = REPLACE(meta_key, ?, ?) WHERE meta_key LIKE ?', array( $usermeta_table ), array( $old_table_prefix, $import_table_prefix, $old_keys ) ) ) {
					$errors_occurred = true;
				}
			}

			if ( $errors_occurred ) {
				$backupplus->log( 'Error when changing usermeta table fields' );
				$print_line .= __( 'Error', 'backupplus' );
			} else {
				$backupplus->log( 'Usermeta table fields changed OK' );
				$print_line .= __( 'OK', 'backupplus' );
			}
			$backupplus->log( $print_line, 'notice-restore' );

		}

		do_action( 'backupplus_restored_db_table', $table, $import_table_prefix, $engine );

		// Re-generate permalinks. Do this last - i.e. make sure everything else is fixed up first.
		if ( $table == $import_table_prefix . 'options' && ! $this->has_table_selection() ) {
			$this->flush_rewrite_rules();
		}
	}

	/**
	 * This function will search and replace ABSPATH in the restored table
	 *
	 * @param string $table               - the table to search and replace
	 * @param string $import_table_prefix - the import table prefix
	 *
	 * @return void
	 */
	public function backupplus_restored_db_table( $table, $import_table_prefix ) {

		global $backupplus;

		// If the restore URL replacement handler is active, it handles the path update without a second search-and-replace pass.
		if ( ! empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) ) {
			return;
		}

		// If old abspath is not empty then it has changed so try and search replace it, just in case a bad plugin/theme has saved abspath to the DB
		if ( ! empty( $this->old_abspath ) ) {
			$from_array     = array();
			$from_array[]   = $this->old_abspath;
			$to_array       = array();
			$to_array[]     = ABSPATH;
			$stripped_table = substr( $table, strlen( $import_table_prefix ) );
			// The search/replace parameters are allowed to be either strings or arrays
			$report = $this->search_replace_obj->icit_srdb_replacer( $from_array, $to_array, array( $table => $stripped_table ), 5000 );

			// Output any errors encountered during the db work.
			if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) {
				$backupplus->log( __( 'Error:', 'backupplus' ), 'warning-restore', 'restore-db-error' );
				$processed_errors = array();
				foreach ( $report['errors'] as $error ) {
					if ( in_array( $error, $processed_errors ) ) {
						continue;
					}
					$processed_errors[] = $error;
					$num                = count( array_keys( $report['errors'], $error ) );
					$err_string         = $error;
					if ( $num > 1 ) {
						$err_string .= ' (x' . $num . ')';
					}
					$backupplus->log( $err_string, 'warning-restore' );
				}
			} elseif ( false == $report ) {
				$backupplus->log(
					sprintf(
					/* translators: %s: String 'search and replace' */
						__( 'Failed: the %s operation was not able to start.', 'backupplus' ),
						__( 'search and replace', 'backupplus' )
					),
					'warning-notice'
				);
			} elseif ( ! is_array( $report ) ) {
				$backupplus->log(
					sprintf(
					/* translators: %s: String 'search and replace' */
						__( 'Failed: we did not understand the result returned by the %s operation.', 'backupplus' ),
						__( 'search and replace', 'backupplus' )
					),
					'warning-notice'
				);
			}
		}
	}

	/**
	 * This function will return a random table prefix
	 *
	 * @param String $string - default prefix
	 *
	 * @return String - the random prefix
	 */
	public function backupplus_random_restore_table_prefix( $string ) {
		while ( true ) {
			$random_string = BackupPlus_Manipulation_Functions::generate_random_string( 2 ) . '_';
			if ( $string != $random_string ) {
				if ( array() === BackupPlus_SQL::wpdb()->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', array(), array( BackupPlus_Database_Utility::esc_like( $random_string ) . '%' ) ) ) {
					return $random_string;
				}
			}
		}
	}

	/**
	 * Remember the locks held now: the options table of a backup has the locks held when it was taken (at least the lock
	 * of the backup itself), which describe jobs that are not running. Restored, they would stop backups and other jobs
	 * until they expired, while the locks held by this restore would disappear with the table being replaced.
	 */
	private function save_lock_rows() {
		global $wpdb;
		if ( null !== $this->lock_rows || $this->is_dummy_db_restore ) {
			return;
		}
		$rows            = BackupPlus_SQL::wpdb()->rows(
			'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ?',
			array( $wpdb->options ),
			self::lock_patterns(),
			ARRAY_A
		);
		$this->lock_rows = is_array( $rows ) ? $rows : array();
	}

	/**
	 * Put the locks held before the restore back into the restored options table, in place of the backup's
	 *
	 * @param String $table - the restored options table
	 */
	private function restore_lock_rows( $table ) {
		global $backupplus;
		if ( ! is_array( $this->lock_rows ) ) {
			return;
		}
		$sql = BackupPlus_SQL::wpdb();
		$sql->execute( 'DELETE FROM %i WHERE option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ?', array( $table ), self::lock_patterns() );
		foreach ( $this->lock_rows as $row ) {
			$sql->execute( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (?, ?, ?)', array( $table ), array( $row['option_name'], $row['option_value'], $row['autoload'] ) );
		}
		wp_cache_flush();
		$backupplus->log( 'Locks kept from before the restore: ' . count( $this->lock_rows ) );
	}

	/** Option names of BackupPlus's semaphores and WordPress upgrader locks. */
	private static function lock_patterns() {
		$patterns = array();
		foreach ( array( 'backupplus_lock_', 'backupplus_locked_', 'backupplus_unlocked_', 'backupplus_semaphore_', 'backupplus_last_lock_time_' ) as $prefix ) {
			$patterns[] = BackupPlus_Database_Utility::esc_like( $prefix ) . '%';
		}
		$patterns[] = BackupPlus_Database_Utility::esc_like( 'backupplus_' ) . '%' . BackupPlus_Database_Utility::esc_like( '.lock' );
		return $patterns;
	}

	/**
	 * This function will drop all tables from the database
	 *
	 * @param Array $tables - list of table names
	 */
	private function drop_tables( $tables ) {
		foreach ( $tables as $table ) {
			$this->sql_exec( BackupPlus_SQL::render( 'DROP TABLE IF EXISTS %i', array( $table ) ), 1, '', false );
		}
	}
}
