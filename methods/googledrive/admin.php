<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_googledrive_Admin {
	public function authentication_required( $settings ) {
		return ! empty( $settings['clientid'] ) && ! empty( $settings['secret'] ) && empty( $settings['token'] );
	}

	/** Connections made through the retired shared Google app cannot transfer files until the site has its own OAuth client. */
	public function get_admin_notices( $settings ) {
		if ( empty( $settings['user_id'] ) || ! empty( $settings['clientid'] ) ) {
			return array();
		}
		return array(
			array(
				'message' => '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' .
				__( 'Google Drive now requires your own Google OAuth client.', 'backupplus' ) . ' ' .
				__( 'Enter its client ID and secret in BackupPlus Settings, save, then sign in with Google again. Backups are not sent to Google Drive until then.', 'backupplus' ),
				'class'   => 'error',
			),
		);
	}
}
