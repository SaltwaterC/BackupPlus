<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';

/** Azure Blob REST API; Azure signing and block-blob behavior stay in this provider. */
class BackupPlus_Azure_Client extends BackupPlus_Remote_Storage_Client {
	private $base;
	private $secret;
	public function __construct( $options, $http = null ) {
		parent::__construct( $options, $http );
		$this->required( array( 'account', 'accountkey', 'container' ) );
		$endpoint = $options['endpoint'] ?? 'blob.core.windows.net';
		if ( ! in_array( $endpoint, array( 'blob.core.windows.net', 'blob.core.usgovcloudapi.net', 'blob.core.chinacloudapi.cn' ), true ) ) {
			throw new RuntimeException( 'Unsupported Azure Blob endpoint suffix.' );
		}
		if ( ! preg_match( '/^[a-z0-9]{3,24}$/', $options['account'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $options['container'] ) || false !== strpos( $options['container'], '--' ) ) {
			throw new RuntimeException( 'Invalid Azure account or container name.' );
		}
		$this->secret = base64_decode( $options['accountkey'], true );
		if ( false === $this->secret || strlen( $this->secret ) < 32 ) {
			throw new RuntimeException( 'Invalid Azure storage account key.' );
		}
		$this->hosts = array( $options['account'] . '.' . $endpoint );
		$this->base  = 'https://' . $this->hosts[0] . '/' . $options['container'];
	}
	private function blob_url( $name ) {
		return $this->base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $this->key( $name ) ) ) ); }
	private function request( $method, $url, $body = null, $headers = array(), $codes = array( 200 ), $destination = null ) {
		$headers                 = array_change_key_case( $headers, CASE_LOWER );
		$headers['x-ms-date']    = gmdate( 'D, d M Y H:i:s' ) . ' GMT';
		$headers['x-ms-version'] = '2023-11-03';
		if ( null !== $body ) {
			$headers['content-length'] = (string) strlen( $body );
		}
		$canonical = '';
		ksort( $headers );
		foreach ( $headers as $key => $value ) {
			if ( 0 === strpos( $key, 'x-ms-' ) ) {
				$canonical .= $key . ':' . trim( $value ) . "\n";
			}
		}
		$resource = '/' . $this->options['account'] . parse_url( $url, PHP_URL_PATH );
		parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
		ksort( $query );
		foreach ( $query as $key => $value ) {
			$resource .= "\n" . strtolower( $key ) . ':' . $value;
		}
		$string = $method . "\n";
		foreach ( array( 'content-encoding', 'content-language', 'content-length', 'content-md5', 'content-type', 'date', 'if-modified-since', 'if-match', 'if-none-match', 'if-unmodified-since', 'range' ) as $key ) {
			$value   = $headers[ $key ] ?? '';
			$string .= ( 'content-length' === $key && '0' === $value ? '' : $value ) . "\n";
		}
		$headers['authorization'] = 'SharedKey ' . $this->options['account'] . ':' . base64_encode( hash_hmac( 'sha256', $string . $canonical . $resource, $this->secret, true ) );
		return $this->send( $method, $url, $headers, $body, $codes, $destination );
	}
	public function listing() {
		$files  = array();
		$marker = '';
		$seen   = array();
		do {
			$url  = $this->base . '?' . self::query(
				array(
					'restype' => 'container',
					'comp'    => 'list',
					'prefix'  => $this->prefix,
					'marker'  => $marker,
				)
			);
			$body = wp_remote_retrieve_body( $this->request( 'GET', $url ) );
			if ( ! class_exists( 'DOMDocument' ) || preg_match( '/<!DOCTYPE|<!ENTITY/i', $body ) ) {
				throw new RuntimeException( 'Invalid Azure listing XML or missing PHP DOM extension.' );
			}
			$previous = libxml_use_internal_errors( true );
			try {
				$xml = new DOMDocument();
				if ( ! $xml->loadXML( $body, LIBXML_NONET ) ) {
					throw new RuntimeException( 'Invalid Azure listing XML.' );
				}
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous ); }
			if ( 'EnumerationResults' !== $xml->documentElement->nodeName ) {
				throw new RuntimeException( 'Invalid Azure listing response.' );
			}
			foreach ( $xml->getElementsByTagName( 'Blob' ) as $blob ) {
				$entry = $this->entry( $blob->getElementsByTagName( 'Name' )->item( 0 )->textContent ?? '', $blob->getElementsByTagName( 'Content-Length' )->item( 0 )->textContent ?? null );
				if ( $entry ) {
					$files[] = $entry;
				}
			}
			$marker = $xml->getElementsByTagName( 'NextMarker' )->item( 0 )->textContent ?? '';
			if ( $marker && isset( $seen[ $marker ] ) ) {
				throw new RuntimeException( 'Azure repeated a listing continuation marker.' );
			}
			$seen[ $marker ] = true;
		} while ( '' !== $marker );
		return $files;
	}
	public function upload( $name, $source, $progress = null ) {
		$url = $this->blob_url( $name );
		if ( 0 === filesize( $source ) ) {
			$this->request(
				'PUT',
				$url,
				'',
				array(
					'x-ms-blob-type' => 'BlockBlob',
					'content-type'   => 'application/octet-stream',
				),
				array( 201 )
			);
			if ( $progress ) {
				$progress( 0 );
			}
			return true;
		}
		$blocks = array();
		$nonce  = bin2hex( random_bytes( 12 ) );
		$this->chunks(
			$source,
			8388608,
			function ( $data, $offset, $total ) use ( $url, $nonce, &$blocks, $progress ) {
				$id = base64_encode( $nonce . sprintf( '%08d', count( $blocks ) ) );
				$this->request(
					'PUT',
					$url . '?' . self::query(
						array(
							'comp'    => 'block',
							'blockid' => $id,
						)
					),
					$data,
					array(
						'content-type' => 'application/octet-stream',
						'content-md5'  => base64_encode( md5( $data, true ) ),
					),
					array( 201 )
				);
				$blocks[] = $id;
				if ( $progress ) {
					$progress( $offset + strlen( $data ) );
				}
			}
		);
		$body = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
		foreach ( $blocks as $id ) {
			$body .= '<Latest>' . $id . '</Latest>';
		}
		$this->request(
			'PUT',
			$url . '?comp=blocklist',
			$body . '</BlockList>',
			array(
				'content-type'           => 'application/xml',
				'x-ms-blob-content-type' => 'application/octet-stream',
			),
			array( 201 )
		);
		return true;
	}
	public function download( $name, $destination ) {
		$url  = $this->blob_url( $name );
		$size = wp_remote_retrieve_header( $this->request( 'HEAD', $url ), 'content-length' );
		if ( ! is_numeric( $size ) ) {
			throw new RuntimeException( 'Azure returned no archive size.' );
		}
		$temporary = tempnam( dirname( $destination ), '.backupplus-download-' );
		if ( ! $temporary ) {
			throw new RuntimeException( 'Cannot create a local download file.' );
		}
		try {
			$this->request( 'GET', $url, null, array(), array( 200 ), $temporary );
			clearstatcache( true, $temporary );
			if ( (int) $size !== filesize( $temporary ) ) {
				throw new RuntimeException( 'Azure returned an incomplete archive.' );
			}
			if ( ! rename( $temporary, $destination ) ) {
				throw new RuntimeException( 'Cannot publish the downloaded archive.' );
			}
			return true;
		} finally {
			if ( file_exists( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}
	public function delete( $name ) {
		$this->request( 'DELETE', $this->blob_url( $name ), null, array( 'x-ms-delete-snapshots' => 'include' ), array( 202, 404 ) );
		return true;
	}
}
