<?php
/** Annual rotation through real WordPress cron APIs, using the storage fixture. */
call_user_func(function() use ($fixture_directory, $fixture_option) {
	$hook = BackupPlus_Credential_Key_Rotation::HOOK;
	$retry_hook = BackupPlus_Credential_Key_Rotation::RETRY_HOOK;
	$interval = BackupPlus_Credential_Key_Store::ROTATION_INTERVAL;
	$count_events = function($wanted_hook) {
		$count = 0;
		foreach (_get_cron_array() as $events) if (isset($events[$wanted_hook])) $count += count($events[$wanted_hook]);
		return $count;
	};
	$config = BackupPlus_Credential_Key_Store::configuration();
	// Cover every stored token field and more than one provider instance.
	$options = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	$extra = Dropbox_Encrypter::from_config('rotation-extra');
	$plain = serialize((object) array('oauth_token' => 'disposable-annual-extra'));
	$options['settings']['rotation-extra'] = array('tk_access_token' => $extra->encrypt($plain), 'tk_request_token' => $extra->encrypt($plain));
	BackupPlus_Options::update_backupplus_option('backupplus_dropbox', $options);
	BackupPlus_Credential_Key_Rotation::schedule();
	$event = wp_get_scheduled_event($hook);
	dropbox_wp_expect($event && $event->schedule === BackupPlus_Credential_Key_Rotation::SCHEDULE && $event->interval === $interval, 'Activation must register a recurring annual rotation');
	dropbox_wp_expect($event->timestamp === $config['rotated_at'] + $interval, 'Annual rotation must be scheduled a year after the last key rotation');
	BackupPlus_Credential_Key_Rotation::schedule();
	BackupPlus_Credential_Key_Rotation::schedule();
	dropbox_wp_expect(1 === $count_events($hook), 'Repeated requests must not duplicate annual rotation jobs');
	do_action($hook);
	dropbox_wp_expect($config === BackupPlus_Credential_Key_Store::configuration(), 'A premature cron callback must not rotate a fresh key');
	$unchanged = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	do_action($hook);
	dropbox_wp_expect($unchanged === BackupPlus_Options::get_backupplus_option('backupplus_dropbox'), 'Duplicate callbacks must not repeatedly rewrite credentials with the same active key');

	$config['rotated_at'] = time() - $interval;
	file_put_contents($fixture_directory.'/credentials.php', BackupPlus_Credential_Key_Store::encode($config));
	wp_clear_scheduled_hook($hook);
	BackupPlus_Credential_Key_Rotation::schedule();
	$due = wp_next_scheduled($hook);
	dropbox_wp_expect($due <= time(), 'An overdue key must get a due rotation event');
	// WP-Cron reschedules the recurring event before invoking its callback.
	wp_reschedule_event($due, BackupPlus_Credential_Key_Rotation::SCHEDULE, $hook);
	wp_unschedule_event($due, $hook);
	do_action($hook);
	$rotated = BackupPlus_Credential_Key_Store::configuration();
	dropbox_wp_expect($rotated['active'] !== $config['active'] && count($rotated['keys']) === count($config['keys']) + 1, 'An annual callback must generate exactly one new active key');
	foreach ($config['keys'] as $id => $key) dropbox_wp_expect(isset($rotated['keys'][$id]) && $rotated['keys'][$id] === $key, 'Annual rotation must retain previous keys');
	$options = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	foreach ($options['settings'] as $id => $settings) {
		foreach (array('tk_access_token', 'tk_request_token') as $field) {
			if (empty($settings[$field])) continue;
			dropbox_wp_expect(0 === strpos($settings[$field], Dropbox_Encrypter::PREFIX.$rotated['active'].':'), 'Stored credentials must be re-encrypted under the annual key');
			dropbox_wp_expect(false !== Dropbox_Encrypter::from_config($id)->decrypt($settings[$field]), 'Re-encrypted credentials must remain readable');
		}
	}
	dropbox_wp_expect(wp_next_scheduled($hook) === $rotated['rotated_at'] + $interval && !$count_events($retry_hook), 'Successful annual rotation must schedule the next year and clear retries');
	do_action($hook);
	dropbox_wp_expect($rotated === BackupPlus_Credential_Key_Store::configuration() && $options === BackupPlus_Options::get_backupplus_option('backupplus_dropbox'), 'Repeated annual callbacks must not rotate again or rewrite completed credentials');

	// A successful key publication followed by a failed DB save must retry only the rewrite.
	$aged = $rotated;
	$aged['rotated_at'] = time() - $interval;
	file_put_contents($fixture_directory.'/credentials.php', BackupPlus_Credential_Key_Store::encode($aged));
	$block_save = function($value, $old) { return $old; };
	add_filter('pre_update_option_'.$fixture_option, $block_save, PHP_INT_MAX, 2);
	do_action($hook);
	$published = BackupPlus_Credential_Key_Store::configuration();
	dropbox_wp_expect($published['active'] !== $aged['active'] && $options === BackupPlus_Options::get_backupplus_option('backupplus_dropbox'), 'A failed credential save must preserve ciphertext and retain its old key');
	dropbox_wp_expect(1 === $count_events($retry_hook) && get_option(BackupPlus_Credential_Key_Rotation::ERROR_OPTION), 'A failed rotation must expose an error and schedule one retry');
	do_action($retry_hook);
	dropbox_wp_expect($published === BackupPlus_Credential_Key_Store::configuration() && 1 === $count_events($retry_hook), 'Failed retries must reuse the active key and avoid duplicate retry events');
	remove_filter('pre_update_option_'.$fixture_option, $block_save, PHP_INT_MAX);
	do_action($retry_hook);
	dropbox_wp_expect($published === BackupPlus_Credential_Key_Store::configuration() && !$count_events($retry_hook), 'A successful retry must finish credential rewriting without generating a second key');
	dropbox_wp_expect(!get_option(BackupPlus_Credential_Key_Rotation::ERROR_OPTION), 'Successful retry must clear the rotation error');
	BackupPlus_Dropbox_Token_Migration::verify($published);
	$options = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
	foreach ($options['settings'] as $settings) if (!empty($settings['tk_access_token'])) dropbox_wp_expect(0 === strpos($settings['tk_access_token'], Dropbox_Encrypter::PREFIX.$published['active'].':'), 'Retry must persist credentials encrypted under the already published key');

	wp_clear_scheduled_hook($hook);
	wp_schedule_event(time() + 60, BackupPlus_Credential_Key_Rotation::SCHEDULE, $hook);
	BackupPlus_Credential_Key_Admin::perform('rotate');
	$manual = BackupPlus_Credential_Key_Store::configuration();
	dropbox_wp_expect($manual['active'] !== $published['active'] && wp_next_scheduled($hook) === $manual['rotated_at'] + $interval, 'Manual rotation must reset the annual schedule');
	BackupPlus_Credential_Key_Rotation::queue_retry();
	do_action('deactivate_'.plugin_basename(BACKUPPLUS_DIR.'/backupplus.php'), false);
	dropbox_wp_expect(!wp_next_scheduled($hook) && !wp_next_scheduled($retry_hook), 'Deactivation must remove annual and retry events');
	do_action('activate_'.plugin_basename(BACKUPPLUS_DIR.'/backupplus.php'), false);
	dropbox_wp_expect($manual === BackupPlus_Credential_Key_Store::configuration() && 1 === $count_events($hook), 'Reactivation must resume scheduling without rotating the key early');
	echo "PASS: annual cron scheduling, credential re-encryption, duplicate prevention, failure retries, manual reset and deactivation\n";
});
