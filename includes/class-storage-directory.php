<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Stable, unpredictable names for installation storage folders. */
class BackupPlus_Storage_Directory {

	public static function name( $prefix ) {
		global $wpdb;
		// The main site's options table has a unique option_name, including on multisite.
		$switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			$option = 'backupplus_storage_directory_' . str_replace( '-', '_', $prefix );
			$name   = get_option( $option, '' );
			if ( ! $name ) {
				// INSERT IGNORE keeps the winner even when concurrent requests both saw no option.
				$sql = BackupPlus_SQL::wpdb();
				if ( false === $sql->execute( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (?, ?, ?)', array( $wpdb->options ), array( $option, $prefix . '-' . bin2hex( random_bytes( 16 ) ), 'no' ) ) ) {
					throw new RuntimeException( 'The BackupPlus storage directory name could not be saved. Please retry.' );
				}
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( $option, 'options' );
				$name = get_option( $option, '' );
			}
			if ( ! is_string( $name ) || ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '-[a-f0-9]{32}$/D', $name ) ) {
				throw new RuntimeException( 'The BackupPlus storage directory name could not be saved. Please retry.' );
			}
			return $name;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/** Include historical folders and surviving randomized folders after a database restore. */
	public static function existing( $base, $prefix ) {
		$directories = array( $base . '/' . $prefix );
		foreach ( (array) @glob( $base . '/' . $prefix . '-*', GLOB_ONLYDIR ) as $directory ) {
			if ( is_string( $directory ) && preg_match( '/^' . preg_quote( $prefix, '/' ) . '-[a-f0-9]{32}$/D', basename( $directory ) ) ) {
				$directories[] = $directory;
			}
		}
		return $directories;
	}
}
