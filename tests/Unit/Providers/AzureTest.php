<?php
/** Azure REST signing, block commits and backup integration without an account. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\CloudProviderTestCase;
use BackupPlus_Azure_Client;
use Cloud_Test_HTTP;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class AzureTest extends CloudProviderTestCase {

	private array $options;

	private array $objects;

	private array $blocks;

	private bool $commit_failure;

	private bool $malformed;

	private BackupPlus_Azure_Client $client;

	protected function setUp(): void {
		parent::setUp();
		$provider             = $this->create_provider( 'azure' );
		$this->options        = array_merge(
			$provider->get_default_options(),
			array(
				'account'    => 'testaccount',
				'accountkey' => base64_encode( str_repeat( 'K', 64 ) ),
				'container'  => 'test-container',
				'path'       => 'site/backups +#',
			)
		);
		$this->objects        = array( 'elsewhere/backup_other.zip' => 'unrelated' );
		$this->blocks         = array();
		$this->commit_failure = false;
		$this->malformed      = false;
		$this->http->handler  = array( $this, 'serve' );
		$this->client         = new BackupPlus_Azure_Client( $this->options, $this->http );
	}

	/** The service: rebuilds the Shared Key signature from the wire data, keeps blobs and staged blocks. */
	public function serve( $method, $url, $headers, $body ) {
		$this->assertSame( 'testaccount.blob.core.windows.net', parse_url( $url, PHP_URL_HOST ), 'Azure must use its account endpoint' );
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
		$this->assertSame( 'SharedKey testaccount:' . base64_encode( hash_hmac( 'sha256', $expected, str_repeat( 'K', 64 ), true ) ), $headers['authorization'], 'Azure must sign headers, encoded paths and decoded sorted queries correctly' );
		$key = rawurldecode( substr( parse_url( $url, PHP_URL_PATH ), strlen( '/test-container/' ) ) );
		if ( 'list' === ( $query['comp'] ?? '' ) ) {
			if ( $this->malformed ) {
				return Cloud_Test_HTTP::response( 200, '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>' );
			}
			$entries = array();
			foreach ( $this->objects as $name => $contents ) {
				$entries[] = '<Blob><Name>' . htmlspecialchars( $name, ENT_XML1 ) . '</Name><Properties><Content-Length>' . strlen( $contents ) . '</Content-Length></Properties></Blob>';
			}
			$split  = (int) ceil( count( $entries ) / 2 );
			$page   = empty( $query['marker'] ) ? array_slice( $entries, 0, $split ) : array_slice( $entries, $split );
			$marker = empty( $query['marker'] ) && count( $entries ) > $split ? 'second-page' : '';
			return Cloud_Test_HTTP::response( 200, '<EnumerationResults><Blobs>' . implode( '', $page ) . '</Blobs><NextMarker>' . $marker . '</NextMarker></EnumerationResults>' );
		}
		if ( 'PUT' === $method && 'block' === ( $query['comp'] ?? '' ) ) {
			$this->assertSame( base64_encode( md5( $body, true ) ), $headers['content-md5'], 'Azure block uploads must include the correct MD5' );
			$this->blocks[ $key ][ $query['blockid'] ] = $body;
			return Cloud_Test_HTTP::response( 201 );
		}
		if ( 'PUT' === $method && 'blocklist' === ( $query['comp'] ?? '' ) ) {
			if ( $this->commit_failure ) {
				return Cloud_Test_HTTP::response( 403 );
			}
			preg_match_all( '#<Latest>([^<]+)</Latest>#', $body, $matches );
			$this->objects[ $key ] = '';
			foreach ( $matches[1] as $id ) {
				$this->assertTrue( isset( $this->blocks[ $key ][ $id ] ), 'Azure must commit only successfully staged blocks' );
				$this->objects[ $key ] .= $this->blocks[ $key ][ $id ];
			}
			return Cloud_Test_HTTP::response( 201 );
		}
		if ( 'DELETE' === $method ) {
			unset( $this->objects[ $key ] );
			return Cloud_Test_HTTP::response( 202 ); }
		if ( 'PUT' === $method && 'BlockBlob' === ( $headers['x-ms-blob-type'] ?? '' ) ) {
			$this->assertSame( '', $body, 'Azure empty archives must use an empty BlockBlob PUT' );
			$this->objects[ $key ] = '';
			return Cloud_Test_HTTP::response( 201 );
		}
		if ( ! isset( $this->objects[ $key ] ) ) {
			return Cloud_Test_HTTP::response( 404 );
		}
		return Cloud_Test_HTTP::response( 200, 'HEAD' === $method ? '' : $this->objects[ $key ], array( 'content-length' => strlen( $this->objects[ $key ] ) ) );
	}

	/** A fresh process: the contract includes a check that the provider loads no other provider's files. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_storage_contract(): void {
		$this->assert_storage_contract( 'azure', $this->client, $this->options );
		$this->assertSame( array( 'elsewhere/backup_other.zip' => 'unrelated' ), $this->objects, 'Azure must preserve other prefixes and clean credential-test objects' );
	}

	public function test_large_archives_are_committed_as_ordered_blocks(): void {
		$large = str_repeat( 'A', 8388608 ) . 'last block';
		file_put_contents( $this->fixture . '/large.zip', $large );
		$this->client->upload( 'backup_large.zip', $this->fixture . '/large.zip' );
		$this->assertSame( $large, $this->objects['site/backups +#/backup_large.zip'], 'Azure must commit multiple blocks in order' );
	}

	public function test_rejected_block_commits_publish_nothing(): void {
		file_put_contents( $this->fixture . '/large.zip', str_repeat( 'A', 8388608 ) . 'last block' );
		$this->commit_failure = true;
		$this->thrown(
			function () {
				$this->client->upload( 'backup_failed.zip', $this->fixture . '/large.zip' );
			},
			'Azure must fail rejected block commits'
		);
		$this->assertFalse( isset( $this->objects['site/backups +#/backup_failed.zip'] ), 'Azure must not publish uncommitted blocks' );
	}

	public function test_entity_declarations_in_listings_are_rejected(): void {
		$this->malformed = true;
		$this->thrown(
			function () {
				$this->client->listing();
			},
			'Azure must reject entity declarations in XML'
		);
	}

	public function test_unsupported_or_injected_endpoints_are_rejected(): void {
		foreach ( array( 'https://evil.test', 'blob.core.windows.net.evil.test', 'blob.core.cloudapi.de' ) as $endpoint ) {
			$this->thrown(
				function () use ( $endpoint ) {
					new BackupPlus_Azure_Client( array_merge( $this->options, array( 'endpoint' => $endpoint ) ) );
				},
				'Azure must reject unsupported or injected endpoints'
			);
		}
	}
}
