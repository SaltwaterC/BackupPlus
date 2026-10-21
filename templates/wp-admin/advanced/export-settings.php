<?php
	if (!defined('ABSPATH')) die('No direct access allowed');
?>
<div class="advanced_tools export_settings">
	<h3><?php esc_html_e('Export / import settings', 'backupplus');?></h3>
	<p>
		<?php
		echo wp_kses(sprintf(
			/* translators: %s: "including any passwords" (wrapped in <strong> tags) */
			__('Here, you can export your BackupPlus settings (%s), either for using on another site, or to keep as a backup.', 'backupplus'),
			'<strong>'.__('including any passwords', 'backupplus').'</strong>'
		), array('strong' => array())).' '.esc_html__('This tool will export what is currently in the settings tab.', 'backupplus');
		?>
	</p>
	<button type="button" style="clear:left;" class="button-primary" id="backupplus-settings-export"><?php esc_html_e('Export settings', 'backupplus');?></button>
	
	<p>
		<?php echo esc_html(__('You can also import previously-exported settings.', 'backupplus').' '.__('This tool will replace all your saved settings.', 'backupplus')); ?>
	</p>
	
	<button type="button" style="clear:left;" class="button-primary" id="backupplus-settings-import"><?php esc_html_e('Import settings', 'backupplus');?></button>
	<input type="file" name="settings_file" id="import_settings">
</div>
