<?php
/** Native process transport, credential files and failure diagnostics without a database. */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_PITR_Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrProcessTest extends PitrTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-process.php';
	}

	/** A client option file as the restore uses it. */
	private function connection( string $contents, int $mode = 0600 ): string {
		$connection = $this->directory . '/source.cnf';
		file_put_contents( $connection, $contents );
		chmod( $connection, $mode );
		return $connection;
	}

	public function test_memory_credentials_are_passed_through_a_pipe_and_can_be_forgotten(): void {
		$pipe_password = bin2hex( random_bytes( 16 ) ) . '\\"special';
		$memory        = BackupPlus_PITR_Process::memory_connection(
			array(
				'user'     => 'restore_test',
				'password' => $pipe_password,
			)
		);
		$this->assertSame( $pipe_password, BackupPlus_PITR_Process::connection_options( $memory )['password'], 'Memory credentials changed password escaping.' );
		$before_pipe = glob( $this->directory . '/*' );
		$pipe_hash   = BackupPlus_PITR_Process::run( array( '/bin/sh', '-c', 'sha256sum <&3', '--defaults-file=' . $memory ), $this->directory );
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\s/', $pipe_hash, 'Memory credential transport created persistent files.' );
		$this->assertSame( $before_pipe, glob( $this->directory . '/*' ), 'Memory credential transport created persistent files.' );
		BackupPlus_PITR_Process::forget_connection( $memory );
		$this->assertRejected(
			function () use ( $memory ) {
				BackupPlus_PITR_Process::run( array( '/bin/sh', '-c', 'sha256sum <&3', '--defaults-file=' . $memory ), $this->directory );
			},
			'Forgotten restore credentials remained reusable.'
		);
	}

	public function test_world_readable_credentials_are_rejected(): void {
		$connection = $this->connection( "[client]\nuser=root\nhost=127.0.0.1\n", 0644 );
		$this->assertRejected(
			function () use ( $connection ) {
				BackupPlus_PITR_Process::connection_file( $connection, $this->directory );
			},
			'World-readable credentials accepted.'
		);
	}

	public function test_owner_only_local_credentials_are_accepted(): void {
		$connection = $this->connection( "[client]\nuser=root\nhost=127.0.0.1\n" );
		BackupPlus_PITR_Process::connection_file( $connection, $this->directory );
		$this->addToAssertionCount( 1 );
	}

	public function test_unverified_remote_transport_is_rejected(): void {
		$connection = $this->connection( "[client]\nuser=root\nhost=remote.example\n" );
		$this->assertRejected(
			function () use ( $connection ) {
				BackupPlus_PITR_Process::connection_file( $connection, $this->directory );
			},
			'Unverified remote transport accepted.'
		);
	}

	public function test_client_behavior_overrides_are_rejected(): void {
		$connection = $this->connection( "[client]\nuser=root\nhost=127.0.0.1\ninit-command=DROP DATABASE wordpress\n" );
		$this->assertRejected(
			function () use ( $connection ) {
				BackupPlus_PITR_Process::connection_file( $connection, $this->directory );
			},
			'Client behavior override accepted.'
		);
	}

	public function test_native_output_is_returned(): void {
		$this->assertSame( 'done', BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'echo "done";' ), $this->directory ), 'Native output was lost.' );
	}

	public static function diagnostics(): iterable {
		foreach (
			array(
				'ERROR 2026 (HY000): TLS/SSL error: self-signed certificate' => 'issuing CA is not trusted',
				'ERROR 2026 (HY000): SSL connection error: hostname does not match certificate' => 'does not match the database hostname',
				'ERROR 2026 (HY000): TLS/SSL error: certificate has expired' => 'expired or not yet valid',
				'ERROR 2026 (HY000): TLS/SSL error: unsupported protocol' => 'TLS connection failed',
				'ERROR 1045 (28000): Access denied'      => 'authentication failed',
				'ERROR 1227 (42000): Access denied; RELOAD required' => 'needs the RELOAD privilege',
				'ERROR 1419 (HY000): stored program creation denied' => 'restore account needs SUPER',
				'ERROR 1064 (42000): malformed query'    => 'Database error 1064',
				'ERROR 2002 (HY000): Connection refused' => 'Could not reach the database',
				'ERROR 2013 (HY000): Lost connection'    => 'connection was lost',
				'Flush logs or delete master logs failure in server' => 'Database log rotation failed',
			) as $diagnostic => $expected
		) {
			yield $diagnostic => array( $diagnostic, $expected );
		}
	}

	#[DataProvider( 'diagnostics' )]
	public function test_native_diagnostics_are_actionable_and_never_leak_secrets( string $diagnostic, string $expected ): void {
		$child   = 'fwrite(STDERR, ' . var_export( $diagnostic . "\npassword=private-secret; SELECT private_data FROM customer_table", true ) . '); exit(1);';
		$error   = $this->assertRejected(
			function () use ( $child ) {
				BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', $child ), $this->directory );
			},
			'A failed native client was accepted.'
		);
		$message = $error->getMessage();
		$this->assertStringContainsString( $expected, $message, 'Native diagnostics hid the actionable failure: ' . $expected );
		$this->assertStringNotContainsString( 'private-secret', $message, 'Native diagnostics leaked credentials or SQL.' );
		$this->assertStringNotContainsString( 'customer_table', $message, 'Native diagnostics leaked credentials or SQL.' );
	}

	public function test_a_nonzero_exit_is_rejected(): void {
		$this->assertRejected(
			function () {
				BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'exit(7);' ), $this->directory );
			},
			'Native nonzero exit accepted.'
		);
	}

	public function test_a_timed_out_child_is_rejected(): void {
		$this->assertRejected(
			function () {
				BackupPlus_PITR_Process::run( array( PHP_BINARY, '-r', 'sleep(5);' ), $this->directory, null, null, 0.1 );
			},
			'Timed-out child accepted.'
		);
	}
}
