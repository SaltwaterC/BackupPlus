<?php

/** Cleanup of the -old directories a restore leaves behind, and the backup directory. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Old_Directories {

	/**
	 * Paint the HTML for the form for deleting old directories
	 *
	 * @param Boolean $include_blurb - whether to include explanatory text
	 * @param Boolean $include_div   - whether to wrap inside a div tag
	 */
	public function print_delete_old_dirs_form( $include_blurb = true, $include_div = true ) {
		if ( $include_blurb ) {
			if ( $include_div ) {
				echo '<div id="backupplus_delete_old_dirs_pagediv" class="updated delete-old-directories">';
			}
			echo '<p>' . esc_html( __( 'Your WordPress install has old folders from its state before you restored/migrated (technical information: these are suffixed with -old).', 'backupplus' ) . ' ' . __( 'You should press this button to delete them as soon as you have verified that the restoration worked.', 'backupplus' ) ) . '</p>';
		}
		?>
		<form method="post" action="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'error'                      => false,
					'backupplus_restore_success' => false,
					'action'                     => false,
					'page'                       => 'backupplus',
				),
				BackupPlus_Options::admin_page_url()
			)
		);
		?>
									">
			<?php wp_nonce_field( 'backupplus-credentialtest-nonce', 'backupplus_delete_old_dirs_nonce' ); ?>
			<input type="hidden" name="action" value="backupplus_delete_old_dirs">
			<input type="submit" class="button-primary" value="<?php echo esc_attr( __( 'Delete old folders', 'backupplus' ) ); ?>">
		</form>
		<?php
		if ( $include_blurb && $include_div ) {
			echo '</div>';
		}
	}

	private function delete_old_dirs_go( $show_return = true ) {
		echo $show_return ? '<h1>BackupPlus - ' . esc_html__( 'Remove old folders', 'backupplus' ) . '</h1>' : '<h2>' . esc_html__( 'Remove old directories', 'backupplus' ) . '</h2>';

		if ( $this->delete_old_dirs() ) {
			echo '<p>' . esc_html__( 'Old folders successfully removed.', 'backupplus' ) . '</p><br>';
		} else {
			echo '<p>' . esc_html__( 'Old folder removal failed for some reason.', 'backupplus' ) . ' ' . esc_html__( 'You may want to do this manually.', 'backupplus' ) . '</p><br>';
		}
		if ( $show_return ) {
			echo '<b>' . esc_html__( 'Actions', 'backupplus' ) . ':</b> <a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
		}
	}

	/**
	 * Deletes the -old directories and wp-config-pre-ud-restore-backup.php that are created when a backup is restored.
	 *
	 * @return Boolean. Can also exit (something we ought to probably review)
	 */
	private function delete_old_dirs() {
		global $wp_filesystem, $backupplus;
		$credentials = request_filesystem_credentials( wp_nonce_url( BackupPlus_Options::admin_page_url() . '?page=backupplus&action=backupplus_delete_old_dirs', 'backupplus-credentialtest-nonce', 'backupplus_delete_old_dirs_nonce' ) );
		$wpfs        = WP_Filesystem( $credentials );
		if ( ! empty( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
			foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
				show_message( $message );
			}
			exit;
		}
		if ( ! $wpfs ) {
			exit;
		}

		// From WP_CONTENT_DIR - which contains 'themes'
		$ret = $this->delete_old_dirs_dir( $wp_filesystem->wp_content_dir() );

		$backupplus_dir = $backupplus->backups_dir_location();
		if ( $backupplus_dir ) {
			$ret4 = $backupplus_dir ? $this->delete_old_dirs_dir( $backupplus_dir, false ) : true;
		} else {
			$ret4 = true;
		}

		$plugs = untrailingslashit( $wp_filesystem->wp_plugins_dir() );
		if ( $wp_filesystem->is_dir( $plugs . '-old' ) ) {
			echo '<strong>' . esc_html__( 'Delete', 'backupplus' ) . ': </strong>plugins-old: ';
			if ( ! $wp_filesystem->delete( $plugs . '-old', true ) ) {
				$ret3 = false;
				echo '<strong>' . esc_html__( 'Failed', 'backupplus' ) . '</strong><br>';
				echo esc_html( $backupplus->log_permission_failure_message( $wp_filesystem->wp_content_dir(), 'Delete ' . $plugs . '-old' ) );
			} else {
				$ret3 = true;
				echo '<strong>' . esc_html__( 'OK', 'backupplus' ) . '</strong><br>';
			}
		} else {
			$ret3 = true;
		}

		$ret2 = true;
		if ( $wp_filesystem->is_file( ABSPATH . 'wp-config-pre-ud-restore-backup.php' ) ) {
			echo '<strong>' . esc_html__( 'Delete', 'backupplus' ) . ': </strong>wp-config-pre-ud-restore-backup.php: ';

			if ( $wp_filesystem->delete( ABSPATH . 'wp-config-pre-ud-restore-backup.php' ) ) {
				echo '<strong>' . esc_html__( 'OK', 'backupplus' ) . '</strong><br>';
			} else {
				$ret2 = false;
				echo '<strong>' . esc_html__( 'Failed', 'backupplus' ) . '</strong><br>';
			}
		}

		return $ret && $ret2 && $ret3 && $ret4;
	}

	private function delete_old_dirs_dir( $dir, $wpfs = true ) {

		$dir = trailingslashit( $dir );

		global $wp_filesystem, $backupplus;

		if ( $wpfs ) {
			$list = $wp_filesystem->dirlist( $dir );
		} else {
			$list = scandir( $dir );
		}
		if ( ! is_array( $list ) ) {
			return false;
		}

		$ret = true;
		foreach ( $list as $item ) {
			$name = ( is_array( $item ) ) ? $item['name'] : $item;
			if ( '-old' == substr( $name, -4, 4 ) ) {
				// recursively delete
				print '<strong>' . esc_html__( 'Delete', 'backupplus' ) . ': </strong>' . esc_html( basename( $dir ) . '/' . $name ) . ': ';

				if ( $wpfs ) {
					if ( ! $wp_filesystem->delete( $dir . $name, true ) ) {
						$ret = false;
						echo '<strong>' . esc_html__( 'Failed', 'backupplus' ) . '</strong><br>';
						echo wp_kses_post( $backupplus->log_permission_failure_message( $dir, 'Delete ' . $dir . $name ) );
					} else {
						echo '<strong>' . esc_html__( 'OK', 'backupplus' ) . '</strong><br>';
					}
				} else {
					if ( BackupPlus_Filesystem_Functions::remove_local_directory( $dir . $name ) ) {
						echo '<strong>' . esc_html__( 'OK', 'backupplus' ) . '</strong><br>';
					} else {
						$ret = false;
						echo '<strong>' . esc_html__( 'Failed', 'backupplus' ) . '</strong><br>';
						echo wp_kses_post( $backupplus->log_permission_failure_message( $dir, 'Delete ' . $dir . $name ) );
					}
				}
			}
		}
		return $ret;
	}

	/**
	 * The aim is to get a directory that is writable by the webserver, because that's the only way we can create zip files
	 *
	 * @return Boolean|WP_Error true if successful, otherwise false or a WP_Error
	 */
	private function create_backup_dir() {

		global $wp_filesystem, $backupplus;

		if ( false === ( $credentials = request_filesystem_credentials( BackupPlus_Options::admin_page() . '?page=backupplus&action=backupplus_create_backup_dir&nonce=' . wp_create_nonce( 'create_backup_dir' ) ) ) ) {
			return false;
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			// our credentials were no good, ask the user for them again
			request_filesystem_credentials( BackupPlus_Options::admin_page() . '?page=backupplus&action=backupplus_create_backup_dir&nonce=' . wp_create_nonce( 'create_backup_dir' ), '', true );
			return false;
		}

		$backupplus_dir = $backupplus->backups_dir_location();

		$default_backup_dir = $wp_filesystem->find_folder( dirname( $backupplus_dir ) ) . basename( $backupplus_dir );

		$backupplus_dir = ( $backupplus_dir ) ? $wp_filesystem->find_folder( dirname( $backupplus_dir ) ) . basename( $backupplus_dir ) : $default_backup_dir;

		if ( ! $wp_filesystem->is_dir( $default_backup_dir ) && ! $wp_filesystem->mkdir( $default_backup_dir, 0775 ) ) {
			$wperr = new WP_Error();
			if ( $wp_filesystem->errors->get_error_code() ) {
				foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
					$wperr->add( 'mkdir_error', $message );
				}
				return $wperr;
			} else {
				return new WP_Error( 'mkdir_error', __( 'The request to the filesystem to create the directory failed.', 'backupplus' ) );
			}
		}

		if ( $wp_filesystem->is_dir( $default_backup_dir ) ) {

			if ( BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {
				return true;
			}

			@$wp_filesystem->chmod( $default_backup_dir, 0775 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			if ( BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {
				return true;
			}

			@$wp_filesystem->chmod( $default_backup_dir, 0777 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.

			if ( BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {
				echo '<p>' . esc_html__( 'The folder was created, but we had to change its file permissions to 777 (world-writable) to be able to write to it.', 'backupplus' ) . ' ' . esc_html__( 'You should check with your hosting provider that this will not cause any problems', 'backupplus' ) . '</p>';
				return true;
			} else {
				@$wp_filesystem->chmod( $default_backup_dir, 0775 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				$show_dir = ( 0 === strpos( $default_backup_dir, ABSPATH ) ) ? substr( $default_backup_dir, strlen( ABSPATH ) ) : $default_backup_dir;
				return new WP_Error( 'writable_error', __( 'The folder exists, but your webserver does not have permission to write to it.', 'backupplus' ) . ' ' . __( 'You will need to consult with your web hosting provider to find out how to set permissions for a WordPress plugin to write to the directory.', 'backupplus' ) . ' (' . $show_dir . ')' );
			}
		}

		return true;
	}

	/**
	 * scans the content dir to see if any -old dirs are present
	 *
	 * @param  Boolean $print_as_comment Echo information in an HTML comment
	 * @return Boolean
	 */
	private function scan_old_dirs( $print_as_comment = false ) {
		global $backupplus;
		$dirs = scandir( untrailingslashit( WP_CONTENT_DIR ) );
		if ( ! is_array( $dirs ) ) {
			$dirs = array();
		}
		foreach ( $dirs as $dir ) {
			if ( preg_match( '/-old$/', $dir ) ) {
				if ( $print_as_comment ) {
					echo '<!--' . esc_html( $dir ) . '-->';
				}
				return true;
			}
		}
		$backups_dir_location = $backupplus->backups_dir_location();
		$dirs                 = @scandir( $backups_dir_location );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( ! is_array( $dirs ) ) {
			$dirs = array();
		}
		foreach ( $dirs as $dir ) {
			if ( preg_match( '/-old$/', $dir ) ) {
				if ( $print_as_comment ) {
					echo '<!--' . esc_html( $dir ) . '-->';
				}
				return true;
			}
		}
		// No need to scan ABSPATH - we don't backup there
		if ( is_dir( untrailingslashit( WP_PLUGIN_DIR ) . '-old' ) ) {
			if ( $print_as_comment ) {
				echo '<!--' . esc_html( untrailingslashit( WP_PLUGIN_DIR ) . '-old' ) . '-->';
			}
			return true;
		}

		if ( is_file( ABSPATH . 'wp-config-pre-ud-restore-backup.php' ) ) {
			if ( $print_as_comment ) {
				echo '<!--' . esc_html( ABSPATH . 'wp-config-pre-ud-restore-backup.php' ) . '-->';
			}
			return true;
		}

		return false;
	}
}
