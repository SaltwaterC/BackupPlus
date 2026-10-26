<?php
/**
 * Command execution (the SCP shell servers) of Ssh_Session: child process pipes and exit status.
 * Loaded by session.php.
 */

trait Ssh_Session_Exec {
	private function start_process( $id, $command ) {
		ssh_log( 'exec: ' . $command );
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->server['root']
		);
		if ( ! is_resource( $process ) ) {
			return false;
		}
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$this->channels[ $id ]['process'] = array(
			'handle' => $process,
			'stdin'  => $pipes[0],
			'out'    => $pipes[1],
			'err'    => $pipes[2],
			'input'  => '',
			'done'   => false,
		);
		foreach ( array( 1, 2 ) as $index ) {
			$this->loop->on_read(
				$pipes[ $index ],
				function ( $stream ) use ( $id, $index ) {
					$this->process_output( $id, $stream, 2 === $index );
				}
			);
		}
		return true;
	}

	private function process_output( $id, $stream, $extended ) {
		if ( ! isset( $this->channels[ $id ] ) ) {
			$this->loop->remove_reader( $stream );
			return;
		}
		$chunk = fread( $stream, 32768 );
		if ( '' !== $chunk && false !== $chunk ) {
			if ( $extended ) {
				ssh_log( 'stderr: ' . $chunk );
			}
			if ( ! $this->channels[ $id ]['client_closed'] ) {
				$this->channels[ $id ]['queue'][] = array( $extended, $chunk );
				$this->channel_flush( $id );
			}
			return;
		}
		if ( feof( $stream ) ) {
			$this->loop->remove_reader( $stream );
			$process = &$this->channels[ $id ]['process'];
			fclose( $stream );
			$process[ $extended ? 'err' : 'out' ] = null;
			if ( null === $process['out'] && null === $process['err'] ) {
				$this->process_finished( $id );
			}
		}
	}

	private function process_finished( $id ) {
		$process = &$this->channels[ $id ]['process'];
		$this->process_close_input( $id );
		$status                        = proc_close( $process['handle'] );
		$process['handle']             = null;
		$this->channels[ $id ]['exit'] = $status < 0 ? 255 : $status;
		ssh_log( 'exit ' . $status );
		if ( $this->channels[ $id ]['client_closed'] ) {
			$this->finish_channel( $id );
			return;
		}
		$this->channel_flush( $id );
	}

	private function process_write( $id ) {
		$process = &$this->channels[ $id ]['process'];
		if ( ! $process || ! is_resource( $process['stdin'] ) ) {
			return;
		}
		while ( '' !== $process['input'] ) {
			$written = @fwrite( $process['stdin'], $process['input'] );
			if ( ! $written ) {
				$this->loop->on_write(
					$process['stdin'],
					function () use ( $id ) {
						$this->process_write( $id );
					}
				);
				return;
			}
			$process['input'] = (string) substr( $process['input'], $written );
		}
		$this->loop->remove_writer( $process['stdin'] );
		$this->process_input_done( $id );
	}

	private function process_input_done( $id ) {
		$channel = $this->channels[ $id ];
		if ( $channel['process'] && $channel['eof_received'] && '' === $channel['process']['input'] ) {
			$this->process_close_input( $id );
		}
	}

	private function process_close_input( $id ) {
		$process = &$this->channels[ $id ]['process'];
		if ( $process && is_resource( $process['stdin'] ) ) {
			$this->loop->remove_writer( $process['stdin'] );
			fclose( $process['stdin'] );
		}
	}
}
