<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

$accept = apply_filters( 'backupplus_accept_archivename', array() );
if ( ! is_array( $accept ) ) {
	$accept = array();
}
$image_folder     = BACKUPPLUS_DIR . '/images/icons/';
$image_folder_url = BACKUPPLUS_URL . '/images/icons/';

?>
<table class="existing-backups-table wp-list-table widefat striped">
	<thead>
		<tr style="margin-bottom: 4px;">
			<th class="check-column">
				<label class="screen-reader-text" for="cb-select-all">
					<?php esc_html_e( 'Select All' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?>
				</label>
				<input id="cb-select-all" type="checkbox">
			</th>
			<th class="backup-date"><?php esc_html_e( 'Backup date', 'backupplus' ); ?></th>
			<th class="backup-data"><?php esc_html_e( 'Backup data (click to download)', 'backupplus' ); ?></th>
			<th class="backupplus_backup_actions"><?php esc_html_e( 'Actions', 'backupplus' ); ?></th>
		</tr>		
	</thead>
	<tbody>
		<?php

		if ( $backup_count <= count( $backup_history ) - 1 ) {
			$backup_history      = array_slice( $backup_history, 0, $backup_count, true );
			$show_paging_actions = true;
		}

		foreach ( $backup_history as $key => $backup ) {


			// https://core.trac.wordpress.org/ticket/25331 explains why the following line is wrong
			// $pretty_date = date_i18n('Y-m-d G:i',$key);
			// Convert to blog time zone
			// $pretty_date = get_date_from_gmt(gmdate('Y-m-d H:i:s', (int)$key), 'Y-m-d G:i');
			$pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $key ), 'M d, Y G:i' );

			$esc_pretty_date = esc_attr( $pretty_date );
			$entities        = '';

			$nonce = $backup['nonce'];

			$jobdata = isset( $backup['jobdata'] ) ? $backup['jobdata'] : $backupplus->jobdata_getarray( $nonce );

			$rawbackup = $backupplus_admin->raw_backup_info( $backup_history, $key, $nonce, $jobdata );

			$is_locked = ! empty( $backup['always_keep'] );
			ob_start();
			?>
			<button type="button" class="backupplus-lock-toggle" data-key="<?php echo esc_attr( $key ); ?>" data-locked="<?php echo $is_locked ? '1' : '0'; ?>" title="<?php echo esc_attr( $is_locked ? __( 'Locked: retention never deletes this backup. Click to unlock.', 'backupplus' ) : __( 'Unlocked: retention may delete this backup. Click to lock.', 'backupplus' ) ); ?>">
				<span class="dashicons <?php echo $is_locked ? 'dashicons-lock' : 'dashicons-unlock'; ?>"></span>
				<span class="screen-reader-text"><?php echo esc_html( $is_locked ? __( 'Unlock this backup', 'backupplus' ) : __( 'Lock this backup', 'backupplus' ) ); ?></span>
			</button>
			<?php
			$lock_toggle = ob_get_clean();
			$date_label  = $backupplus_admin->date_label( $pretty_date . $lock_toggle, $key, $backup, $jobdata, $nonce );


			?>
			<tr class="backupplus_existing_backups_row backupplus_existing_backups_row_<?php echo esc_attr( $key ); ?>" data-key="<?php echo esc_attr( $key ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<td class="backup-select">
					<label class="screen-reader-text">
						<?php esc_html_e( 'Select All' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?>
					</label>
					<input type="checkbox">
				</td>
				<td class="backupplus_existingbackup_date"  data-nonce="<?php echo esc_attr( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>" data-timestamp="<?php echo esc_attr( $key ); ?>" data-label="<?php esc_attr_e( 'Backup date', 'backupplus' ); ?>">
					<div tabindex="0" class="backup_date_label">
						<?php
							echo wp_kses_post( $date_label );
							$restore_points = $backupplus_admin->restore_points( $backup );
						if ( ! empty( $restore_points ) ) {
							$encrypted_points = count(
								array_filter(
									$restore_points,
									function ( $point ) {
										return ! empty( $point['encrypted'] );
									}
								)
							);
							$point_count      = count( $restore_points );
							$summary          = sprintf(
								/* translators: 1: Number of encrypted recovery points, 2: Total number of recovery points. */
								__( 'Encrypted points: %1$d/%2$d', 'backupplus' ),
								$encrypted_points,
								$point_count
							);
							?>
							<span class="backupplus-chain-badge backupplus-encryption-badge"><?php echo esc_html( $summary ); ?></span>
							<?php
						} else {
							$backup_encrypted = BackupPlus_Age_Restore::has_encrypted( $backup );
							?>
							<span class="backupplus-chain-badge backupplus-encryption-badge"><?php echo esc_html( $backup_encrypted ? __( 'Encrypted', 'backupplus' ) : __( 'Not encrypted', 'backupplus' ) ); ?></span>
							<?php
						}
						if ( ! isset( $backup['service'] ) ) {
							$backup['service'] = array();
						}
						if ( ! is_array( $backup['service'] ) ) {
							$backup['service'] = array( $backup['service'] );
						}
						foreach ( $backup['service'] as $service ) {
							if ( 'none' === $service || '' === $service || ( is_array( $service ) && ( empty( $service ) || array( 'none' ) === $service || array( '' ) === $service ) ) ) {
								// Do nothing
							} else {
								$image_url = file_exists( $image_folder . $service . '.png' ) ? $image_folder_url . $service . '.png' : $image_folder_url . 'folder.png';

								$remote_storage = isset( $backupplus->backup_methods[ $service ] ) ? $backupplus->backup_methods[ $service ] : $service;
								/* translators: %s: Remote storage name*/
								$remote_storage_label = __( 'Remote storage: %s', 'backupplus' );
								?>
									<img class="stored_icon" src="<?php echo esc_url( $image_url ); ?>" title="<?php echo esc_attr( sprintf( $remote_storage_label, $remote_storage ) ); ?>">
									<?php
							}
						}
						?>
					</div>
				</td>
				
				<td data-label="<?php esc_attr_e( 'Backup data (click to download)', 'backupplus' ); ?>">
				<?php
				if ( empty( $backup['meta_foreign'] ) || ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {
					if ( isset( $backup['db'] ) ) {
						$entities .= '/db=0/';

						// Set a flag according to whether or not the database archive is encrypted.
						$db = is_array( $backup['db'] ) ? $backup['db'][0] : $backup['db'];
						if ( BackupPlus_Encryption::is_file_encrypted( $db ) ) {
							$entities .= '/dbcrypted=1/';
						}

						$backupplus_admin->download_db_button( 'db', $key, $esc_pretty_date, $backup, $accept );
					}

					// External databases.
					foreach ( $backup as $bkey => $binfo ) {
						if ( 'db' == $bkey || 'db' != substr( $bkey, 0, 2 ) || '-size' == substr( $bkey, -5, 5 ) ) {
							continue;
						}
						$backupplus_admin->download_db_button( $bkey, $key, $esc_pretty_date, $backup );
					}
				} else {
					// Foreign without separate database.
					$entities = '/db=0/meta_foreign=1/';
				}

				if ( ! empty( $backup['meta_foreign'] ) && ! empty( $accept[ $backup['meta_foreign'] ] ) && ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {
					$entities .= '/meta_foreign=2/';
				}

					$backupplus_admin->download_buttons( $backup, $key, $accept, $entities, $esc_pretty_date );
				?>
				</td>
				<td class="before-restore-button" data-label="<?php esc_attr_e( 'Actions', 'backupplus' ); ?>">
					<?php
					$backupplus_admin->restore_button( $backup, $key, $pretty_date, $entities );
					$backupplus_admin->upload_button( $key, $nonce, $backup, $jobdata );
					$backupplus_admin->delete_button( $key, $nonce, $backup );
					if ( empty( $backup['meta_foreign'] ) ) {
						$backupplus_admin->log_button( $backup );
					}
					?>
				</td>
			</tr>
		<?php } ?>	

	</tbody>
	<?php if ( $show_paging_actions ) : ?>
	<tfoot>
		<tr class="backupplus_existing_backups_page_actions">
			<td colspan="4" style="text-align: center;">
				<a class="backupplus-load-more-backups"><?php esc_html_e( 'Show more backups...', 'backupplus' ); ?></a> | <a class="backupplus-load-all-backups"><?php esc_html_e( 'Show all backups...', 'backupplus' ); ?></a>
			</td>
		</tr>
	</tfoot>
	<?php endif; ?>
</table>
<div id="ud_massactions">
	<strong><?php esc_html_e( 'Actions upon selected backups', 'backupplus' ); ?></strong>
	<div class="backupplus-remove"><button title="<?php esc_attr_e( 'Delete selected backups', 'backupplus' ); ?>" type="button" class="button button-remove js--delete-selected-backups"><?php esc_html_e( 'Delete', 'backupplus' ); ?></button></div>
	<div class="backupplus-viewlogdiv"><button title="<?php esc_attr_e( 'Lock selected backups so retention never deletes them', 'backupplus' ); ?>" type="button" class="button js--lock-selected-backups"><?php esc_html_e( 'Lock', 'backupplus' ); ?></button></div>
	<div class="backupplus-viewlogdiv"><button title="<?php esc_attr_e( 'Unlock selected backups so retention may delete them', 'backupplus' ); ?>" type="button" class="button js--unlock-selected-backups"><?php esc_html_e( 'Unlock', 'backupplus' ); ?></button></div>
	<div class="backupplus-viewlogdiv"><button title="<?php esc_attr_e( 'Select all backups', 'backupplus' ); ?>" type="button" class="button js--select-all-backups" href="#"><?php esc_html_e( 'Select all', 'backupplus' ); ?></button></div>
	<div class="backupplus-viewlogdiv"><button title="<?php esc_attr_e( 'Deselect all backups', 'backupplus' ); ?>" type="button" class="button js--deselect-all-backups" href="#"><?php esc_html_e( 'Deselect', 'backupplus' ); ?></button></div>
	<small class="ud_massactions-tip"><?php esc_html_e( 'Use ctrl / cmd + press to select several items, or ctrl / cmd + shift + press to select all in between', 'backupplus' ); ?></small>
</div>
<div id="backupplus-delete-waitwarning" class="backupplus-hidden" style="display:none;">
	<span class="spinner"></span> <em><?php esc_html_e( 'Deleting...', 'backupplus' ); ?> <span class="backupplus-deleting-remote"><?php esc_html_e( 'Please allow time for the communications with the remote storage to complete.', 'backupplus' ); ?><span></em>
	<p id="backupplus-deleted-files-total"></p>
</div>
