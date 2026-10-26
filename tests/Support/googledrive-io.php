<?php
/**
 * Google Drive SDK and the HTTP transport double for GoogleDriveTest.
 *
 * The bundled SDK has pre-PHP 8.4 nullable declarations. The deprecations are silenced only while it is compiled;
 * every other diagnostic stays enabled so missing shims and runtime warnings still fail the tests.
 */

$backupplus_reporting = error_reporting( error_reporting() & ~E_DEPRECATED );
require_once BACKUPPLUS_DIR . '/includes/class-manipulation-functions.php';
require_once BACKUPPLUS_DIR . '/methods/googledrive/Google/autoload.php';
require_once BACKUPPLUS_DIR . '/methods/googledrive/google-extensions.php';

class Provider_Test_Google_IO extends UDP_Google_IO_Curl {
	public $requests = array();
	public $handler;
	public function makeRequest( UDP_Google_Http_Request $request ) {
		$this->requests[]            = $request;
		list($code, $headers, $body) = ( $this->handler )( $request );
		$request->setResponseHttpCode( $code );
		$request->setResponseHeaders( $headers );
		$request->setResponseBody( $body );
		return $request;
	}
}

error_reporting( $backupplus_reporting );
unset( $backupplus_reporting );
