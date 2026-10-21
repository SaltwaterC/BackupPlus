<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!empty($options['include_header'])) echo '<h2>'.esc_html(__('Existing backups', 'backupplus').' ('.count($backup_history).')').'</h2>';

?>
<div class="download-backups form-table">
	<?php if (!empty($options['include_whitespace_warning'])) { ?>
		<p class="ud-whitespace-warning backupplus-hidden" style="display:none;">
			<?php echo '<strong>'.esc_html__('Warning', 'backupplus').':</strong> '.esc_html(__('Your WordPress installation has a problem with outputting extra whitespace.', 'backupplus').' '.__('This can corrupt backups that you download from here. Check for output before or after PHP opening and closing tags in plugins and themes.', 'backupplus'));?>
		</p>
	<?php }
	$backupplus_tab_backups['bom_warning'] = $backupplus_admin->get_bom_warning_text();
	if (!empty($backupplus_tab_backups['bom_warning'])) {
	?>
	<p class="ud-bom-warning">
	<?php
	echo wp_kses_post($backupplus_tab_backups['bom_warning']);
	?>
	</p>
	<?php
	}
	$backupplus_admin->take_backup_content();
	?>
	
	<div class="backupplus_existing_backups_wrapper">
		<h3 id="backupplus-existing-backups-heading"><?php esc_html_e('Existing backups', 'backupplus');?> <span class="backupplus_existing_backups_count"><?php echo esc_html(count($backup_history));?></span></h3>
		<ul class="backupplus-disk-space-actions">
			<?php
				echo wp_kses_post(BackupPlus_Filesystem_Functions::web_server_disk_space($options['will_immediately_calculate_disk_space']));
			?>
	
			<li class="backupplus-server-scan">
				<strong><?php esc_html_e('More tasks:', 'backupplus');?></strong>
				<?php
					if (!empty($options['include_uploader'])) {
					?>
					<a class="backupplus_uploader_toggle" href="<?php echo esc_url(BackupPlus::get_current_clean_url());?>"><?php esc_html_e('Upload backup files', 'backupplus'); ?></a> |
					<?php
					}
				?>
				<a href="<?php echo esc_url(BackupPlus::get_current_clean_url());?>" class="backupplus_rescan_local" title="<?php echo esc_attr(__('Press here to look inside your BackupPlus directory (in your web hosting space) for any new backup sets that you have uploaded.', 'backupplus').' '.__('The location of this directory is set in the expert settings, in the Settings tab.', 'backupplus')); ?>"><?php esc_html_e('Rescan local folder for new backup sets', 'backupplus');?></a>
				| <a href="<?php echo esc_url(BackupPlus::get_current_clean_url());?>" class="backupplus_rescan_remote" title="<?php esc_attr_e('Press here to look inside your remote storage methods for any existing backup sets (from any site, if they are stored in the same folder).', 'backupplus'); ?>"><?php esc_html_e('Rescan remote storage', 'backupplus');?></a>
			</li>
			<?php if (!empty($options['include_opera_warning'])) { ?>
				<li class="backupplus-opera-warning"><strong><?php esc_html_e('Opera web browser', 'backupplus');?>:</strong> <?php esc_html_e('If you are using this, then turn Turbo/Road mode off.', 'backupplus');?></li>
			<?php } ?>
		</ul>
		<?php
			if (!empty($options['include_uploader'])) {
			?>
		
			<div id="backupplus-plupload-modal" style="display:none;" title="<?php esc_attr_e('BackupPlus - Upload backup files', 'backupplus'); ?>">
			<p class="upload"><em><?php echo esc_html(__("Upload files into BackupPlus.", 'backupplus').' '.__('Or, you can place them manually into your BackupPlus directory (usually wp-content/backupplus), e.g. via FTP, and then use the "rescan" link above.', 'backupplus'));?></em></p>
			<?php
			if (version_compare($backupplus->get_wordpress_version(), '3.3', '<')) {
				echo '<em>'.esc_html(
					sprintf(
						/* translators: %1$s: WordPress  %2$s: version number */
						__('This feature requires %1$s version %2$s or later', 'backupplus'),
						'WordPress',
						'3.3'
					)
				).'</em>';
			} else {
				?>
				<div id="plupload-upload-ui">
				<div id="drag-drop-area">
					<div class="drag-drop-inside">
					<p class="drag-drop-info"><?php esc_html_e('Drop your backup files', 'backupplus'); ?></p>
					<p><?php echo esc_html_x('or', 'Uploader: Drop your backup files - or - Select Files'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?></p>
					<p class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e('Select Files'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?>" class="button /"></p>
					</div>
				</div>
				<div id="filelist">
				</div>
				</div>
				<?php
			}
			?>
			</div>
		<?php
			}
		?>
		<div class="ud_downloadstatus"></div>
		<div class="backupplus_existing_backups">
			<?php
			echo BackupPlus_Backup_History::existing_backup_table($backup_history);// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML has an onclick attribute, but otherwise is already sanitised
			?>
		</div>
	</div>
</div>
