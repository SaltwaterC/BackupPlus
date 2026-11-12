<?php
/**
 * BPS-11: a logged-out request continues a restore only with the token issued to the restore page, stored as a hash, compared
 * with hash_equals() and valid for a fixed period; job IDs come from random_bytes().
 *
 * scripts/check-restore-continuation.php covers the same rules over HTTP in WordPress.
 */

namespace BackupPlus\Tests\Unit\Restore;

use BackupPlus_Restore_Continuation;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RestoreContinuationTest extends TestCase {

	private const JOB = '0123456789ab';

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/restore-continuation-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-restore-continuation.php';
		update_site_option( 'backupplus_restore_in_progress', self::JOB );
	}

	public function test_issues_a_random_token_and_stores_only_its_hash(): void {
		$before = time();
		$token  = BackupPlus_Restore_Continuation::issue( self::JOB );
		$stored = get_site_option( BackupPlus_Restore_Continuation::OPTION );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/D', $token );
		$this->assertSame( array( 'job_id', 'hash', 'expires' ), array_keys( $stored ) );
		$this->assertSame( self::JOB, $stored['job_id'] );
		$this->assertSame( hash( 'sha256', $token ), $stored['hash'] );
		$this->assertStringNotContainsString( $token, serialize( $stored ) );
		$this->assertGreaterThanOrEqual( $before + 86400, $stored['expires'] );
		$this->assertLessThanOrEqual( time() + 86400, $stored['expires'] );
		$this->assertNotSame( $token, BackupPlus_Restore_Continuation::issue( self::JOB ) );
	}

	public function test_accepts_the_issued_token_for_the_restore_in_progress(): void {
		$token = BackupPlus_Restore_Continuation::issue( self::JOB );
		$this->assertTrue( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
	}

	public function test_refuses_wrong_or_malformed_values(): void {
		$token = BackupPlus_Restore_Continuation::issue( self::JOB );
		foreach ( array(
			'other token'      => array( self::JOB, bin2hex( random_bytes( 32 ) ) ),
			'stored hash'      => array( self::JOB, get_site_option( BackupPlus_Restore_Continuation::OPTION )['hash'] ),
			'upper case'       => array( self::JOB, strtoupper( $token ) ),
			'short token'      => array( self::JOB, substr( $token, 0, 63 ) ),
			'trailing newline' => array( self::JOB, $token . "\n" ),
			'empty token'      => array( self::JOB, '' ),
			'missing token'    => array( self::JOB, null ),
			'array token'      => array( self::JOB, array( $token ) ),
			'other job'        => array( 'ba9876543210', $token ),
			'job prefix'       => array( substr( self::JOB, 0, 11 ), $token ),
			'missing job'      => array( false, $token ),
			'array job'        => array( array( self::JOB ), $token ),
		) as $case => $arguments ) {
			$this->assertFalse( BackupPlus_Restore_Continuation::verify( ...$arguments ), $case );
		}
		$this->assertTrue( BackupPlus_Restore_Continuation::verify( self::JOB, $token ), 'The refusals must not invalidate the token' );
	}

	public function test_refuses_a_token_issued_for_another_job_or_when_no_restore_is_in_progress(): void {
		$token = BackupPlus_Restore_Continuation::issue( self::JOB );
		update_site_option( 'backupplus_restore_in_progress', 'ba9876543210' );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ), 'The token is for a job that is no longer in progress' );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( 'ba9876543210', $token ), 'The token was not issued for the job in progress' );
		delete_site_option( 'backupplus_restore_in_progress' );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
		update_site_option( 'backupplus_restore_in_progress', array( 'nonce' => self::JOB ) );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
	}

	public function test_refuses_an_expired_token(): void {
		$token             = BackupPlus_Restore_Continuation::issue( self::JOB );
		$stored            = get_site_option( BackupPlus_Restore_Continuation::OPTION );
		$stored['expires'] = time();
		$GLOBALS['restore_continuation_options'][ BackupPlus_Restore_Continuation::OPTION ] = $stored;
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
	}

	public function test_a_new_token_replaces_the_previous_one(): void {
		$first  = BackupPlus_Restore_Continuation::issue( self::JOB );
		$second = BackupPlus_Restore_Continuation::issue( self::JOB );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $first ) );
		$this->assertTrue( BackupPlus_Restore_Continuation::verify( self::JOB, $second ) );
	}

	public function test_refuses_a_damaged_stored_value(): void {
		$token = BackupPlus_Restore_Continuation::issue( self::JOB );
		foreach ( array(
			'not an array',
			array( 'job_id' => self::JOB ),
			array(
				'job_id'  => self::JOB,
				'hash'    => array(),
				'expires' => time() + 60,
			),
			array(
				'job_id'  => 1,
				'hash'    => hash( 'sha256', $token ),
				'expires' => time() + 60,
			),
		) as $stored ) {
			$GLOBALS['restore_continuation_options'][ BackupPlus_Restore_Continuation::OPTION ] = $stored;
			$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ), serialize( $stored ) );
		}
	}

	public function test_clear_removes_only_the_token_of_the_given_job(): void {
		$token = BackupPlus_Restore_Continuation::issue( self::JOB );
		BackupPlus_Restore_Continuation::clear( 'ba9876543210' );
		$this->assertTrue( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
		BackupPlus_Restore_Continuation::clear( self::JOB );
		$this->assertFalse( get_site_option( BackupPlus_Restore_Continuation::OPTION ) );
		$this->assertFalse( BackupPlus_Restore_Continuation::verify( self::JOB, $token ) );
		BackupPlus_Restore_Continuation::issue( self::JOB );
		BackupPlus_Restore_Continuation::clear();
		$this->assertFalse( get_site_option( BackupPlus_Restore_Continuation::OPTION ) );
	}

	public function test_refuses_to_issue_for_an_invalid_job_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		BackupPlus_Restore_Continuation::issue( '../x' );
	}

	public function test_job_ids_are_twelve_random_hexadecimal_characters(): void {
		require_once BACKUPPLUS_DIR . '/includes/core/trait-backup-dispatch.php';
		$job  = new class() {
			use \BackupPlus_Core_Backup_Dispatch;
			public $job_time_ms;
			public $backup_time;
			public $file_nonce;
			public $nonce;
		};
		$seen = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$nonce = $job->backup_time_nonce();
			$this->assertMatchesRegularExpression( '/\A[0-9a-f]{12}\z/D', $nonce );
			$seen[ $nonce ] = true;
		}
		// Within one second the old md5( time() . wp_rand() ) could only vary by wp_rand(); these must all differ
		$this->assertCount( 2000, $seen );
		$this->assertSame( 'abcdef012345', $job->backup_time_nonce( 'abcdef012345' ), 'A given job ID is kept' );
		$source = (string) file_get_contents( BACKUPPLUS_DIR . '/includes/core/trait-backup-dispatch.php' );
		$this->assertStringContainsString( 'bin2hex( random_bytes( 6 ) )', $source );
		$this->assertStringNotContainsString( 'wp_rand', $source );
	}

	public function test_only_the_continuation_is_open_to_logged_out_requests(): void {
		$admin = (string) file_get_contents( BACKUPPLUS_DIR . '/admin.php' );
		preg_match_all( "/add_action\\( 'wp_ajax_nopriv_backupplus_ajaxrestore[a-z_]*'/", $admin, $matches );
		$this->assertSame( array( "add_action( 'wp_ajax_nopriv_backupplus_ajaxrestore_continue'" ), $matches[0] );
	}
}
