<?php
/** B2 Native API authorization, large-file checksums and version-aware retention. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\CloudProviderTestCase;
use BackupPlus_Backblaze_Client;
use Cloud_Test_HTTP;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

final class BackblazeTest extends CloudProviderTestCase {

	private array $options;

	private array $versions;

	private array $parts;

	private string $large_name;

	private int $sequence;

	private bool $cancelled;

	private bool $part_failure;

	private bool $bad_endpoint;

	private BackupPlus_Backblaze_Client $client;

	protected function setUp(): void {
		parent::setUp();
		$this->create_provider( 'backblaze' );
		$this->options       = array(
			'application_key_id' => 'key-id',
			'application_key'    => 'test-secret',
			'bucketid'           => 'bucket-id',
			'path'               => 'site/backups',
		);
		$this->versions      = array();
		$this->parts         = array();
		$this->large_name    = '';
		$this->sequence      = 0;
		$this->cancelled     = false;
		$this->part_failure  = false;
		$this->bad_endpoint  = false;
		$this->http->handler = array( $this, 'serve' );
		$this->client        = new BackupPlus_Backblaze_Client( $this->options, $this->http );
	}

	/** The service: checks tokens and checksums on the wire, keeps file versions and large file parts. */
	public function serve( $method, $url, $headers, $body ) {
		$operation = basename( parse_url( $url, PHP_URL_PATH ) );
		if ( 'b2_authorize_account' === $operation ) {
			$this->assertSame( 'Basic ' . base64_encode( 'key-id:test-secret' ), $headers['authorization'], 'B2 must authorize with the application key ID and key' );
			return Cloud_Test_HTTP::response(
				200,
				array(
					'authorizationToken' => 'account-token',
					'apiUrl'             => $this->bad_endpoint ? 'https://evil.test' : 'https://api001.backblazeb2.com',
					'downloadUrl'        => 'https://f001.backblazeb2.com',
				)
			);
		}
		if ( 'upload-file' === $operation ) {
			$this->assertSame( 'upload-token', $headers['authorization'], 'B2 must use the upload token and SHA1 of the actual bytes' );
			$this->assertSame( sha1( $body ), $headers['x-bz-content-sha1'], 'B2 must use the upload token and SHA1 of the actual bytes' );
			$key                   = rawurldecode( $headers['x-bz-file-name'] );
			$id                    = 'file-' . ( ++$this->sequence );
			$this->versions[ $id ] = array(
				'fileName'      => $key,
				'fileId'        => $id,
				'contentLength' => strlen( $body ),
				'contentSha1'   => sha1( $body ),
				'action'        => 'upload',
				'bytes'         => $body,
			);
			$result                = $this->versions[ $id ];
			unset( $result['bytes'] );
			return Cloud_Test_HTTP::response( 200, $result );
		}
		if ( 'upload-part' === $operation ) {
			if ( $this->part_failure ) {
				return Cloud_Test_HTTP::response( 503 );
			}
			$this->assertSame( 'part-token', $headers['authorization'], 'B2 parts must use their own token and checksum' );
			$this->assertSame( sha1( $body ), $headers['x-bz-content-sha1'], 'B2 parts must use their own token and checksum' );
			$this->parts[ (int) $headers['x-bz-part-number'] ] = $body;
			return Cloud_Test_HTTP::response( 200, array( 'contentSha1' => sha1( $body ) ) );
		}
		if ( 'b2_download_file_by_id' === $operation ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
			return isset( $this->versions[ $query['fileId'] ] ) ? Cloud_Test_HTTP::response( 200, $this->versions[ $query['fileId'] ]['bytes'] ) : Cloud_Test_HTTP::response( 404 );
		}
		$this->assertSame( 'account-token', $headers['authorization'], 'B2 metadata operations must use the account token' );
		$data = json_decode( $body, true );
		if ( in_array( $operation, array( 'b2_list_file_names', 'b2_list_file_versions' ), true ) ) {
			$this->assertSame( 'bucket-id', $data['bucketId'], 'B2 must use the configured bucket ID' );
			$files = array_values(
				array_filter(
					array_reverse( $this->versions ),
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
			unset( $this->versions[ $data['fileId'] ] );
			return Cloud_Test_HTTP::response( 200, array( 'fileId' => $data['fileId'] ) );
		}
		if ( 'b2_start_large_file' === $operation ) {
			$this->large_name = $data['fileName'];
			$this->parts      = array();
			return Cloud_Test_HTTP::response( 200, array( 'fileId' => 'large-id' ) );
		}
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
			$this->assertSame( array_map( 'sha1', array_values( $this->parts ) ), $data['partSha1Array'], 'B2 completion must use the ordered confirmed part hashes' );
			$contents                   = implode( '', $this->parts );
			$this->versions['large-id'] = array(
				'fileName'      => $this->large_name,
				'fileId'        => 'large-id',
				'contentLength' => strlen( $contents ),
				'bytes'         => $contents,
				'action'        => 'upload',
			);
			return Cloud_Test_HTTP::response( 200, array( 'contentLength' => strlen( $contents ) ) );
		}
		if ( 'b2_cancel_large_file' === $operation ) {
			$this->cancelled = true;
			return Cloud_Test_HTTP::response( 200, array( 'fileId' => 'large-id' ) );
		}
		throw new RuntimeException( 'Unexpected B2 operation: ' . $operation );
	}

	/** A fresh process: the contract includes a check that the provider loads no other provider's files. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_storage_contract(): void {
		$this->assert_storage_contract( 'backblaze', $this->client, $this->options );
		$this->assertSame( array(), $this->versions, 'B2 credential tests must clean remote objects' );
	}

	public function test_large_archives_are_assembled_from_multiple_parts(): void {
		$large = str_repeat( 'B', 8388608 ) . 'final part';
		file_put_contents( $this->fixture . '/large.zip', $large );
		$this->client->upload( 'backup_large.zip', $this->fixture . '/large.zip' );
		$this->assertSame( $large, $this->versions['large-id']['bytes'], 'B2 must assemble large archives from multiple parts' );
		$this->assertSame( 2, count( $this->parts ), 'B2 must assemble large archives from multiple parts' );
	}

	public function test_deletion_removes_every_exact_name_version_and_keeps_prefix_neighbors(): void {
		file_put_contents( $this->fixture . '/large.zip', str_repeat( 'B', 8388608 ) . 'final part' );
		$this->client->upload( 'backup_large.zip', $this->fixture . '/large.zip' );
		$this->versions['older-id']        = array_merge( $this->versions['large-id'], array( 'fileId' => 'older-id' ) );
		$this->versions['prefix-neighbor'] = array_merge(
			$this->versions['large-id'],
			array(
				'fileId'   => 'prefix-neighbor',
				'fileName' => 'site/backups/backup_large.zip.keep',
			)
		);
		$this->client->delete( 'backup_large.zip' );
		$this->assertSame( array( 'prefix-neighbor' ), array_keys( $this->versions ), 'B2 retention must remove every exact-name version and preserve prefix neighbors' );
	}

	public function test_part_failures_cancel_the_unfinished_large_file(): void {
		file_put_contents( $this->fixture . '/large.zip', str_repeat( 'B', 8388608 ) . 'final part' );
		$this->part_failure = true;
		$this->thrown(
			function () {
				$this->client->upload( 'backup_failed.zip', $this->fixture . '/large.zip' );
			},
			'B2 must propagate part failures'
		);
		$this->assertTrue( $this->cancelled, 'B2 must cancel an unfinished large file after a part failure' );
	}

	public function test_untrusted_authorization_endpoints_are_rejected(): void {
		$this->bad_endpoint = true;
		$untrusted          = new BackupPlus_Backblaze_Client( $this->options, $this->http );
		$this->thrown(
			function () use ( $untrusted ) {
				$untrusted->listing();
			},
			'B2 must reject untrusted authorization endpoints'
		);
	}
}
