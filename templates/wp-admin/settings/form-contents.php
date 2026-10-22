<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

$backupplus_dir     = $backupplus->backups_dir_location();
$really_is_writable = BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir );

// $options is passed through
$default_options = array(
	'include_database_decrypter' => true,
	'include_save_button'        => true,
);

foreach ( $default_options as $k => $v ) {
	if ( ! isset( $options[ $k ] ) ) {
		$options[ $k ] = $v;
	}
}
?>
<table class="form-table backup-schedule">
	<tr>
		<th scope="row"><label for="backupplus_interval"><?php esc_html_e( 'Full file backups', 'backupplus' ); ?></label></th>
		<td class="js-file-backup-schedule">
			<div class="backupplus-schedule-controls">
				<select id="backupplus_interval" title="<?php esc_attr_e( 'Files backup interval', 'backupplus' ); ?>" class="backupplus_interval" name="backupplus_interval">
				<?php
				$intervals         = $backupplus_admin->get_intervals( 'files' );
				$selected_interval = BackupPlus_Options::get_backupplus_option( 'backupplus_interval', 'manual' );
				foreach ( $intervals as $cronsched => $descrip ) {
					echo '<option value="' . esc_attr( $cronsched ) . '" ' . selected( $selected_interval, $cronsched, false ) . '>' . esc_html( $descrip ) . '</option>';
				}
				$backupplus_retain = max( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ), 1 );
				?>
				</select>
				<label class="backupplus-schedule-retention" for="backupplus_retain">
					<span class="backupplus-field-prefix"><?php esc_html_e( 'Keep', 'backupplus' ); ?></span>
					<input id="backupplus_retain" type="number" min="1" step="1" title="<?php esc_attr_e( 'Retain this many scheduled file backups', 'backupplus' ); ?>" name="backupplus_retain" value="<?php echo esc_attr( $backupplus_retain ); ?>" class="retain-files">
					<span><?php esc_html_e( 'backup chains', 'backupplus' ); ?></span>
				</label>
			</div>
			<span class="backupplus_files_timings">
				<?php echo wp_kses( apply_filters( 'backupplus_schedule_showfileopts', BackupPlus_Backup_Schedule::instance()->timing_controls( 'files' ), $selected_interval ), BackupPlus_Backup_Schedule::allowed_html() ); ?>
			</span>
			<?php
				do_action( 'backupplus_incremental_cell', $selected_interval );
				do_action( 'backupplus_after_filesconfig' );
			?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="backupplus_interval_increments"><?php esc_html_e( 'Incremental file backups', 'backupplus' ); ?></label></th>
		<td>
			<div class="backupplus-schedule-controls">
				<select id="backupplus_interval_increments" name="backupplus_interval_increments" aria-describedby="backupplus-incremental-description">
					<option value="none"><?php esc_html_e( 'Disabled', 'backupplus' ); ?></option>
					<?php
					$increment_interval = BackupPlus_Backup_Schedule::increments_interval( BackupPlus_Options::get_backupplus_option( 'backupplus_interval_increments', 'none' ) );
					// The plugin's own list, as for full backups: WordPress's "Once Hourly" would duplicate "Every hour".
					foreach ( array_intersect_key( backupplus_list_cron_schedules(), wp_get_schedules() ) as $schedule => $details ) {
						if ( $details['interval'] >= HOUR_IN_SECONDS && $details['interval'] <= DAY_IN_SECONDS ) {
							echo '<option value="' . esc_attr( $schedule ) . '" ' . selected( $increment_interval, $schedule, false ) . '>' . esc_html( $details['display'] ) . '</option>';
						}
					}
					?>
				</select>
			</div>
			<span class="backupplus_increments_timings">
				<?php echo wp_kses( BackupPlus_Backup_Schedule::instance()->timing_controls( 'increments' ), BackupPlus_Backup_Schedule::allowed_html() ); ?>
			</span>
			<p id="backupplus-incremental-description" class="description"><?php esc_html_e( 'Back up changed files between full backups. A new full backup is taken at least every seven days. Increments are kept with their full backup.', 'backupplus' ); ?></p>
		</td>
	</tr>


	<?php apply_filters( 'backupplus_after_file_intervals', false, $selected_interval ); ?>
	<tr>
		<th scope="row"><label for="backupplus_interval_database"><?php esc_html_e( 'Database backups', 'backupplus' ); ?></label></th>
		<td class="js-database-backup-schedule">
			<div class="backupplus-schedule-controls">
				<select id="backupplus_interval_database" class="backupplus_interval_database" title="<?php esc_attr_e( 'Database backup interval', 'backupplus' ); ?>" name="backupplus_interval_database">
				<?php
				$intervals            = $backupplus_admin->get_intervals( 'db' );
				$selected_interval_db = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database', BackupPlus_Options::get_backupplus_option( 'backupplus_interval' ) );
				foreach ( $intervals as $cronsched => $descrip ) {
					echo '<option value="' . esc_attr( $cronsched ) . '" ' . selected( $selected_interval_db, $cronsched, false ) . '>' . esc_html( $descrip ) . '</option>';
				}
				$backupplus_retain_db = max( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', $backupplus_retain ), 1 );
				?>
				</select>
				<label class="backupplus-schedule-retention" for="backupplus_retain_db">
					<span class="backupplus-field-prefix"><?php esc_html_e( 'Keep', 'backupplus' ); ?></span>
					<input id="backupplus_retain_db" type="number" min="1" step="1" title="<?php esc_attr_e( 'Retain this many scheduled database backups', 'backupplus' ); ?>" name="backupplus_retain_db" value="<?php echo esc_attr( $backupplus_retain_db ); ?>" class="retain-files">
					<span><?php esc_html_e( 'backups', 'backupplus' ); ?></span>
				</label>
			</div>
			<span class="backupplus_same_schedules_message"><?php echo esc_html( apply_filters( 'backupplus_schedule_sametimemsg', '' ) ); ?></span>
			<span class="backupplus_db_timings">
				<?php echo wp_kses( apply_filters( 'backupplus_schedule_showdbopts', BackupPlus_Backup_Schedule::instance()->timing_controls( 'db' ), $selected_interval_db ), BackupPlus_Backup_Schedule::allowed_html() ); ?>
			</span>
			<?php do_action( 'backupplus_after_dbconfig' ); ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="backupplus_retention_cleanup_mode"><?php esc_html_e( 'Delete old backups', 'backupplus' ); ?></label></th>
		<td class="js-retention-cleanup-schedule">
			<?php
			$backupplus_cleanup_mode = BackupPlus_Backup_Schedule::sanitize_cleanup_mode( BackupPlus_Options::get_backupplus_option( 'backupplus_retention_cleanup_mode', 'after_backup' ) );
			$backupplus_cleanup_time = BackupPlus_Backup_Schedule::sanitize_time( BackupPlus_Options::get_backupplus_option( 'backupplus_retention_cleanup_time', '' ) );
			?>
			<div class="backupplus-schedule-controls">
				<select id="backupplus_retention_cleanup_mode" name="backupplus_retention_cleanup_mode" aria-describedby="backupplus-retention-cleanup-description">
					<option value="after_backup" <?php selected( $backupplus_cleanup_mode, 'after_backup' ); ?>><?php esc_html_e( 'After each backup', 'backupplus' ); ?></option>
					<option value="daily" <?php selected( $backupplus_cleanup_mode, 'daily' ); ?>><?php esc_html_e( 'Daily at a chosen time', 'backupplus' ); ?></option>
				</select>
				<label class="backupplus-retention-cleanup-time"><span class="backupplus-field-prefix"><?php esc_html_e( 'at', 'backupplus' ); ?></span> <input type="time" step="60" id="backupplus_retention_cleanup_time" name="backupplus_retention_cleanup_time" value="<?php echo esc_attr( $backupplus_cleanup_time ); ?>"></label>
			</div>
			<p id="backupplus-retention-cleanup-description" class="description"><?php esc_html_e( 'Backups beyond the numbers kept above are deleted, except backups marked "Always Keep" and imported backups. In daily mode, backups no longer delete old backups when they finish; a separate cleanup runs once a day without creating a backup.', 'backupplus' ); ?></p>
			<?php echo wp_kses( BackupPlus_Backup_Schedule::timing_help(), BackupPlus_Backup_Schedule::allowed_html() ); ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="backupplus_autobackup_default"><?php esc_html_e( 'Pre-update backups', 'backupplus' ); ?></label></th>
		<td>
			<input type="hidden" name="backupplus_autobackup_default" value="0">
			<input type="checkbox" id="backupplus_autobackup_default" name="backupplus_autobackup_default" value="1" <?php checked( BackupPlus_Options::get_backupplus_option( 'backupplus_autobackup_default', 1 ) ); ?> aria-describedby="backupplus-pre-update-description">
			<p id="backupplus-pre-update-description" class="description"><?php esc_html_e( 'Automatically back up the database and site files before plugin, theme and WordPress core updates. Updates continue only after the backup and all configured remote uploads finish successfully.', 'backupplus' ); ?></p>
		</td>
	</tr>
</table>

<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Sending Your Backup To Remote Storage', 'backupplus' ); ?></h2>

<?php
$debug_mode     = BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ? 'checked="checked"' : '';
$active_service = BackupPlus_Options::get_backupplus_option( 'backupplus_service' );
if ( is_array( $active_service ) ) {
	$active_service = $backupplus->just_one( $active_service );
}

// Change this to give a class that we can exclude
$multi = apply_filters( 'backupplus_storage_printoptions_multi', '' );
?>

<table id="remote-storage-holder" class="form-table width-900">
	<tr class="backupplus-background-white">
		<th>
		<?php
			echo esc_html__( 'Select your preferred remote storage location', 'backupplus' ) . '<br>' . wp_kses_post( apply_filters( 'backupplus_after_remote_storage_heading_message', '<em>' . __( '(tap on an icon to select or unselect)', 'backupplus' ) . '</em>' ) );
		?>
		<td>
		<div id="remote-storage-container" class="available-remote-storages">
			<?php
			foreach ( $backupplus->backup_methods as $method => $description ) {
				$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $method );
				if ( is_wp_error( $provider ) || is_a( $provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) {
					continue;
				}
				/* translators: 1: String 'BackupPlus', 2: String 'AWS S3' */
				$backupplus->show_remote_storage( $description, $method, $multi, $active_service, $description );
			}
			?>
		</div>
		</td>
	</tr>



	<?php do_action( 'backupplus_after_remote_storage' ); ?>

	<tr class="backupplusmethod none ud_nostorage">
		<td></td>
		<td><em><?php echo esc_html( __( 'If you choose no remote storage, then the backups remain on the web-server.', 'backupplus' ) . ' ' . __( 'This is not recommended (unless you plan to manually copy them to your computer), as losing the web-server would mean losing both your website and the backups in one event.', 'backupplus' ) ); ?></em></td>
	</tr>
</table>

<hr class="backupplus_separator">

<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'File Options', 'backupplus' ); ?></h2>

