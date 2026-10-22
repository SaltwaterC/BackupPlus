<?php
/** B2 Native API authorization, large-file checksums and version-aware retention. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
$provider            = provider_create( 'backblaze' );
$options             = array(
	'application_key_id' => 'key-id',
	'application_key'    => 'test-secret',
	'bucketid'           => 'bucket-id',
	'path'               => 'site/backups',
);
$versions            = array();
$parts               = array();
$large_name          = '';
$sequence            = 0;
$cancelled           = false;
$part_failure        = false;
$bad_endpoint        = false;
$cloud_http->handler = function ( $method, $url, $headers, $body ) use ( &$versions, &$parts, &$large_name, &$sequence, &$cancelled, &$part_failure, &$bad_endpoint ) {
	$operation = basename( parse_url( $url, PHP_URL_PATH ) );
	if ( 'b2_authorize_account' === $operation ) {
		provider_expect( 'Basic ' . base64_encode( 'key-id:test-secret' ) === $headers['authorization'], 'B2 must authorize with the application key ID and key' );
		return Cloud_Test_HTTP::response(
			200,
			array(
				'authorizationToken' => 'account-token',
				'apiUrl'             => $bad_endpoint ? 'https://evil.test' : 'https://api001.backblazeb2.com',
				'downloadUrl'        => 'https://f001.backblazeb2.com',
			)
		);
	}
	if ( 'upload-file' === $operation ) {
		provider_expect( 'upload-token' === $headers['authorization'] && sha1( $body ) === $headers['x-bz-content-sha1'], 'B2 must use the upload token and SHA1 of the actual bytes' );
		$key             = rawurldecode( $headers['x-bz-file-name'] );
		$id              = 'file-' . ( ++$sequence );
		$versions[ $id ] = array(
			'fileName'      => $key,
			'fileId'        => $id,
			'contentLength' => strlen( $body ),
			'contentSha1'   => sha1( $body ),
			'action'        => 'upload',
			'bytes'         => $body,
		);
		$result          = $versions[ $id ];
		unset( $result['bytes'] );
		return Cloud_Test_HTTP::response( 200, $result );
	}
	if ( 'upload-part' === $operation ) {
		if ( $part_failure ) {
			return Cloud_Test_HTTP::response( 503 );
		}
		provider_expect( 'part-token' === $headers['authorization'] && sha1( $body ) === $headers['x-bz-content-sha1'], 'B2 parts must use their own token and checksum' );
		$parts[ (int) $headers['x-bz-part-number'] ] = $body;
		return Cloud_Test_HTTP::response( 200, array( 'contentSha1' => sha1( $body ) ) );
	}
	if ( 'b2_download_file_by_id' === $operation ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		return isset( $versions[ $query['fileId'] ] ) ? Cloud_Test_HTTP::response( 200, $versions[ $query['fileId'] ]['bytes'] ) : Cloud_Test_HTTP::response( 404 );
	}
	provider_expect( 'account-token' === $headers['authorization'], 'B2 metadata operations must use the account token' );
	$data = json_decode( $body, true );
	if ( in_array( $operation, array( 'b2_list_file_names', 'b2_list_file_versions' ), true ) ) {
		provider_expect( 'bucket-id' === $data['bucketId'], 'B2 must use the configured bucket ID' );
		$files = array_values(
			array_filter(
				array_reverse( $versions ),
				function ( $file ) use ( $data ) {
					return 0 === strpos( $file['fileName'], $data['prefix'] );
				}
			)
		);
		if ( 'b2_list_file_names' === $operation ) {
			$unique = array();
			foreach ( $files as $file ) {
				if ( ! isset( $unique[ $file['fileName'] ] ) ) {
					$unique[ $file['fileName'] ] = $file;
				}
			}
			$files = array_values( $unique );
		}
		// Tiny server pages exercise the real continuation loop.
		$offset = isset( $data['startFileName'] ) ? (int) substr( $data['startFileName'], strlen( 'cursor-' ) ) : 0;
		$page   = array_slice( $files, $offset, 1 );
		foreach ( $page as &$file ) {
			unset( $file['bytes'] );
		}
		$next = count( $files ) > $offset + 1 ? 'cursor-' . ( $offset + 1 ) : null;
		return Cloud_Test_HTTP::response(
			200,
			array(
				'files'        => $page,
				'nextFileName' => $next,
				'nextFileId'   => $next ? 'next-id' : null,
			)
		);
	}
	if ( 'b2_get_upload_url' === $operation ) {
		return Cloud_Test_HTTP::response(
			200,
			array(
				'uploadUrl'          => 'https://pod001.backblazeb2.com/upload-file',
				'authorizationToken' => 'upload-token',
			)
		);
	}
	if ( 'b2_delete_file_version' === $operation ) {
		unset( $versions[ $data['fileId'] ] );
		return Cloud_Test_HTTP::response( 200, array( 'fileId' => $data['fileId'] ) ); }
	if ( 'b2_start_large_file' === $operation ) {
		$large_name = $data['fileName'];
		$parts      = array();
		return Cloud_Test_HTTP::response( 200, array( 'fileId' => 'large-id' ) ); }
	if ( 'b2_get_upload_part_url' === $operation ) {
		return Cloud_Test_HTTP::response(
			200,
			array(
				'uploadUrl'          => 'https://pod001.backblazeb2.com/upload-part',
				'authorizationToken' => 'part-token',
			)
		);
	}
	if ( 'b2_finish_large_file' === $operation ) {
		provider_expect( array_map( 'sha1', array_values( $parts ) ) === $data['partSha1Array'], 'B2 completion must use the ordered confirmed part hashes' );
		$contents             = implode( '', $parts );
		$versions['large-id'] = array(
			'fileName'      => $large_name,
			'fileId'        => 'large-id',
			'contentLength' => strlen( $contents ),
			'bytes'         => $contents,
			'action'        => 'upload',
		);
		return Cloud_Test_HTTP::response( 200, array( 'contentLength' => strlen( $contents ) ) );
	}
	if ( 'b2_cancel_large_file' === $operation ) {
		$cancelled = true;
		return Cloud_Test_HTTP::response( 200, array( 'fileId' => 'large-id' ) ); }
	throw new RuntimeException( 'Unexpected B2 operation: ' . $operation );
};
$client              = new BackupPlus_Backblaze_Client( $options, $cloud_http );
cloud_module_checks( 'backblaze', $client, $options, $cloud_http );
provider_expect( array() === $versions, 'B2 credential tests must clean remote objects' );
$large = str_repeat( 'B', 8388608 ) . 'final part';
file_put_contents( $fixture . '/large.zip', $large );
$client->upload( 'backup_large.zip', $fixture . '/large.zip' );
provider_expect( $large === $versions['large-id']['bytes'] && 2 === count( $parts ), 'B2 must assemble large archives from multiple parts' );
$versions['older-id']        = array_merge( $versions['large-id'], array( 'fileId' => 'older-id' ) );
$versions['prefix-neighbor'] = array_merge(
	$versions['large-id'],
	array(
		'fileId'   => 'prefix-neighbor',
		'fileName' => 'site/backups/backup_large.zip.keep',
	)
);
$client->delete( 'backup_large.zip' );
provider_expect( array( 'prefix-neighbor' ) === array_keys( $versions ), 'B2 retention must remove every exact-name version and preserve prefix neighbors' );
$part_failure = true;
cloud_throws(
	function () use ( $client, $fixture ) {
		$client->upload( 'backup_failed.zip', $fixture . '/large.zip' );
	},
	'B2 must propagate part failures'
);
provider_expect( $cancelled, 'B2 must cancel an unfinished large file after a part failure' );
$bad_endpoint = true;
$untrusted    = new BackupPlus_Backblaze_Client( $options, $cloud_http );
cloud_throws(
	function () use ( $untrusted ) {
		$untrusted->listing();
	},
	'B2 must reject untrusted authorization endpoints'
);
echo "PASS: B2 authorization, pagination, large-file SHA1 checks, failed-upload cleanup and exact-name version deletion\n";
