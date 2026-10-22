<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div id="backupplus-message-modal" title="BackupPlus">
	<div id="backupplus-message-modal-innards">
	</div>
</div>

<div id="backupplus-delete-modal" title="<?php esc_html_e( 'Delete backup set', 'backupplus' ); ?>">
	<form id="backupplus_delete_form" method="post">
		<p id="backupplus_delete_question_singular">
			<?php
			/* translators: %s: Item to be removed */
			printf( esc_html__( 'Are you sure that you wish to remove %s from BackupPlus?', 'backupplus' ), esc_html__( 'this backup set', 'backupplus' ) );
			?>
		</p>
		<p id="backupplus_delete_question_plural" class="backupplus-hidden" style="display:none;">
			<?php
			/* translators: %s: Item to be removed */
			printf( esc_html__( 'Are you sure that you wish to remove %s from BackupPlus?', 'backupplus' ), esc_html__( 'these backup sets', 'backupplus' ) );
			?>
		</p>
		<div id="backupplus_delete_chain_warning" class="backupplus-delete-chain-warning" style="display:none;">
			<p><strong><?php esc_html_e( 'Warning: deleting a full backup also deletes every incremental backup chained to it.', 'backupplus' ); ?></strong></p>
			<p><?php esc_html_e( 'All restore points in the following sets will be permanently removed, including from remote storage if that option is ticked. Incremental backups cannot be kept without their full backup.', 'backupplus' ); ?></p>
			<ul></ul>
		</div>
		<fieldset>
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>">
			<input type="hidden" name="action" value="backupplus_ajax">
			<input type="hidden" name="subaction" value="deleteset">
			<input type="hidden" name="backup_timestamp" value="0" id="backupplus_delete_timestamp">
			<input type="hidden" name="backup_nonce" value="0" id="backupplus_delete_nonce">
			<div id="backupplus-delete-remote-section">
				<input checked="checked" type="checkbox" name="delete_remote" id="backupplus_delete_remote" value="1"> <label for="backupplus_delete_remote"><?php esc_html_e( 'Also delete from remote storage', 'backupplus' ); ?></label><br>
			</div>
		</fieldset>
	</form>
</div>

