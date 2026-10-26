<?php
/** Email delivery behavior; wp_mail is captured and never sends real mail. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_Options;

final class EmailTest extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private string $name = 'backup_database.gz';

	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->create_provider( 'email' );
		file_put_contents( $this->fixture . '/' . $this->name, 'database archive' );
	}

	public function test_recipients_are_split_and_the_archive_is_attached(): void {
		BackupPlus_Options::$options['backupplus_email'] = array( 'first@example.test, second@example.test', 'https://example.test/webhook' );
		$this->provider->backup( array( 'db2' => $this->name ) );
		$this->assertSame( array( 'first@example.test', 'second@example.test' ), array_column( $this->mail, 'to' ), 'Email must split and trim recipients and exclude webhook URLs' );
		$this->assertSame( array( $this->fixture . '/' . $this->name ), $this->mail[0]['attachments'], 'Email must attach the actual archive' );
		$this->assertStringContainsString( 'Provider test site', $this->mail[0]['subject'], 'Email must identify the site and archive type' );
		$this->assertStringContainsString( 'https://example.test (db)', $this->mail[0]['message'], 'Email must identify the site and archive type' );
		$this->assertSame( array( 'From: Backup sender <backups@example.test>' ), $this->mail[0]['headers'], 'Email must pass sender headers to wp_mail' );
		$this->assertSame( array( $this->name ), $this->job->uploaded, 'Email must record a completed archive once, even with multiple recipients' );
		$this->assertEmpty( $this->filters['wp_mail_failed'][10], 'Email delivery failure hooks must be removed after delivery' );
	}

	public function test_failed_deliveries_are_logged_and_not_marked_complete(): void {
		BackupPlus_Options::$options['backupplus_email'] = 'first@example.test';
		$this->mail_success                              = false;
		$this->provider->backup( array( 'db' => $this->name ) );
		$this->assertSame( array(), $this->job->uploaded, 'Email must not mark failed deliveries complete' );
		$this->assertContains( 'error', array_column( $this->job->logs, 1 ), 'Email delivery failure must be logged as an error' );
	}

	public function test_the_delivery_filter_can_prevent_sending(): void {
		BackupPlus_Options::$options['backupplus_email'] = 'first@example.test';
		add_filter(
			'backupplus_email_backup',
			function ( $send ) {
				return false;
			}
		);
		$this->provider->backup( array( 'db' => $this->name ) );
		$this->assertSame( array(), $this->mail, 'Email must honor the delivery filter' );
		$this->assertSame( array(), $this->job->uploaded, 'Email must honor the delivery filter' );
	}

	public function test_no_recipients_neither_sends_nor_records_success(): void {
		BackupPlus_Options::$options['backupplus_email'] = array();
		$this->provider->backup( array( 'db' => $this->name ) );
		$this->assertSame( array(), $this->mail, 'Email without recipients must not send or record success' );
		$this->assertSame( array(), $this->job->uploaded, 'Email without recipients must not send or record success' );
	}

	public function test_oversized_attachments_warn_until_delivered(): void {
		BackupPlus_Options::$options['backupplus_email'] = 'first@example.test';
		file_put_contents( $this->fixture . '/' . $this->name, str_repeat( 'A', BACKUPPLUS_WARN_EMAIL_SIZE + 1 ) );
		clearstatcache();
		$this->provider->backup( array( 'db' => $this->name ) );
		$this->assertContains( 'warning', array_column( $this->job->logs, 1 ), 'Oversized email attachments must produce a warning' );
		$this->assertSame( array( 'toobigforemail_' . md5( $this->name ) ), $this->job->data['removed_warnings'], 'Successful delivery must clear the attachment size warning' );
		$this->assertTrue( $this->provider->delete( $this->name ), 'Email retention deletion must succeed without remote deletion' );
	}
}
