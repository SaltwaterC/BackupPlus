<?php
/** Email delivery behavior; wp_mail is captured and never sends real mail. */
require __DIR__ . '/fixtures/provider-test-bootstrap.php';
$provider = provider_create( 'email' );
$name     = 'backup_database.gz';
file_put_contents( $fixture . '/' . $name, 'database archive' );
BackupPlus_Options::$options['backupplus_email'] = array( 'first@example.test, second@example.test', 'https://example.test/webhook' );
$provider_mail                                   = array();
$provider_mail_success                           = true;
$provider->backup( array( 'db2' => $name ) );
provider_expect( array( 'first@example.test', 'second@example.test' ) === array_column( $provider_mail, 'to' ), 'Email must split and trim recipients and exclude webhook URLs' );
provider_expect( array( $fixture . '/' . $name ) === $provider_mail[0]['attachments'], 'Email must attach the actual archive' );
provider_expect( false !== strpos( $provider_mail[0]['subject'], 'Provider test site' ) && false !== strpos( $provider_mail[0]['message'], 'https://example.test (db)' ), 'Email must identify the site and archive type' );
provider_expect( array( 'From: Backup sender <backups@example.test>' ) === $provider_mail[0]['headers'], 'Email must pass sender headers to wp_mail' );
provider_expect( array( $name ) === $backupplus->uploaded, 'Email must record a completed archive once, even with multiple recipients' );
provider_expect( empty( $provider_filters['wp_mail_failed'][10] ), 'Email delivery failure hooks must be removed after delivery' );

$provider_mail_success = false;
$backupplus->uploaded  = array();
$provider->backup( array( 'db' => $name ) );
provider_expect( array() === $backupplus->uploaded, 'Email must not mark failed deliveries complete' );
provider_expect( in_array( 'error', array_column( $backupplus->logs, 1 ), true ), 'Email delivery failure must be logged as an error' );

$provider_mail = array();
add_filter(
	'backupplus_email_backup',
	function ( $send ) {
		return false;
	}
);
$provider->backup( array( 'db' => $name ) );
provider_expect( array() === $provider_mail && array() === $backupplus->uploaded, 'Email must honor the delivery filter' );
unset( $provider_filters['backupplus_email_backup'] );
BackupPlus_Options::$options['backupplus_email'] = array();
$provider->backup( array( 'db' => $name ) );
provider_expect( array() === $provider_mail && array() === $backupplus->uploaded, 'Email without recipients must not send or record success' );

BackupPlus_Options::$options['backupplus_email'] = 'first@example.test';
$provider_mail_success                           = true;
file_put_contents( $fixture . '/' . $name, str_repeat( 'A', BACKUPPLUS_WARN_EMAIL_SIZE + 1 ) );
clearstatcache();
$backupplus->logs = array();
$provider->backup( array( 'db' => $name ) );
provider_expect( in_array( 'warning', array_column( $backupplus->logs, 1 ), true ), 'Oversized email attachments must produce a warning' );
provider_expect( array( 'toobigforemail_' . md5( $name ) ) === $backupplus->data['removed_warnings'], 'Successful delivery must clear the attachment size warning' );
provider_expect( true === $provider->delete( $name ), 'Email retention deletion must succeed without remote deletion' );
echo "PASS: Email recipients, attachments, sender headers, delivery filters, failures and size warnings\n";
