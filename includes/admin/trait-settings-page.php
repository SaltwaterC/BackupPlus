<?php

/** The settings page frame, tabs and tab contents. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Settings_Page {

	/**
	 * Include the settings header template
	 */
	public function settings_header() {
		$this->include_template( 'wp-admin/settings/header.php' );
	}

	/**
	 * Include the settings footer template
	 */
	public function settings_footer() {
		$this->include_template( 'wp-admin/settings/footer.php' );
	}

	/**
	 * Output the settings page content. Will also run a restore if $_REQUEST so indicates.
	 */
	public function settings_output() {

		if ( false == ( $render = apply_filters( 'backupplus_settings_page_render', true ) ) ) {
			do_action( 'backupplus_settings_page_render_abort', $render );
			return;
		}

		do_action( 'backupplus_settings_page_init' );

		global $backupplus;

		/**
		 * We use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credential for the WP_Filesystem. to do this WP outputs a form, but we don't pass our parameters via that. So the values are passed back in as GET parameters.
		 */
		if ( isset( $_REQUEST['action'] ) && ( ( 'backupplus_restore' == $_REQUEST['action'] && isset( $_REQUEST['backup_timestamp'] ) ) || ( 'backupplus_restore_continue' == $_REQUEST['action'] && ! empty( $_REQUEST['job_id'] ) ) ) ) {

			$request_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'nonce' );
			if ( empty( $request_nonce ) || ! wp_verify_nonce( $request_nonce, 'backupplus-credentialtest-nonce' ) ) {
				die( 'Security Check' );
			}

			$this->prepare_restore();
			return;
		}

		if ( isset( $_REQUEST['action'] ) && 'backupplus_delete_old_dirs' == $_REQUEST['action'] ) {
			$delete_old_dirs_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'backupplus_delete_old_dirs_nonce' );
			$nonce                 = empty( $delete_old_dirs_nonce ) ? '' : $delete_old_dirs_nonce;
			if ( ! wp_verify_nonce( $nonce, 'backupplus-credentialtest-nonce' ) ) {
				die( 'Security check' );
			}
			$this->delete_old_dirs_go();
			return;
		}
		$request_subaction = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'subaction' );
		if ( ! empty( $_REQUEST['action'] ) && 'backupplus_broadcastaction' == $_REQUEST['action'] && ! empty( $request_subaction ) ) {
			$request_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'nonce' );
			$nonce         = ( empty( $request_nonce ) ) ? '' : $request_nonce;
			if ( ! wp_verify_nonce( $nonce, 'backupplus-credentialtest-nonce' ) ) {
				die( 'Security check' );
			}
			do_action( $request_subaction );
			return;
		}
		$error             = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'error' );
		$error_description = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'error_description' );
		if ( isset( $error ) ) {
			// This is used by Microsoft OneDrive authorisation failures (May 15). I am not sure what may have been using the 'error' GET parameter otherwise - but it is harmless. June 2024: also now used for insufficient Google Drive permissions upon return from auth.backupplusplus.com.
			if ( ! empty( $error_description ) ) {
				$this->show_admin_warning( htmlspecialchars( $error_description ) . ' (' . htmlspecialchars( $error ) . ')', 'error' );
			} else {
				$this->show_admin_warning( htmlspecialchars( $error ), 'error' );
			}
		}
		$message = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'message' );
		if ( isset( $message ) ) {
			$this->show_admin_warning( htmlspecialchars( $message ) );
		}
		$global_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'nonce' );
		if ( isset( $_GET['action'] ) && 'backupplus_create_backup_dir' == $_GET['action'] && isset( $global_nonce ) && wp_verify_nonce( $global_nonce, 'create_backup_dir' ) ) {
			$created = $this->create_backup_dir();
			if ( is_wp_error( $created ) ) {
				echo '<p>' . esc_html__( 'Backup directory could not be created', 'backupplus' ) . '...<br>';
				echo '<ul class="disc">';
				foreach ( $created->get_error_messages() as $msg ) {
					echo '<li>' . esc_html( $msg ) . '</li>';
				}
				echo '</ul></p>';
			} elseif ( false !== $created ) {
				echo '<p>' . esc_html__( 'Backup directory successfully created.', 'backupplus' ) . '</p><br>';
			}
			echo '<b>' . esc_html__( 'Actions', 'backupplus' ) . ':</b> <a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus' ) . '">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;
		}

		echo '<div id="backupplus_backup_started" class="updated backupplus-hidden" style="display:none;"></div>';

		// This opens a div
		$this->settings_header();
		?>

			<div id="backupplus-hidethis">
			<p>
			<strong><?php esc_html_e( 'Warning:', 'backupplus' ); ?> <?php esc_html_e( 'If you can still read these words after the page finishes loading, then there is a JavaScript or jQuery problem in the site.', 'backupplus' ); ?></strong>

			<?php if ( false !== strpos( basename( BACKUPPLUS_URL ), ' ' ) ) { ?>
				<strong><?php echo esc_html( __( 'The BackupPlus directory in wp-content/plugins has white-space in it; WordPress does not like this.', 'backupplus' ) . ' ' . __( 'You should rename the directory to wp-content/plugins/backupplus to fix this problem.', 'backupplus' ) ); ?></strong>
			<?php } else { ?>
				<?php esc_html_e( 'Check the browser console for JavaScript errors and confirm that this site does not block its admin scripts.', 'backupplus' ); ?>
			<?php } ?>
			</p>
			</div>

			<?php

			$include_deleteform_div = true;

			// Opens a div, which needs closing later
			if ( isset( $_GET['backupplus_restore_success'] ) ) {

				if ( get_template() === 'optimizePressTheme' || is_plugin_active( 'optimizePressPlugin' ) || is_plugin_active_for_network( 'optimizePressPlugin' ) ) {
					$this->show_admin_warning( "<a href='https://optimizepress.zendesk.com/hc/en-us/articles/203699826-Update-URL-References-after-moving-domain' target='_blank'>" . __( 'OptimizePress 2.0 encodes its contents, so search/replace does not work.', 'backupplus' ) . ' ' . __( 'To fix this problem go here.', 'backupplus' ) . '</a>', 'notice notice-warning' );
				}
				echo '<div class="updated backup-restored"><span><strong>' . esc_html__( 'Your backup has been restored.', 'backupplus' ) . '</strong></span><br>';
				// Unnecessary - will be advised of this below
				// if (2 == $_GET['backupplus_restore_success']) echo ' '.__('Your old (themes, uploads, plugins, whatever) directories have been retained with "-old" appended to their name. Remove them when you are satisfied that the backup worked properly.');
				$include_deleteform_div = false;

			}

			if ( $this->scan_old_dirs( true ) ) {
				$this->print_delete_old_dirs_form( true, $include_deleteform_div );
			}

			// Close the div opened by the earlier section
			if ( isset( $_GET['backupplus_restore_success'] ) ) {
				echo '</div>';
			}

			if ( ! $backupplus->memory_check( 64 ) ) {
				// HS8390 - A case where BackupPlus::memory_check_current() returns -1
				$memory_check_current = $backupplus->memory_check_current();
				if ( $memory_check_current > 0 ) {
					?>
					<div class="updated memory-limit"><?php echo esc_html( __( 'Your PHP memory limit (set by your web hosting company) is very low.', 'backupplus' ) . ' ' . __( 'BackupPlus attempted to raise it but was unsuccessful.', 'backupplus' ) . ' ' . __( 'This plugin may struggle with a memory limit of less than 64 Mb  - especially if you have very large files uploaded (though on the other hand, many sites will be successful with a 32Mb limit - your experience may vary).', 'backupplus' ) . ' ' . __( 'Current limit is:', 'backupplus' ) . ' ' . $backupplus->memory_check_current() ); ?> MB</div>
					<?php
				}
			}

			if ( ! empty( $backupplus->errors ) ) {
				echo '<div class="error backupplus_list_errors">';
				$backupplus->list_errors();
				echo '</div>';
			}

			$backup_history = BackupPlus_Backup_History::get_history();
			if ( empty( $backup_history ) ) {
				BackupPlus_Backup_History::rebuild();
				$backup_history = BackupPlus_Backup_History::get_history();
			}

			$tabflag   = 'backups';
			$main_tabs = $this->get_main_tabs_array();
			$tab       = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'tab' );
			if ( isset( $tab ) ) {
				$request_tab    = sanitize_text_field( $tab );
				$valid_tabflags = array_keys( $main_tabs );
				if ( in_array( $request_tab, $valid_tabflags ) ) {
					$tabflag = $request_tab;
				} else {
					$tabflag = 'backups';
				}
			}

			$this->include_template(
				'wp-admin/settings/tab-bar.php',
				false,
				array(
					'main_tabs'      => $main_tabs,
					'backup_history' => $backup_history,
					'tabflag'        => $tabflag,
				)
			);
		?>
		
		<div id="backupplus-poplog">
			<pre id="backupplus-poplog-content"></pre>
		</div>
		
		<?php
			$this->include_template( 'wp-admin/settings/delete-and-restore-modals.php' );
		?>
		
		<div id="backupplus-navtab-backups-content" 
		<?php
		if ( 'backups' != $tabflag ) {
			echo 'class="backupplus-hidden"';}
		?>
		style="
		<?php
		if ( 'backups' != $tabflag ) {
			echo 'display:none;';}
		?>
