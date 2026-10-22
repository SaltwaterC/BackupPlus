<?php
/** Graph uploads/downloads and OneDrive's administrator-bound consent flow. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
$provider = provider_create( 'onedrive' );
provider_expect( false !== strpos( $provider->get_configuration_template(), 'action=backupplusmethod-onedrive-auth' ), 'The OneDrive settings template must contain a working per-instance connect link' );
$options             = array_merge(
	$provider->get_default_options(),
	array(
		'client_id'     => 'test-client',
		'client_secret' => 'test-secret',
		'refresh_token' => 'refresh-token',
		'path'          => 'site/backups',
	)
);
$objects             = array();
$sessions            = array();
$tokens              = 0;
$once_unauthorized   = false;
$bad_endpoint        = false;
$rotated             = array();
$cloud_http->handler = function ( $method, $url, $headers, $body ) use ( &$objects, &$sessions, &$tokens, &$once_unauthorized, &$bad_endpoint ) {
	$host = parse_url( $url, PHP_URL_HOST );
	$path = parse_url( $url, PHP_URL_PATH );
	if ( 'login.microsoftonline.com' === $host ) {
		parse_str( $body, $parameters );
		provider_expect( 'test-client' === $parameters['client_id'] && 'test-secret' === $parameters['client_secret'], 'OneDrive must exchange its own application credentials' );
		provider_expect( in_array( $parameters['grant_type'], array( 'refresh_token', 'authorization_code' ), true ), 'OneDrive must use an OAuth grant' );
		return Cloud_Test_HTTP::response(
			200,
			array(
				'access_token'  => 'access-' . ( ++$tokens ),
				'expires_in'    => 3600,
				'refresh_token' => 'rotated-refresh',
			)
		);
	}
	if ( 'up.1drv.com' === $host ) {
		provider_expect( ! isset( $headers['authorization'] ), 'OneDrive must not send Graph bearer credentials to preauthorized upload URLs' );
		$id = (int) basename( $path );
		if ( 'DELETE' === $method ) {
			unset( $sessions[ $id ] );
			return Cloud_Test_HTTP::response( 204 ); }
		$offset = strlen( $sessions[ $id ]['bytes'] );
		provider_expect( 0 === strpos( $headers['content-range'], 'bytes ' . $offset . '-' ), 'OneDrive upload fragments must use sequential byte ranges' );
		if ( $offset ) {
			provider_expect( 0 === $offset % 327680, 'OneDrive non-final fragments must be multiples of 320 KiB' );
		}
		$sessions[ $id ]['bytes'] .= $body;
		$total                     = (int) substr( strrchr( $headers['content-range'], '/' ), 1 );
		if ( strlen( $sessions[ $id ]['bytes'] ) < $total ) {
			return Cloud_Test_HTTP::response( 202, array( 'nextExpectedRanges' => array( strlen( $sessions[ $id ]['bytes'] ) . '-' ) ) );
		}
		$objects[ $sessions[ $id ]['name'] ] = $sessions[ $id ]['bytes'];
		return Cloud_Test_HTTP::response(
			201,
			array(
				'size' => $total,
				'id'   => 'file-id',
			)
		);
	}
	if ( 'tenant.sharepoint.com' === $host ) {
		provider_expect( ! isset( $headers['authorization'] ), 'OneDrive must not leak Graph bearer tokens to content hosts' );
		$key = rawurldecode( substr( $path, strlen( '/content/' ) ) );
		return Cloud_Test_HTTP::response( 200, $objects[ $key ] );
	}
	provider_expect( 'graph.microsoft.com' === $host && 0 === strpos( $headers['authorization'] ?? '', 'Bearer access-' ), 'Drive operations must authenticate only to Microsoft Graph' );
	if ( $once_unauthorized ) {
		$once_unauthorized = false;
		return Cloud_Test_HTTP::response( 401 ); }
	$prefix = '/v1.0/me/drive/root:/';
	provider_expect( 0 === strpos( $path, $prefix ), 'OneDrive must address the configured drive and folder' );
	$key = rawurldecode( substr( $path, strlen( $prefix ) ) );
	if ( ':/children' === substr( $key, -10 ) ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
		$items = array();
		foreach ( $objects as $name => $contents ) {
			$items[] = array(
				'name' => basename( $name ),
				'size' => strlen( $contents ),
				'file' => array(),
			);
		}
		$items[] = array(
			'name'   => 'directory',
			'folder' => array(),
			'size'   => 0,
		);
		$items[] = array(
			'name'       => 'shortcut',
			'file'       => array(),
			'remoteItem' => array(),
			'size'       => 1,
		);
		$offset  = (int) ( $query['page'] ?? 0 );
		$data    = array( 'value' => array_slice( $items, $offset, 1 ) );
		if ( $offset + 1 < count( $items ) ) {
			$data['@odata.nextLink'] = 'https://graph.microsoft.com' . $path . '?page=' . ( $offset + 1 );
		}
		return Cloud_Test_HTTP::response( 200, $data );
	}
	if ( ':/createUploadSession' === substr( $key, -21 ) ) {
		$key  = substr( $key, 0, -21 );
		$data = json_decode( $body, true );
		provider_expect( 'replace' === $data['item']['@microsoft.graph.conflictBehavior'], 'OneDrive retries must replace the same remote backup' );
		$id              = count( $sessions ) + 1;
		$sessions[ $id ] = array(
			'name'  => $key,
			'bytes' => '',
		);
		return Cloud_Test_HTTP::response( 200, array( 'uploadUrl' => $bad_endpoint ? 'https://evil.test/upload' : 'https://up.1drv.com/session/' . $id ) );
	}
	if ( ':/content' === substr( $key, -9 ) ) {
		$key = substr( $key, 0, -9 );
		provider_expect( 'PUT' === $method && '' === $body, 'OneDrive empty files must use a zero-byte content PUT' );
		$objects[ $key ] = '';
		return Cloud_Test_HTTP::response( 201, array( 'size' => 0 ) );
	}
	if ( 'DELETE' === $method ) {
		$existed = isset( $objects[ $key ] );
		unset( $objects[ $key ] );
		return Cloud_Test_HTTP::response( $existed ? 204 : 404 ); }
	if ( ! isset( $objects[ $key ] ) ) {
		return Cloud_Test_HTTP::response( 404 );
	}
	return Cloud_Test_HTTP::response(
		200,
		array(
			'name'                         => basename( $key ),
			'size'                         => strlen( $objects[ $key ] ),
			'file'                         => array(),
			'@microsoft.graph.downloadUrl' => 'https://tenant.sharepoint.com/content/' . rawurlencode( $key ),
		)
	);
};
$client              = new BackupPlus_OneDrive_Client(
	$options,
	$cloud_http,
	function ( $token, $original ) use ( &$rotated ) {
		$rotated[] = $token;
	}
);
cloud_module_checks( 'onedrive', $client, $options, $cloud_http );
provider_expect( array( 'rotated-refresh' ) === $rotated, 'OneDrive must report refresh-token rotation to its owning provider' );
$before            = $tokens;
$once_unauthorized = true;
$client->listing();
provider_expect( $tokens === $before + 1, 'OneDrive must refresh and retry after a rejected access token' );
$large = str_repeat( 'O', 10485760 ) . 'last fragment';
file_put_contents( $fixture . '/large.zip', $large );
$client->upload( 'backup_large.zip', $fixture . '/large.zip' );
provider_expect( $large === $objects['site/backups/backup_large.zip'], 'OneDrive must commit multi-fragment archives' );
$bad_endpoint = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->upload( 'backup_bad_host.zip', $fixture . '/large.zip' );
	},
	'OneDrive must reject untrusted upload endpoints'
);
$bad_endpoint = false;

// Consent callbacks must reject replay, another user, a bad state and expiry.
$oauth = provider_create( 'onedrive' );
$oauth->set_options( $options );
BackupPlus_Options::$options['backupplus_onedrive'] = array(
	'version'  => 1,
	'settings' => array( 'test-instance' => $options ),
);
$url = $oauth->authorization_url();
parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
provider_expect( 'offline_access Files.ReadWrite' === $parameters['scope'] && $oauth->oauth_redirect_uri() === $parameters['redirect_uri'], 'OneDrive consent must request offline file access and the registered redirect URI' );
cloud_throws(
	function () use ( $oauth ) {
		$oauth->complete_oauth( 'wrong-state', 'code' );
	},
	'OneDrive must reject an invalid state'
);
$cloud_user = 2;
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code' );
	},
	'OneDrive must reject a callback for another administrator'
);
$cloud_user                        = 1;
$valid                             = $oauth->get_options();
$expired                           = $valid;
$expired['oauth_state']['expires'] = 0;
$oauth->set_options( $expired );
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code' );
	},
	'OneDrive must reject expired authorization state'
);
$oauth->set_options( $valid );
provider_expect( $oauth->complete_oauth( $parameters['state'], 'code' ) && 'rotated-refresh' === $oauth->get_options()['refresh_token'], 'OneDrive consent must save its refresh token' );
cloud_throws(
	function () use ( $oauth, $parameters ) {
		$oauth->complete_oauth( $parameters['state'], 'code' );
	},
	'OneDrive must reject a replayed callback'
);
echo "PASS: OneDrive pagination, 320 KiB alignment, refresh rotation/retry, signed URL isolation and one-use administrator consent\n";
