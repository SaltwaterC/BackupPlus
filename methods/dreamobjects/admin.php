<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_dreamobjects_Admin {

	public function get_admin_notices($settings) {
		$notices = array();
		if ('objects-us-east-1.dream.io' === ($settings['endpoint'] ?? '')) {
			$notices[] = array(
				'message' => '<strong>'.__('BackupPlus notice:', 'backupplus').'</strong> '.
				/* translators: %s: Storage endpoint name */
				sprintf(__('As of Nov 12th, 2025, objects-us-east-1.dream.io has been permanently shut down.', 'backupplus'), 'objects-us-east-1.dream.io').' '.
				__('For most DreamObjects customers, this involves updating the endpoint and region to s3.us-east-005.dream.io (us-east-005).', 'backupplus').' '.
				__('If you need assistance migrating your data, please reach out to the DreamObjects support team.', 'backupplus').' '.
				/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
				sprintf(__('%1$sPlease see this article for more information%2$s', 'backupplus'), '<a href="https://help.dreamhost.com/hc/en-us/articles/360001370846-What-DreamObjects-hostname-should-I-use-to-connect" target="_blank">', '</a>'),
				'class' => 'updated'
			);
		} elseif (!$this->is_valid_endpoint($settings['endpoint'] ?? '')) {
			$notices[] = array(
				'message' => '<strong>'.__('Error:', 'backupplus').'</strong> '.
					/* translators: %s: Non-translated string 'DreamObjects'. */
					sprintf(__('Invalid custom %s endpoint.', 'backupplus'), '<em>DreamObjects</em>').' '.
					/* translators: %s: Desired endpoint format. */
					sprintf(__('Please enter the endpoint in the format "%s".', 'backupplus'), '<em>s3.&lt;region&gt;.dream.io</em>'),
				'class' => 'error'
			);
		}
		return $notices;
	}
}
