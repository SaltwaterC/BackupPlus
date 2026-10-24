<?php

/** A persistent native client session. Never reconnect: its lifetime owns backup locks. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_Session {

	private $process;
	private $pipes = array();
	private $stderr;
	private $connection;
	private $backup_lock;

	/** $backup_lock names the engine whose instance backup lock this session will hold, so close() releases it first. */
	public function __construct( $arguments, $directory, $backup_lock = null ) {
		$this->backup_lock = $backup_lock;
		$this->stderr      = $directory . '/session-' . bin2hex( random_bytes( 12 ) ) . '.err';
		BackupPlus_PITR_Process::private_file( $this->stderr );
		$this->process = BackupPlus_PITR_Process::open(
			$arguments,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'file', $this->stderr, 'w' ),
			),
			$this->pipes,
			$directory
		);
		if ( ! is_resource( $this->process ) ) {
			throw new RuntimeException( 'Could not start the database schema guard.' );
		}
		stream_set_blocking( $this->pipes[1], false );
		try {
			$this->connection = $this->query( 'SELECT CONNECTION_ID()', 7 )[0][0];
		} catch ( Throwable $error ) {
			$this->close();
			throw $error;
		}
	}

	public function id() {
		return $this->connection;
	}

	public function query( $sql, $timeout = 5 ) {
		if ( ! is_resource( $this->process ) ) {
			throw new RuntimeException( 'The database schema guard is closed.' );
		}
		$marker = bin2hex( random_bytes( 16 ) );
		$input  = $sql . ";\nSELECT '" . $marker . "';\n";
		$offset = 0;
		while ( $offset < strlen( $input ) ) {
			$written = @fwrite( $this->pipes[0], substr( $input, $offset ) );
			if ( ! $written ) {
				throw new RuntimeException( 'Lost the database schema guard connection.' );
			}
			$offset += $written;
		}
		fflush( $this->pipes[0] );
		$output   = '';
		$deadline = microtime( true ) + $timeout;
		while ( microtime( true ) < $deadline ) {
			$chunk = stream_get_contents( $this->pipes[1] );
			if ( false === $chunk ) {
				throw new RuntimeException( 'Could not read the database schema guard.' );
			}
			$output .= $chunk;
			if ( strlen( $output ) > 8388608 ) {
				throw new RuntimeException( 'Database guard response exceeded its limit.' );
			}
			if ( preg_match( '/(?:\A|\n)' . $marker . '\r?\n\z/', $output ) ) {
				$output = substr( $output, 0, -strlen( $marker ) - 1 );
				$rows   = array();
				foreach ( explode( "\n", rtrim( $output, "\r\n" ) ) as $line ) {
					if ( '' !== $line ) {
						$rows[] = explode( "\t", rtrim( $line, "\r" ) );
					}
				}
				return $rows;
			}
			if ( feof( $this->pipes[1] ) || ! proc_get_status( $this->process )['running'] ) {
				$diagnostic = file_get_contents( $this->stderr, false, null, 0, 8192 );
				throw new RuntimeException( 'Database schema guard failed. ' . BackupPlus_PITR_Process::failure_detail( $diagnostic ) );
			}
			usleep( 20000 );
		}
		throw new RuntimeException( 'Database schema guard timed out.' );
	}

	public function ping() {
		if ( array( array( $this->connection ) ) !== $this->query( 'SELECT CONNECTION_ID()', 2 ) ) {
			throw new RuntimeException( 'Database schema guard connection changed.' );
		}
	}

	public function close() {
		if ( is_resource( $this->process ) ) {
			try {
				if ( 'mysql' === $this->backup_lock ) { $this->query( 'UNLOCK INSTANCE', 2 ); }
				elseif ( 'mariadb' === $this->backup_lock ) { $this->query( 'BACKUP STAGE END', 2 ); }
			} catch ( Throwable $error ) {
				/* Disconnect also releases all session-owned locks. */
			}
			foreach ( $this->pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			proc_terminate( $this->process );
			usleep( 20000 );
			proc_terminate( $this->process, 9 );
			proc_close( $this->process );
			$this->process = null;
		}
		if ( $this->stderr && is_file( $this->stderr ) ) {
			unlink( $this->stderr );
		}
	}

	public function __destruct() {
		$this->close();
	}
}
