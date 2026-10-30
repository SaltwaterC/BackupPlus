<?php

/** Site state around a restore: maintenance mode, options, permalinks, plugins and server configuration. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Site_State {

	/**
	 * Enter or leave maintenance mode
	 *
	 * @param Boolean $active - whether to activate, or de-activate, maintenance mode
	 */
	private function maintenance_mode( $active ) {
		// This allows add-ons to do something different if they prefer
		if ( apply_filters( 'backupplus_restore_maintenance_mode', true, $active, $this, $this->wp_upgrader ) ) {
			$this->wp_upgrader->maintenance_mode( $active );
		}
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_permalink_structure( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'permalink_structure' );
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_page_on_front( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'page_on_front' );
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_rewrite_rules( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'rewrite_rules' );
	}

	private function flush_rewrite_rules() {

		// We have to deal with the fact that the procedures used call get_option, which could be looking at the wrong table prefix, or have the wrong thing cached

		$filter_these = array( 'permalink_structure', 'rewrite_rules', 'page_on_front' );

		foreach ( $filter_these as $opt ) {
			add_filter( 'pre_option_' . $opt, array( $this, 'option_filter_' . $opt ) );
		}

		global $wp_rewrite;
		$wp_rewrite->init();
		// Don't do this: it will cause rules created by plugins that weren't active at the start of the restore run to be lost
		// flush_rewrite_rules(true);

		if ( function_exists( 'save_mod_rewrite_rules' ) ) {
			save_mod_rewrite_rules();
		}
		if ( function_exists( 'iis7_save_url_rewrite_rules' ) ) {
			iis7_save_url_rewrite_rules();
		}

		foreach ( $filter_these as $opt ) {
			remove_filter( 'pre_option_' . $opt, array( $this, 'option_filter_' . $opt ) );
		}
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_template( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'template' );
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_stylesheet( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'stylesheet' );
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_template_root( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'template_root' );
	}

	/**
	 * WordPress options filter
	 *
	 * @param String $val - pre-filter value
	 *
	 * @return String - filtered value
	 */
	public function option_filter_stylesheet_root( $val ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;
		return $backupplus->option_filter_get( 'stylesheet_root' );
	}

	/**
	 * This function will loop through all the sites available and get their active plugins and ensure any missing plugins are removed from the active list to prevent crashes.
	 *
	 * @param string $import_table_prefix - the table prefix
	 *
	 * @return void
	 */
	private function check_active_plugins( $import_table_prefix ) {
		global $wpdb;

		$sql = BackupPlus_SQL::wpdb();
		if ( $this->is_multisite ) {
			// Get the site wide active plugins; restoring one site of the network leaves the network's alone
			$sitemeta_table = $import_table_prefix . 'sitemeta';
			$plugins        = 'network' === BackupPlus_Multisite_Restore::mode( $this->restore_options ) ? $sql->row( "SELECT meta_value FROM %i WHERE meta_key = 'active_sitewide_plugins'", array( $sitemeta_table ) ) : null;
			if ( ! empty( $plugins->meta_value ) ) {
				$plugins = $this->deactivate_missing_plugins( $plugins->meta_value );
				$sql->execute( "UPDATE %i SET meta_value = ? WHERE meta_key = 'active_sitewide_plugins'", array( $sitemeta_table ), array( $plugins ) );
			}

			$offset = 0;
			$limit  = 250;

			while ( true ) {
				// Loop over and get each sites active plugins
				$blogs = $sql->rows( 'SELECT blog_id FROM %i LIMIT ?, ?', array( $wpdb->blogs ), array( $offset, $limit ), ARRAY_A );

				if ( empty( $blogs ) ) {
					break;
				}

				foreach ( $blogs as $row ) {
					if ( ! apply_filters( 'backupplus_restore_this_site', true, $row['blog_id'], '', $this->restore_options ) ) {
						continue;
					}
					$options_table = $wpdb->get_blog_prefix( absint( $row['blog_id'] ) ) . 'options';
					$plugins       = $sql->row( "SELECT option_value FROM %i WHERE option_name = 'active_plugins'", array( $options_table ) );
					if ( empty( $plugins->option_value ) ) {
						continue;
					}
					$plugins = $this->deactivate_missing_plugins( $plugins->option_value );
					$sql->execute( "UPDATE %i SET option_value = ? WHERE option_name = 'active_plugins'", array( $options_table ), array( $plugins ) );
				}

				$offset += $limit;
			}
		} else {
			$options_table = $import_table_prefix . 'options';
			$plugins       = $sql->row( "SELECT option_value FROM %i WHERE option_name = 'active_plugins'", array( $options_table ) );
			if ( empty( $plugins->option_value ) ) {
				return;
			}
			$plugins = $this->deactivate_missing_plugins( $plugins->option_value );
			$plugins = BackupPlus::unserialize( $plugins );
			if ( ! is_array( $plugins ) ) {
				$plugins = array();
			}
			$old_backupplus_plugin_slug = $this->old_backupplus_plugin_slug;
			if ( empty( $old_backupplus_plugin_slug ) || ! file_exists( WP_PLUGIN_DIR . '/' . $old_backupplus_plugin_slug ) ) {
				$old_backupplus_plugin_slug = BACKUPPLUS_PLUGIN_SLUG;
			}
			if ( ! in_array( $old_backupplus_plugin_slug, $plugins ) ) {
				$plugins[] = $old_backupplus_plugin_slug;
			}
			$plugins = serialize( $plugins );
			$sql->execute( "UPDATE %i SET option_value = ? WHERE option_name = 'active_plugins'", array( $options_table ), array( $plugins ) );
		}
	}

	/**
	 * This function will check the list of active plugins and ensure they are still installed, if any are missing it will deactivate them to prevent the site from crashing.
	 *
	 * @param String $plugins - serialized active plugins
	 *
	 * @return String - filtered results
	 */
	private function deactivate_missing_plugins( $plugins ) {
		global $backupplus;

		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_plugins = array_keys( get_plugins() );
		$plugins           = $backupplus->unserialize( $plugins );

		foreach ( $plugins as $key => $path ) {
			// Single site and multisite have a different array structure, in single site the path is the array value, in multisite the path is the array key.
			if ( ! in_array( $key, $installed_plugins ) && ! in_array( $path, $installed_plugins ) ) {
				$log_path = $this->is_multisite ? $key : $path;
				$backupplus->log_e( 'Plugin path %s not found: de-activating.', $log_path );
				unset( $plugins[ $key ] );
			}
		}

		$plugins = serialize( $plugins );

		return $plugins;
	}

	/**
	 * Adjust and replace the invalid root path of the auto_prepend_file values with the current server's root path
	 */
	private function adjust_auto_prepend_directive() {

		global $wp_filesystem, $backupplus;

		$external_plugins = array(
			'wordfence' => array(
				'filename' => 'wordfence-waf.php', // this file is located in the root directory so there's no additional path in it, other plugins may place the corresponding file in its plugin directory, in that case the additional path should be added (e.g. 'wp-content/plugins/plugin-name/file-name.php')
				'callback' => 'adjust_wordfencewaf_root_path',
			),
		);

		foreach ( $backupplus->server_configuration_file_list() as $server_config_file ) {
			if ( empty( $server_config_file ) ) {
				continue;
			}
			if ( file_exists( $this->abspath . $server_config_file ) ) {
				$backupplus->log( "$server_config_file configuration file has been detected during the restoration. Trying to open the file now for various-fixing tasks" );
				$server_config_file_content = file_get_contents( $this->abspath . $server_config_file );
				if ( false !== $server_config_file_content ) {
					foreach ( $external_plugins as $data ) {
						$file_pattern = str_replace( array( '/', '.', "'", '"' ), array( '\/', '\.', "\'", '\"' ), $data['filename'] );
						if ( file_exists( $this->abspath . $data['filename'] ) ) {
							if ( ! $wp_filesystem->put_contents( $this->abspath . $server_config_file, preg_replace( '/((?:php_value\s\s*)?auto_prepend_file(?:\s*=)?\s*(?:\'|")).+?' . $file_pattern . '(\'|")/is', "$1{$this->abspath}{$data['filename']}$2", $server_config_file_content ) ) ) {
								$backupplus->log( "Couldn't write a fix into the $server_config_file file" );
							}
							if ( isset( $data['callback'] ) && method_exists( $this, $data['callback'] ) ) {
								call_user_func( array( $this, $data['callback'] ) );
							}
						} else {
							// if somehow, some way, the plugin's auto prepended file is missing then the auto_prepend_file directive in the config file needs to be removed or it will cause a fatal error
							if ( ! $wp_filesystem->put_contents( $this->abspath . $server_config_file, preg_replace( '/((?:php_value\s\s*)?auto_prepend_file(?:\s*=)?\s*(?:\'|")).+?' . $file_pattern . '(\'|")/is', '', $server_config_file_content ) ) ) {
								$backupplus->log( "The {$data['filename']} file doesn't exist, couldn't write a fix into the $server_config_file file" );
							}
						}
					}
				} else {
					$backupplus->log( "Failed to read the $server_config_file file" );
				}
			}
		}
	}

	/**
	 * Adjust and replace the root paths in the wordfence-waf.php file with the current server's root path
	 */
	private function adjust_wordfencewaf_root_path() {
		global $wp_filesystem, $backupplus;
		if ( file_exists( $this->abspath . 'wordfence-waf.php' ) ) {
			$backupplus->log( 'Wordfence auto-prepended file has been detected during the restoration. Trying to open the file now for various-fixing tasks' );
			$wordfence_waf = file_get_contents( $this->abspath . 'wordfence-waf.php' );
			if ( false !== $wordfence_waf ) {
				// https://regex101.com/r/VeCwzH/1/
				if ( preg_match_all( '/(?:wp-content[\/\\\]+plugins[\/\\\]+wordfence[\/\\\]+waf[\/\\\]+bootstrap\.php|wp-content[\/\\\]+wflogs[\/\\\]*)((?:\'|"))/is', $wordfence_waf, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
					$matches = array_reverse( $matches );
					foreach ( $matches as $match ) {
						$enclosure_cnt = 0;
						$start         = (int) $match[0][1];
						$enclosure     = $match[1][0];
						$offset        = -1;
						for ( $i = $start; $i >= 0; $i-- ) {
							if ( $enclosure_cnt > 0 ) {
								if ( '\\' === $wordfence_waf[ $i ] ) {
									--$enclosure_cnt;
								} else {
									$offset = $i + 2;
									break;
								}
							} else {
								if ( $enclosure === $wordfence_waf[ $i ] ) {
									++$enclosure_cnt;
								}
							}
						}
						if ( $offset >= 0 ) {
							if ( false !== stripos( $match[0][0], 'wflogs' ) ) {
								$wordfence_waf = substr_replace( $wordfence_waf, WP_CONTENT_DIR . '/wflogs/', $offset, ( (int) $match[1][1] ) - $offset );
							} else {
								$wordfence_waf = substr_replace( $wordfence_waf, WP_PLUGIN_DIR . '/wordfence/waf/bootstrap.php', $offset, ( (int) $match[1][1] ) - $offset );
							}
						}
					}
					if ( ! $wp_filesystem->put_contents( $this->abspath . 'wordfence-waf.php', $wordfence_waf ) ) {
						$backupplus->log( "Couldn't write fixes into the wordfence-waf.php file" );
					}
				}
			} else {
				$backupplus->log( 'Failed to read the wordfence-waf.php file' );
			}
		}
	}
}
