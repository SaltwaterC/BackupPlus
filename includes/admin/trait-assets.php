<?php

/** Admin scripts, styles and inline script/style output. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Assets {

	/**
	 * The unminified files that make up the admin script, in load order. They share the global scope, so a file may only run at load time what the files before it define. scripts/build-admin-common.mjs concatenates and minifies the same list for normal use.
	 *
	 * @return String[] File names under includes/admin-common/, without extension
	 */
	private function get_admin_common_script_files() {
		$files = json_decode( (string) file_get_contents( BACKUPPLUS_DIR . '/includes/admin-common/files.json' ), true );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * The minified admin script that make build-assets produces, if it is in use and present
	 *
	 * @return String|null Path relative to the plugin directory
	 */
	private function get_admin_common_minified_script() {
		global $backupplus;

		$path = 'includes/backupplus-admin-common' . $backupplus->get_backupplus_file_version() . '.js';
		return ( ! $backupplus->use_unminified_scripts() && is_file( BACKUPPLUS_DIR . '/' . $path ) ) ? $path : null;
	}

	/**
	 * The handle of the first admin script file, which carries the data localised for all the others
	 *
	 * @return String
	 */
	private function get_admin_common_first_handle() {
		if ( null !== $this->get_admin_common_minified_script() ) {
			return 'backupplus-admin-common';
		}
		$files = $this->get_admin_common_script_files();
		return 'backupplus-admin-common-' . $files[0];
	}

	/**
	 * Enqueue sufficient versions of jQuery and our own scripts
	 */
	private function ensure_sufficient_jquery_and_enqueue() {
		global $backupplus;

		$enqueue_version = $backupplus->use_unminified_scripts() ? $backupplus->version . '.' . time() : $backupplus->version;
		$min_or_not      = $backupplus->use_unminified_scripts() ? '' : '.min';

		$dependencies = array( 'jquery', 'jquery-ui-dialog', 'jquery-ui-core', 'jquery-ui-accordion' );
		$in_footer    = false;
		if ( version_compare( $backupplus->get_wordpress_version(), '3.3', '<' ) ) {
			// Require a newer jQuery (3.2.1 has 1.6.1, so we go for something not too much newer). We use .on() in a way that is incompatible with < 1.7
			wp_deregister_script( 'jquery' );
			$jquery_enqueue_version = $backupplus->use_unminified_scripts() ? '1.7.2' . '.' . time() : '1.7.2';
			wp_register_script( 'jquery', 'https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery' . $min_or_not . '.js', false, $jquery_enqueue_version, false ); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- The external jQuery script is only loaded on WordPress versions below 3.3
			wp_enqueue_script( 'jquery' );
			// No plupload until 3.3
			$in_footer = true;
		} else {
			$dependencies[] = 'plupload-all';
		}

		$minified = $this->get_admin_common_minified_script();
		if ( null !== $minified ) {
			wp_enqueue_script( 'backupplus-admin-common', BACKUPPLUS_URL . '/' . $minified, $dependencies, $enqueue_version, $in_footer );
			return;
		}

		// Debug mode, or no build present: load the source files one by one. The last one carries the handle other scripts depend on.
		$files = $this->get_admin_common_script_files();
		$last  = count( $files ) - 1;
		foreach ( $files as $index => $file ) {
			$path    = 'includes/admin-common/' . $file . '.js';
			$handle  = ( $index === $last ) ? 'backupplus-admin-common' : 'backupplus-admin-common-' . $file;
			$version = $backupplus->use_unminified_scripts() ? $enqueue_version : $backupplus->version . '.' . filemtime( BACKUPPLUS_DIR . '/' . $path );
			wp_enqueue_script( $handle, BACKUPPLUS_URL . '/' . $path, $dependencies, $version, $in_footer );
			$dependencies = array( $handle );
		}
	}

	/**
	 * This is also called directly from the auto-backup add-on
	 */
	public function admin_enqueue_scripts() {

		global $backupplus;

		// This should not happen, but is an extra protection in case it does.
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		$enqueue_version       = $backupplus->use_unminified_scripts() ? $backupplus->version . '.' . time() : $backupplus->version;
		$min_or_not            = $backupplus->use_unminified_scripts() ? '' : '.min';
		$backupplus_min_or_not = $backupplus->get_backupplus_file_version();

		// Defeat other plugins/themes which dump their jQuery UI CSS onto our settings page
		if ( ! wp_style_is( 'jquery-ui', 'done' ) ) {
			wp_dequeue_style( 'jquery-ui' );
			wp_deregister_style( 'jquery-ui' );
		}
		$jquery_ui_version             = version_compare( $backupplus->get_wordpress_version(), '5.6', '>=' ) ? '1.12.1' : '1.11.4';
		$jquery_ui_css_enqueue_version = $backupplus->use_unminified_scripts() ? $jquery_ui_version . '.0' . '.' . time() : $jquery_ui_version . '.0';
		wp_enqueue_style( 'backupplus-jquery-ui', BACKUPPLUS_URL . "/includes/jquery-ui.custom-v$jquery_ui_version$backupplus_min_or_not.css", array(), $jquery_ui_css_enqueue_version );

		wp_enqueue_style( 'backupplus-admin-css', BACKUPPLUS_URL . '/css/backupplus-admin' . $backupplus_min_or_not . '.css', array(), $enqueue_version );
		wp_enqueue_style( 'backupplus-decryption-css', BACKUPPLUS_URL . '/css/backupplus-decryption.css', array( 'backupplus-admin-css' ), $enqueue_version );
		wp_enqueue_script( 'backupplus-age-keygen', BACKUPPLUS_URL . '/js/backupplus-age-keygen.js', array(), $enqueue_version, true );
		wp_localize_script(
			'backupplus-age-keygen',
			'backupplus_age_keygen',
			array(
				'added'       => __( 'Public recipient added. Click Save Changes to save it and enable age encryption.', 'backupplus' ),
				'failed'      => __( 'Could not generate an age key. Try again in a modern browser over HTTPS or localhost.', 'backupplus' ),
				'unsupported' => __( 'Local age key generation requires HTTPS (or localhost) and a modern browser with Web Crypto.', 'backupplus' ),
				'limit'       => __( 'You can save at most 100 recipient entries. Remove an entry before generating another key.', 'backupplus' ),
			)
		);
		wp_enqueue_script( 'backupplus-decryption', BACKUPPLUS_URL . '/includes/backupplus-decryption.js', array(), $enqueue_version, true );
		wp_enqueue_script( 'backupplus-restore-decryption', BACKUPPLUS_URL . '/includes/backupplus-restore-decryption.js', array( 'backupplus-decryption', 'backupplus-admin-common' ), $enqueue_version . '.' . filemtime( BACKUPPLUS_DIR . '/includes/backupplus-restore-decryption.js' ), true );
		$agessh_enqueue_version = $enqueue_version . '.' . filemtime( BACKUPPLUS_DIR . '/js/backupplus-agessh.wasm' );
		wp_localize_script(
			'backupplus-decryption',
			'backupplus_decryption',
			array(
				'worker'      => BACKUPPLUS_URL . '/js/backupplus-decryption-worker.js?ver=' . rawurlencode( $enqueue_version ),
				'agessh'      => array(
					'compressedWasmURL' => BACKUPPLUS_URL . '/js/backupplus-agessh.wasm.gz?ver=' . rawurlencode( $agessh_enqueue_version ),
					'wasmURL'           => BACKUPPLUS_URL . '/js/backupplus-agessh.wasm?ver=' . rawurlencode( $agessh_enqueue_version ),
					'runtimeURL'        => BACKUPPLUS_URL . '/js/backupplus-agessh-wasm_exec.js?ver=' . rawurlencode( $agessh_enqueue_version ),
				),
				'decrypting'  => __( 'Decrypting locally:', 'backupplus' ),
				'extracting'  => __( 'Extracting locally:', 'backupplus' ),
				'stages'      => array(
					'decrypt' => __( 'Decrypting:', 'backupplus' ),
					'extract' => __( 'Extracting:', 'backupplus' ),
				),
				'downloading' => __( 'Downloading encrypted archive:', 'backupplus' ),
				'uploading'   => __( 'Uploading plaintext restore copy:', 'backupplus' ),
				'download'    => __( 'Download', 'backupplus' ),
				'complete'    => __( 'Extraction complete. Download your files, then clear the files to remove temporary browser copies.', 'backupplus' ),
				'cancelled'   => __( 'Extraction cancelled. Temporary output and keys have been cleared.', 'backupplus' ),
				'none'        => __( 'This is not an encrypted or compressed backup archive, so there is nothing to extract.', 'backupplus' ),
				'nothing'     => __( 'Nothing to extract: none of the selected files is encrypted, compressed or a set of backup archives.', 'backupplus' ),
				/* translators: %d: number of links, devices and other special entries that were not extracted from a tar archive */
				'skipped'     => __( '%d symbolic links or special files in the archive were not extracted.', 'backupplus' ),
				'legacy'      => __( 'Legacy encryption: integrity cannot be verified. Check the downloaded archive before use.', 'backupplus' ),
				'errors'      => array(
					'TRANSFER_FAILED' => __( 'Could not transfer the restore archives. Retry the restore preparation.', 'backupplus' ),
					'CLEANUP_FAILED'  => __( 'Temporary browser files could not be removed. Close other extraction tabs and clear files again, or clear this site’s browser storage.', 'backupplus' ),
					'STORAGE_FAILED'  => __( 'Browser storage is full or unavailable. Free local space and try again.', 'backupplus' ),
					'DECRYPT_FAILED'  => __( 'Unable to decrypt. Check the passphrase or identity and that the archive is complete and unmodified.', 'backupplus' ),
					'ARCHIVE_FAILED'  => __( 'Unable to extract. The archive is incomplete, damaged or in an unsupported format. Zip files must be stored or deflated and not password protected.', 'backupplus' ),
					'UNSUPPORTED_KEY' => __( 'Unsupported private key format or cipher. Use a native age identity, an OpenSSH RSA/Ed25519 key (unencrypted or bcrypt with AES-256-CTR/AES-256-GCM), or a PEM key (unencrypted or PBES2/PBKDF2/AES-CBC).', 'backupplus' ),
					'SECURE_CONTEXT'  => __( 'Local decryption and extraction require HTTPS (or localhost), Web Crypto and Web Workers in a modern browser.', 'backupplus' ),
					'MEMORY_LIMIT'    => __( 'This browser cannot stage large files locally. Allow private browser storage or use a browser with Origin Private File System support. Files above 128 MiB require that storage.', 'backupplus' ),
					'WORKER_FAILED'   => __( 'The local extraction worker could not run. Check browser storage, memory and site policy, then try again.', 'backupplus' ),
				),
			)
		);
		// add_filter('style_loader_tag', array($this, 'style_loader_tag'), 10, 2);

		$this->ensure_sufficient_jquery_and_enqueue();
		$jquery_blockui_enqueue_version = $backupplus->use_unminified_scripts() ? '2.71.0' . '.' . time() : '2.71.0';
		wp_enqueue_script( 'jquery-blockui', BACKUPPLUS_URL . '/includes/blockui/jquery.blockUI' . $min_or_not . '.js', array( 'jquery' ), $jquery_blockui_enqueue_version );

		wp_enqueue_script( 'jquery-labelauty', BACKUPPLUS_URL . '/includes/labelauty/jquery-labelauty' . $backupplus_min_or_not . '.js', array( 'jquery' ), $enqueue_version );
		wp_enqueue_style( 'jquery-labelauty', BACKUPPLUS_URL . '/includes/labelauty/jquery-labelauty' . $backupplus_min_or_not . '.css', array(), $enqueue_version );
		$serialize_js_enqueue_version = $backupplus->use_unminified_scripts() ? '3.2.0' . '.' . time() : '3.2.0';
		wp_enqueue_script( 'jquery.serializeJSON', BACKUPPLUS_URL . '/includes/jquery.serializeJSON/jquery.serializejson' . $min_or_not . '.js', array( 'jquery' ), $serialize_js_enqueue_version );
		wp_enqueue_script( 'handlebars', BACKUPPLUS_URL . '/includes/handlebars/handlebars' . $min_or_not . '.js', array(), $enqueue_version );
		$this->enqueue_jstree();

		$jqueryui_dialog_extended_version = $backupplus->use_unminified_scripts() ? '1.0.4' . '.' . time() : '1.0.4';
		wp_enqueue_script( 'jquery-ui.dialog.extended', BACKUPPLUS_URL . '/includes/jquery-ui.dialog.extended/jquery-ui.dialog.extended' . $backupplus_min_or_not . '.js', array( 'jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-dialog' ), $jqueryui_dialog_extended_version );

		$remote_storage_options_and_templates = BackupPlus_Storage_Methods_Interface::get_remote_storage_options_and_templates();
		$backup_methods                       = $backupplus->backup_methods;
		$provider_script_data                 = array();
		foreach ( array_keys( $backup_methods ) as $method ) {
			$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $method );
			if ( ! is_wp_error( $provider ) ) {
				$provider_script_data = array_merge( $provider_script_data, $provider->get_admin_script_data() );
			}
		}
		do_action( 'backupplus_admin_enqueue_scripts' );
		$main_tabs = $this->get_main_tabs_array();

		$hosting_company = $backupplus->get_hosting_info();
		$tab             = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'tab', '', false, 'string', null );
		wp_localize_script(
			$this->get_admin_common_first_handle(),
			'backupplus_i18n',
			array_merge(
				$provider_script_data,
				array(
					'tab'                                  => ( empty( $tab ) || ! preg_match( '/^[a-z]+$/', $tab ) ) ? 'backups' : $tab,
					'sendonlyonwarnings'                   => __( 'Send a report only when there are warnings/errors', 'backupplus' ),
					'wholebackup'                          => __( 'When email storage method is enabled, and an email address is entered, also send the backup', 'backupplus' ),
					/* translators: %s: Typical mail server size limit in megabytes */
					'emailsizelimits'                      => esc_attr( sprintf( __( 'Be aware that mail servers tend to have size limits; typically around %s Mb; backups larger than any limits will likely not arrive.', 'backupplus' ), '10-20' ) ),
					'rescanning'                           => __( 'Rescanning (looking for backups that you have uploaded manually into the internal backup store)...', 'backupplus' ),
					'dbbackup'                             => __( 'Only email the database backup', 'backupplus' ),
					'rescanningremote'                     => __( 'Rescanning remote and local storage for backup sets...', 'backupplus' ),
					'enteremailhere'                       => esc_attr( __( 'To send to more than one address, separate each address with a comma.', 'backupplus' ) ),
					'excludedeverything'                   => __( 'If you exclude both the database and the files, then you have excluded everything!', 'backupplus' ),
					'nofileschosen'                        => __( 'You have chosen to backup files, but no file entities have been selected', 'backupplus' ),
					'notableschosen'                       => __( 'You have chosen to backup a database, but no tables have been selected', 'backupplus' ),
					'nocloudserviceschosen'                => __( 'You have chosen to send this backup to remote storage, but no remote storage locations have been selected', 'backupplus' ),
					'restore_proceeding'                   => __( 'The restore operation has begun.', 'backupplus' ) . ' ' . __( 'Do not close your browser until it reports itself as having finished.', 'backupplus' ),
					'unexpectedresponse'                   => __( 'Unexpected response:', 'backupplus' ),
					'backup_locked_title'                  => __( 'Locked: retention never deletes this backup. Click to unlock.', 'backupplus' ),
					'backup_unlocked_title'                => __( 'Unlocked: retention may delete this backup. Click to lock.', 'backupplus' ),
					'backup_lock_failed'                   => __( 'Some backups could not be changed:', 'backupplus' ),
					'servererrorcode'                      => __( 'The web server returned an error code (try again, or check your web server logs)', 'backupplus' ),
					'newuserpass'                          => __( "The new user's RackSpace console password is (this will not be shown again):", 'backupplus' ),
					'trying'                               => __( 'Trying...', 'backupplus' ),
					'fetching'                             => __( 'Fetching...', 'backupplus' ),
					'calculating'                          => __( 'calculating...', 'backupplus' ),
					'begunlooking'                         => __( 'Begun looking for this entity', 'backupplus' ),
					'download_all'                         => __( 'Download all', 'backupplus' ),
					'chain_points_title'                   => __( 'Recovery points', 'backupplus' ),
					'chain_points_note'                    => __( 'Each recovery point downloads with all of its files. Several points download as one ZIP archive.', 'backupplus' ),
					'chain_points_encrypted'               => __( 'Encrypted archives cannot be browsed here; decrypt them in the Extract tab.', 'backupplus' ),
					'chain_points_filter'                  => __( 'Filter by date or time, for example 2026-10-26 15:5', 'backupplus' ),
					'chain_points_select'                  => __( 'Select all matching', 'backupplus' ),
					'chain_points_clear'                   => __( 'Clear selection', 'backupplus' ),
					'chain_points_more'                    => __( 'Show more', 'backupplus' ),
					'chain_points_one'                     => __( 'recovery point', 'backupplus' ),
					'chain_points_many'                    => __( 'recovery points', 'backupplus' ),
					'chain_points_files'                   => __( 'file(s)', 'backupplus' ),
					'chain_points_this'                    => __( 'Download', 'backupplus' ),
					'chain_points_through'                 => __( 'Download with earlier points', 'backupplus' ),
					'chain_points_through_title'           => __( 'Download this point with the full backup and every incremental point before it, which is everything needed to recover to it from files', 'backupplus' ),
					'chain_points_download_selected'       => __( 'Download selected', 'backupplus' ),
					'chain_points_download_all'            => __( 'Download all', 'backupplus' ),
					'chain_points_download_matching'       => __( 'Download all matching', 'backupplus' ),
					'chain_points_delete_selected'         => __( 'Delete selected from server', 'backupplus' ),
					'chain_points_delete'                  => __( 'Delete from server', 'backupplus' ),
					'chain_points_delete_title'            => __( 'Delete this recovery point from this web server. Copies in remote storage are kept', 'backupplus' ),
					'chain_points_delete_title_only'       => __( 'Delete this recovery point and every later one permanently, as a recovery point cannot be restored without the ones before it', 'backupplus' ),
					'chain_points_delete_remote_selected'  => __( 'Delete selected from remote storage', 'backupplus' ),
					'chain_points_delete_remote'           => __( 'Delete from remote storage', 'backupplus' ),
					'chain_points_delete_remote_title'     => __( 'Delete this recovery point and every later one from remote storage. Copies on this web server are kept', 'backupplus' ),
					'chain_points_deleting'                => __( 'Deleting...', 'backupplus' ),
					'chain_points_nothing'                 => __( 'Nothing to delete: none of the selected recovery points has a copy there.', 'backupplus' ),
					/* translators: 1: Number of files, 2: Size freed. */
					'chain_points_deleted'                 => __( 'Deleted %d file(s) (%s) from this server.', 'backupplus' ),
					/* translators: %d: Number of files. */
					'chain_points_deleted_remote'          => __( 'Deleted %d file(s) from remote storage.', 'backupplus' ),
					/* translators: 1: Number of recovery points, 2: Backup component, e.g. Uploads. */
					'chain_points_confirm_server'          => __( 'Delete the copies of %1$d recovery point(s) of %2$s from this web server? Copies in remote storage are kept and fetched again when needed.', 'backupplus' ),
					/* translators: 1: Number of recovery points, 2: Backup component, e.g. Uploads. */
					'chain_points_confirm_permanent'       => __( 'Permanently delete %1$d recovery point(s) of %2$s? A recovery point can only be restored together with everything before it, so the selected points and every later one are removed.', 'backupplus' ),
					'chain_points_confirm_permanent_remote' => __( 'Their copies in remote storage are deleted too.', 'backupplus' ),
					/* translators: 1: Number of recovery points, 2: Backup component, e.g. Uploads. */
					'chain_points_confirm_remote'          => __( 'Delete %1$d recovery point(s) of %2$s from remote storage? Every later recovery point is deleted there too, because it cannot be restored without the ones before it. Copies on this web server are kept.', 'backupplus' ),
					/* translators: %d: Number of recovery points. */
					'chain_points_confirm_lost'            => __( '%d of them are not on this web server and are lost permanently.', 'backupplus' ),
					'chain_points_none'                    => __( 'No recovery points match.', 'backupplus' ),
					'chain_points_failed'                  => __( 'The request failed. Check your web server logs and try again.', 'backupplus' ),
					/* translators: %d: Number of recovery points. */
					'chain_points_preparing'               => __( 'Preparing %d recovery point(s); files held only in remote storage are retrieved first...', 'backupplus' ),
					/* translators: 1: Archive name, 2: File count, 3: Archive size. */
					'chain_points_started'                 => __( 'Download started: %s (%d files, %s).', 'backupplus' ),
					'download_selected'                    => __( 'Download selected', 'backupplus' ),
					'download_select_all'                  => __( 'Select all', 'backupplus' ),
					'dismiss_selected'                     => __( 'Dismiss selected', 'backupplus' ),
					'dismiss_all'                          => __( 'Dismiss all', 'backupplus' ),
					'stilldownloading'                     => __( 'Some files are still downloading or being processed - please wait.', 'backupplus' ),
					'processing'                           => __( 'Processing files - please wait...', 'backupplus' ),
					'emptyresponse'                        => __( 'Error: the server sent an empty response.', 'backupplus' ),
					'warnings'                             => __( 'Warnings:', 'backupplus' ),
					'errors'                               => __( 'Errors:', 'backupplus' ),
					'jsonnotunderstood'                    => __( 'Error: the server sent us a response which we did not understand.', 'backupplus' ),
					'errordata'                            => __( 'Error data:', 'backupplus' ),
					'error'                                => __( 'Error:', 'backupplus' ),
					'errornocolon'                         => __( 'Error', 'backupplus' ),
					'existing_backups'                     => __( 'Existing backups', 'backupplus' ),
					'fileready'                            => __( 'File ready.', 'backupplus' ),
					'filesize'                             => __( 'File size', 'backupplus' ),
					'actions'                              => __( 'Actions', 'backupplus' ),
					'deletefromserver'                     => __( 'Delete from your web server', 'backupplus' ),
					'downloadtocomputer'                   => __( 'Download to your computer', 'backupplus' ),
					'browse_contents'                      => __( 'Browse contents', 'backupplus' ),
					'notunderstood'                        => __( 'Download error: the server sent us a response which we did not understand.', 'backupplus' ),
					'requeststart'                         => __( 'Requesting start of backup...', 'backupplus' ),
					'phpinfo'                              => __( 'PHP information', 'backupplus' ),
					'delete_old_dirs'                      => __( 'Delete Old Directories', 'backupplus' ),
					'raw'                                  => __( 'Raw backup history', 'backupplus' ),
					'notarchive'                           => __( 'This filename does not match a BackupPlus or UpdraftPlus backup archive. Expected backup_(time)_(site name)_(code)_(type).zip or .gz, optionally followed by .crypt.', 'backupplus' ) . ' ' . __( 'However, BackupPlus and UpdraftPlus archives are standard zip/SQL files - so if you are sure that your file has the right format, then you can rename it to match that pattern.', 'backupplus' ),
					'notarchive2'                          => '<p>' . __( 'This filename does not match a BackupPlus or UpdraftPlus backup archive. Expected backup_(time)_(site name)_(code)_(type).zip or .gz, optionally followed by .crypt.', 'backupplus' ) . '</p>',
					'makesure'                             => __( '(make sure that you were trying to upload a zip file previously created by BackupPlus or UpdraftPlus)', 'backupplus' ),
					'uploaderror'                          => __( 'Upload error:', 'backupplus' ),
					'notdba'                               => __( 'Use a BackupPlus encrypted archive ending in .sql.zst.crypt, .tar.zst.crypt, .gz.crypt or .zip.crypt.', 'backupplus' ),
					'uploaderr'                            => __( 'Upload error', 'backupplus' ),
					'followlink'                           => __( 'Decrypt and download the backup archive to your computer.', 'backupplus' ),
					'thiskey'                              => __( 'This decryption key will be attempted:', 'backupplus' ),
					'unknownresp'                          => __( 'Unknown server response:', 'backupplus' ),
					'ukrespstatus'                         => __( 'Unknown server response status:', 'backupplus' ),
					'uploaded'                             => __( 'The file was uploaded.', 'backupplus' ),
					// One of the translators has erroneously changed "Backup" into "Back up" (which means, "reverse" !)
					'backupnow'                            => str_ireplace( 'Back Up', 'Backup', __( 'Backup Now', 'backupplus' ) ),
					'cancel'                               => __( 'Cancel', 'backupplus' ),
					'deletebutton'                         => __( 'Delete', 'backupplus' ),
					'createbutton'                         => __( 'Create', 'backupplus' ),
					'uploadbutton'                         => __( 'Upload', 'backupplus' ),
					'youdidnotselectany'                   => __( 'You did not select any components to restore.', 'backupplus' ) . ' ' . __( 'Please select at least one, and then try again.', 'backupplus' ),
					'restoreactivitylogfullscreen'         => __( 'Full-screen', 'backupplus' ),
					'restoreactivitylogscreenexit'         => __( 'Exit full-screen', 'backupplus' ),
					'proceedwithupdate'                    => __( 'Proceed with update', 'backupplus' ),
					'close'                                => __( 'Close', 'backupplus' ),
					'restore'                              => __( 'Restore', 'backupplus' ),
					'downloadlogfile'                      => __( 'Download log file', 'backupplus' ),
					'automaticbackupbeforeupdate'          => __( 'Automatic backup before update', 'backupplus' ),
					'unsavedsettings'                      => __( 'You have made changes to your settings, and not saved.', 'backupplus' ),
					'saving'                               => __( 'Saving...', 'backupplus' ),
					'connect'                              => __( 'Connect', 'backupplus' ),
					'connecting'                           => __( 'Connecting...', 'backupplus' ),
					'disconnect'                           => __( 'Disconnect', 'backupplus' ),
					'disconnecting'                        => __( 'Disconnecting...', 'backupplus' ),
					'counting'                             => __( 'Counting...', 'backupplus' ),
					'updatequotacount'                     => __( 'Update quota count', 'backupplus' ),
					// 'resetting' => __('Resetting...', 'backupplus'),
					'download'                             => _x( 'Download', '(verb)', 'backupplus' ),
					'browse_download_link'                 => apply_filters( 'backupplus_browse_download_link', '' ),
					'unsavedsettingsbackup'                => __( 'You have made changes to your settings, and not saved.', 'backupplus' ) . "\n" . __( 'You should save your changes to ensure that they are used for making your backup.', 'backupplus' ),
					'unsaved_settings_export'              => __( 'You have made changes to your settings, and not saved.', 'backupplus' ) . "\n" . __( 'Your export file will be of your displayed settings, not your saved ones.', 'backupplus' ),
					'days'                                 => __( 'day(s)', 'backupplus' ),
					'hours'                                => __( 'hour(s)', 'backupplus' ),
					'weeks'                                => __( 'week(s)', 'backupplus' ),
					'forbackupsolderthan'                  => __( 'For backups older than', 'backupplus' ),
					'ud_url'                               => BACKUPPLUS_URL,
					'processing'                           => __( 'Processing...', 'backupplus' ),
					'loading'                              => __( 'Loading...', 'backupplus' ),
					'pleasefillinrequired'                 => __( 'Please fill in the required information.', 'backupplus' ),
					/* translators: %s: Settings type */
					'test_settings'                        => __( 'Test %s Settings', 'backupplus' ),
					/* translators: %s: Settings type */
					'testing_settings'                     => __( 'Testing %s Settings...', 'backupplus' ),
					/* translators: %s: Settings type */
					'settings_test_result'                 => __( '%s settings test result:', 'backupplus' ),
					'nothing_yet_logged'                   => __( 'Nothing yet logged', 'backupplus' ),
					'import_select_file'                   => __( 'You have not yet selected a file to import.', 'backupplus' ),
					'import_invalid_json_file'             => __( 'Error: The chosen file is corrupt.', 'backupplus' ) . ' ' . __( 'Please choose a valid BackupPlus or UpdraftPlus export file.', 'backupplus' ),
					'import_skipped_settings'              => __( 'These settings were skipped because this BackupPlus build does not support them:', 'backupplus' ),
					'backupplus_settings_url'              => BackupPlus_Options::admin_page_url() . '?page=backupplus',
					'network_site_url'                     => network_site_url(),
					'importing'                            => __( 'Importing...', 'backupplus' ),
					'importing_data_from'                  => __( 'This will import data from:', 'backupplus' ),
					'exported_on'                          => __( 'Which was exported on:', 'backupplus' ),
					'continue_import'                      => __( 'Do you want to carry out the import?', 'backupplus' ),
					'complete'                             => __( 'Complete', 'backupplus' ),
					'backup_complete'                      => __( 'The backup has finished running', 'backupplus' ),
					'backup_aborted'                       => __( 'The backup was aborted', 'backupplus' ),
					'remote_delete_limit'                  => defined( 'BACKUPPLUS_REMOTE_DELETE_LIMIT' ) ? BACKUPPLUS_REMOTE_DELETE_LIMIT : 25, // this used to be number of files but now the value of this constant is in seconds
					'remote_files_deleted'                 => __( 'remote files deleted', 'backupplus' ),
					'http_code'                            => __( 'HTTP code:', 'backupplus' ),
					'makesure2'                            => __( 'The file failed to upload.', 'backupplus' ) . ' ' . __( 'Please check the following:', 'backupplus' ) . "\n\n - " . __( 'Any settings in your .htaccess or web.config file that affects the maximum upload or post size.', 'backupplus' ) . "\n - " . __( 'The available memory on the server.', 'backupplus' ) . "\n - " . __( 'That you are attempting to upload a zip file previously created by BackupPlus or UpdraftPlus.', 'backupplus' ) . "\n\n" . __( 'Further information may be found in the browser JavaScript console, and the server PHP error logs.', 'backupplus' ),
					'zip_file_contents'                    => __( 'Browsing zip file', 'backupplus' ),
					'zip_file_contents_info'               => __( 'Select a file to view information about it', 'backupplus' ),
					'search'                               => __( 'Search', 'backupplus' ),
					'download_timeout'                     => __( 'Unable to download file.', 'backupplus' ) . ' ' . __( 'This could be caused by a timeout.', 'backupplus' ) . ' ' . __( 'It would be best to download the zip to your computer.', 'backupplus' ),
					'loading_log_file'                     => __( 'Loading log file', 'backupplus' ),
					'backupplus_version'                   => $backupplus->version,
					'credential_export_omissions'          => BackupPlus_Credential_Storage::export_omitted_options(),
					'export_settings_file_name'            => 'backupplus-settings-' . sanitize_title( get_bloginfo( 'name' ) ) . '.json',
					// For remote storage handlebarsjs template
					'remote_storage_options'               => $remote_storage_options_and_templates['options'],
					'remote_storage_templates'             => $remote_storage_options_and_templates['templates'],
					'remote_storage_partial_templates'     => $remote_storage_options_and_templates['partial_templates'],
					'remote_storage_methods'               => $backup_methods,
					/* translators: %s: Remote storage type, e.g. SFTP / SCP */
					'add_instance'                         => __( 'Add another %s destination', 'backupplus' ),
					'instance_enabled'                     => __( 'Currently enabled', 'backupplus' ),
					'instance_disabled'                    => __( 'Currently disabled', 'backupplus' ),
					'local_upload_started'                 => __( 'Local backup upload has started; please check the log file to see the upload progress', 'backupplus' ),
					'local_upload_error'                   => __( 'You must select at least one remote storage destination to upload this backup set to.', 'backupplus' ),
					'already_uploaded'                     => __( '(already uploaded)', 'backupplus' ),
					'onedrive_folder_url_warning'          => __( 'Please specify the Microsoft OneDrive folder name, not the URL.', 'backupplus' ),
					'main_tabs_keys'                       => array_keys( $main_tabs ),
					'current_clean_url'                    => esc_url( BackupPlus::get_current_clean_url() ),
					'exclude_rule_remove_conformation_msg' => __( 'Are you sure you want to remove this exclusion rule?', 'backupplus' ),
					'exclude_select_file_or_folder_msg'    => __( 'Please select a file/folder which you would like to exclude', 'backupplus' ),
					'exclude_select_folder_wildcards_msg'  => __( 'Please select a folder in which the files/directories you would like to exclude are located', 'backupplus' ),
					'exclude_type_ext_msg'                 => __( 'Please enter a file extension, like zip', 'backupplus' ),
					'exclude_ext_error_msg'                => __( 'Please enter a valid file extension', 'backupplus' ),
					'exclude_type_prefix_msg'              => __( 'Please enter characters that begin the filename which you would like to exclude', 'backupplus' ),
					'exclude_prefix_error_msg'             => __( 'Please enter a valid file name prefix', 'backupplus' ),
					'exclude_contain_error_msg'            => __( 'Please enter part of the file name', 'backupplus' ),
					'duplicate_exclude_rule_error_msg'     => __( 'The exclusion rule which you are trying to add already exists', 'backupplus' ),
					'files_new_backup'                     => __( 'Include your files in the backup', 'backupplus' ),
					'files_incremental_backup'             => __( 'File backup options', 'backupplus' ),
					'database_new_backup'                  => __( 'Include your database in the backup', 'backupplus' ),
					'database_incremental_backup'          => __( 'Include your database (point-in-time recovery)', 'backupplus' ),
					'send_backup_remote'                   => __( 'Send this backup to remote storage', 'backupplus' ),
					'send_files_remote'                    => __( 'Send file backup to remote storage', 'backupplus' ),
					'pitr_verify_success'                  => __( 'The backup chain and automatic restore protection checks passed. Restoring will take the site offline, block application database writes, and replace this site’s entire database. A pinned pre-restore backup will be created first.', 'backupplus' ),
					'pitr_restore_database'                => __( 'Restore database', 'backupplus' ),
					'pitr_setup_working'                   => __( 'Checking PITR backup access…', 'backupplus' ),
					'pitr_setup_failed'                    => __( 'The backup-account check could not contact the server. Retry the check.', 'backupplus' ),
					'pitr_restore_running'                 => __( 'Restoring the database and replaying incremental stages…', 'backupplus' ),
					'pitr_restore_complete'                => __( 'Database recovery completed', 'backupplus' ),
					'restore_done'                         => __( 'Done', 'backupplus' ),
					'pitr_restore_success'                 => __( 'Database recovery completed. PITR capture keeps its previous setting and starts a new chain.', 'backupplus' ),
					'pitr_unavailable'                     => __( 'This database recovery chain has no verified points. Rescan its artifacts before restoring.', 'backupplus' ),
					'ajax_restore_invalid_response'        => __( 'HTML was detected in the response.', 'backupplus' ) . ' ' . __( 'You may have a security module on your webserver blocking the restoration operation.', 'backupplus' ),
					'emptyrestorepath'                     => __( 'You have not selected a restore path for your chosen backups', 'backupplus' ),
					'credentials'                          => __( 'credentials', 'backupplus' ),
					'username'                             => __( 'Username', 'backupplus' ),
					'password'                             => __( 'Password', 'backupplus' ),
					/* translators: %d: Seconds ago */
					'last_activity'                        => __( 'last activity: %d seconds ago', 'backupplus' ),
					/* translators: %d: Seconds until resumption */
					'no_recent_activity'                   => __( 'no recent activity; will offer resumption after: %d seconds', 'backupplus' ),
					/* translators: 1: the total number of files already restored, 2: the total number of files to be restored */
					'restore_files_progress'               => __( 'Restoring %1$s files out of %2$s', 'backupplus' ),
					/* translators: %s: Database table name */
					'restore_db_table_progress'            => __( 'Restoring table: %s', 'backupplus' ),
					/* translators: %s: Stored routine name */
					'restore_db_stored_routine_progress'   => __( 'Restoring stored routine: %s', 'backupplus' ),
					'finished'                             => __( 'Finished', 'backupplus' ),
					'begun'                                => __( 'Begun', 'backupplus' ),
					'maybe_downloading_entities'           => __( 'Downloading backup files if needed', 'backupplus' ),
					'preparing_backup_files'               => __( 'Preparing backup files', 'backupplus' ),
					'ajax_restore_contact_failed'          => __( 'Attempts by the browser to contact the website failed.', 'backupplus' ),
					'ajax_restore_error'                   => __( 'Restore error:', 'backupplus' ),
					'ajax_restore_404_detected'            => '<div class="notice notice-warning" style="margin: 0px; padding: 5px;"><p><span class="dashicons dashicons-warning"></span> <strong>' . __( 'Warning:', 'backupplus' ) . '</strong></p><p>' . __( 'Attempts by the browser to access some pages have returned a "not found (404)" error.', 'backupplus' ) . ' ' . __( 'This could mean that your .htaccess file has incorrect contents, is missing, or that your webserver is missing an equivalent mechanism.', 'backupplus' ) . '</p><p>' . __( 'Missing pages:', 'backupplus' ) . '</p><ul class="backupplus_missing_pages"></ul></div>',
					'delete_error_log_prompt'              => __( 'Please check the error log for more details', 'backupplus' ),
					'existing_backups_limit'               => defined( 'BACKUPPLUS_EXISTING_BACKUPS_LIMIT' ) ? BACKUPPLUS_EXISTING_BACKUPS_LIMIT : 100,
					'remote_scan_warning'                  => __( 'Warning: if you continue, you will add all backups stored in the configured remote storage directory (whichever site they were created by).', 'backupplus' ),
					'hosting_restriction_one_backup_permonth' => __( 'You have reached the monthly limit for the number of backups you can create at this time.', 'backupplus' ) . ' ' .
							__( 'Your hosting provider only allows you to take one backup per month.', 'backupplus' ) . ' ' .
							/* translators: %s: Hosting provider name */
							sprintf( __( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ), $hosting_company['name'] ),
					'hosting_restriction_one_incremental_perday' => __( 'You have reached the daily limit for the number of incremental backups you can create at this time.', 'backupplus' ) . ' ' .
							__( 'Your hosting provider only allows you to take one incremental backup per day.', 'backupplus' ) . ' ' .
							/* translators: %s: Hosting provider name */
							sprintf( __( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ), $hosting_company['name'] ),
					'hosting_restriction'                  => $backupplus->is_hosting_backup_limit_reached(),
					'conditional_logic'                    => array(
						'day_of_the_week_options' => $backupplus->list_days_of_the_week(),
						'logic_options'           => array(
							array(
								'label' => __( 'on every backup', 'backupplus' ),
								'value' => '',
							),
							array(
								'label' => __( 'if any of the following conditions are matched:', 'backupplus' ),
								'value' => 'any',
							),
							array(
								'label' => __( 'if all of the following conditions are matched:', 'backupplus' ),
								'value' => 'all',
							),
						),
						'operand_options'         => array(
							array(
								'label' => __( 'Day of the week', 'backupplus' ),
								'value' => 'day_of_the_week',
							),
							array(
								'label' => __( 'Day of the month', 'backupplus' ),
								'value' => 'day_of_the_month',
							),
						),
						'operator_options'        => array(
							array(
								'label' => __( 'is', 'backupplus' ),
								'value' => 'is',
							),
							array(
								'label' => __( 'is not', 'backupplus' ),
								'value' => 'is_not',
							),
						),
					),
					'php_max_input_vars_detected_warning'  => __( 'The number of restore options that will be sent exceeds the configured maximum in your PHP configuration (max_input_vars).', 'backupplus' ) . ' ' . __( 'If you proceed with the restoration then some of the restore options will be lost and you may get unexpected results.', 'backupplus' ) . ' ' . __( 'See the browser console log for more information.', 'backupplus' ),
					'expired_tokens'                       => __( 'This is usually caused by your dashboard page having been open a long time, and the included security tokens having since expired.', 'backupplus' ),
					'reload_page'                          => __( 'Therefore, please reload the page.', 'backupplus' ),
					'save_changes'                         => __( 'Save Changes', 'backupplus' ),
					'close'                                => __( 'Close', 'backupplus' ),
				)
			)
		);
	}

	/**
	 * Run upon the WP admin_head action
	 */
	public function admin_head() {

		global $pagenow;

		if ( BackupPlus_Options::admin_page() != $pagenow || ! isset( $_REQUEST['page'] ) || 'backupplus' != $_REQUEST['page'] || ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		$chunk_size = min( wp_max_upload_size() - 1024, 1048576 * 2 );

		// The multiple_queues argument is ignored in plupload 2.x (WP3.9+) - http://make.wordpress.org/core/2014/04/11/plupload-2-x-in-wordpress-3-9/
		// max_file_size is also in filters as of plupload 2.x, but in its default position is still supported for backwards-compatibility. Likewise, our use of filters.extensions below is supported by a backwards-compatibility option (the current way is filters.mime-types.extensions

		$plupload_init = array(
			'runtimes'         => 'html5,flash,silverlight,html4',
			'browse_button'    => 'plupload-browse-button',
			'container'        => 'plupload-upload-ui',
			'drop_element'     => 'backupplus-navtab-backups-content',
			'file_data_name'   => 'async-upload',
			'multiple_queues'  => true,
			'max_file_size'    => '100Gb',
			'chunk_size'       => $chunk_size . 'b',
			'url'              => admin_url( 'admin-ajax.php', 'relative' ),
			'multipart'        => true,
			'multi_selection'  => true,
			'urlstream_upload' => true,
			// additional post data to send to our ajax hook
			'multipart_params' => array(
				'_ajax_nonce' => wp_create_nonce( 'backupplus-uploader' ),
				'action'      => 'plupload_action',
			),
		);

		// WP 3.9 updated to plupload 2.0 - https://core.trac.wordpress.org/ticket/25663
		if ( is_file( ABSPATH . WPINC . '/js/plupload/Moxie.swf' ) ) {
			$plupload_init['flash_swf_url'] = includes_url( 'js/plupload/Moxie.swf' );
		} else {
			$plupload_init['flash_swf_url'] = includes_url( 'js/plupload/plupload.flash.swf' );
		}

		if ( is_file( ABSPATH . WPINC . '/js/plupload/Moxie.xap' ) ) {
			$plupload_init['silverlight_xap_url'] = includes_url( 'js/plupload/Moxie.xap' );
		} else {
			$plupload_init['silverlight_xap_url'] = includes_url( 'js/plupload/plupload.silverlight.swf' );
		}

		?>
		<script type="text/javascript">
			var backupplus_credentialtest_nonce = '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>';
			var backupplus_settings_nonce = '<?php echo esc_js( wp_create_nonce( 'backupplus-settings-nonce' ) ); ?>';
			var backupplus_siteurl = '<?php echo esc_js( site_url( '', 'relative' ) ); ?>';
			var backupplus_plupload_config = <?php echo json_encode( $plupload_init ); ?>;
			var backupplus_download_nonce = '<?php echo esc_js( wp_create_nonce( 'backupplus_download' ) ); ?>';
			var backupplus_accept_archivename = <?php echo esc_js( apply_filters( 'backupplus_accept_archivename_js', '[]' ) ); ?>;
			<?php
				$overdue = $this->howmany_overdue_crons();
			if ( $overdue >= 4 ) {
				?>
					jQuery(function() {
						setTimeout(function(){ backupplus_check_overduecrons(); }, 11000);
					});
				<?php } ?>
		</script>
		<?php
	}

	/**
	 * Script to display active remote storage or notice if no remote storage is active.
	 *
	 * @param array   $method_objects     Array of all remote storages available.
	 * @param boolean $really_is_writable Is the BackupPlus directory writable.
	 * @param string  $backupplus_dir        BackupPlus directory.
	 * @param mixed   $active_service     Single or multiple Active remote storage location.
	 * @return void Display script for showing/hide active remote storages.
	 */
	public function get_settings_js( $method_objects, $really_is_writable, $backupplus_dir, $active_service ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use

		global $backupplus;
		?>
		jQuery(function() {
			<?php
			if ( ! $really_is_writable ) {
				echo "jQuery('.backupdirrow').show();\n";
			}
			if ( ! empty( $active_service ) ) {
				if ( is_array( $active_service ) ) {
					foreach ( $active_service as $serv ) {
						echo "jQuery('." . esc_js( $serv ) . "').show();\n";
					}
				} else {
					echo "jQuery('." . esc_js( $active_service ) . "').show();\n";
				}
			} else {
				echo "jQuery('.none').show();\n";
			}
			foreach ( $method_objects as $object ) {
				if ( is_callable( array( $object, 'config_print_javascript_onready' ) ) ) {
					$object->config_print_javascript_onready();
				}
			}
			?>
		});
		<?php
	}

	/**
	 * Enqueue JSTree JavaScript and CSS, taking into account whether it is already enqueued, and current debug settings
	 */
	public function enqueue_jstree() {
		global $backupplus;

		static $already_enqueued = false;
		if ( $already_enqueued ) {
			return;
		}

		$already_enqueued       = true;
		$jstree_enqueue_version = $backupplus->use_unminified_scripts() ? '3.3.12-rc.0' . '.' . time() : '3.3.12-rc.0';
		$min_or_not             = $backupplus->use_unminified_scripts() ? '' : '.min';

		wp_enqueue_script( 'jstree', BACKUPPLUS_URL . '/includes/jstree/jstree' . $min_or_not . '.js', array( 'jquery' ), $jstree_enqueue_version );
		wp_enqueue_style( 'jstree', BACKUPPLUS_URL . '/includes/jstree/themes/default/style' . $min_or_not . '.css', array(), $jstree_enqueue_version );
	}

	/**
	 * Print the unfinished restoration dialog scripts
	 */
	public function print_unfinished_restoration_dialog_scripts() {
		?>
	<script>
		jQuery(function($) {
			$('.show_admin_restore_in_progress_notice').on('click', 'button#backupplus_restore_abort', function(e) {
				e.preventDefault();
				jQuery('#backupplus_restore_continue_action').val('backupplus_restore_abort');
				jQuery(this).parent('form').trigger('submit');
			});
		});
	</script>
		<?php
	}

	/**
	 * Print CSS rules for the unfinished restoration dialog
	 */
	public function print_unfinished_restoration_dialog_styles() {
		?>
		<style>
			.show_admin_restore_in_progress_notice .backupplus_admin_restore_dialog .unfinished-restoration {
				font-size: 120% !important;
			}

			.show_admin_restore_in_progress_notice .backupplus_admin_restore_dialog {
				padding-top: 12px;
				padding-bottom: 12px;
			}

			.show_admin_restore_in_progress_notice .backupplus_admin_restore_dialog button {
				margin-right: 5px;
			}
		</style>
		<?php
	}
}
