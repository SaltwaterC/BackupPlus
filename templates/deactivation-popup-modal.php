<?php
	if (!defined('ABSPATH')) die('No direct access allowed');
?>
<div class="udp-dialog-intro">
	<?php esc_html_e('Deactivating stops scheduled backups and all other plugin features, but does not delete any stored backup sets.', 'backupplus'); ?>
</div>
<div class="udp-deinstall-dialog-content">
	<div class="udp-remove-data">
		<label class="udp-toggle-container">
			<input type="checkbox" name="backupplus_deinstall_option" value="yes">
			<span class="udp-toggle-slider"></span>
		</label>
		<div class="udp-remove-text">
			<h4><?php esc_html_e('Remove plugin settings', 'backupplus'); ?></h4>
			<p>
				<?php esc_html_e('Permanently deletes all BackupPlus settings and saved data, but not backup sets.', 'backupplus'); ?>
				<?php esc_html_e('The data will only be deleted if the plugin is removed.', 'backupplus'); ?>
				<?php esc_html_e('You still have a chance to undo your decision before the deletion happens.', 'backupplus'); ?>
			</p>
		</div>
	</div>
</div>
