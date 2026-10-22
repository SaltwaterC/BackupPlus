<?php
require_once dirname(dirname(dirname(__DIR__))).'/class-credential-encrypter.php';

/** Compatibility adapter: preserve the authenticated context of existing Dropbox tokens. */
class Dropbox_Encrypter extends BackupPlus_Credential_Encrypter {
	public static function from_config($instance_id) {
		if (null === $instance_id || '' === (string) $instance_id) throw new Dropbox_Exception('Dropbox credential encryption requires a storage instance ID.');
		$config = BackupPlus_Credential_Key_Store::configuration();
		return new self($config['keys'], $config['active'], 'dropbox:'.get_current_blog_id().':'.$instance_id);
	}
}
