<?php
/** Google Cloud Storage wire protocol, refresh tokens and service-account JWTs. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
$provider            = provider_create( 'googlecloud' );
$options             = array_merge(
	$provider->get_default_options(),
	array(
		'bucket'   => 'test-bucket',
		'path'     => 'site/backups',
		'clientid' => 'test-client',
		'secret'   => 'test-secret',
		'token'    => 'refresh-token',
	)
);
$objects             = array();
$sessions            = array();
$tokens              = 0;
$once_unauthorized   = false;
$bad_endpoint        = false;
$bad_commit          = false;
$public_key          = null;
$cloud_http->handler = function ( $method, $url, $headers, $body ) use ( &$objects, &$sessions, &$tokens, &$once_unauthorized, &$bad_endpoint, &$bad_commit, &$public_key ) {
	$path = parse_url( $url, PHP_URL_PATH );
	parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
	if ( 'oauth2.googleapis.com' === parse_url( $url, PHP_URL_HOST ) ) {
		parse_str( $body, $parameters );
		if ( 'refresh_token' === $parameters['grant_type'] ) {
			provider_expect( 'test-client' === $parameters['client_id'] && 'test-secret' === $parameters['client_secret'] && 'refresh-token' === $parameters['refresh_token'], 'Google must exchange the configured OAuth refresh credentials' );
		} else {
			provider_expect( 'urn:ietf:params:oauth:grant-type:jwt-bearer' === $parameters['grant_type'], 'Google service accounts must use the JWT bearer grant' );
			list($header, $claims, $signature) = explode( '.', $parameters['assertion'] );
			$decode                            = function ( $text ) {
				return base64_decode( strtr( $text, '-_', '+/' ) );
			};
			$payload                           = json_decode( $decode( $claims ), true );
			provider_expect( 'service@example.iam.gserviceaccount.com' === $payload['iss'] && 'https://oauth2.googleapis.com/token' === $payload['aud'] && $payload['exp'] - $payload['iat'] === 3600, 'Google JWTs must bind issuer, fixed audience and expiry' );
			provider_expect( $public_key->withHash( 'sha256' )->withPadding( \phpseclib3\Crypt\RSA::SIGNATURE_PKCS1 )->verify( $header . '.' . $claims, $decode( $signature ) ), 'Google must issue an independently verifiable RS256 assertion' );
		}
		return Cloud_Test_HTTP::response(
			200,
			array(
				'access_token' => 'access-' . ( ++$tokens ),
				'expires_in'   => 3600,
			)
		);
	}
	provider_expect( 0 === strpos( $headers['authorization'] ?? '', 'Bearer access-' ), 'Cloud Storage operations must carry a bearer token' );
	if ( $once_unauthorized ) {
		$once_unauthorized = false;
		return Cloud_Test_HTTP::response( 401 ); }
	if ( 'POST' === $method && 0 === strpos( $path, '/upload/storage/' ) ) {
		$id              = count( $sessions ) + 1;
		$sessions[ $id ] = array(
			'name'  => $query['name'],
			'bytes' => '',
		);
		return Cloud_Test_HTTP::response( 200, '', array( 'location' => $bad_endpoint ? 'https://evil.test/upload' : 'https://storage.googleapis.com/session/' . $id ) );
	}
	if ( 'PUT' === $method && 0 === strpos( $path, '/session/' ) ) {
		$id     = (int) basename( $path );
		$offset = strlen( $sessions[ $id ]['bytes'] );
		$range  = $headers['content-range'];
		provider_expect( 'bytes */0' === $range || 0 === strpos( $range, 'bytes ' . $offset . '-' ), 'Google upload fragments must be sequential' );
		$sessions[ $id ]['bytes'] .= $body;
		$total                     = (int) substr( strrchr( $range, '/' ), 1 );
		if ( strlen( $sessions[ $id ]['bytes'] ) < $total || $bad_commit ) {
			return Cloud_Test_HTTP::response( 308, '', array( 'range' => 'bytes=0-' . ( strlen( $sessions[ $id ]['bytes'] ) - 1 ) ) );
		}
		$objects[ $sessions[ $id ]['name'] ] = $sessions[ $id ]['bytes'];
		return Cloud_Test_HTTP::response( 200, array( 'size' => (string) $total ) );
	}
	$prefix = '/storage/v1/b/test-bucket/o';
	provider_expect( 0 === strpos( $path, $prefix ), 'Google requests must use the configured bucket' );
	if ( $path === $prefix ) {
		$items = array();
		foreach ( $objects as $name => $contents ) {
			$items[] = array(
				'name' => $name,
				'size' => (string) strlen( $contents ),
			);
		}
		$items[] = array(
			'name' => 'other-site/backup_other.zip',
			'size' => '5',
		);
		$items[] = array(
			'name' => 'site/backups/nested/backup_nested.zip',
			'size' => '5',
		);
		$offset  = (int) ( $query['pageToken'] ?? 0 );
		$result  = array( 'items' => array_slice( $items, $offset, 1 ) );
		if ( $offset + 1 < count( $items ) ) {
			$result['nextPageToken'] = (string) ( $offset + 1 );
		}
		return Cloud_Test_HTTP::response( 200, $result );
	}
	$key = rawurldecode( substr( $path, strlen( $prefix ) + 1 ) );
	if ( 'DELETE' === $method ) {
		$existed = isset( $objects[ $key ] );
		unset( $objects[ $key ] );
		return Cloud_Test_HTTP::response( $existed ? 204 : 404 ); }
	if ( ! isset( $objects[ $key ] ) ) {
		return Cloud_Test_HTTP::response( 404 );
	}
	return 'media' === ( $query['alt'] ?? '' ) ? Cloud_Test_HTTP::response( 200, $objects[ $key ] ) : Cloud_Test_HTTP::response(
		200,
		array(
			'name' => $key,
			'size' => (string) strlen( $objects[ $key ] ),
		)
	);
};
$client              = new BackupPlus_GoogleCloud_Client( $options, $cloud_http );
cloud_module_checks( 'googlecloud', $client, $options, $cloud_http );
provider_expect( array() === $objects, 'Google credential tests must remove their remote file' );
$before            = $tokens;
$once_unauthorized = true;
$client->listing();
provider_expect( $tokens === $before + 1, 'Google must refresh and retry once after HTTP 401' );
$large = str_repeat( 'G', 8388608 ) . 'last chunk';
file_put_contents( $fixture . '/large.zip', $large );
$client->upload( 'backup_large.zip', $fixture . '/large.zip' );
provider_expect( $large === $objects['site/backups/backup_large.zip'], 'Google must complete uploads across the 8 MiB chunk boundary' );
$bad_commit = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->upload( 'backup_bad_commit.zip', $fixture . '/large.zip' );
	},
	'Google must reject an incomplete final upload response'
);
$bad_commit   = false;
$bad_endpoint = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->upload( 'backup_bad_host.zip', $fixture . '/large.zip' );
	},
	'Google must reject foreign upload session endpoints'
);
$bad_endpoint = false;
require_once BACKUPPLUS_DIR . '/vendor/autoload.php';
$private_key                        = \phpseclib3\Crypt\RSA::createKey( 2048 );
$public_key                         = $private_key->getPublicKey();
$service_options                    = $options;
$service_options['service_account'] = json_encode(
	array(
		'type'         => 'service_account',
		'client_email' => 'service@example.iam.gserviceaccount.com',
		'private_key'  => $private_key->toString( 'PKCS8' ),
		'token_uri'    => 'https://evil.test/token',
	)
);
$service_client                     = new BackupPlus_GoogleCloud_Client( $service_options, $cloud_http );
$service_client->listing();
provider_expect( ! $provider->authentication_required( $service_options ), 'A service account must not request an interactive Google login' );
echo "PASS: Google Cloud pagination, OAuth retry, RS256 service accounts, chunk boundaries, upload confirmation and endpoint validation\n";
