<?php
/**
 * Minimal single-process, non-blocking socket loop for the disposable test servers.
 * Every server also watches stdin: EOF stops it and "state" prints its counters as JSON.
 */
class Integration_Loop {
	private $readers = array();
	private $writers = array();
	private $running = true;
	public $state    = array();

	public function on_read( $stream, $callback ) {
		$this->readers[ (int) $stream ] = array( $stream, $callback );
	}

	public function on_write( $stream, $callback ) {
		$this->writers[ (int) $stream ] = array( $stream, $callback );
	}

	public function remove_reader( $stream ) {
		unset( $this->readers[ (int) $stream ] );
	}

	public function remove_writer( $stream ) {
		unset( $this->writers[ (int) $stream ] );
	}

	public function stop() {
		$this->running = false;
	}

	/** Report listeners to the parent, then serve until stdin closes. */
	public function run( $info, $state_callback ) {
		fwrite( STDOUT, json_encode( $info ) . "\n" );
		stream_set_blocking( STDIN, false );
		$control = '';
		$this->on_read(
			STDIN,
			function ( $stream ) use ( &$control, $state_callback ) {
				$chunk = fread( $stream, 4096 );
				if ( '' === $chunk || false === $chunk ) {
					if ( feof( $stream ) ) {
						$this->stop();
					}
					return;
				}
				$control .= $chunk;
				while ( false !== ( $newline = strpos( $control, "\n" ) ) ) {
					$command = trim( substr( $control, 0, $newline ) );
					$control = substr( $control, $newline + 1 );
					if ( 'state' === $command ) {
						fwrite( STDOUT, json_encode( $state_callback() ) . "\n" );
					}
				}
			}
		);
		while ( $this->running ) {
			$read   = array();
			$write  = array();
			$except = null;
			foreach ( $this->readers as $entry ) {
				$read[] = $entry[0];
			}
			foreach ( $this->writers as $entry ) {
				$write[] = $entry[0];
			}
			if ( false === @stream_select( $read, $write, $except, 1 ) ) {
				continue;
			}
			foreach ( $read as $stream ) {
				if ( isset( $this->readers[ (int) $stream ] ) ) {
					call_user_func( $this->readers[ (int) $stream ][1], $stream );
				}
			}
			foreach ( $write as $stream ) {
				if ( isset( $this->writers[ (int) $stream ] ) ) {
					call_user_func( $this->writers[ (int) $stream ][1], $stream );
				}
			}
		}
	}
}

/** A buffered non-blocking connection owned by a loop. */
class Integration_Connection {
	public $stream;
	public $in      = '';
	public $out     = '';
	public $closed  = false;
	public $closing = false;
	/** Whether a 100-continue interim response was already sent for the pending request. */
	public $continued = false;
	private $loop;
	private $on_data;
	private $on_close;

	public function __construct( $loop, $stream, $on_data, $on_close = null ) {
		$this->loop     = $loop;
		$this->stream   = $stream;
		$this->on_data  = $on_data;
		$this->on_close = $on_close;
		stream_set_blocking( $stream, false );
		$loop->on_read( $stream, array( $this, 'readable' ) );
	}

	/** Drain the stream completely: TLS streams can hold bytes the socket no longer reports. */
	public function readable() {
		$eof = false;
		while ( true ) {
			$chunk = @fread( $this->stream, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				$eof = false === $chunk || feof( $this->stream );
				break;
			}
			$this->in .= $chunk;
		}
		if ( '' !== $this->in ) {
			call_user_func( $this->on_data, $this );
		}
		if ( $eof ) {
			$this->close();
		}
	}

	public function send( $data ) {
		if ( $this->closed ) {
			return;
		}
		$this->out .= $data;
		$this->flush();
	}

	public function flush() {
		while ( '' !== $this->out && ! $this->closed ) {
			$written = @fwrite( $this->stream, $this->out );
			if ( false === $written ) {
				$this->close();
				return;
			}
			if ( 0 === $written ) {
				$this->loop->on_write( $this->stream, array( $this, 'flush' ) );
				return;
			}
			$this->out = (string) substr( $this->out, $written );
		}
		if ( $this->closed ) {
			return;
		}
		$this->loop->remove_writer( $this->stream );
		if ( $this->closing ) {
			$this->close();
		}
	}

	/** Close once every queued byte has been written. */
	public function close_after_flush() {
		$this->closing = true;
		$this->flush();
	}

	public function close() {
		if ( $this->closed ) {
			return;
		}
		$this->closed = true;
		$this->loop->remove_reader( $this->stream );
		$this->loop->remove_writer( $this->stream );
		@fclose( $this->stream );
		if ( $this->on_close ) {
			call_user_func( $this->on_close, $this );
		}
	}
}
