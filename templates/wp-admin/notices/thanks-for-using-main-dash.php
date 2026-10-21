<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div id="backupplus-dashnotice" class="updated">
	<div style="float:right;"><a href="#" onclick="jQuery('#backupplus-dashnotice').slideUp(); jQuery.post(ajaxurl, {action: 'backupplus_ajax', subaction: 'dismissdashnotice', nonce: '<?php echo esc_js(wp_create_nonce('backupplus-credentialtest-nonce'));?>' });">
		<?php
		/* translators: %d: Number of months */
		printf(esc_html__('Dismiss (for %d months)', 'backupplus'), 12);
		?>
	</a></div>

	<h3><?php esc_html_e('Thank you for installing BackupPlus!', 'backupplus');?></h3>
	<p><?php esc_html_e('BackupPlus is a GPLv3 fork of UpdraftPlus. It retains compatibility with existing UpdraftPlus backup sets.', 'backupplus');?></p>
	<p><a class="button button-primary" href="<?php echo esc_url(BackupPlus_Options::admin_page_url().'?page=backupplus');?>"><?php esc_html_e('Open BackupPlus settings', 'backupplus');?></a></p>
</div>
