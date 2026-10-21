<?php if (!defined('ABSPATH')) die('No direct access allowed'); ?>
<div class="advanced_tools total_size">
	<h3> <?php esc_html_e('Total (uncompressed) on-disk data:', 'backupplus');?></h3>
	<p class="uncompressed-data">
		<em>
			<?php esc_html_e('N.B. This count is based upon what was, or was not, excluded the last time you saved the options.', 'backupplus');?>
		</em>
	</p>
	<table>
		<?php
		foreach ($backupable_entities as $backupplus_key => $backupplus_info) {

			$backupplus_sdescrip = preg_replace('/ \(.*\)$/', '', $backupplus_info['description']);
			if (strlen($backupplus_sdescrip) > 20 && isset($backupplus_info['shortdescription'])) $backupplus_sdescrip = $backupplus_info['shortdescription'];
			
			$backupplus_admin->settings_debugrow(ucfirst($backupplus_sdescrip).':', '<span id="backupplus_diskspaceused_'.esc_attr($backupplus_key).'"><em></em></span> <a href="'.esc_url(BackupPlus::get_current_clean_url()).'" class="count" data-type="'.esc_attr($backupplus_key).'" onclick="backupplus_diskspace_entity(\''.esc_js($backupplus_key).'\'); return false;">'.__('count', 'backupplus').'</a>');
		}
		?>
	</table>
</div>
