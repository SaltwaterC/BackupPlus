<?php

/** Measuring directory sizes and disk space. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Filesystem_Functions_Disk_Usage {

	/**
	 * If $basedirs is passed as an array, then $directorieses must be too
	 * Note: Reason $directorieses is being used because $directories is used within the foreach-within-a-foreach further down
	 *
	 * @param Array|String $directorieses List of of directories, or a single one
	 * @param Array        $exclude       An exclusion array of directories
	 * @param Array|String $basedirs      A list of base directories, or a single one
	 * @param String       $format        Return format - 'text' or 'numeric'
	 * @return String|Integer
	 */
	public static function recursive_directory_size( $directorieses, $exclude = array(), $basedirs = '', $format = 'text', $include_private_key = false ) {

		$size = 0;

		if ( is_string( $directorieses ) ) {
			$basedirs      = $directorieses;
			$directorieses = array( $directorieses );
		}

		if ( is_string( $basedirs ) ) {
			$basedirs = array( $basedirs );
		}

		foreach ( $directorieses as $ind => $directories ) {
			if ( ! is_array( $directories ) ) {
				$directories = array( $directories );
			}

			$basedir = empty( $basedirs[ $ind ] ) ? $basedirs[0] : $basedirs[ $ind ];

			foreach ( $directories as $dir ) {
				if ( is_file( $dir ) ) {
					$size += @filesize( $dir );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				} else {
					$suffix = ( '' != $basedir ) ? ( ( 0 === strpos( $dir, $basedir . '/' ) ) ? substr( $dir, 1 + strlen( $basedir ) ) : '' ) : '';
					$size  += self::recursive_directory_size_raw( $basedir, $exclude, $suffix, $include_private_key );
				}
			}
		}

		if ( 'numeric' == $format ) {
			return $size;
		}

		return BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $size );
	}

	/**
	 * Get the html of "Web-server disk space" line which resides above of the existing backup table
	 *
	 * @param Boolean $will_immediately_calculate_disk_space Whether disk space should be counted now or when user click Refresh link
	 *
	 * @return String Web server disk space html to render
	 */
	public static function web_server_disk_space( $will_immediately_calculate_disk_space = true ) {
		if ( $will_immediately_calculate_disk_space ) {
			$disk_space_used = self::get_disk_space_used( 'backupplus', 'numeric' );
			if ( $disk_space_used > apply_filters( 'backupplus_display_usage_line_threshold_size', 104857600 ) ) { // 104857600 = 100 MB = (100 * 1024 * 1024)
				$disk_space_text   = BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $disk_space_used );
				$refresh_link_text = __( 'refresh', 'backupplus' );
				return self::web_server_disk_space_html( $disk_space_text, $refresh_link_text );
			} else {
				return '';
			}
		} else {
			$disk_space_text   = '';
			$refresh_link_text = __( 'calculate', 'backupplus' );
			return self::web_server_disk_space_html( $disk_space_text, $refresh_link_text );
		}
	}

	/**
	 * Get the html of "Web-server disk space" line which resides above of the existing backup table
	 *
	 * @param String $disk_space_text   The texts which represents disk space usage
	 * @param String $refresh_link_text Refresh disk space link text
	 *
	 * @return String - Web server disk space HTML
	 */
	public static function web_server_disk_space_html( $disk_space_text, $refresh_link_text ) {
		return '<li class="backupplus-server-disk-space" title="' . esc_attr__( 'This is a count of the contents of your BackupPlus directory', 'backupplus' ) . '"><strong>' . __( 'Web-server disk space in use by BackupPlus', 'backupplus' ) . ':</strong> <span class="backupplus_diskspaceused"><em>' . $disk_space_text . '</em></span> <a class="backupplus_diskspaceused_update" href="#">' . $refresh_link_text . '</a></li>';
	}

	public static function recursive_directory_size_raw( $prefix_directory, &$exclude = array(), $suffix_directory = '', $include_private_key = false ) {

		$directory = $prefix_directory . ( '' == $suffix_directory ? '' : '/' . $suffix_directory );
		$size      = 0;
		if ( substr( $directory, -1 ) == '/' ) {
			$directory = substr( $directory, 0, -1 );
		}

		if ( ! file_exists( $directory ) || ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return -1;
		}
		if ( file_exists( $directory . '/.donotbackup' ) && ( ! $include_private_key || ! BackupPlus_Credential_Key_Store::include_private_directory_in_age_backup( $directory, false ) ) ) {
			return 0;
		}

		if ( $handle = opendir( $directory ) ) {
			while ( ( $file = readdir( $handle ) ) !== false ) {
				if ( '.' != $file && '..' != $file ) {
					$spath = ( '' == $suffix_directory ) ? $file : $suffix_directory . '/' . $file;
					if ( false !== ( $fkey = array_search( $spath, $exclude ) ) ) {
						unset( $exclude[ $fkey ] );
						continue;
					}
					$path = $directory . '/' . $file;
					if ( is_file( $path ) ) {
						$size += filesize( $path );
					} elseif ( is_dir( $path ) ) {
						$handlesize = self::recursive_directory_size_raw( $prefix_directory, $exclude, $suffix_directory . ( '' == $suffix_directory ? '' : '/' ) . $file, $include_private_key );
						if ( $handlesize >= 0 ) {
							$size += $handlesize;
						}
					}
				}
			}
			closedir( $handle );
		}

		return $size;
	}

	/**
	 * Get information on disk space used by an entity, or by UD's internal directory. Returns as a human-readable string.
	 *
	 * @param String $entity - the entity (e.g. 'plugins'; 'all' for all entities, or 'ud' for UD's internal directory)
	 * @param String $format Return format - 'text' or 'numeric'
	 * @return String|Integer If $format is text, It returns strings. Otherwise integer value.
	 */
	public static function get_disk_space_used( $entity, $format = 'text' ) {
		global $backupplus;
		if ( 'backupplus' == $entity ) {
			return self::recursive_directory_size( $backupplus->backups_dir_location(), array(), '', $format );
		}

		$backupable_entities = $backupplus->get_backupable_file_entities( true, false );

		if ( 'all' == $entity ) {
			$total_size = 0;
			foreach ( $backupable_entities as $entity => $data ) {
				// Might be an array
				$basedir = $backupable_entities[ $entity ];
				$dirs    = apply_filters( 'backupplus_dirlist_' . $entity, $basedir );
				$size    = self::recursive_directory_size( $dirs, $backupplus->get_exclude( $entity ), $basedir, 'numeric', BackupPlus_Credential_Key_Store::BACKUP_ENTITY === $entity );
				if ( is_numeric( $size ) && $size > 0 ) {
					$total_size += $size;
				}
			}

			if ( 'numeric' == $format ) {
				return $total_size;
			} else {
				return BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $total_size );
			}
		} elseif ( ! empty( $backupable_entities[ $entity ] ) ) {
			// Might be an array
			$basedir = $backupable_entities[ $entity ];
			$dirs    = apply_filters( 'backupplus_dirlist_' . $entity, $basedir );
			return self::recursive_directory_size( $dirs, $backupplus->get_exclude( $entity ), $basedir, $format, BackupPlus_Credential_Key_Store::BACKUP_ENTITY === $entity );
		}

		// Default fallback
		return apply_filters( 'backupplus_get_disk_space_used_none', __( 'Error', 'backupplus' ), $entity, $backupable_entities );
	}
}
