<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** One-time upgrade of saved credentials; normal token reads never decode CBC. */
class BackupPlus_Dropbox_Token_Migration {
	const OPTION = 'backupplus_dropbox_token_format';

	public static function upgrade() {
		if (BackupPlus_Credential_Key_Store::upgrade_complete()) return 0;
		return self::upgrade_restored();
	}

	/** Only a deliberate, authorized restore action can reopen legacy conversion. */
	public static function upgrade_restored() {
		$count = self::rewrite(true);
		BackupPlus_Credential_Key_Store::mark_upgraded();
		update_option(self::OPTION, 1, false);
		if (1 !== (int) get_option(self::OPTION, 0)) throw new RuntimeException('The Dropbox credential upgrade could not be saved. Please retry in BackupPlus Settings.');
		return $count;
	}

	public static function rotate() { return self::rewrite(false); }

	public static function verify($config) {
		$options = class_exists('BackupPlus_Credential_Storage') ? get_option('backupplus_dropbox') : BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
		if (empty($options['settings'])) return;
		foreach ($options['settings'] as $id => $settings) {
			$encrypter = new Dropbox_Encrypter($config['keys'], $config['active'], 'dropbox:'.get_current_blog_id().':'.$id);
			foreach (array('tk_access_token', 'tk_request_token') as $field) {
				if (empty($settings[$field]) || !is_string($settings[$field]) || 0 !== strpos($settings[$field], 'bpcred:')) continue;
				if (false === $encrypter->decrypt($settings[$field])) throw new RuntimeException('This recovery file cannot unlock the saved Dropbox credentials. No keys were changed.');
			}
		}
	}

	private static function rewrite($allow_legacy) {
		$raw_original = class_exists('BackupPlus_Credential_Storage') ? get_option('backupplus_dropbox') : BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
		$original = $raw_original;
		if (class_exists('BackupPlus_Credential_Storage')) {
			$original = $allow_legacy ? BackupPlus_Credential_Storage::incoming($original, 'backupplus_dropbox') : BackupPlus_Credential_Storage::reading($original, 'backupplus_dropbox', true);
		}
		if (empty($original)) return 0;
		if (!is_array($original) || !isset($original['settings']) || !is_array($original['settings'])) throw new RuntimeException('The saved Dropbox settings could not be upgraded. Reconnect Dropbox in BackupPlus Settings.');
		$updated = $original;
		$count = 0;
		try {
			foreach ($original['settings'] as $id => $settings) {
				$encrypter = Dropbox_Encrypter::from_config($id);
				foreach (array('tk_access_token', 'tk_request_token') as $field) {
					if (empty($settings[$field])) continue;
					$stored = $settings[$field];
					if (is_string($stored) && 0 === strpos($stored, 'bpcred:')) {
						$plain = $encrypter->decrypt($stored);
						if (false === $plain) throw new RuntimeException();
						if ($allow_legacy) continue;
					} else {
						if (!$allow_legacy) throw new RuntimeException();
						$plain = self::legacy($stored, $field);
					}
					$token = BackupPlus::unserialize($plain, array('stdClass'));
					if (!$token instanceof stdClass || empty($token->oauth_token) || !is_string($token->oauth_token)) throw new RuntimeException();
					$encrypted = $encrypter->encrypt($plain);
					if ($plain !== $encrypter->decrypt($encrypted)) throw new RuntimeException();
					$updated['settings'][$id][$field] = $encrypted;
					$count++;
				}
			}
		} catch (Exception $e) {
			throw new RuntimeException('The Dropbox credential upgrade could not be completed. Your saved credentials are unchanged. Restore a recovery key or reconnect Dropbox in BackupPlus Settings.');
		}
		if ($updated !== $original) {
			if ((class_exists('BackupPlus_Credential_Storage') ? get_option('backupplus_dropbox') : BackupPlus_Options::get_backupplus_option('backupplus_dropbox')) !== $raw_original) throw new RuntimeException('Dropbox settings changed during the upgrade. Please retry.');
			BackupPlus_Options::update_backupplus_option('backupplus_dropbox', $updated);
			if (BackupPlus_Options::get_backupplus_option('backupplus_dropbox') !== $updated) throw new RuntimeException('The upgraded Dropbox credentials could not be saved. Please retry in BackupPlus Settings.');
		}
		return $count;
	}

	/** Invoked only by the upgrade before its persisted completion marker. */
	private static function legacy($stored, $field) {
		global $backupplus;
		if (is_wp_error($backupplus->ensure_phpseclib())) throw new RuntimeException();
		if ('tk_request_token' === $field) return is_string($stored) ? $stored : serialize($stored);
		$raw = is_string($stored) ? base64_decode($stored, true) : false;
		if (false === $raw || strlen($raw) <= 16 || (strlen($raw) - 16) % 16) throw new RuntimeException();
		$cipher = BackupPlus_Crypto::rijndael('ThisOneDoesNotMatterBeyondLength', substr($raw, 0, 16));
		return $cipher->decrypt(substr($raw, 16));
	}
}
