<?php
/** Real crypto/rewrites across three simulated WordPress sites sharing one file. */
require_once BACKUPPLUS_DIR.'/includes/class-credential-key-rotation.php';
$dropbox_is_multisite = true;
$original_key = BackupPlus_Credential_Key_Store::configuration();
$original_options = array();
foreach (array(1, 2, 3) as $site) {
	switch_to_blog($site);
	$token = serialize((object) array('oauth_token' => 'disposable-network-'.$site));
	$encrypter = Dropbox_Encrypter::from_config('instance');
	$settings = array('version' => 1, 'settings' => array('instance' => array('tk_access_token' => $encrypter->encrypt($token), 'tk_request_token' => $encrypter->encrypt($token))));
	BackupPlus_Options::update_backupplus_option('backupplus_dropbox', $settings);
	$original_options[$site] = $settings;
	restore_current_blog();
}
$new_key = BackupPlus_Credential_Key_Store::rotate();
BackupPlus_Options::$fail_blog_id = 2;
expect_dropbox_failure(function() use ($new_key) { BackupPlus_Credential_Key_Rotation::reencrypt_credentials($new_key['active']); }, 'A failed subsite rewrite must be reported for retry');
expect_dropbox(1 === get_current_blog_id() && !$dropbox_blog_stack, 'Network reencryption must restore the caller site even after failures');
$completed = array();
foreach (array(1, 2, 3) as $site) {
	switch_to_blog($site);
	$settings = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	if (2 === $site) {
		expect_dropbox($settings === $original_options[$site] && get_option(BackupPlus_Credential_Key_Rotation::ERROR_OPTION), 'The failed site must retain readable old ciphertext and expose a rotation error');
	} else {
		foreach ($settings['settings']['instance'] as $encrypted) expect_dropbox(0 === strpos($encrypted, Dropbox_Encrypter::PREFIX.$new_key['active'].':'), 'Other sites must finish reencryption despite a neighboring site failure');
		$completed[$site] = $settings;
	}
	restore_current_blog();
}
BackupPlus_Options::$fail_blog_id = null;
BackupPlus_Credential_Key_Rotation::reencrypt_credentials($new_key['active']);
foreach (array(1, 2, 3) as $site) {
	switch_to_blog($site);
	$settings = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	if (isset($completed[$site])) expect_dropbox($settings === $completed[$site], 'Network retries must skip already completed sites');
	foreach ($settings['settings']['instance'] as $encrypted) {
		expect_dropbox(0 === strpos($encrypted, Dropbox_Encrypter::PREFIX.$new_key['active'].':'), 'Every site credential must eventually use the new key');
		$plain = Dropbox_Encrypter::from_config('instance')->decrypt($encrypted);
		expect_dropbox('disposable-network-'.$site === BackupPlus::unserialize($plain, array('stdClass'))->oauth_token, 'Reencryption must preserve each site token and context binding');
	}
	expect_dropbox(!get_option(BackupPlus_Credential_Key_Rotation::ERROR_OPTION), 'Completed sites must clear their rotation errors');
	restore_current_blog();
}
expect_dropbox($new_key === BackupPlus_Credential_Key_Store::configuration() && isset($new_key['keys'][$original_key['active']]), 'Network rewrites and retries must share one rotated key and retain the historical key');
$dropbox_is_multisite = false;
echo "PASS: shared-network key rotation reencryption, partial failures, per-site retries and context preservation\n";
