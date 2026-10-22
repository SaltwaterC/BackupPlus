<?php
	if (!defined('ABSPATH')) die('No direct access allowed');
?>
<div class="advanced_tools export_settings">
	<h3><?php esc_html_e('Export / import settings', 'backupplus');?></h3>
	<p>
		<?php
		echo esc_html__('Export the settings shown in the Settings tab. Storage connection settings, credentials and legacy backup passphrases are omitted. Configure storage connections separately on a new site.', 'backupplus');
		?>
	</p>
	<button type="button" style="clear:left;" class="button-primary" id="backupplus-settings-export"><?php esc_html_e('Export settings', 'backupplus');?></button>
	
	<p>
		<?php echo esc_html(__('You can also import previously-exported settings.', 'backupplus').' '.__('This tool will replace all your saved settings.', 'backupplus')); ?>
	</p>
	
	<button type="button" style="clear:left;" class="button-primary" id="backupplus-settings-import"><?php esc_html_e('Import settings', 'backupplus');?></button>
	<input type="file" name="settings_file" id="import_settings">
</div>
