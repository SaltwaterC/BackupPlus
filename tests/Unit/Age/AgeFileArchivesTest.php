<?php
/** Encrypted file archives: split-part checkpoints and retry, ciphertext sizes and checksums, plaintext cleanup, file-only preflight and upload refusal. */

namespace BackupPlus\Tests\Unit\Age;

use AgeTestState;
use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Age_Restore;
use BackupPlus_Encryption;
use BackupPlus_Manipulation_Functions;
use BackupPlus_Options;
use FileEncryptionFailureProbe;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionMethod;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeFileArchivesTest extends AgeTestCase {

	private array $parts = array( 'backup_2026-10-22-1200_fixture_0123456789ab-plugins.zip', 'backup_2026-10-22-1200_fixture_0123456789ab-plugins2.zip' );

	private string $zip_plain = '';

	private FileEncryptionFailureProbe $probe;

	/** The backup history entry of the plaintext parts. */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->zip_plain = "PK\x03\x04" . str_repeat( "synthetic WordPress secret\0", 5000 );
		BackupPlus_Options::$options['backupplus_database_encryption'] = array(
			'mode'       => 'age',
			'recipients' => array( $this->native['recipient'] ),
		);
		BackupPlus_Options::$options['backupplus_encryptionphrase']    = 'file archive passphrase';
		$this->probe = new FileEncryptionFailureProbe();
		foreach ( $this->parts as $part ) {
			file_put_contents( $this->directory . '/' . $part, $this->zip_plain );
			file_put_contents( $this->directory . '/' . $part . '.list.tmp', '{"files":["synthetic-key"]}' );
		}
		$this->files = array(
			'plugins'       => $this->parts,
			'plugins-size'  => strlen( $this->zip_plain ),
			'plugins1-size' => strlen( $this->zip_plain ),
		);
	}

	/** The second part fails to encrypt, so the first is checkpointed and the history is left half done. */
	private function encrypt_until_the_second_part_fails(): array {
		$this->probe->fail_file = $this->parts[1];
		$this->assertFalse( $this->probe->encrypt_file_archives( $this->files ), 'A failed ZIP part must stop dispatch' );
		return $this->backupplus->jobdata_get( 'backup_files_array' );
	}

	/** Both parts encrypted, after one failure and a retry. */
	private function encrypt_with_a_retry(): array {
		$checkpoint             = $this->encrypt_until_the_second_part_fails();
		$this->probe->fail_file = '';
		$files                  = $this->probe->encrypt_file_archives( $checkpoint );
		$this->assertIsArray( $files, 'A retry must preserve completed ciphertext and finish remaining parts' );
		return $files;
	}

	public function test_encrypted_backups_do_not_upload_a_zip_before_encryption(): void {
		$early_upload = new ReflectionMethod( $this->probe, 'maybe_cloud_backup' );
		if ( PHP_VERSION_ID < 80100 ) {
			$early_upload->setAccessible( true );
		}
		$early_upload->invoke( $this->probe, $this->parts[0], 'plugins', 0 );
		$this->assertSame( array(), $this->probe->uploads, 'Encrypted backups must not upload a ZIP before encryption' );
	}

	public function test_completed_parts_are_checkpointed_individually(): void {
		$checkpoint = $this->encrypt_until_the_second_part_fails();
		$this->assertSame( $this->parts[0] . '.crypt', $checkpoint['plugins'][0], 'Completed parts must be checkpointed individually' );
		$this->assertSame( $this->parts[1], $checkpoint['plugins'][1], 'Completed parts must be checkpointed individually' );
	}

	public function test_only_successfully_encrypted_parts_lose_their_plaintext(): void {
		$this->encrypt_until_the_second_part_fails();
		$this->assertFileDoesNotExist( $this->directory . '/' . $this->parts[0], 'Only successfully encrypted parts may lose their plaintext' );
		$this->assertFileExists( $this->directory . '/' . $this->parts[1], 'Only successfully encrypted parts may lose their plaintext' );
	}

	public function test_a_retry_preserves_completed_ciphertext_and_finishes_remaining_parts(): void {
		$checkpoint             = $this->encrypt_until_the_second_part_fails();
		$first_cipher_hash      = hash_file( 'sha256', $this->directory . '/' . $this->parts[0] . '.crypt' );
		$this->probe->fail_file = '';
		$files                  = $this->probe->encrypt_file_archives( $checkpoint );
		$this->assertIsArray( $files, 'A retry must preserve completed ciphertext and finish remaining parts' );
		$this->assertSame( $first_cipher_hash, hash_file( 'sha256', $this->directory . '/' . $this->parts[0] . '.crypt' ), 'A retry must preserve completed ciphertext and finish remaining parts' );
	}

	public function test_history_refers_to_ciphertext_and_removes_completed_plaintext_and_manifests(): void {
		$files = $this->encrypt_with_a_retry();
		foreach ( $this->parts as $index => $part ) {
			$this->assertSame( $part . '.crypt', $files['plugins'][ $index ], 'History must refer to ciphertext and remove completed plaintext/manifests' );
			$this->assertFileDoesNotExist( $this->directory . '/' . $part, 'History must refer to ciphertext and remove completed plaintext/manifests' );
			$this->assertFileDoesNotExist( $this->directory . '/' . $part . '.list.tmp', 'History must refer to ciphertext and remove completed plaintext/manifests' );
		}
	}

	public function test_upload_checksums_cover_ciphertext(): void {
		$this->encrypt_with_a_retry();
		foreach ( $this->parts as $index => $part ) {
			$this->assertSame( $this->backupplus->jobdata_get( 'sha256-plugins' . $index . '.crypt' ), hash_file( 'sha256', $this->directory . '/' . $part . '.crypt' ), 'Upload checksums must cover ciphertext' );
		}
	}

	public function test_history_sizes_describe_ciphertext(): void {
		$files = $this->encrypt_with_a_retry();
		foreach ( $this->parts as $index => $part ) {
			$this->assertSame( filesize( $this->directory . '/' . $part . '.crypt' ), $files[ 'plugins' . ( $index ? $index : '' ) . '-size' ], 'History sizes must describe ciphertext' );
		}
	}

	/** The history of the encrypted parts with an unselected database, and the ticket a file-only preflight returns. */
	private function file_only_preflight( array $files ): array {
		$backup = array(
			'plugins' => $files['plugins'],
			'db'      => 'unselected-unavailable-db.gz.crypt',
		);
		$ticket = $this->prepare_browser( $backup, 321, $this->native['identity'], '', array( 'plugins' ), 'file archive passphrase' );
		return array( $backup, $ticket );
	}

	public function test_file_only_preflight_reuses_prepared_parts_without_retaining_a_key(): void {
		$files                 = $this->encrypt_with_a_retry();
		list($backup, $ticket) = $this->file_only_preflight( $files );
		$this->assertSame( $ticket, BackupPlus_Age_Restore::prepare( $backup, 321, $ticket, array( 'plugins' ) ), 'File-only preflight must reuse prepared parts without retaining a key' );
	}

	public function test_preserved_job_bindings_contain_no_keys_or_passphrases(): void {
		$files                   = $this->encrypt_with_a_retry();
		list($backup, $ticket)   = $this->file_only_preflight( $files );
		$this->backupplus->nonce = 'restore-file-job';
		BackupPlus_Age_Restore::claim( $ticket, 321, $this->backupplus->nonce );
		unset( AgeTestState::$transients[ 'backupplus_age_restore_' . $ticket ] );
		foreach ( array( $this->native['identity'], 'file archive passphrase' ) as $secret ) {
			$this->assertFalse( strpos( serialize( $this->backupplus->jobdata_get( 'backupplus_prepared_archives' ) ), $secret ), 'Preserved job bindings must not contain keys or passphrases' );
		}
	}

	public function test_file_only_preflight_authenticates_every_selected_split_archive(): void {
		$files                   = $this->encrypt_with_a_retry();
		list($backup, $ticket)   = $this->file_only_preflight( $files );
		$this->backupplus->nonce = 'restore-file-job';
		BackupPlus_Age_Restore::claim( $ticket, 321, $this->backupplus->nonce );
		unset( AgeTestState::$transients[ 'backupplus_age_restore_' . $ticket ] );
		foreach ( $files['plugins'] as $file ) {
			$prepared = BackupPlus_Age_Restore::file( $this->directory . '/' . $file, $ticket, $this->backupplus->nonce, 321 );
			$this->assertIsArray( $prepared, 'File-only preflight must authenticate every selected split archive' );
			$this->assertSame( $this->zip_plain, file_get_contents( $prepared['fullpath'] ), 'File-only preflight must authenticate every selected split archive' );
			unlink( $prepared['fullpath'] );
		}
	}

	public function test_retries_reject_changes_to_completed_ciphertext(): void {
		$files               = $this->encrypt_with_a_retry();
		$encrypted_path      = $this->directory . '/' . $files['plugins'][0];
		$original_ciphertext = file_get_contents( $encrypted_path );
		file_put_contents( $encrypted_path, $original_ciphertext . 'changed' );
		$this->assertFalse( $this->probe->encrypt_file_archives( $files ), 'Retries must reject changes to completed ciphertext' );
	}

	public function test_plaintext_never_reaches_upload_dispatch(): void {
		$error = $this->assertThrowsRuntime(
			function () {
				$this->worker()->cloud_backup( array( 'plugins0' => $this->parts[0] ) );
			},
			'Plaintext must never reach upload dispatch'
		);
		$this->assertStringContainsString( 'refused', $error->getMessage(), 'Dispatch must reject plaintext before constructing providers' );
	}

	public function test_resumption_retains_already_uploaded_encrypted_parts_after_local_removal(): void {
		$files                           = $this->encrypt_with_a_retry();
		$this->backupplus->test_uploaded = $files['plugins'];
		foreach ( $files['plugins'] as $file ) {
			unlink( $this->directory . '/' . $file );
		}
		$this->assertSame( $files, $this->probe->encrypt_file_archives( $files ), 'Resumption must retain already uploaded encrypted parts after local removal' );
	}

	public function test_disabled_mode_ignores_a_previously_saved_backup_passphrase(): void {
		BackupPlus_Options::$options['backupplus_database_encryption'] = array( 'mode' => 'none' );
		$worker = $this->worker();
		$this->assertSame( $this->parts[0], $worker->encrypt_file( $this->parts[0] ), 'Disabled mode must ignore a previously saved backup passphrase' );
		$this->assertFileDoesNotExist( $this->directory . '/' . $this->parts[0] . '.crypt', 'Disabled mode must ignore a previously saved backup passphrase' );
	}

	public function test_disabled_encryption_preserves_ordinary_file_backup_behavior(): void {
		BackupPlus_Options::$options['backupplus_database_encryption'] = array( 'mode' => 'none' );
		$this->assertSame( array( 'plugins' => $this->parts[0] ), $this->worker()->encrypt_file_archives( array( 'plugins' => $this->parts[0] ) ), 'Disabled encryption must preserve ordinary file backup behavior' );
		$this->assertFileExists( $this->directory . '/' . $this->parts[0], 'Disabled encryption must preserve ordinary file backup behavior' );
	}

	public function test_encrypted_split_archive_filenames_preserve_their_type_and_index(): void {
		$parsed = BackupPlus_Manipulation_Functions::parse_filename( $this->parts[1] . '.crypt' );
		$this->assertSame( 'plugins', $parsed['type'], 'Encrypted split archive filenames must preserve their type and index' );
		$this->assertSame( 1, $parsed['index'], 'Encrypted split archive filenames must preserve their type and index' );
		$this->assertSame( 'zip.crypt', $parsed['extension'], 'Encrypted split archive filenames must preserve their type and index' );
	}
}
