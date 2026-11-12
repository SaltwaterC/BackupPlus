<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once __DIR__ . '/class-age-crypto.php';

/** Public encryption configuration and explicit, pinned GitHub recipient lookup. */
class BackupPlus_Database_Encryption {
	public static function register() {
		add_filter( 'backupplus_job_option_cache', array( __CLASS__, 'snapshot' ) );
		add_action( 'backupplus_restore_form_encryption', array( __CLASS__, 'restore_fields' ) );
	}

	public static function snapshot( $cache ) {
		$cache['backupplus_database_encryption'] = BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array() );
		return $cache;
	}

	public static function sanitize( $value ) {
		global $backupplus;
		$old = BackupPlus_Options::get_backupplus_option(
			'backupplus_database_encryption',
			array(
				'mode'       => 'none',
				'recipients' => array(),
			)
		);
		try {
			if ( ! is_array( $value ) || ! isset( $value['mode'] ) || ! in_array( $value['mode'], array( 'none', 'age' ), true ) ) {
				throw new \RuntimeException( 'Choose a valid backup encryption mode.' );
			}
			if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
				throw new \RuntimeException( 'The bundled cryptography library is unavailable.' );
			}
			$source = isset( $value['sources'] ) ? $value['sources'] : ( isset( $value['recipients'] ) ? $value['recipients'] : array() );
			if ( is_string( $source ) ) {
				$source = preg_split( '/\r\n|\n|\r/', trim( $source ) );
			}
			if ( ! is_array( $source ) || count( $source ) > 100 ) {
				throw new \RuntimeException( 'Use at most 100 age recipient entries.' );
			}
			$sources = array();
			foreach ( $source as $text ) {
				if ( ! is_string( $text ) || strlen( $text ) > 4096 ) {
					throw new \RuntimeException( 'Invalid recipient entry.' );
				}
				$text = trim( $text );
				if ( '' === $text ) {
					continue;
				}
				if ( 0 === strpos( $text, 'github:' ) ) {
					self::github_username( substr( $text, 7 ) );
				} else {
					$text = BackupPlus_Age_Keys::recipient( $text )['text'];
				}
				$sources[] = $text;
			}
			$recipients = array();
			foreach ( $sources as $text ) {
				if ( 0 === strpos( $text, 'github:' ) ) {
					if ( 'age' === $value['mode'] ) {
						$recipients = array_merge( $recipients, self::github_keys( substr( $text, 7 ) ) );
					}
				} else {
					$recipients[] = BackupPlus_Age_Keys::recipient( $text )['text'];
				}
			}
			$recipients = array_values( array_unique( $recipients ) );
			if ( count( $recipients ) > 100 || ( 'age' === $value['mode'] && ! $recipients ) ) {
				throw new \RuntimeException( 'Age encryption requires one to 100 supported public recipients.' );
			}
			return array(
				'mode'       => $value['mode'],
				'sources'    => array_values( array_unique( $sources ) ),
				'recipients' => $recipients,
			);
		} catch ( \Exception $e ) {
			// Never render parser messages: third-party errors can contain input keys.
			add_settings_error( 'backupplus_database_encryption', 'invalid_age_recipients', __( 'Encryption settings were not changed. Choose Disabled or age public keys, check the public recipients and GitHub accounts, then save again.', 'backupplus' ) );
			return $old;
		}
	}

	private static function github_username( $username ) {
		if ( ! preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?\z/iD', $username ) || false !== strpos( $username, '--' ) ) {
			throw new \RuntimeException( 'Invalid GitHub username.' );
		}
	}

	public static function github_keys( $username ) {
		self::github_username( $username );
		$response = wp_safe_remote_get(
			'https://github.com/' . $username . '.keys',
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65537,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'Could not fetch GitHub public keys.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) {
			throw new \RuntimeException( 'GitHub public key response is too large.' );
		}
		$keys = array();
		foreach ( preg_split( '/\r\n|\n|\r/', $body ) as $line ) {
			if ( ! preg_match( '/\A(?:ssh-rsa|ssh-ed25519) /', $line ) ) {
				continue;
			}
			$keys[] = BackupPlus_Age_Keys::recipient( $line )['text'];
		}
		if ( ! $keys || count( $keys ) > 100 ) {
			throw new \RuntimeException( 'GitHub account has no supported public keys, or too many keys.' );
		}
		return array_values( array_unique( $keys ) );
	}

	public static function render() {
		$config  = BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array() );
		$mode    = isset( $config['mode'] ) ? $config['mode'] : 'none';
		$sources = isset( $config['sources'] ) ? $config['sources'] : ( isset( $config['recipients'] ) ? $config['recipients'] : array() );
		?>
		<tr><th scope="row"><label for="backupplus-database-encryption"><?php esc_html_e( 'Backup encryption', 'backupplus' ); ?></label></th><td>
		<div class="backupplus-encryption-fields">
		<select id="backupplus-database-encryption" name="backupplus_database_encryption[mode]">
		<?php
		foreach ( array(
			'none' => __( 'Disabled', 'backupplus' ),
			'age'  => __( 'age public keys', 'backupplus' ),
		) as $value => $label ) {
			?>
		<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php } ?></select>
		<?php if ( ! in_array( $mode, array( 'none', 'age' ), true ) ) { ?>
		<p role="alert"><?php esc_html_e( 'The saved backup encryption mode is no longer supported. Choose Disabled or age public keys and save changes before running backups.', 'backupplus' ); ?></p>
		<?php } ?>
		<p class="description"><?php esc_html_e( 'Encrypts database dumps and every file archive, including split tar/Zstandard parts. Encryption must complete before files are uploaded.', 'backupplus' ); ?></p>
		<p><label for="backupplus-age-recipients"><?php esc_html_e( 'age recipients, one per line', 'backupplus' ); ?></label><br>
		<textarea id="backupplus-age-recipients" name="backupplus_database_encryption[sources]" rows="5" spellcheck="false"><?php echo esc_textarea( implode( "\n", $sources ) ); ?></textarea></p>
		<p><button type="button" id="backupplus-age-generate" class="button"><?php esc_html_e( 'Generate age key in browser', 'backupplus' ); ?></button></p>
		<p id="backupplus-age-key-status" class="description" role="status" aria-live="polite"></p>
		<dialog id="backupplus-age-key-dialog" aria-labelledby="backupplus-age-key-title">
			<h2 id="backupplus-age-key-title"><?php esc_html_e( 'Save your private age key', 'backupplus' ); ?></h2>
			<p><?php esc_html_e( 'Download the private key and keep it somewhere safe, separate from your backups. You will need it to decrypt or restore backups. BackupPlus cannot recover it for you.', 'backupplus' ); ?></p>
			<p><?php esc_html_e( 'The private key is generated in your browser and is never sent to the server.', 'backupplus' ); ?></p>
			<p><a id="backupplus-age-key-download" class="button button-primary"><?php esc_html_e( 'Download private key', 'backupplus' ); ?></a></p>
			<p><label><input type="checkbox" id="backupplus-age-key-saved" class="udignorechange" disabled> <?php esc_html_e( 'I have saved my private key somewhere safe.', 'backupplus' ); ?></label></p>
			<p><?php esc_html_e( 'Public recipient:', 'backupplus' ); ?> <code id="backupplus-age-key-public"></code></p>
			<p>
				<button type="button" id="backupplus-age-key-add" class="button button-primary" disabled><?php esc_html_e( 'Add public recipient', 'backupplus' ); ?></button>
				<button type="button" id="backupplus-age-key-cancel" class="button"><?php esc_html_e( 'Cancel', 'backupplus' ); ?></button>
			</p>
		</dialog>
		<p class="description"><?php esc_html_e( 'Use age1… recipients, SSH RSA/Ed25519 public keys, or github:USERNAME. GitHub keys are fetched and pinned when you save; save again to refresh them. Backups use every saved recipient without contacting GitHub.', 'backupplus' ); ?></p>
		<p class="description"><?php echo esc_html( sprintf( __( 'Saved public recipients: %d. Each recipient can decrypt the backup. Keep the corresponding private key for restoration; it is never saved here.', 'backupplus' ), isset( $config['recipients'] ) ? count( $config['recipients'] ) : 0 ) ); ?></p>
		</div>
		</td></tr>
		<?php
	}

	public static function restore_fields() {
		?>
		<p><label for="backupplus-age-identity"><?php esc_html_e( 'age identity or SSH/PEM private key (age-encrypted archives)', 'backupplus' ); ?></label><br>
		<textarea id="backupplus-age-identity" rows="4" cols="65" autocomplete="off" spellcheck="false"></textarea></p>
		<p><label for="backupplus-age-key-passphrase"><?php esc_html_e( 'Private key passphrase, if encrypted', 'backupplus' ); ?></label><br>
		<input id="backupplus-age-key-passphrase" type="password" autocomplete="off"></p>
		<input id="backupplus-age-ticket" name="backupplus_age_ticket" type="hidden" value="">
		<p class="description"><?php esc_html_e( 'Archives are decrypted in your browser, then a plaintext copy is uploaded for this restore. Your private key and its passphrase are never sent to the server.', 'backupplus' ); ?></p>
		<?php
	}
}