">
			<?php
				$user_agent = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_USER_AGENT', '' );
				$is_opera   = ( false !== strpos( $user_agent, 'Opera' ) || false !== strpos( $user_agent, 'OPR/' ) );
				$this->include_template(
					'wp-admin/settings/tab-backups.php',
					false,
					array(
						'backup_history'         => $backup_history,
						'options'                => array(
							'include_uploader'           => true,
							'include_opera_warning'      => false,
							'will_immediately_calculate_disk_space' => true,
							'include_whitespace_warning' => true,
							'include_header'             => false,
							'include_opera_warning'      => $is_opera,
						),
						'backupplus_tab_backups' => array(),
					)
				);
				$this->include_template( 'wp-admin/settings/upload-backups-modal.php' );
			?>
		</div>
		
		<div id="backupplus-navtab-settings-content" 
		<?php
		if ( 'settings' != $tabflag ) {
			echo 'class="backupplus-hidden"';}
		?>
		style="
		<?php
		if ( 'settings' != $tabflag ) {
			echo 'display:none;';}
		?>
">
			<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Backup Contents And Schedule', 'backupplus' ); ?></h2>
			<?php BackupPlus_Options::options_form_begin(); ?>
				<?php $this->settings_formcontents(); ?>
			</form>
		</div>

		<div id="backupplus-navtab-expert-content"
		<?php
		if ( 'expert' != $tabflag ) {
			echo ' class="backupplus-hidden"';}
		?>
		style="
		<?php
		if ( 'expert' != $tabflag ) {
			echo 'display:none;';}
		?>