<table class="form-table js-tour-settings-more width-900 ">
	<tr>
		<th><?php esc_html_e( 'Include in files backup', 'backupplus' ); ?>:</th>
		<td>
			<?php $backupplus_admin->files_selector_widgetry( '', true, true, true ); ?>
			<p><?php esc_html_e( 'This includes all WordPress file directories. WordPress core files can be downloaded again from WordPress.org.', 'backupplus' ); ?></p>
		</td>
	</tr>
	<tr id="backupplus-database-settings">
		<th scope="row"><?php esc_html_e( 'Database connections and tables', 'backupplus' ); ?></th>
		<td><?php BackupPlus_Sources_Admin::database_settings(); ?></td>
	</tr>
</table>

<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Reporting', 'backupplus' ); ?></h2>

<table class="form-table width-900">

<?php
	$report_rows = apply_filters( 'backupplus_report_form', false );
if ( is_string( $report_rows ) ) {
	echo $report_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped HTML.
} else {
	?>

	<tr id="backupplus_report_row_no_addon">
		<th><?php esc_html_e( 'Email', 'backupplus' ); ?>:</th>
		<td>
		<?php
			$backupplus_email = BackupPlus_Options::get_backupplus_option( 'backupplus_email' );
			// Use email storage for reports when a separate reporting module is not installed.
			// The selected service may be one destination or a list, so normalize it before checking for email.
			$temp_services = $active_service;
		if ( is_string( $temp_services ) ) {
			$temp_services = (array) $temp_services;
		}
			$is_email_storage = ! empty( $temp_services ) && in_array( 'email', $temp_services );
		?>
			<label for="backupplus_email" class="backupplus_checkbox email_report">
				<input type="checkbox" id="backupplus_email" name="backupplus_email" value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>"
				<?php
				if ( $is_email_storage || ! empty( $backupplus_email ) ) {
					echo ' checked="checked"';}
				?>
	<?php
	if ( $is_email_storage ) {
					echo 'disabled onclick="return false"';}
	?>
>
			<?php
				// have to add this hidden input so that when the form is submitted and if the backupplus_email checkbox is disabled, this hidden input will be passed to the server along with other active elements
			if ( $is_email_storage ) {
				echo '<input type="hidden" name="backupplus_email" value="' . esc_attr( get_bloginfo( 'admin_email' ) ) . '">';
			}
			?>
				<div id="cb_not_email_storage_label "<?php echo ( $is_email_storage ) ? 'style="display: none"' : 'style="display: inline"'; ?>>
				<?php echo esc_html__( 'Check this box to have a basic report sent to', 'backupplus' ) . ' <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( "your site's admin address", 'backupplus' ) . '</a> (' . esc_html( get_bloginfo( 'admin_email' ) ) . ').'; ?>
				</div>
				<div id="cb_email_storage_label "<?php echo ( ! $is_email_storage ) ? 'style="display: none"' : 'style="display: inline"'; ?>>
				<?php echo esc_html__( 'Your email backup and a report will be sent to', 'backupplus' ) . ' <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( "your site's admin address", 'backupplus' ) . '</a> (' . esc_html( get_bloginfo( 'admin_email' ) ) . ').'; ?>
				</div>
			</label>

		</td>
	</tr>

	<?php
}
?>
</table>

