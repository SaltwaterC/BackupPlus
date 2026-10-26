<?php
/**
 * Base class of the Dropbox credential encryption and key store tests.
 *
 * Each test runs in its own process with the WordPress shims of dropbox-encryption-stubs.php, so the concrete classes
 * carry the process isolation attributes.
 */

namespace BackupPlus\Tests\Support;

use DropboxTestBackupPlus;
use Exception;
use PHPUnit\Framework\TestCase;

abstract class CredentialEncryptionTestCase extends TestCase {

	protected DropboxTestBackupPlus $backupplus;

	/** A private temporary directory, removed after the test. */
	protected string $fixture;

	protected function setUp(): void {
		require_once __DIR__ . '/dropbox-encryption-stubs.php';
		dropbox_stubs_reset();
		$this->backupplus = $GLOBALS['backupplus'];
		$this->fixture    = sys_get_temp_dir() . '/backupplus-dropbox-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture, 0700 );
	}

	protected function tearDown(): void {
		self::remove_tree( $this->fixture );
	}

	protected static function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( scandir( $path ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				self::remove_tree( $path . '/' . $file );
			}
		}
		rmdir( $path );
	}

	/** Asserts that the callback throws an Exception. */
	protected function assert_fails( callable $callback, string $message ): void {
		try {
			$callback();
		} catch ( Exception $e ) {
			$this->assertInstanceOf( Exception::class, $e, $message );
			return;
		}
		$this->fail( $message );
	}

	/**
	 * Runs a mode of dropbox-encryption-child.php in a fresh PHP process.
	 *
	 * @param string[] $arguments The mode and its arguments.
	 * @param string[] $options   Further PHP options (-d ...).
	 * @return array{status: int, stdout: string, stderr: string}
	 */
	protected function run_child( array $arguments, array $options = array() ): array {
		$command = array_merge( array( PHP_BINARY, '-d', 'error_reporting=24575' ), $options, array( __DIR__ . '/dropbox-encryption-child.php' ), $arguments );
		$process = $this->start_child( $command );
		return $this->finish_child( $process );
	}

	/** @return array{0: resource, 1: array<int, resource>} */
	protected function start_child( array $command, string $message = 'The child process must start' ): array {
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process, $message );
		return array( $process, $pipes );
	}

	/**
	 * @param array{0: resource, 1: array<int, resource>} $child
	 * @return array{status: int, stdout: string, stderr: string}
	 */
	protected function finish_child( array $child ): array {
		$stdout = stream_get_contents( $child[1][1] );
		$stderr = stream_get_contents( $child[1][2] );
		fclose( $child[1][1] );
		fclose( $child[1][2] );
		return array(
			'status' => proc_close( $child[0] ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}
}
