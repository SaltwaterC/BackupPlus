<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

// Keep legacy token compatibility with the provider; the cipher and key store
// remain shared services. These hooks run before generic credential maintenance.
require_once __DIR__.'/Dropbox2/Exception.php';
require_once __DIR__.'/Dropbox2/OAuth/Storage/Encrypter.php';
require_once __DIR__.'/token-migration.php';

foreach (array('upgrade', 'upgrade_restored', 'rotate', 'verify') as $operation) {
	add_action('backupplus_credentials_'.$operation, array('BackupPlus_Dropbox_Token_Migration', $operation));
}
add_filter('backupplus_credentials_has_ciphertext', function($found) {
	return $found || false !== strpos(serialize(BackupPlus_Options::get_backupplus_option('backupplus_dropbox')), 'bpcred:');
});
