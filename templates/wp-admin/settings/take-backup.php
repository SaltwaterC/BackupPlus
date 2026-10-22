<?php if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access.' );} ?>
<div class="backupplus_backup_content">
	<div id="backupplus-insert-admin-warning"></div>
	<noscript>
		<div>
			<?php echo esc_html__( 'JavaScript warning', 'backupplus' ) . ': '; ?><span style="color:red"><?php echo esc_html( __( 'This admin interface uses JavaScript heavily.', 'backupplus' ) . ' ' . __( 'You either need to activate it within your browser, or to use a JavaScript-capable browser.', 'backupplus' ) ); ?></span>
		</div>
	</noscript>
	
	<?php
	if ( $backup_disabled ) {
		$this->show_admin_warning(
			htmlspecialchars( __( "The 'Backup Now' button is disabled as your backup directory is not writable (go to the 'Settings' tab and find the relevant option).", 'backupplus' ) ),
			'error'
		);
	}
	?>
	
	<h3 class="backupplus_next_scheduled_backups_heading"><?php esc_html_e( 'Next scheduled backups', 'backupplus' ); ?>:</h3>
	<div class="backupplus_next_scheduled_backups_wrapper postbox">
		<div class="schedule">
			<div class="backupplus_next_scheduled_entity">
				<div class="backupplus_next_scheduled_heading">
					<strong><?php echo esc_html__( 'Files', 'backupplus' ) . ':'; ?></strong>
				</div>
				<div id="backupplus-next-files-backup-inner">
					<?php
					$backupplus_admin->next_scheduled_files_backups_output();
					?>
				</div>
			</div>
			<div class="backupplus_next_scheduled_entity">
				<div class="backupplus_next_scheduled_heading">
					<strong><?php echo esc_html__( 'Incremental backup', 'backupplus' ) . ':'; ?></strong>
				</div>
				<div id="backupplus-next-incremental-backup-inner">
					<?php
					$backupplus_admin->next_scheduled_incremental_backups_output();
					?>
				</div>
			</div>
			<div class="backupplus_next_scheduled_entity">
				<div class="backupplus_next_scheduled_heading">
					<strong><?php echo esc_html__( 'Database', 'backupplus' ) . ':'; ?></strong>
				</div>
				<div id="backupplus-next-database-backup-inner">
					<?php
						$backupplus_admin->next_scheduled_database_backups_output();
					?>
				</div>
			</div>
			<div class="backupplus_next_scheduled_entity">
				<div class="backupplus_next_scheduled_heading">
					<strong><?php echo esc_html__( 'Cleanup', 'backupplus' ) . ':'; ?></strong>
				</div>
				<div id="backupplus-next-cleanup-inner">
					<?php
						echo wp_kses( BackupPlus_Retention_Cleanup::instance()->summary_html(), BackupPlus_Backup_Schedule::allowed_html() );
					?>
				</div>
			</div>
			<div class="backupplus_time_now_wrapper">
				<span class="backupplus_time_now_label"><?php echo esc_html__( 'Time now', 'backupplus' ) . ': '; ?></span>
				<span class="backupplus_time_now"><?php echo esc_html( $current_time ); ?></span>
			</div>
		</div>
		<div class="backupplus_backup_btn_wrapper">
			<button id="backupplus-backupnow-button" type="button" <?php disabled( (bool) $backup_disabled ); ?> class="button button-primary button-large button-hero 
			<?php
			if ( $backup_disabled ) {
				echo 'title="' . esc_attr( __( 'This button is disabled because your backup directory is not writable (see the settings).', 'backupplus' ) ) . '" ';}
			?>
			" onclick="backupplus_backup_dialog_open(); return false;"><?php echo esc_html( str_ireplace( 'Back Up', 'Backup', __( 'Backup Now', 'backupplus' ) ) ); ?></button>
			<?php
			if ( ! $backup_disabled ) {
				$link = '<button id="backupplus_incremental_backup_link" type="button" data-incremental="1" class="button button-large button-hero" onclick="backupplus_backup_dialog_open(\'incremental\'); return false;">' . esc_html__( 'Incremental Backup', 'backupplus' ) . '</button>';
				echo wp_kses( apply_filters( 'backupplus_incremental_backup_link', $link ), $backupplus_admin->kses_allow_tags() );
			}
			?>
		</div>
		<div id="backupplus_activejobs_table">
			<div id="backupplus_activejobsrow">
				<?php
					echo $active_jobs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- it's ignored because the value of the variable contains HTML elements
				?>
			</div>
		</div>
	</div>

	
	<div id="backupplus_lastlogmessagerow">
		<h3><?php esc_html_e( 'Last log message', 'backupplus' ); ?>:</h3>
		<?php $this->most_recently_modified_log_link(); ?>
		<div class="postbox">
			<span id="backupplus_lastlogcontainer"><?php echo wp_kses_post( BackupPlus_Options::get_backupplus_lastmessage() ); ?></span>
		</div>
	</div>
	
	<div id="backupplus-iframe-modal">
		<div id="backupplus-iframe-modal-innards">
		</div>
	</div>
	
	<div id="backupplus-authenticate-modal" style="display:none;" title="<?php esc_attr_e( 'Remote storage authentication', 'backupplus' ); ?>">
		<p><?php esc_html_e( 'You have selected a remote storage option which has an authorization step to complete:', 'backupplus' ); ?></p>
		<div id="backupplus-authenticate-modal-innards">
		</div>
	</div>
	
	<div id="backupplus-backupnow-modal" title="BackupPlus - <?php esc_html_e( 'Perform a backup', 'backupplus' ); ?>">
		<?php
			echo $backupplus_admin->backupnow_modal_contents(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- it's ignored because the call to the method returns a value that contains HTML elements
		?>
	</div>
	

</div>
