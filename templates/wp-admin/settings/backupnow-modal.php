<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

	global $backupplus;

	$free_ret = '<em>' . __( 'All WordPress tables will be backed up.', 'backupplus' ) . '</em>' . "\n";
?>

<p>
	<h3 class="new-backups-only"><?php esc_html_e( 'Take a new backup', 'backupplus' ); ?></h3>
	<h3 class="incremental-backups-only"><?php esc_html_e( 'Take an incremental backup', 'backupplus' ); ?></h3>
</p>

<p class="incremental-backups-only"><?php esc_html_e( 'Back up only file changes since the previous backup. A full backup is taken automatically when no usable base exists or the base is seven days old.', 'backupplus' ); ?></p>

<p id="backupnow_database_container" class="new-backups-only">

	<input type="checkbox" id="backupnow_includedb" checked="checked">
	<label for="backupnow_includedb"><?php esc_html_e( 'Include your database in the backup', 'backupplus' ); ?></label>

	(<a href="#" id="backupnow_database_showmoreoptions">...</a>)<br>

	<div id="backupnow_database_moreoptions" class="backupplus-hidden" style="display:none;">

		<?php
		echo apply_filters( 'backupplus_backupnow_database_showmoreoptions', $free_ret, '' );// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- needs to be presented in html
		?>

	</div>

</p>
	
<p>
	<input type="checkbox" class="new-backups-only" id="backupnow_includefiles" checked="checked">
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
