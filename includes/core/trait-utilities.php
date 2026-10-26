<?php

/** Small standalone helpers. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Utilities {

	/**
	 * Pretty printing of the raw backup information
	 *
	 * @param String  $description
	 * @param Array   $history
	 * @param String  $entity
	 * @param Array   $checksums
	 * @param Array   $jobdata
	 * @param Boolean $smaller
	 * @return String
	 */
	public function printfile( $description, $history, $entity, $checksums, $jobdata, $smaller = false ) {

		if ( empty( $history[ $entity ] ) ) {
			return;
		}

		// PHP 7.2+ throws a warning if you try to count() a string
		$how_many = is_string( $history[ $entity ] ) ? 1 : count( $history[ $entity ] );

		if ( $smaller ) {
			/* translators: %s: Number of files */
			$pfiles = '<strong>' . $description . ' (' . sprintf( __( 'files: %s', 'backupplus' ), $how_many ) . ")</strong><br>\n";
		} else {
			/* translators: %s: Number of files */
			$pfiles = '<h3>' . $description . ' (' . sprintf( __( 'files: %s', 'backupplus' ), $how_many ) . ")</h3>\n\n";
		}

		$is_incremental = ( ! empty( $jobdata ) && ! empty( $jobdata['job_type'] ) && 'incremental' == $jobdata['job_type'] && 'db' != substr( $entity, 0, 2 ) ) ? true : false;

		if ( $is_incremental ) {
			$backup_timestamp = isset( $jobdata['incremental_base_time'] ) ? $jobdata['incremental_base_time'] : $jobdata['backup_time'];
			$backup_history   = BackupPlus_Backup_History::get_history( $backup_timestamp );
			$pfiles          .= '<dl>';
			foreach ( $backup_history['incremental_sets'] as $timestamp => $backup ) {
				if ( isset( $backup[ $entity ] ) ) {
					$pfiles .= '<dt>' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'M d, Y G:i' ) . "\n</dt>\n";
					foreach ( $backup[ $entity ] as $ind => $file ) {
						$pfiles .= '<dd>' . $this->get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) . "\n</dd>\n";
					}
				}
			}
			$pfiles .= "</dl>\n";
		} else {

			$pfiles .= '<ul>';
			$files   = $history[ $entity ];
			if ( is_string( $files ) ) {
				$files = array( $files );
			}

			foreach ( $files as $ind => $file ) {
				$pfiles .= '<li>' . $this->get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) . "\n</li>\n";
			}
			$pfiles .= "</ul>\n";
		}

		return $pfiles;
	}

	/**
	 * Perform conditional checking of two values with the specified operator
	 *
	 * @param Mixed  $value1   the first value to compare
	 * @param String $operator the operator that is used for comparison of the two values
	 * @param Mixed  $value2   the second value to compare
	 *
	 * @return Boolean true if the first value matches against the second value, false otherwise
	 */
	public function if_cond( $value1, $operator, $value2 ) {
		switch ( strtolower( $operator ) ) {
			case 'is':
			case '==':
				return $value1 == $value2;
				break;
			case 'is_not':
			case '!=':
				return $value1 != $value2;
				break;
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages should be escaped when caught and printed.
				throw new Exception( __CLASS__ . '::' . __FUNCTION__ . ': Unsupported (' . $operator . ') operator', 1 );
				break;
		}
	}

	/**
	 * Return a listing of days of the week
	 *
	 * @param Boolean $respect_start_of_week whether to use the WordPress's start_of_week setting
	 * @return Array the days of the week
	 */
	public function list_days_of_the_week( $respect_start_of_week = true ) {
		global $wp_locale;
		$days_of_the_week = array();
		$i                = $j = $respect_start_of_week ? (int) get_option( 'start_of_week', 1 ) : 1;
		while ( $i < $j + 7 ) { // 7 days
			$days_of_the_week[] = array(
				'index' => $i % 7,
				'value' => $wp_locale->get_weekday( $i % 7 ),
			);
			++$i;
		}
		return $days_of_the_week;
	}

	/**
	 * Unserialize data while maintaining compatibility across PHP versions due to different number of arguments required by PHP's "unserialize" function
	 *
	 * @param string        $serialized_data Data to be unserialized, should be one that is already serialized
	 * @param boolean|array $allowed_classes Either an array of class names which should be accepted, false to accept no classes, or true to accept all classes
	 * @param integer       $max_depth       The maximum depth of structures permitted during unserialization, and is intended to prevent stack overflows
	 * @return mixed Unserialized data can be any of types (integer, float, boolean, string, array or object)
	 */
	public static function unserialize( $serialized_data, $allowed_classes = false, $max_depth = 0 ) {
		static $polyfill_unserialize_loaded = false;
		if ( version_compare( PHP_VERSION, '5.2', '<=' ) ) {
			$result = unserialize( $serialized_data ); // For PHP 5.2 users, the search-replace feature has been removed, meaning that any input provided in this context will not undergo search-replace processing
		} else {
			if ( ! $polyfill_unserialize_loaded ) {
				if ( ! class_exists( 'Brumann\Polyfill\DisallowedClassesSubstitutor' ) ) {
					backupplus_try_include_file( 'vendor/brumann/polyfill-unserialize/src/DisallowedClassesSubstitutor.php', 'require_once' );
				}
				if ( ! class_exists( 'Brumann\Polyfill\Unserialize' ) ) {
					backupplus_try_include_file( 'vendor/brumann/polyfill-unserialize/src/Unserialize.php', 'require_once' );
				}
				$polyfill_unserialize_loaded = true;
			}
			$result = call_user_func(
				array( 'Brumann\Polyfill\Unserialize', 'unserialize' ),
				$serialized_data,
				array(
					'allowed_classes' => $allowed_classes,
					'max_depth'       => $max_depth,
				)
			);
		}
		return $result;
	}
}
