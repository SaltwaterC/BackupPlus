<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div class="backupplus-decryption-tool">
	<h2><?php esc_html_e( 'Extract backup archives', 'backupplus' ); ?></h2>
	<p><?php esc_html_e( 'Select backup files to decrypt, decompress and extract in one step: encrypted (.crypt, .age), tar archives (plain, .gz or .zst), single .gz or .zst files, or a zip that holds them. Several files come back as one uncompressed zip. Everything happens entirely in your browser; files, passphrases and private keys are never uploaded. Keep this tab open until your downloads finish.', 'backupplus' ); ?></p>

	<div id="backupplus-decrypt-drop">
		<label for="backupplus-decrypt-files"><?php esc_html_e( 'Drop backup files here or select files', 'backupplus' ); ?></label>
		<input id="backupplus-decrypt-files" type="file" multiple accept=".crypt,.age,.zip,.tar,.tgz,.tzst,.gz,.zst,.zstd" aria-describedby="backupplus-decrypt-selection">
		<p id="backupplus-decrypt-selection"></p>
	</div>

	<div id="backupplus-decrypt-credentials" class="backupplus-decryption-credentials" hidden>
		<h3><?php esc_html_e( 'Decryption credentials', 'backupplus' ); ?></h3>
		<p><?php esc_html_e( 'A selected file is encrypted. Enter the backup passphrase or the private identity that matches how it was encrypted.', 'backupplus' ); ?></p>
		<p>
			<label for="backupplus_db_decrypt"><?php esc_html_e( 'Backup passphrase (UpdraftPlus legacy passphrase-encrypted archives or age -p archives)', 'backupplus' ); ?></label>
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

	<p>
		<button type="button" id="backupplus-decrypt-start" class="button button-primary" disabled><?php esc_html_e( 'Extract locally', 'backupplus' ); ?></button>
		<button type="button" id="backupplus-decrypt-cancel" class="button" hidden><?php esc_html_e( 'Cancel', 'backupplus' ); ?></button>
		<button type="button" id="backupplus-decrypt-clear" class="button"><?php esc_html_e( 'Clear files and keys', 'backupplus' ); ?></button>
	</p>
	<progress id="backupplus-decrypt-progress" max="1" value="0" hidden aria-label="<?php esc_attr_e( 'Extraction progress', 'backupplus' ); ?>"></progress>
	<p id="backupplus-decrypt-status" role="status" aria-live="polite"></p>
	<div id="backupplus-decrypt-results"></div>
</div>
