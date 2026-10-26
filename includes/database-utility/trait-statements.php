<?php

/** Reading dump statements: where one ends and which columns it generates. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Database_Utility_Statements {

	/**
	 * Whether the SQL is well-formed and complete in relation to balanced quotes, brackets, backticks and comments. This is *not* a complete SQL parser; its aim is to detect and fail unfinished SQL statements. It does not look at delimiters.
	 *
	 * @param String $sql - the SQL to  check
	 *
	 * @return Boolean
	 */
	public static function sql_statement_is_finished( $sql ) {

		$bracket_open_levels = 0;

		$open = false;

		$comment_open = false;

		$is_backslash_active = false;

		$rest_of_line_is_comment = false;

		$sequence = '';

		$sql_length = strlen( $sql );

		for ( $i = 0; $i < $sql_length; $i++ ) {

			// if ($i > 0) echo "$char($i) : open=$open, sequence=$sequence, bracket_open_levels=$bracket_open_levels, is_backslash_active=$is_backslash_active,  comment_open=$comment_open, rest_of_line_is_comment=$rest_of_line_is_comment\n";

			$char        = $sql[ $i ];
			$char_before = $i > 0 ? $sql[ $i - 1 ] : false;
			$char_after  = $i < $sql_length - 1 ? $sql[ $i + 1 ] : false;

			if ( $rest_of_line_is_comment ) {
				if ( "\n" === $char || "\r" === $char ) {
					$rest_of_line_is_comment = false;
				}
				continue;
			}

			if ( $is_backslash_active ) {
				// Previous character was a backslash; thus, this character is to be treated literally
				$is_backslash_active = false;
				continue;
			}

			if ( '\\' == $char ) {
				$is_backslash_active = true;
				continue;
			}

			// N.B. Nested comments are not allowed in future MySQL - https://dev.mysql.com/doc/refman/8.4/en/comments.html
			// Note that given the assumptions of this function, comment handling should not be necessary (only an admin attack could introduce comment-based confusion, where $open is false).
			if ( false === $open && $comment_open && ( ( '*' === $char && '/' === $char_after ) || ( '*' === $char_before && '/' === $char ) ) ) {
				$comment_open = false;
				continue;
			}

			if ( $comment_open ) {
				continue;
			}

			if ( false === $open && ( ( '/' === $char && '*' === $char_after ) || ( '/' === $char_before && '*' === $char ) ) ) {
				$comment_open = true;
				continue;
			}

			// All further parsing of the line ends
			if ( false === $open && ( '#' == $char || ( '-' === $char && '-' === $char_after ) ) ) {
				if ( '#' == $char || ( $i < strlen( $sql ) - 2 && ( ' ' == $sql[ $i + 2 ] || ord( $sql[ $i + 2 ] ) < 32 ) ) ) {
					$rest_of_line_is_comment = true;
					continue;
				}
			}

			if ( '(' === $char && false === $open ) {
				++$bracket_open_levels;
				continue;
			}

			if ( ')' === $char && false === $open ) {
				// Immediate abort: a bracket that was never open has been closed
				if ( $bracket_open_levels < 1 ) {
					return false;
				}
				--$bracket_open_levels;
				continue;
			}

			// Single quote, double quote or backtick. Inside this, the same character doubled means a literal. Otherwise, it closes the opened entity.
			if ( in_array( $char, array( "'", '"', '`' ) ) ) {

				// Something other than the current entity is open; in this context, the entity has no special meaning
				if ( $char !== $open && false !== $open ) {
					continue;
				}

				// Nothing is open
				if ( false === $open ) {
					$open     = $char;
					$sequence = '';
					continue;
				}

				// A doubled entity
				if ( $char === $open ) {
					if ( $char === $char_after && '' === $sequence ) {
						// Another of the same entity is coming after: it's a literal
						$sequence = $char;
					} elseif ( $char === $sequence ) {
						// Here is the entity "after"
						$sequence = '';
					} else {
						// The entity is being closed
						$open = false;
					}
				}
				continue;
			}
		}

		// echo "$char : open=$open, sequence=$sequence, bracket_open_levels=$bracket_open_levels, is_backslash_active=$is_backslash_active, comment_open=$comment_open, rest_of_line_is_comment=$rest_of_line_is_comment (END)\n";

		if ( $is_backslash_active ) {
			return false;
		}

		if ( false !== $open ) {
			return false;
		}

		if ( 0 !== $bracket_open_levels ) {
			return false;
		}

		if ( $comment_open ) {
			return false;
		}

		// This condition should not be possible, as it is only set if we know that the next character will fulfil the condition to reset it to empty. However, the check is harmless.
		if ( '' !== $sequence ) {
			return false;
		}

		return true;
	}

	/**
	 * Parse the SQL "create table" column definition (non validating) and check whether it's a generated column and retrieve its column options
	 *
	 * @see https://dev.mysql.com/doc/refman/8.0/en/create-table.html
	 * @see https://mariadb.com/kb/en/create-table/
	 *
	 * @param String  $table_column_definition the column definition statement in which the generated column needs to be identified
	 * @param Integer $starting_offset         the string position of the column definition in a "create table" statement
	 * @return Array|False an array of generated column fragment (column definition, column name, generated column type, etc); false otherwise
	 *
	 * Example input:
	 *
	 *     $column_definition = "fullname varchar(101) GENERATED ALWAYS AS (CONCAT(first_name,' ',last_name)) VIRTUAL NOT NULL COMMENT 'this is the comment',"
	 *
	 * Corresponding result:
	 *
	 *     [
	 *         "column_definition" => "fullname varchar(101) GENERATED ALWAYS AS (CONCAT(first_name,' ',last_name)) VIRTUAL NOT NULL COMMENT 'this is the comment',",
	 *         "column_name" => "fullname",
	 *         "column_data_type_definition" => [
	 *              [
	 *                  "GENERATED ALWAYS AS (CONCAT(first_name,' ',last_name))",
	 *                   90
	 *              ],
	 *              [
	 *                   "VIRTUAL NOT NULL",
	 *                   123 // string position
	 *              ],
	 *              [
	 *                   "COMMENT 'this is the comment'",
	 *                   345 // string position
	 *              ]
	 *         ],
	 *         "is_virtual" => true
	 *     ]
	 */
	public static function get_generated_column_info( $table_column_definition, $starting_offset ) {

		// check whether or not the column definition ($table_column_definition) is a generated column, if so then get all the column definitions
		// https://regex101.com/r/Fy2Bkd/12
		if ( preg_match_all( '/^\s*\`((?:[^`]|``)+)\`([^,\'"]+?)(?:((?:GENERATED\s*ALWAYS\s*)?AS\s*\(.+\))([\w\s]*)(COMMENT\s*(?:\'(?:[^\']|\'\')*\'|\"(?:[^"]|"")*\"))([\w\s]*)|((?:GENERATED\s*ALWAYS\s*)?AS\s*\(.+\)([\w\s]*)))/i', $table_column_definition, $column_definitions, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {

			if ( empty( $column_definitions ) ) {
				return false;
			}

			/**
			 * If the above preg_match_all function succeed, it returns an array with the following format:
			 *
			 *  Array(3) {
			 *  [0]=> // 1st set of the matched/captured string
			 *  array(6) {
			 *      [0]=> // 1st index represents a full column definition
			 *      array(2) {
			 *          [0]=> string(131) ", `full_name` char(41) GENERATED ALWAYS AS (concat(`firstname`,'()`)(()',`lastname`)) VIRTUAL NOT NULL COMMENT 'fu(ll)'_name'' COLUMN_FORMAT DEFAULT"
			 *          [1]=> int(541)
			 *      }
			 *      [1]=> // 2nd index represents a column name
			 *      array(2) {
			 *          [0]=> string(9) "full_name"
			 *          [1]=> int(547)
			 *      }
			 *      [2]=> // 3rd index represents data type option that is captured before "generated always as"
			 *      array(2) {
			 *          [0]=> string(18) " char(41) "
			 *          [1]=> int(555)
			 *      }
			 *      [3]=> // 4rd index represents data type option which is specific for "generated always as"
			 *      array(2) {
			 *          [0]=> string(18) "GENERATED ALWAYS AS (concat(`firstname`,'()`)(()',`lastname`))"
			 *          [1]=> int(629) // this is the position or starting offset of the captured data type's option, this can later be used to help with the unsupported keyword replacement stuff among db server
			 *      }
			 *      [4]=> // 5th index represents data type option that is captured before COMMENT keyword and after "generated always as"
			 *      array(2) {
			 *          [0]=> string(13) " VIRTUAL NOT NULL " // this is the comment string that could be filled with any word even the reserved keyword (e.g. not null, virtual, stored, etc..)
			 *          [1]=> int(656) // this is the position or starting offset of the captured data type's option, this can later be used to help with the unsupported keyword replacement stuff among db server
			 *      }
			 *      [5]=> // 6th index represents the comment
			 *      array(2) {
			 *          [0]=> string(2) "COMMENT 'fu(ll)'_name''"
			 *          [1]=> int(670) // this is the position or starting offset of the captured comment's string
			 *      }
			 *      [6]=> // 7th index represents data type option that is captured after the COMMENT keyword
			 *      array(2) {
			 *          [0]=> string(2) "COLUMN_FORMAT DEFAULT"
			 *          [1]=> int(670)
			 *      }
			 *  }
			 *  array(8) { // 2nd set
			 *      [0]=>
			 *      array(2) {
			 *          [0]=> string(95) ", `full_name6` char(41) GENERATED ALWAYS AS (concat(`firstname`,' ',`lastname2`))STORED NULL"
			 *          [1]=> int(1121)
			 *      }
			 *      [1]=>
			 *      array(2) {
			 *          [0]=> string(10) "full_name6"
			 *          [1]=> int(1127)
			 *      }
			 *      [2]=>
			 *      array(2) {
			 *          [0]=> string(0) " char(41) "
			 *          [1]=> int(1139)
			 *      }
			 *      [3]=>
			 *      array(2) {
			 *          [0]=> string(0) ""
			 *          [1]=> int(-1)
			 *      }
			 *      [4]=>
			 *      array(2) {
			 *          [0]=> string(0) ""
			 *          [1]=> int(-1)
			 *      }
			 *      [5]=>
			 *      array(2) {
			 *          [0]=> string(0) "" // an empty string of this captured token indicates that the column definition doesn't have COMMENT keyword
			 *          [1]=> int(-1)
			 *      }
			 *      [6]=>
			 *      array(2) {
			 *          [0]=> string(0) ""
			 *          [1]=> int(-1)
			 *      }
			 *      [7]=> // 8th index will appear if there's no COMMENT keyword found in the column definition and it represents data type option that is specific for "generated always as"
			 *      array(2) {
			 *          [0]=> string(11) "GENERATED ALWAYS AS (concat(`firstname`,' ',`lastname2`))"
			 *          [1]=> int(1205)
			 *      }
			 *      [8]=> // 9th index will appear if there's no COMMENT keyword found in the column definition and it represents the captured data type options
			 *      array(2) {
			 *          [0]=> string(11) "STORED NULL"
			 *          [1]=> int(1270)
			 *      }
			 *  }
			 *  }
			 */

			foreach ( $column_definitions as $column_definition ) {
				$data_type_definition = ( ! empty( $column_definition[4][0] ) ? $column_definition[4][0] : '' ) . ( ! empty( $column_definition[6][0] ) ? $column_definition[6][0] : '' ) . ( ! empty( $column_definition[8][0] ) ? $column_definition[8][0] : '' );
				// if no virtual, stored or persistent option is specified then it's virtual by default. It's not possible having two generated columns type in the column definition e.g fullname varchar(101) GENERATED ALWAYS AS (CONCAT(first_name,' ',last_name)) VIRTUAL STORED NOT NULL COMMENT 'comment text', both MySQL and MariaDB will produces an error
				$is_virtual = preg_match( '/\bvirtual\b/i', $data_type_definition ) || ( ! preg_match( '/\bstored\b/i', $data_type_definition ) && ! preg_match( '/\bpersistent\b/i', $data_type_definition ) );

				$fragment = array(
					// full syntax of the column definition
					'column_definition'           => $column_definition[0][0],
					// the extracted column name
					'column_name'                 => $column_definition[1][0],
					'column_data_type_definition' => array(),
					'is_virtual'                  => $is_virtual,
				);
				if ( ! empty( $column_definition[2] ) ) {
					$fragment['column_data_type_definition']['DATA_TYPE_TOKEN']    = $column_definition[2];
					$fragment['column_data_type_definition']['DATA_TYPE_TOKEN'][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition']['DATA_TYPE_TOKEN'][1];
				}
				if ( ! empty( $column_definition[3] ) ) {
					$fragment['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'] = $column_definition[3];
					if ( empty( $fragment['column_data_type_definition'][1] ) && ! empty( $column_definition[7][0] ) ) {
						$fragment['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'] = $column_definition[7];
					}
					$fragment['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'][1];
				}
				if ( ! empty( $column_definition[4] ) ) {
					$fragment['column_data_type_definition'][2]    = $column_definition[4];
					$fragment['column_data_type_definition'][2][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition'][2][1];
				}
				if ( ! empty( $column_definition[5] ) ) {
					$fragment['column_data_type_definition']['COMMENT_TOKEN']    = $column_definition[5];
					$fragment['column_data_type_definition']['COMMENT_TOKEN'][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition']['COMMENT_TOKEN'][1];
				}
				if ( ! empty( $column_definition[6] ) ) {
					$fragment['column_data_type_definition'][4]    = $column_definition[6];
					$fragment['column_data_type_definition'][4][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition'][4][1];
				}
				if ( ! empty( $column_definition[8] ) ) {
					$fragment['column_data_type_definition'][5]    = $column_definition[8];
					$fragment['column_data_type_definition'][5][1] = (int) $starting_offset + (int) $fragment['column_data_type_definition'][5][1];
				}
			}
		}
		return isset( $fragment ) ? $fragment : false;
	}

	/**
	 * Retrieve information concerning whether the currently running database server supports generated columns (VIRTUAL, STORED, PERSISTENT)
	 *
	 * @param String $engine Optional. If specified, it should either a well-known database engine like InnoDB, MyISAM, etc or an empty string to instead use database default storage engine; e.g. 'MyISAM'
	 * @return Array|Boolean an array of supported generated column syntax options (whether or not persistent type, not null, virtual index are supported) or false if generated column isn't supported
	 *
	 * The return value is structured thus:
	 *
	 *     [
	 *         // InnoDB supports PERSISTENT generated columns type, whereas MyISAM does not
	 *         "is_persistent_supported" => false,
	 *         // InnoDB supports NOT NULL constraint, whereas MyISAM does not
	 *         "is_not_null_supported" => true,
	 *         // if it's on MariaDB, you can use insert ignore statement to prevent generated columns errors but not on MySQL
	 *         "can_insert_ignore_to_generated_column" => true,
	 *         // No matter what the database engine you use, MySQL doesn't yet support indexing on generated columns
	 *         "is_virtual_index_supported" => false
	 *     ]
	 */
	public static function is_generated_column_supported( $engine = '' ) {

		global $table_prefix, $wpdb;

		$random_table_name = $table_prefix . 'backupplus_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );
		$sql               = new BackupPlus_SQL( $wpdb->dbh );

		// both mysql and mariadb support generated column, virtual is the default type and the other option type is called stored, mariadb has an alias for stored type which is called persistent, whereas mysql doesn't have such thing.
		// MySQL supports NULL and NOT NULL constraints. On the other hand, MariaDB doesn't support it.
		$sql->execute( 'DROP TABLE IF EXISTS %i', array( $random_table_name ) );
		if ( empty( $engine ) ) {
			$is_generated_column_supported = $sql->execute( "CREATE TABLE %i (`virtual_column` varchar(17) GENERATED ALWAYS AS ('virtual_column') VIRTUAL COMMENT 'virtual_column')", array( $random_table_name ) );
		} else {
			$is_generated_column_supported = $sql->execute( "CREATE TABLE %i (`virtual_column` varchar(17) GENERATED ALWAYS AS ('virtual_column') VIRTUAL COMMENT 'virtual_column') ENGINE=%i", array( $random_table_name, $engine ) );
		}
		if ( false !== $is_generated_column_supported ) {
			$is_generated_column_supported = array(
				'is_persistent_supported'               => false !== $sql->execute( "ALTER TABLE %i ADD `persistent_column` VARCHAR(17) AS ('persistent_column') PERSISTENT COMMENT 'generated_column'", array( $random_table_name ) ),
				'is_not_null_supported'                 => false !== $sql->execute( "ALTER TABLE %i ADD `virtual_column_not_null` VARCHAR(17) AS ('virtual_column_not_null') VIRTUAL NOT NULL COMMENT 'virtual_column_not_null'", array( $random_table_name ) ),
				// check if we can get through this: Error Code: 3105. The value specified for generated column 'generated_column' in table 'wp_generated_column_test' is not allowed.
				// DEFAULT is the only allowed value for virtual and stored type (i.e INSERT IGNORE INTO `wp_generated_column_test` (`virtual_column`) VALUES(DEFAULT)), other than that will produce an error, luckily insert ignore works fine on MariaDB but not on MySQL
				'can_insert_ignore_to_generated_column' => (bool) $sql->execute( "INSERT IGNORE INTO %i (`virtual_column`) VALUES('virtual_column')", array( $random_table_name ) ),
				// MySQL does not support the create option 'Index on virtual generated column' on MyISAM storage engine
				'is_virtual_index_supported'            => false !== $sql->execute( "CREATE INDEX `idx_wp_udp_generated_column_test_generated_column` ON %i (virtual_column) COMMENT 'virtual_column' ALGORITHM DEFAULT LOCK DEFAULT", array( $random_table_name ) ),
			);
		} else {
			$is_generated_column_supported = false;
		}
		$sql->execute( 'DROP TABLE IF EXISTS %i', array( $random_table_name ) );

		return $is_generated_column_supported;
	}

	/**
	 * Parse the "insert into" statement, capture the column names (if any) and check whether one of the captured columns matches the given list of the "$generated_columns"
	 *
	 * @see https://regex101.com/r/JZiJqH/2
	 *
	 * @param String $insert_statement  the insert statement in which the generated columns will be checked
	 * @param Array  $generated_columns the list of the available "generated columns"
	 * @return Boolean|Null True if "generated columns" exist in the "insert into" statement, false otherwise, null on empty or unmatched insert statement
	 */
	public static function generated_columns_exist_in_the_statement( $insert_statement, $generated_columns ) {

		$exist = null;
		if ( preg_match( '/\s*insert.+?into(?:\s*`(?:[^`]|`)+?`|[^\(]+)(?:\s*\((.+?)\))?\s*values.+/i', $insert_statement, $matches ) ) {
			/**
			 * the reqex above will search for matches of either the insert statement gives data based on the specified column names (i.e INSERT INTO `table_name`(`col1`,'col2`,`virtual_column`,`stored_column`,`col5`) values('1','2','3','4','5')) or not (i.e INSERT INTO `table_name` values('1',',2','3','4','5')), and if the above preg_match function succeed, it returns an array with the following format:
			 *
			 *  Array(2) {
			 *      [0]=> "INSERT INTO `table_name`(`col1`,'col2`,`virtual_column`,`stored_column`,`col5`) values('1','2','3','4','5')"
			*       [1]=> "`col1`,`col2`,`virtual_column`,`col4`,`stored_column`"
			*   }
			*   OR
			*   Array(1) {
			*       [0]=> "INSERT INTO `table_name` values('1','2','3','4','5')"
			*   }
			*/
			$columns = isset( $matches[1] ) ? preg_split( '/\`\s*,\s*\`/', preg_replace( '/\`((?:[^\`]|\`)+)\`/', '$1', trim( $matches[1] ) ) ) : array();
			/**
			*   the preg_replace is used to remove the leading and trailing backtick, so that the string becomes: col1`,`col2`,`virtual_column`,`col4`,`stored_column
			*   the preg_split is used to split all strings that match `,` pattern
			*   Array(5) {
			*       [0]=> string(5) "col1"
			*       [1]=> string(4) "col2"
			*       [2]=> string(14) "virtual_column"
			*       [3]=> string(4) "col4"
			*       [4]=> string(14) "stored_column"
			*   }
			*/
			$exist = ( false == $columns ) || ( true == array_intersect( $generated_columns, $columns ) );
		}
		return $exist;
	}
}
