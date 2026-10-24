<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

	global $backupplus;
	$pitr_config = BackupPlus_PITR::config();

	$free_ret = '<em>' . __( 'All WordPress tables will be backed up.', 'backupplus' ) . '</em>' . "\n";
?>

<p>
	<h3 class="new-backups-only"><?php esc_html_e( 'Take a new backup', 'backupplus' ); ?></h3>
	<h3 class="incremental-backups-only"><?php esc_html_e( 'Take an incremental backup', 'backupplus' ); ?></h3>
</p>

<p class="incremental-backups-only"><?php esc_html_e( 'Back up changes since the previous backup. A full backup is created automatically when a new baseline is needed.', 'backupplus' ); ?></p>

<p id="backupnow_database_container" data-pitr-enabled="<?php echo empty( $pitr_config['enabled'] ) ? '0' : '1'; ?>">

	<input type="checkbox" id="backupnow_includedb" checked="checked">
	<label id="backupnow_includedb_label" for="backupnow_includedb"><?php esc_html_e( 'Include your database in the backup', 'backupplus' ); ?></label>

	<span class="new-backups-only">(<a href="#" id="backupnow_database_showmoreoptions">...</a>)</span><br>
	<span class="incremental-backups-only"><?php esc_html_e( 'Captures the complete database using your saved point-in-time recovery encryption and storage settings.', 'backupplus' ); ?></span>

	<div id="backupnow_database_moreoptions" class="backupplus-hidden" style="display:none;">

		<?php
		BackupPlus_Sources_Admin::database_settings( true );// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- needs to be presented in html
		?>

	</div>

</p>
<p id="backupnow_pitr_disabled" class="incremental-backups-only"><?php esc_html_e( 'Enable Database point-in-time recovery in Settings to include database changes.', 'backupplus' ); ?></p>
	
<p>
	<input type="checkbox" id="backupnow_includefiles" checked="checked">
	<label id="backupnow_includefiles_label" for="backupnow_includefiles"><?php esc_html_e( 'Include your files in the backup', 'backupplus' ); ?></label>
	
	(<a href="<?php echo esc_url( $backupplus->get_current_clean_url() ); ?>" id="backupnow_includefiles_showmoreoptions">...</a>)<br>

	<div id="backupnow_includefiles_moreoptions" class="backupplus-hidden" style="display:none;">
		<em><?php esc_html_e( 'Your saved settings also affect what is backed up - e.g. files excluded.', 'backupplus' ); ?></em><br>
		
		<?php $backupplus_admin->files_selector_widgetry( 'backupnow_files_', false, 'sometimes', true ); ?>
	</div>
	
</p>

<div class="backupnow_modal_afterfileoptions">
	<?php
	echo apply_filters( 'backupplus_backupnow_modal_afterfileoptions', '', '' );// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- needs to be presented in html
	?>
</div>

<span id="backupnow_remote_container">
	<?php
	echo $this->backup_now_remote_message();// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- needs to be presented in html
	?>
</span>

<p id="backupnow_always_keep_container" class="new-backups-only">
	<input type="checkbox" id="always_keep">
	<label for="always_keep"><?php esc_html_e( 'Only allow this backup to be deleted manually (i.e. keep it even if retention limits are hit).', 'backupplus' ); ?></label>
</p>

<div class="backupnow_modal_afteroptions">
	<?php
	echo apply_filters( 'backupplus_backupnow_modal_afteroptions', '', '' );// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- needs to be presented in html
	?>
</div>
