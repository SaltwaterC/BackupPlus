<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Browser-only key maintenance for hosts without SSH or a file manager. */
class BackupPlus_Credential_Key_Admin {
	public static function register() {
		add_action( 'wp_ajax_backupplus_credential_keys', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_backupplus_recovery_key', array( __CLASS__, 'download' ) );
	}

	public static function allowed() {
		return BackupPlus_Options::user_can_manage() && ( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	}

	public static function perform( $operation, $value = '', $directory = '' ) {
		if ( ! self::allowed() ) {
			throw new RuntimeException( 'You do not have permission to manage installation encryption keys.' );
		}
		if ( 'move' === $operation ) {
			BackupPlus_Credential_Key_Store::relocate( trim( $value ) );
			$message = __( 'The encryption key was moved and verified. Storage destinations remain connected.', 'backupplus' );
		} elseif ( 'rotate' === $operation ) {
			if ( BackupPlus_Credential_Key_Store::environment_managed() ) {
				throw new RuntimeException( 'Credential keys are managed by the ' . BackupPlus_Credential_Key_Store::ENVIRONMENT_KEY . ' environment variable. Update the service configuration to rotate them.' );
			}
			if ( class_exists( 'BackupPlus_Credential_Key_Rotation' ) ) {
				BackupPlus_Credential_Key_Rotation::rotate_manually();
			} else {
				$config = BackupPlus_Credential_Key_Store::rotate();
				do_action( 'backupplus_credentials_rotate' );
				if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
					BackupPlus_Credential_Storage::rewrite();
				}
			}
			$message = __( 'A new encryption key is active. Previous keys were retained so existing backups and running jobs remain readable. Download a new recovery copy.', 'backupplus' );
		} elseif ( 'restore' === $operation ) {
			BackupPlus_Credential_Key_Store::restore( $value, $directory );
			$message = __( 'The recovery key was restored, verified and made active. Other keys were retained so existing credentials remain readable.', 'backupplus' );
		} elseif ( 'retry' === $operation ) {
			BackupPlus_Credential_Key_Store::configuration();
			$message = __( 'Encryption setup is complete.', 'backupplus' );
		} elseif ( 'upgrade' === $operation ) {
			do_action( 'backupplus_credentials_upgrade_restored' );
			if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
				BackupPlus_Credential_Storage::upgrade( true );
			}
			$message = __( 'Restored credentials were upgraded and verified.', 'backupplus' );
		} elseif ( 'replace_missing' === $operation ) {
			BackupPlus_Credential_Key_Store::replace_missing( $directory );
			$message = __( 'A replacement encryption key was created. Reconnect storage destinations, then download a new recovery key. Your previous credentials have been kept for recovery with the original key.', 'backupplus' );
		} else {
			throw new RuntimeException( 'Choose a valid encryption key operation.' );
		}
		do_action( 'backupplus_credentials_upgrade' );
		if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
			BackupPlus_Credential_Storage::upgrade();
		}
		if ( class_exists( 'BackupPlus_Credential_Key_Rotation' ) ) {
			BackupPlus_Credential_Key_Rotation::schedule();
		}
		delete_option( 'backupplus_credential_setup_error' );
		return $message;
	}

	public static function ajax() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( 'Use a POST request.', '', array( 'response' => 405 ) );
		}
		check_ajax_referer( 'backupplus-credential-keys', 'nonce' );
		try {
			$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? $_POST['operation'] : '';
			$value     = isset( $_POST['value'] ) && is_string( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
			$directory = isset( $_POST['directory'] ) && is_string( $_POST['directory'] ) ? wp_unslash( $_POST['directory'] ) : '';
			$message   = self::perform( $operation, $value, $directory );
			wp_send_json(
				array(
					'success'   => true,
					'message'   => $message,
					'directory' => BackupPlus_Credential_Key_Store::directory(),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}
	}

	public static function download() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( 'Use a POST request.', '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'backupplus-credential-keys' );
		if ( ! self::allowed() ) {
			wp_die( 'You do not have permission to download installation encryption keys.', '', array( 'response' => 403 ) );
		}
		try {
			$contents = BackupPlus_Credential_Key_Store::encode( BackupPlus_Credential_Key_Store::configuration() );
		} catch ( Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="backupplus-recovery-key.php"' );
		echo $contents;
		exit;
	}

	public static function render() {
		?>
		<tr class="expertmode backupplus-hidden" style="display:none;"><th><?php esc_html_e( 'Credential encryption', 'backupplus' ); ?></th><td id="backupplus-credential-keys" data-nonce="<?php echo esc_attr( wp_create_nonce( 'backupplus-credential-keys' ) ); ?>" data-download-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( ! self::allowed() ) { ?>
			<p><?php esc_html_e( 'Installation encryption keys are managed by your network administrator.', 'backupplus' ); ?></p>

			<?php
		} else {
			$error = get_option( 'backupplus_credential_setup_error', '' );
			if ( ! $error ) {
				$error = get_option( 'backupplus_credential_rotation_error', '' );
			}
			?>
			<?php if ( BackupPlus_Credential_Key_Store::environment_managed() ) { ?>
				<p><?php printf( esc_html__( 'Credential keys are read from the %s environment variable. The disk-based credentials.php file is ignored while this variable is set.', 'backupplus' ), '<code>' . esc_html( BackupPlus_Credential_Key_Store::ENVIRONMENT_KEY ) . '</code>' ); ?></p>
				<p><?php esc_html_e( 'Set it to a Base64 encoded JSON key configuration. WordPress does not rotate environment-managed keys; update the service configuration when the key age alert appears in backup reports.', 'backupplus' ); ?></p>
				<p role="status" class="backupplus-key-status"><?php echo esc_html( $error ?: __( 'Environment key configuration is active.', 'backupplus' ) ); ?></p>
				<p><button type="button" class="button" id="backupplus-key-download"><?php esc_html_e( 'Download current recovery key', 'backupplus' ); ?></button></p>
				<p><?php esc_html_e( 'If you restored storage settings from a backup made before credential encryption was upgraded, use this button to convert those restored credentials.', 'backupplus' ); ?></p>
				<p><button type="button" class="button" data-key-operation="upgrade"><?php esc_html_e( 'Upgrade restored credentials', 'backupplus' ); ?></button></p>
			<?php } else { ?>
				<p><?php esc_html_e( 'BackupPlus automatically creates and protects the key used to encrypt credentials. No server setup is needed.', 'backupplus' ); ?></p>
				<p role="status" class="backupplus-key-status"><?php echo esc_html( $error ?: __( 'Encryption is ready.', 'backupplus' ) ); ?></p>
				<p><label for="backupplus-key-directory"><?php esc_html_e( 'Key storage folder', 'backupplus' ); ?></label><br>
				<input type="text" class="large-text" id="backupplus-key-directory" value="<?php echo esc_attr( BackupPlus_Credential_Key_Store::directory() ); ?>" <?php disabled( defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) ); ?>></p>
				<p><?php esc_html_e( 'You can move the key to a dedicated writable folder outside wp-content or your website root, if your host allows it. BackupPlus moves and verifies the file for you. The key folder is omitted from unencrypted archives and appears as a separate backup source when age encryption is enabled.', 'backupplus' ); ?></p>
				<p><button type="button" class="button" data-key-operation="move" <?php disabled( defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) ); ?>><?php esc_html_e( 'Move key file', 'backupplus' ); ?></button>
				<button type="button" class="button" data-key-operation="retry"><?php esc_html_e( 'Retry encryption setup', 'backupplus' ); ?></button></p>
				<p><?php esc_html_e( 'If you restored storage settings from a backup made before credential encryption was upgraded, use this button to convert those restored credentials.', 'backupplus' ); ?></p>
				<p><button type="button" class="button" data-key-operation="upgrade"><?php esc_html_e( 'Upgrade restored credentials', 'backupplus' ); ?></button></p>
				<p><?php esc_html_e( 'Keep a recovery copy somewhere safe and separate from your backups. You can restore it here after moving your website or losing the key file. Treat this file as a password.', 'backupplus' ); ?></p>
				<p><button type="button" class="button" id="backupplus-key-download"><?php esc_html_e( 'Download recovery key', 'backupplus' ); ?></button>
				<button type="button" class="button" data-key-operation="rotate"><?php esc_html_e( 'Rotate encryption key', 'backupplus' ); ?></button></p>
				<p><?php esc_html_e( 'Encryption keys rotate automatically every year through WordPress cron. You can also rotate them manually. Download a fresh recovery copy after rotation.', 'backupplus' ); ?></p>
				<p><?php esc_html_e( 'Restoring makes the recovery file\'s active key current. Other keys are kept so credentials saved since that recovery copy was downloaded remain readable.', 'backupplus' ); ?></p>
				<p><label for="backupplus-key-recovery"><?php esc_html_e( 'Recovery key file', 'backupplus' ); ?></label> <input type="file" id="backupplus-key-recovery" accept=".php"></p>
				<p><button type="button" class="button" data-key-operation="restore" data-choose-file="<?php esc_attr_e( 'Choose your downloaded recovery key file first.', 'backupplus' ); ?>"><?php esc_html_e( 'Restore recovery key', 'backupplus' ); ?></button></p>
				<p><?php esc_html_e( 'If the key file is missing and you have no recovery copy, create a replacement key and reconnect your storage destinations. This keeps the old credentials for later recovery but does not unlock them.', 'backupplus' ); ?>
				<button type="button" class="button" data-key-operation="replace_missing"><?php esc_html_e( 'Replace missing key', 'backupplus' ); ?></button></p>
			<?php } ?>
		<?php } ?>
		</td></tr>
		<?php
	}
}