">
			<?php $this->settings_advanced_tools(); ?>
		</div>

		<div id="backupplus-navtab-extract-content"
		<?php
		if ( 'extract' != $tabflag ) {
			echo ' class="backupplus-hidden"';}
		?>
		style="
		<?php
		if ( 'extract' != $tabflag ) {
			echo 'display:none;';}
		?>
">
			<?php $this->include_template( 'wp-admin/settings/tab-extract.php' ); ?>
		</div>

		<?php
		do_action( 'backupplus_after_main_tab_content', $tabflag );
		// settings_header() opens a div
		$this->settings_footer();
	}

	/**
	 * Get main tabs array
	 *
	 * @return Array Array which have key as a tab key and value as tab label
	 */
	private function get_main_tabs_array() {
		$main_tabs = array(
			'backups'  => __( 'Backup / Restore', 'backupplus' ),
			'settings' => __( 'Settings', 'backupplus' ),
			'expert'   => __( 'Advanced Tools', 'backupplus' ),
			'extract'  => __( 'Extract', 'backupplus' ),
		);

		$main_tabs = apply_filters( 'backupplus_main_tabs', $main_tabs );
		unset( $main_tabs['migrate'] );

		return $main_tabs;
	}

	/**
	 * Return widgetry for the 'backup now' modal.
	 * Don't optimise this method away; it's used by third-party plugins (e.g. EUM).
	 *
	 * @return String
	 */
	public function backupnow_modal_contents() {
		return $this->include_template( 'wp-admin/settings/backupnow-modal.php', true );
	}

	public function settings_downloading_and_restoring( $backup_history = array(), $return_result = false, $options = array() ) {
		return $this->include_template(
			'wp-admin/settings/downloading-and-restoring.php',
			$return_result,
			array(
				'backup_history' => $backup_history,
				'options'        => $options,
			)
		);
	}

	/**
	 * Renders take backup content
	 */
	public function take_backup_content() {
		global $backupplus;
		$backupplus_dir  = $backupplus->backups_dir_location();
		$backup_disabled = BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ? '' : 'disabled="disabled"';
		// wp_date() is WP 5.3+, but performs translation into the site locale
		$current_time = function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i' ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s' ), 'D, F j, Y H:i' );
		$this->include_template(
			'wp-admin/settings/take-backup.php',
			false,
			array(
				'backup_disabled' => $backup_disabled,
				'current_time'    => $current_time,
				'active_jobs'     => $this->print_active_jobs(),
			)
		);
	}

	/**
	 * Output a table row using the backupplus_debugrow class
	 *
	 * @param String $head    - header cell contents
	 * @param String $content - content cell contents
	 */
	public function settings_debugrow( $head, $content ) {
		echo '<tr class="backupplus_debugrow"><th>' . wp_kses_post( $head ) . '</th><td>' . wp_kses( $content, $this->kses_allow_tags() ) . '</td></tr>';
	}

	/**
	 * Get site information data for advanced tools
	 *
	 * @param array $options Options for the site info (e.g. suppress_plugins_for_debugging)
	 * @return array Site information data
	 */
	public function get_site_info_data( $options = array() ) {
		global $backupplus, $wpdb;

		$site_info_data = array();

		// Server information
		if ( function_exists( 'php_uname' ) ) {
			// It appears (Mar 2015) that some mod_security distributions block the output of the string el6.x86_64 in PHP output, on the silly assumption that only hackers are interested in knowing what environment PHP is running on.
			$uname_info = @php_uname( 's' ) . ' ' . @php_uname( 'n' ) . ' ';// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			$release_name = @php_uname( 'r' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( preg_match( '/^(.*)\.(x86_64|[3456]86)$/', $release_name, $matches ) ) {
				$release_name = $matches[1] . ' ';
			} else {
				$release_name = '';
			}

			// In case someone does something similar with just the processor type string
			$mtype = @php_uname( 'm' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( 'x86_64' == $mtype ) {
				$mtype = '64-bit';
			} elseif ( preg_match( '/^i([3456]86)$/', $mtype, $matches ) ) {
				$mtype = $matches[1];
			}

			$uname_info .= $release_name . $mtype . ' ' . @php_uname( 'v' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		} else {
			$uname_info = PHP_OS;
		}

		$web_server                   = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );
		$site_info_data['web_server'] = array(
			'label' => __( 'Web server:', 'backupplus' ),
			'value' => htmlspecialchars( $web_server ) . ' (' . htmlspecialchars( $uname_info ) . ')',
		);

		// WordPress paths
		$site_info_data['abspath']        = array(
			'label' => 'ABSPATH:',
			'value' => htmlspecialchars( ABSPATH ),
		);
		$site_info_data['wp_content_dir'] = array(
			'label' => 'WP_CONTENT_DIR:',
			'value' => htmlspecialchars( WP_CONTENT_DIR ),
		);
		$site_info_data['wp_plugin_dir']  = array(
			'label' => 'WP_PLUGIN_DIR:',
			'value' => htmlspecialchars( WP_PLUGIN_DIR ),
		);
		$site_info_data['table_prefix']   = array(
			'label' => __( 'Table prefix:', 'backupplus' ),
			'value' => htmlspecialchars( $backupplus->get_table_prefix() ),
		);

		// Disk space
		$site_info_data['disk_space'] = array(
			'label'   => __( 'Web-server disk space in use by BackupPlus', 'backupplus' ) . ':',
			'value'   => '<span class="backupplus_diskspaceused">' . BackupPlus_Filesystem_Functions::get_disk_space_used( 'backupplus' ) . '</span> <a class="backupplus_diskspaceused_update" href="' . esc_url( BackupPlus::get_current_clean_url() ) . '">' . __( 'refresh', 'backupplus' ) . '</a>',
			'is_html' => true,
		);

		// Memory usage
		$peak_memory_usage                = memory_get_peak_usage( true ) / 1048576;
		$memory_usage                     = memory_get_usage( true ) / 1048576;
		$site_info_data['peak_memory']    = array(
			'label' => __( 'Peak memory usage', 'backupplus' ) . ':',
			'value' => $peak_memory_usage . ' MB',
		);
		$site_info_data['current_memory'] = array(
			'label' => __( 'Current memory usage', 'backupplus' ) . ':',
			'value' => $memory_usage . ' MB',
		);
		$site_info_data['memory_limit']   = array(
			'label' => __( 'Memory limit', 'backupplus' ) . ':',
			'value' => htmlspecialchars( ini_get( 'memory_limit' ) ),
		);

		// PHP version
		$site_info_data['php_version'] = array(
			/* translators: %s: String 'PHP' */
			'label'   => sprintf( __( '%s version:', 'backupplus' ), 'PHP' ),
			'value'   => htmlspecialchars( phpversion() ) . ' - <a href="admin-ajax.php?page=backupplus&amp;action=backupplus_ajax&amp;subaction=phpinfo&amp;nonce=' . wp_create_nonce( 'backupplus-credentialtest-nonce' ) . '" id="backupplus-phpinfo">' . __( 'show PHP information (phpinfo)', 'backupplus' ) . '</a>',
			'is_html' => true,
		);

		// Database version
		$db_version = $wpdb->get_var( 'SELECT VERSION()' );
		// WPDB::db_version() uses mysqli_get_server_info() ; see: https://github.com/joomla/joomla-cms/issues/9062
		if ( '' == $db_version ) {
			$db_version = $wpdb->db_version();
		}

		$site_info_data['mysql_version'] = array(
			/* translators: %s: String 'MySQL' */
			'label' => sprintf( __( '%s version:', 'backupplus' ), 'MySQL' ),
			'value' => htmlspecialchars( $db_version ),
		);

		// Database packet size
		$mysql_max_packet_size              = round( $backupplus->max_packet_size( false, false ) / 1048576, 1 );
		$site_info_data['mysql_max_packet'] = array(
			'label' => __( 'Database maximum packet size:', 'backupplus' ),
			'value' => $mysql_max_packet_size . ' MB',
		);

		// SQL mode
		$sql_mode                   = $wpdb->get_var( 'SELECT @@GLOBAL.sql_mode' );
		$sql_mode                   = ! empty( $sql_mode ) ? htmlspecialchars( $sql_mode ) : '-';
		$site_info_data['sql_mode'] = array(
			'label' => __( 'Current SQL mode:', 'backupplus' ),
			'value' => $sql_mode,
		);

		// Curl version
		if ( function_exists( 'curl_version' ) && function_exists( 'curl_exec' ) ) {
			$cv  = curl_version();
			$cvs = $cv['version'] . ' / SSL: ' . $cv['ssl_version'] . ' / libz: ' . $cv['libz_version'];
		} else {
			$cvs = __( 'Not installed', 'backupplus' ) . ' (' . __( 'required for some remote storage providers', 'backupplus' ) . ')';
		}
		$site_info_data['curl_version'] = array(
			/* translators: %s: String 'Curl' */
			'label' => sprintf( __( '%s version:', 'backupplus' ), 'Curl' ),
			'value' => htmlspecialchars( $cvs ),
		);

		// OpenSSL version
		$site_info_data['openssl_version'] = array(
			/* translators: %s: String 'OpenSSL' */
			'label' => sprintf( __( '%s version:', 'backupplus' ), 'OpenSSL' ),
			'value' => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '-',
		);

		// MCrypt
		$site_info_data['mcrypt'] = array(
			'label' => 'MCrypt:',
			'value' => function_exists( 'mcrypt_encrypt' ) ? __( 'Yes' ) : __( 'No' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
		);

		// ZipArchive
		if ( version_compare( PHP_VERSION, '5.2.0', '>=' ) && extension_loaded( 'zip' ) ) {
			$ziparchive_exists = __( 'Yes', 'backupplus' );
		} else {
			// First do class_exists, because method_exists still sometimes segfaults due to a rare PHP bug
			$ziparchive_exists = ( class_exists( 'ZipArchive' ) && method_exists( 'ZipArchive', 'addFile' ) ) ? __( 'Yes', 'backupplus' ) : __( 'No', 'backupplus' );
		}
		$site_info_data['ziparchive'] = array(
			'label' => 'ZipArchive::addFile:',
			'value' => $ziparchive_exists,
		);

		// Zip executable
		$binzip                           = $backupplus->find_working_bin_zip( false, false );
		$site_info_data['zip_executable'] = array(
			'label' => __( 'zip executable found:', 'backupplus' ),
			'value' => ( ( is_string( $binzip ) ) ? __( 'Yes' ) . ': ' . $binzip : __( 'No' ) ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
		);
		$site_info_data['tar_support']    = array(
			'label' => __( 'Tar archive support:', 'backupplus' ),
			'value' => __( 'Built into BackupPlus; no external tar executable required', 'backupplus' ),
		);

		// Compression and native database tools
		$zstd_binary                       = BackupPlus_PITR_Compression::zstd_binary();
		$site_info_data['zstd_executable'] = array(
			'label' => __( 'zstd executable found:', 'backupplus' ),
			'value' => $zstd_binary ? __( 'Yes', 'backupplus' ) . ': ' . htmlspecialchars( $zstd_binary ) : __( 'No', 'backupplus' ),
		);
		$site_info_data['gzip_support']    = array(
			'label' => __( 'Gzip support (PHP zlib):', 'backupplus' ),
			'value' => function_exists( 'gzopen' ) && function_exists( 'gzread' ) && function_exists( 'gzwrite' ) ? __( 'Yes', 'backupplus' ) : __( 'No', 'backupplus' ),
		);

		// PITR client discovery uses PATH, the configured client directory, and these standard locations.
		$native_binary_dirs = array( '/usr/bin', '/usr/local/bin', '/bin', '/usr/local/mysql/bin' );
		$path_environment   = getenv( 'PATH' );
		if ( is_string( $path_environment ) ) {
			$native_binary_dirs = array_merge( $native_binary_dirs, explode( PATH_SEPARATOR, $path_environment ) );
		}
		$pitr_binary_dir = defined( 'BACKUPPLUS_PITR_BIN_DIRECTORY' ) ? BACKUPPLUS_PITR_BIN_DIRECTORY : apply_filters( 'backupplus_pitr_bin_directory', '' );
		if ( is_string( $pitr_binary_dir ) && '' !== $pitr_binary_dir ) {
			$native_binary_dirs[] = $pitr_binary_dir;
		}
		$mysqldump_candidates = defined( 'BACKUPPLUS_MYSQLDUMP_EXECUTABLE' ) ? explode( ',', BACKUPPLUS_MYSQLDUMP_EXECUTABLE ) : array();
		foreach ( $mysqldump_candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$native_binary_dirs[] = dirname( $candidate );
			}
		}
		$native_binary_dirs = array_values( array_unique( array_filter( $native_binary_dirs, 'strlen' ) ) );
		$find_native_binary = static function ( $name, $explicit_paths = array() ) use ( $native_binary_dirs ) {
			$filenames = array( $name );
			if ( '.exe' !== strtolower( substr( $name, -4 ) ) ) {
				$filenames[] = $name . '.exe';
			}
			$candidates = $explicit_paths;
			foreach ( $native_binary_dirs as $directory ) {
				foreach ( $filenames as $filename ) {
					$candidates[] = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $filename;
				}
			}
			foreach ( $candidates as $candidate ) {
				if ( is_string( $candidate ) && is_file( $candidate ) && is_executable( $candidate ) ) {
					return $candidate;
				}
			}
			return false;
		};
		$native_tools       = array(
			'mysqldump_executable'      => array( 'mysqldump', __( 'mysqldump executable found:', 'backupplus' ), $mysqldump_candidates ),
			'mysql_client_executable'   => array( 'mysql', __( 'MySQL client executable found (PITR):', 'backupplus' ) ),
			'mysqlbinlog_executable'    => array( 'mysqlbinlog', __( 'mysqlbinlog executable found (PITR):', 'backupplus' ) ),
			'mariadb_client_executable' => array( 'mariadb', __( 'MariaDB client executable found (PITR):', 'backupplus' ) ),
			'mariadb_dump_executable'   => array( 'mariadb-dump', __( 'mariadb-dump executable found (PITR):', 'backupplus' ) ),
			'mariadb_binlog_executable' => array( 'mariadb-binlog', __( 'mariadb-binlog executable found (PITR):', 'backupplus' ) ),
		);
		foreach ( $native_tools as $key => $tool ) {
			$binary                 = $find_native_binary( $tool[0], isset( $tool[2] ) ? $tool[2] : array() );
			$site_info_data[ $key ] = array(
				'label' => $tool[1],
				'value' => $binary ? __( 'Yes', 'backupplus' ) . ': ' . htmlspecialchars( $binary ) : __( 'No', 'backupplus' ),
			);
		}

		// Free disk space
		$hosting_bytes_free = $backupplus->get_hosting_disk_quota_free();
		if ( is_array( $hosting_bytes_free ) ) {
			$perc                              = round( 100 * $hosting_bytes_free[1] / ( max( $hosting_bytes_free[2], 1 ) ), 1 );
			$site_info_data['free_disk_space'] = array(
				'label' => __( 'Free disk space in account:', 'backupplus' ),
				/* translators: 1: String 'Free disk space MB', 2: Free disk space in percentage */
				'value' => sprintf( __( '%1$s (%2$s used)', 'backupplus' ), round( $hosting_bytes_free[3] / 1048576, 1 ) . ' MB', "$perc %" ),
			);
		}

		// Apache modules
		if ( function_exists( 'apache_get_modules' ) ) {
			$apache_info    = '';
			$apache_modules = apache_get_modules();
			if ( is_array( $apache_modules ) ) {
				sort( $apache_modules, SORT_STRING );
				foreach ( $apache_modules as $mod ) {
					if ( 0 === strpos( $mod, 'mod_' ) ) {
						$apache_info .= ', ' . substr( $mod, 4 );
					} else {
						$apache_info .= ', ' . $mod;
					}
				}
			}
			$apache_info                      = substr( $apache_info, 2 );
			$site_info_data['apache_modules'] = array(
				'label' => __( 'Apache modules', 'backupplus' ) . ':',
				'value' => $apache_info,
			);
		}

		// Debugging plugins
		if ( empty( $options['suppress_plugins_for_debugging'] ) ) {
			$site_info_data['debugging_plugins'] = array(
				'label'   => __( 'Install debugging plugins:', 'backupplus' ),
				'value'   => '<a href="' . wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&amp;backupplus_noautobackup=1&amp;plugin=wp-crontrol' ), 'install-plugin_wp-crontrol' ) . '">WP Crontrol</a> | <a href="' . wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&amp;backupplus_noautobackup=1&amp;plugin=query-monitor' ), 'install-plugin_query-monitor' ) . '">Query Monitor</a> | <a href="' . wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&amp;backupplus_noautobackup=1&amp;plugin=sql-executioner' ), 'install-plugin_sql-executioner' ) . '">SQL Executioner</a> | <a href="' . wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&amp;backupplus_noautobackup=1&amp;plugin=wp-file-manager' ), 'install-plugin_wp-file-manager' ) . '">WP Filemanager</a>',
				'is_html' => true,
			);
		}

		// HTTP Get test
		$site_info_data['http_get'] = array(
			'label'   => 'HTTP Get: ',
			'value'   => '<input id="backupplus_httpget_uri" type="text" class="call-action"> <a href="' . esc_url( BackupPlus::get_current_clean_url() ) . '" id="backupplus_httpget_go">' . __( 'Fetch', 'backupplus' ) . '</a> <a href="' . esc_url( BackupPlus::get_current_clean_url() ) . '" id="backupplus_httpget_gocurl">' . __( 'Fetch', 'backupplus' ) . ' (Curl)</a><p id="backupplus_httpget_results"></p>',
			'is_html' => true,
		);

		// Call WordPress action
		$site_info_data['wp_action'] = array(
			'label'   => __( 'Call WordPress action:', 'backupplus' ),
			'value'   => '<input id="backupplus_callwpaction" type="text" class="call-action"> <a href="' . esc_url( BackupPlus::get_current_clean_url() ) . '" id="backupplus_callwpaction_go">' . __( 'Call', 'backupplus' ) . '</a><div id="backupplus_callwpaction_results"></div>',
			'is_html' => true,
		);

		// Raw backup history and rescan
		$site_info_data['raw_backup'] = array(
			'label'   => '',
			'value'   => '<a href="admin-ajax.php?page=backupplus&amp;action=backupplus_ajax&amp;subaction=backuphistoryraw&amp;nonce=' . wp_create_nonce( 'backupplus-credentialtest-nonce' ) . '" id="backupplus-rawbackuphistory">' . __( 'Show raw backup and file list', 'backupplus' ) . '</a><br><a id="backupplus-remote-rescan-debug" href="#">' . __( 'Rescan remote storage', 'backupplus' ) . ' - ' . __( 'log results to console', 'backupplus' ) . '</a>',
			'is_html' => true,
		);

		return $site_info_data;
	}

	public function settings_advanced_tools( $return_instead_of_echo = false, $pass_through = array() ) {
		$options                        = isset( $pass_through['options'] ) ? $pass_through['options'] : array();
		$site_info_data                 = $this->get_site_info_data( $options );
		$pass_through['site_info_data'] = $site_info_data;

		return $this->include_template( 'wp-admin/advanced/advanced-tools.php', $return_instead_of_echo, $pass_through );
	}

	public function really_writable_message( $really_is_writable, $backupplus_dir ) {
		if ( $really_is_writable ) {
			$dir_info = '<span style="color:green;">' . __( 'Backup directory specified is writable, which is good.', 'backupplus' ) . '</span>';
		} else {
			$dir_info = '<span style="color:red;">';
			if ( ! is_dir( $backupplus_dir ) ) {
				$dir_info .= __( 'Backup directory specified does not exist.', 'backupplus' );
			} else {
				$dir_info .= __( 'Backup directory specified exists, but is not writable.', 'backupplus' );
			}
			$dir_info .= '<span class="backupplus-directory-not-writable-blurb"><span class="directory-permissions"><a class="backupplus_create_backup_dir" href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&action=backupplus_create_backup_dir&nonce=' . wp_create_nonce( 'create_backup_dir' ) . '">' . __( 'Follow this link to attempt to create the directory and set the permissions', 'backupplus' ) . '</a></span>, ' . __( 'or, to reset this option', 'backupplus' ) . ' <a href="' . esc_url( BackupPlus::get_current_clean_url() ) . '" class="backupplus_backup_dir_reset">' . __( 'press here', 'backupplus' ) . '</a>. ' . __( 'If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.', 'backupplus' ) . '</span>';
		}
		return $dir_info;
	}
}
