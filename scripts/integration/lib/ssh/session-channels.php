<?php
/**
 * Channel handling of Ssh_Session: open, window and queued output, requests, close. Loaded by session.php.
 */

trait Ssh_Session_Channels {
	private function channel_message( $type, Ssh_Reader $reader ) {
		if ( SSH_MSG_CHANNEL_OPEN === $type ) {
			$kind   = $reader->string();
			$remote = $reader->uint32();
			$window = $reader->uint32();
			$max    = $reader->uint32();
			if ( 'session' !== $kind ) {
				$this->send_packet( chr( SSH_MSG_CHANNEL_OPEN_FAILURE ) . pack( 'N', $remote ) . pack( 'N', 1 ) . ssh_string( 'Administratively prohibited' ) . ssh_string( '' ) );
				return;
			}
			$id                    = $this->next_channel++;
			$this->channels[ $id ] = array(
				'remote'        => $remote,
				'remote_window' => $window,
				'remote_max'    => max( 1, min( $max, 32768 ) ),
				'local_window'  => 2097152,
				'queue'         => array(),
				'sftp'          => null,
				'process'       => null,
				'exit'          => null,
				'eof_received'  => false,
				'close_sent'    => false,
				'client_closed' => false,
			);
			$this->send_packet( chr( SSH_MSG_CHANNEL_OPEN_CONFIRM ) . pack( 'NNNN', $remote, $id, 2097152, 32768 ) );
			return;
		}
		$id = $reader->uint32();
		if ( ! isset( $this->channels[ $id ] ) ) {
			return;
		}
		switch ( $type ) {
			case SSH_MSG_CHANNEL_WINDOW_ADJUST:
				$this->channels[ $id ]['remote_window'] += $reader->uint32();
				$this->channel_flush( $id );
				return;
			case SSH_MSG_CHANNEL_DATA:
				$data = $reader->string();
				$this->channel_input( $id, $data );
				return;
			case SSH_MSG_CHANNEL_EOF:
				$this->channels[ $id ]['eof_received'] = true;
				$this->process_input_done( $id );
				return;
			case SSH_MSG_CHANNEL_CLOSE:
				$this->channels[ $id ]['client_closed'] = true;
				$this->channels[ $id ]['eof_received']  = true;
				$this->channels[ $id ]['queue']         = array();
				// A running command is allowed to finish (it sees end of input); our CLOSE then follows.
				if ( $this->channels[ $id ]['process'] && null !== $this->channels[ $id ]['process']['handle'] ) {
					$this->process_input_done( $id );
					return;
				}
				$this->finish_channel( $id );
				return;
			case SSH_MSG_CHANNEL_REQUEST:
				$this->channel_request( $id, $reader );
				return;
		}
	}

	private function channel_request( $id, Ssh_Reader $reader ) {
		$kind  = $reader->string();
		$reply = $reader->boolean();
		$ok    = false;
		if ( 'subsystem' === $kind && ! $this->server['shell'] && 'sftp' === $reader->string() && null === $this->channels[ $id ]['sftp'] ) {
			$this->channels[ $id ]['sftp'] = new Ssh_Sftp( $this->server['root'] );
			$ok                            = true;
		} elseif ( 'exec' === $kind && $this->server['shell'] && null === $this->channels[ $id ]['process'] ) {
			$ok = $this->start_process( $id, $reader->string() );
		}
		if ( $reply ) {
			$this->send_packet( chr( $ok ? SSH_MSG_CHANNEL_SUCCESS : SSH_MSG_CHANNEL_FAILURE ) . pack( 'N', $this->channels[ $id ]['remote'] ) );
		}
	}

	private function channel_input( $id, $data ) {
		$channel                  = &$this->channels[ $id ];
		$channel['local_window'] -= strlen( $data );
		if ( $channel['local_window'] < 1048576 ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_WINDOW_ADJUST ) . pack( 'NN', $channel['remote'], 2097152 - $channel['local_window'] ) );
			$channel['local_window'] = 2097152;
		}
		if ( $channel['sftp'] ) {
			$output = $channel['sftp']->feed( $data );
			if ( '' !== $output ) {
				$channel['queue'][] = array( false, $output );
				$this->channel_flush( $id );
			}
		} elseif ( $channel['process'] ) {
			$channel['process']['input'] .= $data;
			$this->process_write( $id );
		}
	}

	/** Send queued channel output within the client's window and packet size. */
	private function channel_flush( $id ) {
		$channel = &$this->channels[ $id ];
		while ( $channel['queue'] && $channel['remote_window'] > 0 ) {
			list( $extended, $data ) = $channel['queue'][0];
			$size                    = min( strlen( $data ), $channel['remote_window'], $channel['remote_max'] );
			$this->send_packet(
				$extended ?
				chr( SSH_MSG_CHANNEL_EXTENDED_DATA ) . pack( 'NN', $channel['remote'], 1 ) . ssh_string( substr( $data, 0, $size ) ) :
				chr( SSH_MSG_CHANNEL_DATA ) . pack( 'N', $channel['remote'] ) . ssh_string( substr( $data, 0, $size ) )
			);
			$channel['remote_window'] -= $size;
			if ( strlen( $data ) > $size ) {
				$channel['queue'][0][1] = substr( $data, $size );
			} else {
				array_shift( $channel['queue'] );
			}
		}
		if ( ! $channel['queue'] && null !== $channel['exit'] && ! $channel['close_sent'] ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_REQUEST ) . pack( 'N', $channel['remote'] ) . ssh_string( 'exit-status' ) . "\0" . pack( 'N', $channel['exit'] ) );
			$this->send_packet( chr( SSH_MSG_CHANNEL_EOF ) . pack( 'N', $channel['remote'] ) );
			$this->send_packet( chr( SSH_MSG_CHANNEL_CLOSE ) . pack( 'N', $channel['remote'] ) );
			$channel['close_sent'] = true;
		}
	}

	/** Answer the client's CLOSE if needed and forget the channel. */
	private function finish_channel( $id ) {
		if ( ! $this->channels[ $id ]['close_sent'] ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_CLOSE ) . pack( 'N', $this->channels[ $id ]['remote'] ) );
		}
		$this->release_channel( $id );
		unset( $this->channels[ $id ] );
	}

	private function release_channel( $id ) {
		$channel = $this->channels[ $id ] ?? null;
		if ( ! $channel ) {
			return;
		}
		if ( $channel['sftp'] ) {
			$channel['sftp']->close();
		}
		if ( $channel['process'] ) {
			foreach ( array( 'out', 'err', 'stdin' ) as $name ) {
				if ( is_resource( $channel['process'][ $name ] ) ) {
					$this->loop->remove_reader( $channel['process'][ $name ] );
					$this->loop->remove_writer( $channel['process'][ $name ] );
					fclose( $channel['process'][ $name ] );
				}
			}
			if ( is_resource( $channel['process']['handle'] ) ) {
				proc_terminate( $channel['process']['handle'] );
				proc_close( $channel['process']['handle'] );
			}
		}
		$this->channels[ $id ]['sftp']    = null;
		$this->channels[ $id ]['process'] = null;
	}
}
