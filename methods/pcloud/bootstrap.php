<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

// The historical pCloud token name is provider-owned; core encryption stays generic.
add_filter(
	'backupplus_credential_secret_fields',
	function ( $fields, $option ) {
		if ( 'backupplus_pcloud' === $option ) {
			$fields[] = 'pclauth';
		}
		return $fields;
	},
	10,
	2
);

/** Upgrade this formerly unrecognized field, even on an already upgraded site. */
class BackupPlus_Pcloud_Credential_Migration {
	private static function has_plaintext( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( 'pclauth' === $key && ! in_array( $item, array( '', false, null, array() ), true ) && ! ( is_string( $item ) && 0 === strpos( $item, 'bpcred:' ) ) ) {
				return true;
			}
			if ( 'pclauth' !== $key && self::has_plaintext( $item ) ) {
				return true;
			}
		}
		return false;
	}
	public static function upgrade() {
		$raw = get_option( 'backupplus_pcloud', null );
		if ( ! self::has_plaintext( $raw ) ) {
			return;
		}
		// Use the shared policy for all cryptography and context validation.
		$plain = BackupPlus_Credential_Storage::incoming( $raw, 'backupplus_pcloud' );
		BackupPlus_Options::update_backupplus_option( 'backupplus_pcloud', $plain );
		if ( self::has_plaintext( get_option( 'backupplus_pcloud' ) ) || BackupPlus_Options::get_backupplus_option( 'backupplus_pcloud' ) !== $plain ) {
			throw new RuntimeException( 'Cannot upgrade the stored pCloud credential.' );
		}
	}
}
add_action( 'backupplus_credentials_upgrade', array( 'BackupPlus_Pcloud_Credential_Migration', 'upgrade' ) );
