<?php
/**
 * Base class for the age tests.
 *
 * The real encryption, restore and backup writer code runs against shims for the WordPress functions and a job object
 * (age-stubs.php). The classes run every test in its own process, so the shims are loaded once per test. With
 * BACKUPPLUS_TEST_PORTABLE set, the extensions that provide the fast crypto are hidden from phpseclib and the pure PHP
 * engines are forced; the tests that make no sense there are left out of that run by class.
 */

namespace BackupPlus\Tests\Support;

use AgeTestBackupPlus;
use AgeTestState;
use BackupPlus_Backup;
use BackupPlus_Age_Restore;
use BackupPlus_Encryption;
use BackupPlus_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

abstract class AgeTestCase extends TestCase {

	protected string $directory = '';

	protected array $fixture = array();

	protected string $password = '';

	/** The plaintext of the reference ciphertexts. */
	protected string $plain = '';

	/** Where a backup file under test is placed. */
	protected string $path = '';

	protected AgeTestBackupPlus $backupplus;

	/** The reference key set that is not tied to an SSH key. */
	protected array $native = array();

	/** Whether the run hides the fast crypto extensions. */
	protected static function portable(): bool {
		return (bool) getenv( 'BACKUPPLUS_TEST_PORTABLE' );
	}

	/** Whether the tests of the class make sense in a portable run. */
	protected static function runs_portable(): bool {
		return false;
	}

	public static function root(): string {
		return dirname( __DIR__, 2 );
	}

	protected function setUp(): void {
		if ( self::portable() && ! static::runs_portable() ) {
			$this->markTestSkipped( 'Not part of the portable run' );
		}
		require_once __DIR__ . '/age-stubs.php';
		AgeTestState::reset();
		BackupPlus_Options::$options = array();

		$this->backupplus      = new AgeTestBackupPlus();
		$GLOBALS['backupplus'] = $this->backupplus;
		$this->assertSame( true, $this->backupplus->ensure_phpseclib(), 'Crypto loader must succeed' );
		if ( self::portable() ) {
			\phpseclib3\Math\BigInteger::setEngine( PHP_INT_SIZE >= 8 ? 'PHP64' : 'PHP32' );
			\phpseclib3\Crypt\EC::forceEngine( 'PHP' );
			\phpseclib3\Crypt\RSA::forceEngine( 'PHP' );
		}

		$this->directory = sys_get_temp_dir() . '/backupplus-age-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0700 );
		$this->backupplus->directory = $this->directory;

		$this->fixture  = self::fixture_data();
		$this->password = $this->fixture['passphrase'];
		$this->plain    = base64_decode( $this->fixture['plaintext'] );
		$this->native   = $this->fixture['keys']['native'];
		$this->path     = $this->directory . '/backup_2026-10-22-1200_fixture_0123456789ab-db.gz.crypt';
	}

	protected function tearDown(): void {
		if ( '' === $this->directory ) {
			return;
		}
		foreach ( glob( $this->directory . '/*' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $this->directory );
	}

	/** The independently generated keys, ciphertexts and plaintext. */
	public static function fixture_data(): array {
		return json_decode( file_get_contents( self::root() . '/scripts/fixtures/age-keys.json' ), true );
	}

	/** The real backup writer, without the WordPress bootstrap its constructor needs. */
	protected function worker(): BackupPlus_Backup {
		return ( new ReflectionClass( 'BackupPlus_Backup' ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Decrypt the file at $this->path the way a restore does, after placing $raw there and a destination to preserve.
	 * The decryption must fail, keep an existing destination and leave no staged plaintext behind.
	 */
	protected function assertRejected( string $raw, string $identity, string $passphrase, string $message ): void {
		file_put_contents( $this->path, $raw );
		$destination = substr( $this->path, 0, -6 );
		file_put_contents( $destination, 'preserve destination' );
		$this->assertFalse( BackupPlus_Encryption::decrypt( $this->path, $identity, false, $passphrase ), $message );
		$this->assertSame( 'preserve destination', file_get_contents( $destination ), 'Rejection must preserve an existing destination' );
		$this->assertSame( array(), glob( $this->directory . '/decrypt_*.tmp' ), 'Rejection must remove staged plaintext' );
	}

	/** Simulate a browser upload after independent decryption (no key enters the restore API). */
	protected function prepare_browser( array $backup, int $timestamp, string $identity, string $passphrase, array $entities = array( 'db' ), string $archive_passphrase = '' ): string {
		$manifest = BackupPlus_Age_Restore::manifest( $backup, $timestamp, $entities );
		foreach ( $manifest['files'] as $file ) {
			$path   = $this->backupplus->backups_dir_location() . '/' . $file;
			$is_age = BackupPlus_Age_Restore::is_age( $path );
			$plain  = BackupPlus_Encryption::decrypt( $path, $is_age ? $identity : $archive_passphrase, true, $passphrase );
			$this->assertIsArray( $plain, 'Test browser simulation must decrypt the archive' );
			$bytes = file_get_contents( $plain['fullpath'] );
			unlink( $plain['fullpath'] );
			BackupPlus_Age_Restore::upload( $manifest['ticket'], $file, 0, $bytes, strlen( $bytes ), hash( 'sha256', $bytes ) );
		}
		return BackupPlus_Age_Restore::prepare( $backup, $timestamp, $manifest['ticket'], $entities );
	}

	/** Run the callback, which must throw a RuntimeException. */
	protected function assertThrowsRuntime( callable $callback, string $message ): RuntimeException {
		try {
			$callback();
		} catch ( RuntimeException $error ) {
			$this->addToAssertionCount( 1 );
			return $error;
		}
		$this->fail( $message );
	}
}
