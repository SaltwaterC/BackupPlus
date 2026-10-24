<?php
/**
 * Helpers shared by the host-side integration tests.
 * Servers run as child PHP processes; closing their stdin stops them.
 */
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

function integration_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function integration_temp_dir( $prefix ) {
	$directory = sys_get_temp_dir() . '/' . $prefix . bin2hex( random_bytes( 6 ) );
	mkdir( $directory, 0700 );
	return realpath( $directory );
}

function integration_remove_tree( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	chmod( $path, 0700 );
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			integration_remove_tree( $path . '/' . $entry );
		}
	}
	rmdir( $path );
}

/** Run a command to completion; returns exit status, stdout and stderr. */
function integration_run( $command, $input = '', $environment = null, $timeout = 120 ) {
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		null,
		$environment
	);
	integration_expect( is_resource( $process ), 'Unable to start ' . ( is_array( $command ) ? $command[0] : $command ) );
	fwrite( $pipes[0], $input );
	fclose( $pipes[0] );
	$output   = array(
		1 => '',
		2 => '',
	);
	$open     = array(
		1 => $pipes[1],
		2 => $pipes[2],
	);
	$deadline = microtime( true ) + $timeout;
	while ( $open ) {
		$read   = $open;
		$write  = null;
		$except = null;
		if ( microtime( true ) > $deadline ) {
			proc_terminate( $process, 9 );
			proc_close( $process );
			throw new RuntimeException( 'Command timed out: ' . ( is_array( $command ) ? implode( ' ', $command ) : $command ) );
		}
		if ( false === stream_select( $read, $write, $except, 1 ) ) {
			continue;
		}
		foreach ( $read as $stream ) {
			$index = array_search( $stream, $open, true );
			$chunk = fread( $stream, 65536 );
			if ( '' === $chunk || false === $chunk ) {
				if ( feof( $stream ) ) {
					fclose( $stream );
					unset( $open[ $index ] );
				}
				continue;
			}
			$output[ $index ] .= $chunk;
		}
	}
	return array(
		'status' => proc_close( $process ),
		'stdout' => $output[1],
		'stderr' => $output[2],
	);
}

/** Wrapper around a child server process speaking a line protocol on stdin/stdout. */
class Integration_Server {
	public $info;
	private $process;
	private $pipes;

	public static function start( $script, $arguments = array() ) {
		$server          = new self();
		$server->process = proc_open(
			array_merge( array( PHP_BINARY, '-d', 'error_reporting=24575', $script ), $arguments ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => STDERR,
			),
			$server->pipes
		);
		integration_expect( is_resource( $server->process ), 'Unable to start ' . basename( $script ) );
		$server->info = json_decode( $server->read_line(), true );
		integration_expect( is_array( $server->info ), basename( $script ) . ' did not report its listeners' );
		return $server;
	}

	private function read_line() {
		$line     = '';
		$deadline = microtime( true ) + 60;
		while ( false === strpos( $line, "\n" ) ) {
			$read   = array( $this->pipes[1] );
			$write  = null;
			$except = null;
			integration_expect( microtime( true ) < $deadline, 'Timed out waiting for the test server' );
			if ( stream_select( $read, $write, $except, 1 ) ) {
				$chunk = fread( $this->pipes[1], 8192 );
				integration_expect( '' !== $chunk && false !== $chunk, 'The test server exited unexpectedly' );
				$line .= $chunk;
			}
		}
		return $line;
	}

	/** Server-reported counters and violations. */
	public function state() {
		fwrite( $this->pipes[0], "state\n" );
		return json_decode( $this->read_line(), true );
	}

	public function stop() {
		if ( ! $this->process ) {
			return;
		}
		fclose( $this->pipes[0] );
		fclose( $this->pipes[1] );
		$deadline = microtime( true ) + 5;
		while ( proc_get_status( $this->process )['running'] && microtime( true ) < $deadline ) {
			usleep( 50000 );
		}
		if ( proc_get_status( $this->process )['running'] ) {
			proc_terminate( $this->process, 9 );
		}
		proc_close( $this->process );
		$this->process = null;
	}
}

/**
 * Issue a PEM certificate and key. Without an issuer the certificate is a self-signed CA;
 * otherwise it is a localhost server certificate signed by the issuer.
 *
 * @return array{certificate: string, key: string, path: string, key_path: string, private: object, dn: string}
 */
function integration_issue_certificate( $directory, $name, $issuer = null, $expired = false ) {
	require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	$private = RSA::createKey( 2048 )->withPadding( RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1 );
	$subject = new X509();
	$subject->setDN( 'CN=' . $name );
	$subject->setPublicKey( $private->getPublicKey() );
	$authority = new X509();
	$authority->setDN( $issuer ? $issuer['dn'] : 'CN=' . $name );
	$authority->setPrivateKey( $issuer ? $issuer['private'] : $private );
	$signer = new X509();
	$signer->setStartDate( '-10 days' );
	$signer->setEndDate( $expired ? '-1 day' : '+1 day' );
	$signer->setSerialNumber( bin2hex( random_bytes( 8 ) ), 16 );
	if ( $issuer ) {
		$subject->setDomain( 'localhost' );
		$signer->setExtension( 'id-ce-extKeyUsage', array( 'id-kp-serverAuth' ) );
	} else {
		$signer->makeCA();
	}
	$signed = $signer->sign( $authority, $subject, 'sha256WithRSAEncryption' );
	integration_expect( is_array( $signed ), 'Unable to sign the ' . $name . ' certificate' );
	$certificate = $signer->saveX509( $signed );
	$path        = $directory . '/' . $name . '.pem';
	$key_path    = $directory . '/' . $name . '.key';
	file_put_contents( $path, $certificate );
	file_put_contents( $key_path, $private->toString( 'PKCS8' ) );
	chmod( $key_path, 0600 );
	return array(
		'certificate' => $certificate,
		'path'        => $path,
		'key_path'    => $key_path,
		'private'     => $private,
		'dn'          => 'CN=' . $name,
	);
}

/** Run a command with this process's stdout and stderr; returns its exit status. */
function integration_stream( $command, $environment = null ) {
	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => STDOUT,
			2 => STDERR,
		),
		$pipes,
		null,
		$environment
	);
	integration_expect( is_resource( $process ), 'Unable to start ' . ( is_array( $command ) ? $command[0] : $command ) );
	return proc_close( $process );
}

function integration_find_executable( $name ) {
	foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $directory ) {
		if ( is_file( $directory . '/' . $name ) && is_executable( $directory . '/' . $name ) ) {
			return $directory . '/' . $name;
		}
	}
	return false;
}
