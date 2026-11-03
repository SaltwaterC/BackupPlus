<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_dropbox_Admin {
	/** A token is usable when it was authorized with PKCE (token_app) or for a custom app; tokens of the retired UpdraftPlus app cannot be renewed. */
	public function authentication_required( $settings ) {
		return empty( $settings['tk_access_token'] ) || ( empty( $settings['token_app'] ) && empty( $settings['appkey'] ) );
	}
}
