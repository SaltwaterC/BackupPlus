<?php
/** Azure REST signing, block commits and backup integration without an account. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
$provider            = provider_create( 'azure' );
$options             = array_merge(
	$provider->get_default_options(),
	array(
		'account'    => 'testaccount',
		'accountkey' => base64_encode( str_repeat( 'K', 64 ) ),
		'container'  => 'test-container',
		'path'       => 'site/backups +#',
	)
);
$objects             = array( 'elsewhere/backup_other.zip' => 'unrelated' );
$blocks              = array();
$commit_failure      = false;
$malformed           = false;
$cloud_http->handler = function ( $method, $url, $headers, $body ) use ( $options, &$objects, &$blocks, &$commit_failure, &$malformed ) {
	provider_expect( 'testaccount.blob.core.windows.net' === parse_url( $url, PHP_URL_HOST ), 'Azure must use its account endpoint' );
	parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
	// Independently rebuild Shared Key canonicalization from the emitted wire data.
	$canonical = array_filter(
		$headers,
		function ( $key ) {
			return 0 === strpos( $key, 'x-ms-' );
		},
		ARRAY_FILTER_USE_KEY
	);
	ksort( $canonical );
	$parts    = array( $method, '', '', empty( $headers['content-length'] ) ? '' : $headers['content-length'], $headers['content-md5'] ?? '', $headers['content-type'] ?? '', '', '', '', '', '', '' );
	$expected = implode( "\n", $parts ) . "\n";
	foreach ( $canonical as $key => $value ) {
		$expected .= $key . ':' . $value . "\n";
	}
	$expected .= '/testaccount' . parse_url( $url, PHP_URL_PATH );
	ksort( $query );
	foreach ( $query as $key => $value ) {
		$expected .= "\n" . $key . ':' . $value;
	}
	provider_expect( 'SharedKey testaccount:' . base64_encode( hash_hmac( 'sha256', $expected, str_repeat( 'K', 64 ), true ) ) === $headers['authorization'], 'Azure must sign headers, encoded paths and decoded sorted queries correctly' );
	$key = rawurldecode( substr( parse_url( $url, PHP_URL_PATH ), strlen( '/test-container/' ) ) );
	if ( 'list' === ( $query['comp'] ?? '' ) ) {
		if ( $malformed ) {
			return Cloud_Test_HTTP::response( 200, '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>' );
		}
		$entries = array();
		foreach ( $objects as $name => $contents ) {
			$entries[] = '<Blob><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Properties><Content-Length>' . strlen( $contents ) . '</Content-Length></Properties></Blob>';
		}
		$split  = (int) ceil( count( $entries ) / 2 );
		$page   = empty( $query['marker'] ) ? array_slice( $entries, 0, $split ) : array_slice( $entries, $split );
		$marker = empty( $query['marker'] ) && count( $entries ) > $split ? 'second-page' : '';
		return Cloud_Test_HTTP::response( 200, '<EnumerationResults><Blobs>' . implode( '', $page ) . '</Blobs><NextMarker>' . $marker . '</NextMarker></EnumerationResults>' );
	}
	if ( 'PUT' === $method && 'block' === ( $query['comp'] ?? '' ) ) {
		provider_expect( base64_encode( md5( $body, true ) ) === $headers['content-md5'], 'Azure block uploads must include the correct MD5' );
		$blocks[ $key ][ $query['blockid'] ] = $body;
		return Cloud_Test_HTTP::response( 201 );
	}
	if ( 'PUT' === $method && 'blocklist' === ( $query['comp'] ?? '' ) ) {
		if ( $commit_failure ) {
			return Cloud_Test_HTTP::response( 403 );
		}
		preg_match_all( '#<Latest>([^<]+)</Latest>#', $body, $matches );
		$objects[ $key ] = '';
		foreach ( $matches[1] as $id ) {
			provider_expect( isset( $blocks[ $key ][ $id ] ), 'Azure must commit only successfully staged blocks' );
			$objects[ $key ] .= $blocks[ $key ][ $id ];
		}
		return Cloud_Test_HTTP::response( 201 );
	}
	if ( 'DELETE' === $method ) {
		unset( $objects[ $key ] );
		return Cloud_Test_HTTP::response( 202 ); }
	if ( 'PUT' === $method && 'BlockBlob' === ( $headers['x-ms-blob-type'] ?? '' ) ) {
		provider_expect( '' === $body, 'Azure empty archives must use an empty BlockBlob PUT' );
		$objects[ $key ] = '';
		return Cloud_Test_HTTP::response( 201 );
	}
	if ( ! isset( $objects[ $key ] ) ) {
		return Cloud_Test_HTTP::response( 404 );
	}
	return Cloud_Test_HTTP::response( 200, 'HEAD' === $method ? '' : $objects[ $key ], array( 'content-length' => strlen( $objects[ $key ] ) ) );
};
$client              = new BackupPlus_Azure_Client( $options, $cloud_http );
cloud_module_checks( 'azure', $client, $options, $cloud_http );
provider_expect( array( 'elsewhere/backup_other.zip' => 'unrelated' ) === $objects, 'Azure must preserve other prefixes and clean credential-test objects' );
$large = str_repeat( 'A', 8388608 ) . 'last block';
file_put_contents( $fixture . '/large.zip', $large );
$client->upload( 'backup_large.zip', $fixture . '/large.zip' );
provider_expect( $large === $objects['site/backups +#/backup_large.zip'], 'Azure must commit multiple blocks in order' );
$commit_failure = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->upload( 'backup_failed.zip', $fixture . '/large.zip' );
	},
	'Azure must fail rejected block commits'
);
provider_expect( ! isset( $objects['site/backups +#/backup_failed.zip'] ), 'Azure must not publish uncommitted blocks' );
$malformed = true;
cloud_throws(
	function () use ( $client ) {
		$client->listing();
	},
	'Azure must reject entity declarations in XML'
);
foreach ( array( 'https://evil.test', 'blob.core.windows.net.evil.test', 'blob.core.cloudapi.de' ) as $endpoint ) {
	cloud_throws(
		function () use ( $options, $endpoint ) {
			new BackupPlus_Azure_Client( array_merge( $options, array( 'endpoint' => $endpoint ) ) );
		},
		'Azure must reject unsupported or injected endpoints'
	);
}
echo "PASS: Azure Shared Key signatures, paginated XML, block commit failures, large archives and endpoint validation\n";
