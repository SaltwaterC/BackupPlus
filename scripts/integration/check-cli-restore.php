<?php
/**
 * Hidden terminal restore checks; disposable identities remain in process memory.
 * Drives `wp backupplus restore` inside the development container through a pseudo-terminal.
 */
require __DIR__ . '/lib/support.php';

$runtime       = getenv( 'PODMAN' ) ? getenv( 'PODMAN' ) : 'podman';
$container     = getenv( 'WP_TEST_CONTAINER' ) ? getenv( 'WP_TEST_CONTAINER' ) : 'backupplus-dev-wordpress';
$directory     = '/tmp/backupplus-cli-restore-' . bin2hex( random_bytes( 12 ) );
$base          = array( $runtime, 'exec', $container, 'wp', '--allow-root', '--skip-plugins=backupplus' );
$fixture       = 'wp-content/plugins/backupplus/scripts/check-cli-restore.php';
$secret_values = array();

function cli_helper( $action ) {
	global $base, $fixture, $directory;
	$result = integration_run( array_merge( $base, array( 'eval-file', $fixture, $action, $directory ) ) );
	integration_expect( 0 === $result['status'], 'Encrypted restore fixture operation failed: ' . $action );
	return $result['stdout'];
}

function cli_no_secrets( $data ) {
	global $secret_values;
	foreach ( $secret_values as $value ) {
		integration_expect( false === strpos( $data, $value ), 'A restore secret appeared in output or persisted state' );
	}
}

function cli_report( $expected ) {
	$state = json_decode( cli_helper( 'report' ), true );
	cli_no_secrets( $state['state'] );
	integration_expect( $state['file'] === $expected && $state['table'] === $expected, 'Restore must change both selected components only after all archives decrypt' );
	integration_expect( empty( $state['staging'] ), 'Decrypted staging files must be removed on success and failure' );
}

function cli_command( $timestamp ) {
	global $directory;
	return array(
		'wp',
		'--allow-root',
		'--skip-plugins=backupplus',
		'--exec=putenv(' . json_encode( 'BACKUPPLUS_CLI_TEST_DIR=' . $directory, JSON_UNESCAPED_SLASHES ) . ');',
		'--require=/workspace/backupplus/scripts/fixtures/cli.php',
		'backupplus',
		'restore',
		(string) $timestamp,
		'--yes',
	);
}

/** Run an interactive restore on a pseudo-terminal and answer the hidden prompts. */
function cli_terminal_restore( $timestamp, $identity, $password, $interrupt = false ) {
	global $runtime, $container;
	$process = proc_open(
		array_merge( array( $runtime, 'exec', '-it', $container ), cli_command( $timestamp ) ),
		array(
			0 => array( 'pty' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	integration_expect( is_resource( $process ), 'Unable to start the interactive restore' );
	foreach ( $pipes as $pipe ) {
		stream_set_blocking( $pipe, false );
	}
	$transcript    = '';
	$sent_key      = false;
	$sent_password = false;
	$exit          = null;
	$deadline      = microtime( true ) + ( $interrupt ? 15 : 120 );
	$collect       = function () use ( &$pipes, &$transcript ) {
		foreach ( $pipes as $pipe ) {
			if ( ! is_resource( $pipe ) ) {
				continue;
			}
			while ( true ) {
				$chunk = @fread( $pipe, 65536 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$transcript .= $chunk;
			}
		}
		cli_no_secrets( $transcript );
	};
	try {
		while ( null === $exit ) {
			integration_expect( microtime( true ) < $deadline, 'Interactive restore did not finish' );
			$read   = array_values( array_filter( $pipes, 'is_resource' ) );
			$write  = null;
			$except = null;
			@stream_select( $read, $write, $except, 0, 200000 );
			$collect();
			if ( ! $sent_key && false !== strpos( $transcript, 'Private key (hidden;' ) ) {
				fwrite( $pipes[0], $identity . ( $interrupt ? "\x03" : "\n" ) );
				$sent_key = true;
			}
			if ( $sent_key && ! $sent_password && false !== strpos( $transcript, 'Private key passphrase (hidden;' ) ) {
				fwrite( $pipes[0], $password . "\n" );
				$sent_password = true;
			}
			// The exit code is only reported by the first status call after the process ends.
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$exit = $status['exitcode'];
			}
		}
		$collect();
		integration_expect( $sent_key && ( $sent_password || $interrupt ), 'Both hidden secret prompts must be shown' );
		return $exit;
	} finally {
		if ( null === $exit ) {
			proc_terminate( $process );
		}
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		proc_close( $process );
	}
}

try {
	// Setup communicates its generated identities solely through a captured pipe.
	echo "Checking encrypted restore prompts and cancellation...\n";
	$setup          = json_decode( cli_helper( 'setup' ), true );
	$password       = $setup['passphrase'];
	$wrong_password = 'wrong-restore-secret-' . bin2hex( random_bytes( 8 ) );
	$secret_values  = array( $password, $wrong_password );
	foreach ( array( 'identity', 'unencrypted_identity' ) as $field ) {
		foreach ( preg_split( '/\r?\n/', $setup[ $field ] ) as $line ) {
			if ( '' !== $line && 0 !== strpos( $line, '-----' ) ) {
				$secret_values[] = $line;
			}
		}
	}
	$timestamp = $setup['timestamp'];
	// No command-line secret flags or piped-stdin fallback; --yes bypasses confirmation only.
	$noninteractive = integration_run( array_merge( array( $runtime, 'exec', $container ), cli_command( $timestamp ) ) );
	integration_expect( 0 !== $noninteractive['status'], 'Encrypted restores must require a terminal' );
	cli_no_secrets( $noninteractive['stdout'] . $noninteractive['stderr'] );
	cli_report( 'LIVE' );
	$cancel_probe    = 'cancelled-secret-' . bin2hex( random_bytes( 8 ) );
	$secret_values[] = $cancel_probe;
	integration_expect( 0 !== cli_terminal_restore( $timestamp, $cancel_probe, '', true ), 'Cancelling secret entry must abort the restore' );
	cli_report( 'LIVE' );
	integration_expect( 0 !== cli_terminal_restore( $timestamp, $setup['identity'], $wrong_password ), 'A wrong private-key passphrase must fail' );
	cli_report( 'LIVE' );
	integration_expect( 0 === cli_terminal_restore( $timestamp, $setup['identity'], $password ), 'The encrypted private key and passphrase must restore the selected file and table' );
	cli_report( 'BACKUP' );
	cli_helper( 'reset' );
	integration_expect( 0 === cli_terminal_restore( $timestamp, "# identity comment\n" . $setup['unencrypted_identity'], '' ), 'An unencrypted multiline identity must accept an empty passphrase' );
	cli_report( 'BACKUP' );
	cli_helper( 'reset' );
	cli_helper( 'tamper' );
	integration_expect( 0 !== cli_terminal_restore( $timestamp, $setup['identity'], $password ), 'A corrupted archive must fail authentication before any selected component is restored' );
	cli_report( 'LIVE' );
	echo "PASS: CLI encrypted file/database restores, hidden multiline prompts, wrong passwords, authentication failures and secret non-persistence\n";
} finally {
	cli_helper( 'cleanup' );
	// Keep no secret files, environment variables or command-line arguments to clean up.
	$secret_values = array();
}