<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Backup encryption', 'backupplus' ); ?></h2>

<table class="form-table width-900 backupplus-encryption-settings">
	<?php BackupPlus_Database_Encryption::render(); ?>

</table>

<h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Database Options', 'backupplus' ); ?></h2>

<table class="form-table width-900">

	<?php
		$database_encryption_config = apply_filters( 'backupplus_database_encryption_config', '' );
	if ( ! empty( $database_encryption_config ) ) {
		?>
	<tr>
		<th><?php esc_html_e( 'Database encryption phrase', 'backupplus' ); ?>:</th>
		<td><?php echo wp_kses( $database_encryption_config, $backupplus_admin->kses_allow_tags() ); ?></td>
	</tr>
	<?php } ?>

	<?php
		$moredbs_config = apply_filters( 'backupplus_database_moredbs_config', '' );
	if ( ! empty( $moredbs_config ) ) {
		?>
			<tr>
				<th><?php esc_html_e( 'Backup more databases', 'backupplus' ); ?>:</th>
				<td><?php echo wp_kses( $moredbs_config, $backupplus_admin->kses_allow_tags() ); ?>
				</td>
			</tr>
		<?php
	}
	?>

</table>


<script>
<?php
	$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array_keys( $backupplus->backup_methods ) );
	// In PHP 5.5+, there's array_column() for this
	$method_objects = array();
