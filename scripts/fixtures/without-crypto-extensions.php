<?php
/** Test-only extension discovery shim for PHP builds with statically linked OpenSSL. */
namespace phpseclib3\Crypt {
	function extension_loaded( $name ) {
		return in_array( $name, array( 'openssl', 'sodium', 'mcrypt' ), true ) ? false : \extension_loaded( $name );
	}
}
namespace phpseclib3\Crypt\Common {
	function extension_loaded( $name ) {
		return in_array( $name, array( 'openssl', 'sodium', 'mcrypt' ), true ) ? false : \extension_loaded( $name );
	}
}
