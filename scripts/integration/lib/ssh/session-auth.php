<?php
/**
 * Password and public key authentication of Ssh_Session. Loaded by session.php.
 */

trait Ssh_Session_Auth {
	private function authenticate( Ssh_Reader $reader ) {
		$user    = $reader->string();
		$service = $reader->string();
		$method  = $reader->string();
		$state   = $this->server['state'];
		if ( 'ssh-connection' !== $service ) {
			$this->auth_failure();
			return;
		}
		if ( 'password' === $method ) {
			$reader->boolean();
			$password          = $reader->string();
			$state->attempts[] = array( 'password', $password );
			$this->auth_result( 'test' === $user && ' test password ' === $password );
			return;
		}
		if ( 'publickey' === $method ) {
			$signed            = $reader->boolean();
			$algorithm         = $reader->string();
			$blob              = $reader->string();
			$state->attempts[] = array( 'key', base64_encode( $blob ) );
			$known             = 'test' === $user && in_array( $blob, $this->server['keys'], true );
			if ( ! $signed ) {
				if ( $known ) {
					$this->send_packet( chr( SSH_MSG_USERAUTH_PK_OK ) . ssh_string( $algorithm ) . ssh_string( $blob ) );
				} else {
					$this->auth_failure();
				}
				return;
			}
			$data = ssh_string( $this->session_id ) . chr( SSH_MSG_USERAUTH_REQUEST ) . ssh_string( $user ) . ssh_string( $service ) . ssh_string( 'publickey' ) . "\1" . ssh_string( $algorithm ) . ssh_string( $blob );
			$this->auth_result( $known && ssh_verify_client( $blob, $reader->string(), $data ) );
			return;
		}
		$this->auth_failure();
	}

	private function auth_result( $success ) {
		if ( ! $success ) {
			$this->auth_failure();
			return;
		}
		$this->authenticated = true;
		$this->send_packet( chr( SSH_MSG_USERAUTH_SUCCESS ) );
	}

	private function auth_failure() {
		$this->send_packet( chr( SSH_MSG_USERAUTH_FAILURE ) . ssh_list( array( 'password', 'publickey' ) ) . "\0" );
	}
}
