<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div class="backupplus-decryption-tool">
	<h2><?php esc_html_e( 'Decrypt backup archives', 'backupplus' ); ?></h2>
	<p><?php esc_html_e( 'Select encrypted backup archives and enter the backup passphrase or private identity. Decryption happens entirely in your browser; files, passphrases and private keys are never uploaded. Keep this tab open until your downloads finish.', 'backupplus' ); ?></p>

	<div class="backupplus-decryption-credentials">
		<p>
			<label for="backupplus_db_decrypt"><?php esc_html_e( 'Backup passphrase (UpdraftPlus legacy passphrase-encrypted archives)', 'backupplus' ); ?></label>
			<input id="backupplus_db_decrypt" type="password" class="regular-text" autocomplete="off">
		</p>
		<p>
			<label for="backupplus-manual-age-identity"><?php esc_html_e( 'age identity or SSH/PEM private key', 'backupplus' ); ?></label>
			<textarea id="backupplus-manual-age-identity" class="large-text" rows="6" autocomplete="off" spellcheck="false"></textarea>
		</p>
		<p>
			<label for="backupplus-manual-age-passphrase"><?php esc_html_e( 'Private key passphrase, if encrypted', 'backupplus' ); ?></label>
			<input id="backupplus-manual-age-passphrase" type="password" class="regular-text" autocomplete="off">
		</p>
	</div>

	<div id="backupplus-decrypt-drop">
		<label for="backupplus-decrypt-files"><?php esc_html_e( 'Drop encrypted backup archives here or select files', 'backupplus' ); ?></label>
		<input id="backupplus-decrypt-files" type="file" multiple accept=".crypt,.age" aria-describedby="backupplus-decrypt-selection">
		<p id="backupplus-decrypt-selection"></p>
	</div>
	<p>
		<button type="button" id="backupplus-decrypt-start" class="button button-primary" disabled><?php esc_html_e( 'Decrypt locally', 'backupplus' ); ?></button>
		<button type="button" id="backupplus-decrypt-cancel" class="button" hidden><?php esc_html_e( 'Cancel', 'backupplus' ); ?></button>
		<button type="button" id="backupplus-decrypt-clear" class="button"><?php esc_html_e( 'Clear files and keys', 'backupplus' ); ?></button>
	</p>
	<progress id="backupplus-decrypt-progress" max="1" value="0" hidden aria-label="<?php esc_attr_e( 'Decryption progress', 'backupplus' ); ?>"></progress>
	<p id="backupplus-decrypt-status" role="status" aria-live="polite"></p>
	<div id="backupplus-decrypt-results"></div>
</div>
