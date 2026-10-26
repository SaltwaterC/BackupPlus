<?php
/** Restore of age encrypted backups through a browser that decrypts independently: the restore API never receives a key. */

namespace BackupPlus\Tests\Unit\Age;

use AgeTestState;
use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Age_Restore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeRestoreTest extends AgeTestCase {

	private string $reference = '';

	protected function setUp(): void {
		parent::setUp();
		$this->reference = base64_decode( $this->native['ciphertext'] );
		file_put_contents( $this->path, $this->reference );
	}

	/** @return array{0: array, 1: string, 2: string} The selected backup, the transfer ticket and the file of the transfer. */
	private function begin_transfer(): array {
		$upload_backup = array(
			'db'      => basename( $this->path ),
			'uploads' => 'unselected-missing.zip.crypt',
		);
		$manifest      = BackupPlus_Age_Restore::manifest( $upload_backup, 122, array( 'db' ) );
		return array( $upload_backup, $manifest['ticket'], basename( $this->path ) );
	}

	public function test_only_selected_encrypted_archives_belong_to_a_transfer(): void {
		list($upload_backup) = $this->begin_transfer();
		$manifest            = BackupPlus_Age_Restore::manifest( $upload_backup, 122, array( 'db' ) );
		$this->assertSame( array( basename( $this->path ) ), $manifest['files'], 'Only selected encrypted archives belong to a transfer' );
	}

	public function test_transfer_downloads_resolve_the_original_ciphertext(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		$this->assertSame( $this->path, BackupPlus_Age_Restore::download( $ticket, $file ), 'Transfer downloads must resolve the original ciphertext' );
	}

	public function test_uploads_outside_the_transfer_directory_fail(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		$this->assertThrowsRuntime(
			function () use ( $ticket, $file ) {
				BackupPlus_Age_Restore::upload( $ticket, '../' . $file, 0, 'x' );
			},
			'Invalid upload operation must fail'
		);
	}

	public function test_partial_plaintext_is_never_published(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		BackupPlus_Age_Restore::upload( $ticket, $file, 0, substr( $this->plain, 0, 3 ) );
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket ), 'Partial plaintext must never be published' );
	}

	public function test_partial_transfers_cannot_be_prepared_claimed_or_resumed_at_a_wrong_offset(): void {
		list($upload_backup, $ticket, $file) = $this->begin_transfer();
		BackupPlus_Age_Restore::upload( $ticket, $file, 0, substr( $this->plain, 0, 3 ) );
		$this->assertThrowsRuntime(
			function () use ( $upload_backup, $ticket ) {
				BackupPlus_Age_Restore::prepare( $upload_backup, 122, $ticket );
			},
			'Invalid upload operation must fail'
		);
		$this->assertThrowsRuntime(
			function () use ( $ticket ) {
				BackupPlus_Age_Restore::claim( $ticket, 122, 'partial-job' );
			},
			'Invalid upload operation must fail'
		);
		$this->assertThrowsRuntime(
			function () use ( $ticket, $file ) {
				BackupPlus_Age_Restore::upload( $ticket, $file, 2, 'x' );
			},
			'Invalid upload operation must fail'
		);
	}

	public function test_another_user_cannot_download_the_transfer(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		AgeTestState::$user    = 2;
		$this->assertThrowsRuntime(
			function () use ( $ticket, $file ) {
				BackupPlus_Age_Restore::download( $ticket, $file );
			},
			'Invalid upload operation must fail'
		);
	}

	public function test_a_hash_mismatch_does_not_publish_plaintext(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		BackupPlus_Age_Restore::upload( $ticket, $file, 0, substr( $this->plain, 0, 3 ) );
		$this->assertThrowsRuntime(
			function () use ( $ticket, $file ) {
				BackupPlus_Age_Restore::upload( $ticket, $file, 3, substr( $this->plain, 3 ), strlen( $this->plain ), str_repeat( '0', 64 ) );
			},
			'Invalid upload operation must fail'
		);
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket ), 'Hash mismatch must not publish plaintext' );
	}

	public function test_cancellation_removes_plaintext_and_its_reference(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		BackupPlus_Age_Restore::upload( $ticket, $file, 0, substr( $this->plain, 0, 3 ) );
		$pending_path = $this->directory . '/' . AgeTestState::$transients[ 'backupplus_age_restore_' . $ticket ]['files'][ $file ]['path'];
		$this->assertFileExists( $pending_path, 'The pending plaintext must exist before cancellation' );
		BackupPlus_Age_Restore::discard( $ticket );
		$this->assertFileDoesNotExist( $pending_path, 'Cancellation must remove plaintext and its reference' );
		$this->assertFalse( get_transient( 'backupplus_age_restore_' . $ticket ), 'Cancellation must remove plaintext and its reference' );
	}

	public function test_changed_ciphertext_prevents_upload_completion(): void {
		list(, $ticket, $file) = $this->begin_transfer();
		file_put_contents( $this->path, $this->reference . 'changed' );
		$this->assertThrowsRuntime(
			function () use ( $ticket, $file ) {
				BackupPlus_Age_Restore::upload( $ticket, $file, 0, $this->plain, strlen( $this->plain ), hash( 'sha256', $this->plain ) );
			},
			'Invalid upload operation must fail'
		);
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket ), 'Changed ciphertext must prevent upload completion' );
	}

	/** A restore ticket holds only public file bindings, never private keys/passphrases. */
	private function prepared_ticket(): string {
		return $this->prepare_browser( array( 'db' => basename( $this->path ) ), 123, $this->native['encrypted_pem'], $this->password );
	}

	public function test_preflight_produces_an_opaque_ticket(): void {
		$this->assertSame( 64, strlen( $this->prepared_ticket() ), 'Restore preflight must produce an opaque ticket' );
	}

	public function test_the_restore_cache_never_contains_identities_or_passphrases(): void {
		$this->prepared_ticket();
		$cached = serialize( AgeTestState::$transients );
		foreach ( array( $this->native['identity'], $this->native['encrypted_pem'], $this->password ) as $secret ) {
			$this->assertFalse( strpos( $cached, $secret ), 'Restore cache must never contain identities or passphrases' );
		}
	}

	public function test_the_prepared_file_is_authenticated_and_complete(): void {
		$ticket   = $this->prepared_ticket();
		$prepared = BackupPlus_Age_Restore::file( $this->path, $ticket, '', 123 );
		$this->assertIsArray( $prepared, 'Prepared file must be authenticated and complete' );
		$this->assertSame( $this->plain, file_get_contents( $prepared['fullpath'] ), 'Prepared file must be authenticated and complete' );
		unlink( $prepared['fullpath'] );
	}

	public function test_another_user_blog_or_backup_cannot_reuse_the_ticket(): void {
		$ticket             = $this->prepared_ticket();
		AgeTestState::$user = 2;
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket ), 'Another user must not reuse the ticket' );
		AgeTestState::$user = 1;
		AgeTestState::$blog = 2;
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket ), 'Another blog must not reuse the ticket' );
		AgeTestState::$blog = 1;
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket, '', 124 ), 'Another backup must not reuse the ticket' );
	}

	public function test_a_claimed_ticket_belongs_to_its_job_and_continues_without_an_identity(): void {
		$ticket = $this->prepared_ticket();
		BackupPlus_Age_Restore::claim( $ticket, 123, 'restore-job' );
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket, 'other-job' ), 'Another job must not reuse the ticket' );
		AgeTestState::$user = 0;
		$this->assertIsArray( BackupPlus_Age_Restore::file( $this->path, $ticket, 'restore-job' ), 'A bound background job must continue without an identity' );
	}

	public function test_prepared_plaintext_changes_fail(): void {
		$ticket   = $this->prepared_ticket();
		$prepared = BackupPlus_Age_Restore::file( $this->path, $ticket, '', 123 );
		BackupPlus_Age_Restore::claim( $ticket, 123, 'restore-job' );
		file_put_contents( $prepared['fullpath'], 'tampered plaintext' );
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket, 'restore-job' ), 'Prepared plaintext changes must fail' );
	}

	public function test_ciphertext_changes_after_preflight_fail(): void {
		$ticket = $this->prepared_ticket();
		BackupPlus_Age_Restore::claim( $ticket, 123, 'restore-job' );
		file_put_contents( $this->path, $this->reference . 'changed' );
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket, 'restore-job' ), 'Ciphertext changes after preflight must fail' );
	}

	public function test_expired_tickets_fail(): void {
		$ticket = $this->prepared_ticket();
		BackupPlus_Age_Restore::claim( $ticket, 123, 'restore-job' );
		AgeTestState::$transients[ 'backupplus_age_restore_' . $ticket ]['expires'] = time() - 1;
		$this->assertFalse( BackupPlus_Age_Restore::file( $this->path, $ticket, 'restore-job' ), 'Expired tickets must fail' );
	}

	public function test_logs_and_settings_errors_omit_supplied_secrets(): void {
		$ticket   = $this->prepared_ticket();
		$prepared = BackupPlus_Age_Restore::file( $this->path, $ticket, '', 123 );
		unlink( $prepared['fullpath'] );
		$this->assertFalse( strpos( implode( "\n", $this->backupplus->logs ), $this->password ), 'Logs and settings errors must omit supplied secrets' );
		$this->assertFalse( strpos( implode( "\n", AgeTestState::$settings_errors ), $this->password ), 'Logs and settings errors must omit supplied secrets' );
	}

	public function test_the_manual_decryption_ui_never_places_secrets_in_links_or_page_text(): void {
		$js = '';
		foreach ( glob( BACKUPPLUS_DIR . '/includes/admin-common/*.js' ) as $js_file ) {
			$js .= file_get_contents( $js_file );
		}
		$this->assertNotSame( '', $js, 'The admin scripts must be present' );
		$this->assertFalse( strpos( $js, '&decrypt_key=' ), 'Manual decryption UI must not place secrets in links or page text' );
		$this->assertFalse( strpos( $js, 'backupplus_i18n.thiskey' ), 'Manual decryption UI must not place secrets in links or page text' );
	}
}