<div class="backupplus_restore_container" style="display: none;">
	<div class="backupplus_restore_main--header"><?php esc_html_e( 'BackupPlus Restoration', 'backupplus' ); ?> — <strong><?php esc_html_e( 'Restore files from', 'backupplus' ); ?>:</strong> <span class="backupplus_restore_date"></span></div>
	<div class="backupplus_restore_main">
		<div id="backupplus-restore-modal" title="BackupPlus - <?php esc_html_e( 'Restore backup', 'backupplus' ); ?>">

			<div class="backupplus-restore-modal--stage backupplus--flex" id="backupplus-restore-modal-stage2">
				<div class="backupplus--two-halves">
					<p><strong><?php esc_html_e( 'Retrieving (if necessary) and preparing backup files...', 'backupplus' ); ?></strong></p>
					<div id="backupplus-restore-modal-stage2a"></div>
					<div id="ud_downloadstatus2"></div>
				</div>
			</div>

			<div class="backupplus-restore-modal--stage backupplus--flex" id="backupplus-restore-modal-stage1">
				<div class="backupplus--one-half backupplus-color--very-light-grey">
					<p><?php echo esc_html( __( "Restoring will replace this site's themes, plugins, uploads, database and/or other content directories (according to what is contained in the backup set, and your selection).", 'backupplus' ) . ' ' . __( 'Choose the components to restore', 'backupplus' ) ); ?>:</p>
					<p><em><?php esc_html_e( 'Before restoring, confirm that you have a current backup and enough disk space for the restored files.', 'backupplus' ); ?></em></p>
				</div>
				<div class="backupplus--one-half">
					<form id="backupplus_restore_form" method="post">
						<fieldset>
							<input type="hidden" name="action" value="backupplus_restore">
							<input type="hidden" name="backupplus_ajax_restore" value="start_ajax_restore">
							<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>">
							<input type="hidden" name="backup_timestamp" value="0" id="backupplus_restore_timestamp">
							<input type="hidden" name="meta_foreign" value="0" id="backupplus_restore_meta_foreign">
							<input type="hidden" name="backupplus_restorer_backup_info" value="" id="backupplus_restorer_backup_info">
							<input type="hidden" name="backupplus_restorer_restore_options" value="" id="backupplus_restorer_restore_options">
							<?php

								// The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
							if ( $backupplus->detect_safe_mode() ) {
								echo wp_kses_post( '<p><em>' . __( "Your web server has PHP's so-called safe_mode active.", 'backupplus' ) . ' ' . __( 'This makes time-outs much more likely. Turn it off if possible, or restore only one entity at a time.', 'backupplus' ) . '</em></p>' );
							}
							?>
							<div id="backupplus_restore_point_row" class="udp-notice backupplus-restore-option" style="display:none;">
								<p><strong><label for="backupplus_incremental_restore_point"><?php esc_html_e( 'Restore files to this point in the backup chain:', 'backupplus' ); ?></label></strong></p>
								<select name="backupplus_incremental_restore_point" id="backupplus_incremental_restore_point" disabled="disabled"></select>
								<p><em><?php esc_html_e( 'Restoring applies the full backup and then every incremental backup up to the chosen point. The database is not part of the chain.', 'backupplus' ); ?></em></p>
							</div>
							<p><strong><?php esc_html_e( 'Choose the components to restore:', 'backupplus' ); ?></strong></p>
							<?php
								$backupable_entities = $backupplus->get_backupable_file_entities( true, true );
							foreach ( BackupPlus_Backup_History::get_history() as $source_backup ) { $backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupable_entities, $source_backup ); }

							foreach ( $backupable_entities as $type => $info ) {
								if ( ! isset( $info['restorable'] ) || true == $info['restorable'] ) {
									$sdescrip = isset( $info['shortdescription'] ) ? $info['shortdescription'] : $info['description'];
									echo '<div class="backupplus-restore-item"><input id="backupplus_restore_' . esc_attr( $type ) . '" type="checkbox" name="backupplus_restore[]" value="' . esc_attr( $type ) . '"> <label id="backupplus_restore_label_' . esc_attr( $type ) . '" for="backupplus_restore_' . esc_attr( $type ) . '">' . esc_html( $sdescrip ) . '</label><br>';
									BackupPlus_Sources_Admin::restore_fields( $type, $info );
									do_action( "backupplus_restore_form_$type" );
									echo '</div>';
								} else {
									$sdescrip = isset( $info['shortdescription'] ) ? $info['shortdescription'] : $info['description'];
									echo '<div class="backupplus-restore-item cannot-restore"><em>' .
									/* translators: %s: Entity that cannot be restored */
									esc_html( sprintf( __( 'The following entity cannot be restored automatically: "%s".', 'backupplus' ), $sdescrip ) ) . ' ' .
									esc_html__( 'You will need to restore it manually.', 'backupplus' ) . '</em><br>' . '<input id="backupplus_restore_' . esc_attr( $type ) . '" type="hidden" name="backupplus_restore[]" value="' . esc_attr( $type ) . '">';
									echo '</div>';
								}
							}
							?>
							<div class="backupplus-restore-item">
								<input id="backupplus_restore_db" type="checkbox" name="backupplus_restore[]" value="db"> <label for="backupplus_restore_db"><?php esc_html_e( 'Database', 'backupplus' ); ?></label>
								<div id="backupplus_restorer_dboptions" class="udp-notice below-h2 backupplus-restore-option backupplus-hidden"><h4>
									<?php
									echo sprintf(
										/* translators: %s: Restoration type */
										esc_html__( '%s restoration options:', 'backupplus' ),
										esc_html__( 'Database', 'backupplus' )
									);
									?>
								</h4> 
								<?php do_action( 'backupplus_restore_form_db' ); ?>
								</div>
							</div>
							<div class="backupplus-restore-encryption">
								<?php do_action( 'backupplus_restore_form_encryption' ); ?>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<div class="backupplus-restore--footer">
				<button type="button" class="button backupplus-restore--cancel"><?php esc_html_e( 'Cancel', 'backupplus' ); ?></button>
				<ul class="backupplus-restore--stages">
					<li class="active"><span>1. <?php esc_html_e( 'Component selection', 'backupplus' ); ?></span></li>
					<li><span>2. <?php esc_html_e( 'Verifications', 'backupplus' ); ?></span></li>
					<li><span>3. <?php esc_html_e( 'Restoration', 'backupplus' ); ?></span></li>
				</ul>
				<button type="button" class="button button-primary backupplus-restore--next-step"><?php esc_html_e( 'Next', 'backupplus' ); ?></button>
			</div>

		</div>
	</div>
</div>