foreach ( $storage_objects_and_ids as $method => $method_information ) {
	$method_objects[ $method ] = $method_information['object'];
}

	$backupplus_admin->get_settings_js( $method_objects, $really_is_writable, $backupplus_dir, $active_service );
?>
</script>
<table class="form-table width-900">
	<tr>
		<td colspan="2"><h2 class="backupplus_settings_sectionheading"><?php esc_html_e( 'Advanced / Debugging Settings', 'backupplus' ); ?></h2></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Expert settings', 'backupplus' ); ?>:</th>
		<td><a class="enableexpertmode" href="<?php echo esc_url( BackupPlus::get_current_clean_url() ); ?>#enableexpertmode"><?php esc_html_e( 'Show expert settings', 'backupplus' ); ?></a> - <?php esc_html_e( "open this to show some further options; don't bother with this unless you have a problem or are curious.", 'backupplus' ); ?> <?php do_action( 'backupplus_expertsettingsdescription' ); ?></td>
	</tr>
	<?php BackupPlus_Credential_Key_Admin::render(); ?>

	<?php
	$delete_local   = BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 1 );
	$split_every_mb = BackupPlus_Options::get_backupplus_option( 'backupplus_split_every', 400 );
	if ( ! is_numeric( $split_every_mb ) ) {
		$split_every_mb = 400;
	}
	if ( $split_every_mb < BACKUPPLUS_SPLIT_MIN ) {
		$split_every_mb = BACKUPPLUS_SPLIT_MIN;
	}
	?>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Debug mode', 'backupplus' ); ?>:</th>
		<td><input type="checkbox" id="backupplus_debug_mode" data-backupplus_settings_test="debug_mode" name="backupplus_debug_mode" value="1" <?php echo wp_kses( $debug_mode, array() ); ?> /> <br><label for="backupplus_debug_mode"><?php esc_html_e( 'Check this to receive more information and emails on the backup process - useful if something is going wrong.', 'backupplus' ); ?> <?php esc_html_e( 'This will also cause debugging output from all plugins to be shown upon this screen - please do not be surprised to see these.', 'backupplus' ); ?></label></td>
	</tr>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Split archives every:', 'backupplus' ); ?></th>
		<td>
			<input type="text" name="backupplus_split_every" class="backupplus_split_every" value="<?php echo esc_attr( $split_every_mb ); ?>" size="5" /> MB<br>
			<?php
				echo esc_html(
					__( 'BackupPlus will split up backup archives when they exceed this file size.', 'backupplus' ) . ' ' .
					/* translators: %s: The default value of the backup split size */
					sprintf( __( 'The default value is %s megabytes.', 'backupplus' ), 400 ) . ' ' .
					__( 'Be careful to leave some margin if your web-server has a hard size limit (e.g. the 2 GB / 2048 MB limit on some 32-bit servers/file systems).', 'backupplus' ) . ' ' .
					__( 'The higher the value, the more server resources are required to create the archive.', 'backupplus' )
				);
				?>
		</td>
	</tr>

	<tr class="deletelocal expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Delete local backup', 'backupplus' ); ?>:</th>
		<td><input type="checkbox" id="backupplus_delete_local" name="backupplus_delete_local" value="1" 
		<?php
		if ( $delete_local ) {
			echo 'checked="checked"';}
		?>
		> <br><label for="backupplus_delete_local"><?php esc_html_e( 'Check this to delete any superfluous backup files from your server after the backup run finishes (i.e. Whilst this option is unchecked, files sent to remote storage will also remain stored locally, and no locally stored files will be deleted in response to any retention rules).', 'backupplus' ); ?></label></td>
	</tr>

	<tr class="expertmode backupdirrow backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Backup directory', 'backupplus' ); ?>:</th>
		<td><input type="text" name="backupplus_dir" id="backupplus_dir" style="width:525px" value="<?php echo esc_attr( BackupPlus_Manipulation_Functions::prune_backupplus_dir_prefix( $backupplus_dir ) ); ?>" /></td>
	</tr>
	<tr class="expertmode backupdirrow backupplus-hidden" style="display:none;">
		<td></td>
		<td>
			<span id="backupplus_writable_mess">
				<?php echo wp_kses_post( $backupplus_admin->really_writable_message( $really_is_writable, $backupplus_dir ) ); ?>
			</span>
			<?php
				echo esc_html__( 'This is where BackupPlus will write the zip files it creates initially.', 'backupplus' ) . ' ' . esc_html__( 'This directory must be writable by your web server.', 'backupplus' ) . ' ' . esc_html__( 'It is relative to your content directory (which by default is called wp-content).', 'backupplus' ) . ' ' . esc_html__( 'Do not place it inside your uploads or plugins directory, as that will cause recursion (backups of backups of backups of...).', 'backupplus' );
			?>
		</td>
	</tr>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( "Use the server's SSL certificates", 'backupplus' ); ?>:</th>
		<td><input data-backupplus_settings_test="useservercerts" type="checkbox" id="backupplus_ssl_useservercerts" name="backupplus_ssl_useservercerts" value="1" 
		<?php
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_useservercerts' ) ) {
			echo 'checked="checked"';}
		?>
		> <br><label for="backupplus_ssl_useservercerts"><?php echo esc_html__( 'By default BackupPlus uses its own store of SSL certificates to verify the identity of remote sites (i.e. to make sure it is talking to the real Dropbox, Amazon S3, etc., and not an attacker).', 'backupplus' ) . ' ' . esc_html__( 'We keep these up to date.', 'backupplus' ) . ' ' . esc_html__( 'However, if you get an SSL error, then choosing this option (which causes BackupPlus to use your web server\'s collection instead) may help.', 'backupplus' ); ?></label></td>
	</tr>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Do not verify SSL certificates', 'backupplus' ); ?>:</th>
		<td><input data-backupplus_settings_test="disableverify" type="checkbox" id="backupplus_ssl_disableverify" name="backupplus_ssl_disableverify" value="1" 
		<?php
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' ) ) {
			echo 'checked="checked"';}
		?>
		> <br><label for="backupplus_ssl_disableverify"><?php echo esc_html( __( 'Choosing this option lowers your security by stopping BackupPlus from verifying the identity of encrypted sites that it connects to (e.g. Dropbox, Google Drive).', 'backupplus' ) . ' ' . __( 'It means that BackupPlus will be using SSL only for encryption of traffic, and not for authentication.', 'backupplus' ) . ' ' . __( 'Note that not all cloud backup methods are necessarily using SSL authentication.', 'backupplus' ) ); ?></label></td>
	</tr>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Disable SSL entirely where possible', 'backupplus' ); ?>:</th>
		<td><input data-backupplus_settings_test="nossl" type="checkbox" id="backupplus_ssl_nossl" name="backupplus_ssl_nossl" value="1" 
		<?php
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_nossl' ) ) {
			echo 'checked="checked"';}
		?>
		> <br><label for="backupplus_ssl_nossl"><?php echo esc_html( __( 'Choosing this option lowers your security by stopping BackupPlus from using SSL for authentication and encrypted transport at all, where possible.', 'backupplus' ) . ' ' . __( 'Note that some cloud storage providers do not allow this (e.g. Dropbox), so with those providers this setting will have no effect.', 'backupplus' ) . ' ' . __( 'For certificate errors, check that your server has an up-to-date CA certificate bundle.', 'backupplus' ) ); ?></label></td>
	</tr>

	<tr class="expertmode backupplus-hidden" style="display:none;">
		<th><?php esc_html_e( 'Automatic updates', 'backupplus' ); ?>:</th>
		<td><label><input type="checkbox" id="backupplus_auto_updates" data-backupplus_settings_test="backupplus_auto_updates" name="backupplus_auto_updates" value="1" 
		<?php
		if ( $backupplus->is_automatic_updating_enabled() ) {
			echo 'checked="checked"';}
		?>
		><br /><?php esc_html_e( 'Ask WordPress to automatically update BackupPlus when it finds an available update.', 'backupplus' ); ?></label><p><a href="https://wordpress.org/plugins/stops-core-theme-and-plugin-updates/" target="_blank"><?php esc_html_e( 'Read more about Easy Updates Manager', 'backupplus' ); ?></a></p></td>
	</tr>

	<?php do_action( 'backupplus_configprint_expertoptions' ); ?>
	<?php if ( ! empty( $options['include_save_button'] ) ) { ?>
	<tr>
		<td></td>
		<td>
			<input type="hidden" name="action" value="update" />
			<input type="submit" class="button-primary" id="backupplus-settings-save" value="<?php esc_html_e( 'Save Changes', 'backupplus' ); ?>" />
		</td>
	</tr>
	<?php } ?>
</table>
