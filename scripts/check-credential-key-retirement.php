<?php
/**
 * Credential key retirement (BPS-20) in the key fixture of check-dropbox-token-storage.php, which includes this file after
 * rotation has left several keys. Credentials that an older key wrote are re-encrypted with the active key, current envelopes
 * keep their ciphertext, and the old keys are removed only when nothing stored still uses them.
 */
call_user_func(
	function () use ( $fixture_directory ) {
		$config = BackupPlus_Credential_Key_Store::configuration();
		$active = (string) $config['active'];
		$old    = '';
		foreach ( array_keys( $config['keys'] ) as $id ) {
			if ( (string) $id !== $active ) {
				$old = (string) $id;
				break;
			}
		}
		dropbox_wp_expect( '' !== $old, 'Retirement needs a key ring that rotation has left with an older key' );

		// A Dropbox token and a saved job that the older key wrote, beside the token the active key wrote
		$token                             = serialize( (object) array( 'oauth_token' => 'disposable-retirement-token' ) );
		$options                           = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
		$current                           = $options['settings'];
		$old_dropbox                       = new Dropbox_Encrypter( $config['keys'], $old, 'dropbox:' . get_current_blog_id() . ':retire-old' );
		$options['settings']['retire-old'] = array(
			'tk_access_token' => $old_dropbox->encrypt( $token ),
			// A sign-in still pending, whose verifier the older key wrote
			'tk_pkce'         => $old_dropbox->encrypt( 'disposable-pending-verifier' ),
		);
		BackupPlus_Options::update_backupplus_option( 'backupplus_dropbox', $options );
		$job_id   = bin2hex( random_bytes( 6 ) );
		$job_name = 'backupplus_jobdata_' . $job_id;
		// A running job's snapshot holds the Dropbox settings with the token still in the Dropbox envelope of the older key
		$snapshot  = serialize(
			array(
				'backupplus_s3'      => array( 'settings' => array( 's-retire' => array( 'secretkey' => 'disposable-retirement-secret' ) ) ),
				'backupplus_dropbox' => array( 'settings' => array( 'retire-old' => array( 'tk_access_token' => $options['settings']['retire-old']['tk_access_token'] ) ) ),
			)
		);
		$probe     = 'backupplus_retire_probe_' . bin2hex( random_bytes( 6 ) );
		$old_job   = ( new BackupPlus_Credential_Encrypter( $config['keys'], $old, 'job:' . $job_id . ':option_cache' ) )->encrypt( $snapshot );
		$untouched = BackupPlus_Credential_Encrypter::PREFIX . $old . ':';
		try {
			update_site_option(
				$job_name,
				array(
					'option_cache' => $old_job,
					'jobstatus'    => 'begun',
				)
			);
			// Ciphertext of the old key outside any credential option cannot be re-encrypted: retirement must refuse and change nothing
			update_option( $probe, 'copied ' . $old_job, false );
			$refused = false;
			try {
				BackupPlus_Credential_Key_Rotation::retire_manually();
			} catch ( RuntimeException $e ) {
				$refused = false !== strpos( $e->getMessage(), $probe ) && false !== strpos( $e->getMessage(), 'No keys were removed' );
			}
			dropbox_wp_expect( $refused, 'Retirement must refuse while stored data still holds an envelope of a key it would remove, and name the place' );
			dropbox_wp_expect( $config === BackupPlus_Credential_Key_Store::configuration(), 'A refused retirement must not change the key file' );
			delete_option( $probe );

			$result  = BackupPlus_Credential_Key_Rotation::retire_manually();
			$retired = BackupPlus_Credential_Key_Store::configuration();
			dropbox_wp_expect( array( $active ) === array_map( 'strval', array_keys( $retired['keys'] ) ) && $active === (string) $retired['active'] && $config['keys'][ $active ] === $retired['keys'][ $active ], 'Retirement must keep the active key alone' );
			dropbox_wp_expect( in_array( $old, $result['retired'], true ) && count( $result['retired'] ) === count( $config['keys'] ) - 1, 'Retirement must report the removed keys' );
			dropbox_wp_expect( $retired === BackupPlus_Credential_Key_Store::read( $fixture_directory ), 'The retired key ring must be the one saved on disk' );

			$after = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
			dropbox_wp_expect( 0 === strpos( $after['settings']['retire-old']['tk_access_token'], BackupPlus_Credential_Encrypter::PREFIX . $active . ':' ) && $token === Dropbox_Encrypter::from_config( 'retire-old' )->decrypt( $after['settings']['retire-old']['tk_access_token'] ), 'A token an older key wrote must be re-encrypted with the active key' );
			foreach ( $current as $id => $settings ) {
				foreach ( array( 'tk_access_token', 'tk_request_token' ) as $field ) {
					if ( ! empty( $settings[ $field ] ) ) {
						dropbox_wp_expect( $settings[ $field ] === $after['settings'][ $id ][ $field ], 'Envelopes of the active key must keep their ciphertext, which the incremental fingerprint hashes' );
					}
				}
			}
			$job = get_site_option( $job_name );
			dropbox_wp_expect( 0 === strpos( $job['option_cache'], BackupPlus_Credential_Encrypter::PREFIX . $active . ':' ) && 'begun' === $job['jobstatus'], 'A saved job must be re-encrypted with the active key' );
			$decrypted = BackupPlus_Credential_Storage::job( $job, $job_id, 'decrypt' );
			$expected  = unserialize( $snapshot );
			unset( $expected['backupplus_dropbox'] );
			$in_job = $decrypted['option_cache']['backupplus_dropbox']['settings']['retire-old']['tk_access_token'];
			unset( $decrypted['option_cache']['backupplus_dropbox'] );
			dropbox_wp_expect( $expected === $decrypted['option_cache'], 'A re-encrypted job must still decrypt to its credentials' );
			dropbox_wp_expect( 0 === strpos( $in_job, BackupPlus_Credential_Encrypter::PREFIX . $active . ':' ) && $token === Dropbox_Encrypter::from_config( 'retire-old' )->decrypt( $in_job ), 'A Dropbox token inside a saved job must be re-encrypted with the active key' );
			$pending = $after['settings']['retire-old']['tk_pkce'];
			dropbox_wp_expect( 0 === strpos( $pending, BackupPlus_Credential_Encrypter::PREFIX . $active . ':' ) && 'disposable-pending-verifier' === Dropbox_Encrypter::from_config( 'retire-old' )->decrypt( $pending ), 'A pending sign-in verifier must be re-encrypted with the active key' );
			dropbox_wp_expect( array() === BackupPlus_Credential_Storage::key_references( array( $old ) ), 'Nothing stored may still use a retired key' );

			$again = BackupPlus_Credential_Key_Rotation::retire_manually();
			dropbox_wp_expect( array() === $again['retired'] && $retired === BackupPlus_Credential_Key_Store::configuration(), 'Retiring again must be a no-op' );
		} finally {
			delete_option( $probe );
			delete_site_option( $job_name );
			$options = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
			unset( $options['settings']['retire-old'] );
			BackupPlus_Options::update_backupplus_option( 'backupplus_dropbox', $options );
		}
		echo "PASS: key retirement re-encrypts old envelopes, keeps current ones, refuses on leftovers and removes old keys\n";
	}
);
