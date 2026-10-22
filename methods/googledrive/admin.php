<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_googledrive_Admin {
	public function authentication_required( $settings ) {
		if ( ( defined( 'BACKUPPLUS_CUSTOM_GOOGLEDRIVE_APP' ) && BACKUPPLUS_CUSTOM_GOOGLEDRIVE_APP ) || ! empty( $settings['clientid'] ) ) {
			return ! empty( $settings['clientid'] ) && empty( $settings['token'] );
		}
		return empty( $settings['user_id'] );
	}
}
