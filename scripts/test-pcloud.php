<?php
/** pCloud regional API, streaming uploads and app consent without a real account. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
provider_create( 'pcloud' );
provider_expect( false !== strpos( BackupPlus_Storage_Provider_Factory::create( 'pcloud' )->get_configuration_template(), 'action=backupplusmethod-pcloud-auth' ), 'The pCloud settings template must contain a working per-instance connect link' );
require BACKUPPLUS_DIR . '/methods/pcloud/bootstrap.php';
class Cloud_Test_Pcloud_Module extends BackupPlus_BackupModule_pcloud {
	public function get_id() {
		return 'pcloud'; }
	protected function create_client( $options ) {
		return new BackupPlus_Pcloud_Client( $options, $GLOBALS['cloud_http'] ); }
}
$provider = new Cloud_Test_Pcloud_Module();
$provider->set_options( $provider->get_default_options(), false, 'test-instance' );
$options             = array_merge(
	$provider->get_default_options(),
	array(
		'pclauth'       => 'test-token',
		'location'      => '2',
		'path'          => 'site/backups',
		'client_id'     => 'test-client',
		'client_secret' => 'test-secret',
	)
);
$objects             = array();
$folders             = array( 0 => '' );
$invalid_result      = false;
$bad_endpoint        = false;
$invalid_size        = false;
$cloud_http->handler = function ( $method, $url, $headers, $body ) use ( &$objects, &$folders, &$invalid_result, &$bad_endpoint, &$invalid_size ) {
	$host      = parse_url( $url, PHP_URL_HOST );
	$operation = basename( parse_url( $url, PHP_URL_PATH ) );
	if ( 'c1.pcloud.com' === $host ) {
		provider_expect( ! isset( $headers['authorization'] ), 'pCloud downloads must not send account credentials to the content server' );
		$key = rawurldecode( substr( parse_url( $url, PHP_URL_PATH ), strlen( '/content/' ) ) );
		return Cloud_Test_HTTP::response( 200, $objects[ $key ] );
	}
	provider_expect( 'eapi.pcloud.com' === $host, 'pCloud must use the configured EU region' );
	if ( 'PUT' === $method ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
	} else {
		parse_str( $body, $parameters );
	}
	if ( 'oauth2_token' === $operation ) {
		provider_expect( 'test-client' === $parameters['client_id'] && 'test-secret' === $parameters['client_secret'] && 'code' === $parameters['code'], 'pCloud must exchange an authorization code with its own app credentials' );
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result'       => 0,
				'access_token' => 'authorized-token',
			)
		);
	}
	provider_expect( 'test-token' === $parameters['access_token'], 'pCloud API calls must carry the configured token' );
	if ( $invalid_result ) {
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result' => 2000,
				'error'  => 'Login failed',
			)
		);
	}
	if ( 'createfolderifnotexists' === $operation ) {
		$folder = trim( $folders[ $parameters['folderid'] ] . '/' . $parameters['name'], '/' );
		$id     = array_search( $folder, $folders, true );
		if ( false === $id ) {
			$id             = count( $folders );
			$folders[ $id ] = $folder; }
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result'   => 0,
				'metadata' => array( 'folderid' => $id ),
			)
		);
	}
	if ( 'uploadfile' === $operation ) {
		provider_expect( 'PUT' === $method && 1 === (int) $parameters['nopartial'], 'pCloud must stream raw bytes and reject partial uploads' );
		$key             = $folders[ $parameters['folderid'] ] . '/' . $parameters['filename'];
		$objects[ $key ] = $body;
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result'   => 0,
				'metadata' => array(
					array(
						'name' => $parameters['filename'],
						'size' => strlen( $body ),
					),
				),
			)
		);
	}
	if ( 'listfolder' === $operation ) {
		$items = array();
		foreach ( $objects as $key => $contents ) {
			$items[] = array(
				'name'     => basename( $key ),
				'size'     => strlen( $contents ),
				'isfolder' => false,
			);
		}
		$items[] = array(
			'name'     => 'folder',
			'isfolder' => true,
		);
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result'   => 0,
				'metadata' => array( 'contents' => $items ),
			)
		);
	}
	$key = ltrim( $parameters['path'] ?? '', '/' );
	if ( 'deletefile' === $operation ) {
		$exists = isset( $objects[ $key ] );
		unset( $objects[ $key ] );
		return Cloud_Test_HTTP::response( 200, array( 'result' => $exists ? 0 : 2009 ) ); }
	if ( 'stat' === $operation ) {
		return isset( $objects[ $key ] ) ? Cloud_Test_HTTP::response(
			200,
			array(
				'result'   => 0,
				'metadata' => array(
					'size'     => $invalid_size ? 'unknown' : strlen( $objects[ $key ] ),
					'fileid'   => $key,
					'isfolder' => false,
				),
			)
		) : Cloud_Test_HTTP::response( 200, array( 'result' => 2009 ) );
	}
	if ( 'getfilelink' === $operation ) {
		return Cloud_Test_HTTP::response(
			200,
			array(
				'result' => 0,
				'hosts'  => array( $bad_endpoint ? 'evil.test' : 'c1.pcloud.com' ),
				'path'   => '/content/' . rawurlencode( $parameters['fileid'] ),
			)
		);
	}
	throw new RuntimeException( 'Unexpected pCloud operation: ' . $operation );
};
$client              = new BackupPlus_Pcloud_Client( $options, $cloud_http );
cloud_module_checks( 'pcloud', $client, $options, $cloud_http, $provider );
provider_expect( array() === $objects, 'pCloud credential tests must remove their remote file' );
provider_expect( in_array( 'site/backups', $folders, true ), 'pCloud must create every folder component in its own account' );
$invalid_result = true;
cloud_throws(
	function () use ( $client ) {
		$client->listing();
	},
	'pCloud must reject API errors even when HTTP status is 200'
);
$invalid_result = false;
file_put_contents( $fixture . '/file.zip', 'archive' );
$client->upload( 'backup_file.zip', $fixture . '/file.zip' );
$invalid_size = true;
file_put_contents( $fixture . '/download.zip', 'preserve this archive' );
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->download( 'backup_file.zip', $fixture . '/download.zip' );
	},
	'Storage downloads must reject invalid remote sizes'
);
provider_expect( 'preserve this archive' === file_get_contents( $fixture . '/download.zip' ), 'Invalid remote metadata must not replace the local archive' );
$invalid_size = false;
$bad_endpoint = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->download( 'backup_file.zip', $fixture . '/download.zip' );
	},
	'pCloud must reject untrusted content hosts'
);
$bad_endpoint = false;
provider_expect( in_array( 'pclauth', apply_filters( 'backupplus_credential_secret_fields', array(), 'backupplus_pcloud' ), true ) && array() === apply_filters( 'backupplus_credential_secret_fields', array(), 'backupplus_other' ), 'pCloud must register its historical token name with generic credential encryption only for its own option' );

$oauth = provider_create( 'pcloud' );
$oauth->set_options( $options );
BackupPlus_Options::$options['backupplus_pcloud'] = array(
	'version'  => 1,
	'settings' => array( 'test-instance' => $options ),
);
$url = $oauth->authorization_url();
parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
provider_expect( 'my.pcloud.com' === parse_url( $url, PHP_URL_HOST ) && $parameters['redirect_uri'] === $oauth->oauth_redirect_uri(), 'pCloud must use its own registered app consent redirect' );
cloud_throws(
	function () use ( $oauth ) {
		$oauth->complete_oauth( 'bad-state', 'code', '2' );
	},
	'pCloud must reject invalid state'
);
$cloud_user = 2;
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code', '2' );
	},
	'pCloud must reject a callback for another administrator'
);
$cloud_user = 1;
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code', 'https://evil.test' );
	},
	'pCloud must reject injected authorization regions'
);
provider_expect( $oauth->complete_oauth( $parameters['state'], 'code', '2' ) && 'authorized-token' === $oauth->get_options()['pclauth'] && '2' === $oauth->get_options()['location'], 'pCloud must save the authorized token and data region' );
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code', '2' );
	},
	'pCloud must reject replayed consent callbacks'
);
echo "PASS: pCloud regional endpoints, streamed PUTs, folder creation, API errors, content host validation, encrypted-token policy and one-use consent\n";
