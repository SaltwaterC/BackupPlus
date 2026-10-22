<?php
/** OpenStack and Rackspace Cloud Files behavior without external accounts. */
require __DIR__ . '/fixtures/provider-test-bootstrap.php';
provider_create( 'openstack' );
provider_create( 'cloudfiles' );

trait Provider_Test_Swift_Service {
	// The legacy bases create these fields dynamically; declare them for PHP 8.2+.
	protected $container;
	protected $container_object;
	public $service;
	public $service_error = false;
	public $service_options;
	public function get_id() {
		return $this->method; }
	public function get_openstack_service( $opts, $useservercerts = false, $disablesslverify = null ) {
		$this->service_options = $opts;
		if ( $this->service_error ) {
			throw new RuntimeException( 'Service unavailable' );
		}
		return $this->service;
	}
}
class Provider_Test_OpenStack extends BackupPlus_BackupModule_openstack {
	use Provider_Test_Swift_Service;
}
class Provider_Test_CloudFiles extends BackupPlus_BackupModule_cloudfiles {
	use Provider_Test_Swift_Service;
}

class Provider_Test_Swift_Objects {
	private $items;
	public function __construct( $items ) {
		$this->items = $items; }
	public function count() {
		return count( $this->items ); }
	public function offsetGet( $index ) {
		return $this->items[ $index ] ?? false; }
}
class Provider_Test_Swift_Object {
	private $container;
	private $name;
	private $length;
	public function __construct( $container ) {
		$this->container = $container; }
	public function populateFromResponse( $response ) {
		$this->length = $response;
		return $this; }
	public function setName( $name ) {
		$this->name = $name;
		return $this; }
	public function getContentLength() {
		return $this->length; }
	public function delete() {
		if ( 'delete' === $this->container->failure ) {
			throw new RuntimeException( 'Deletion denied' );
		}
		$this->container->deleted[] = $this->name;
		unset( $this->container->objects[ $this->name ] );
	}
}
class Provider_Test_Swift_Request {
	private $callback;
	public function __construct( $callback ) {
		$this->callback = $callback; }
	public function send() {
		return ( $this->callback )(); }
}
class Provider_Test_Swift_Container {
	public $objects = array();
	public $failure;
	public $deleted = array();
	public $lists   = array();
	public $headers;
	public function getContainer( $name ) {
		provider_expect( 'backups' === $name, 'Swift operations must use the configured container' );
		if ( 'container' === $this->failure ) {
			throw new RuntimeException( 'Container denied' );
		}
		return $this;
	}
	public function getClient() {
		return $this; }
	public function getUrl( $file ) {
		return $file; }
	public function dataObject() {
		return new Provider_Test_Swift_Object( $this ); }
	public function head( $name ) {
		return new Provider_Test_Swift_Request(
			function () use ( $name ) {
				if ( ! isset( $this->objects[ $name ] ) ) {
					throw new RuntimeException( 'Object missing' );
				}
				return strlen( $this->objects[ $name ] );
			}
		);
	}
	public function put( $name, $headers ) {
		return new Provider_Test_Swift_Request(
			function () use ( $name, $headers ) {
				$this->headers = $headers;
				if ( 'manifest' === $this->failure ) {
					throw new RuntimeException( 'Manifest denied' );
				}
				$prefix   = substr( $headers['X-Object-Manifest'], strlen( 'backups/' ) );
				$contents = '';
				$objects  = $this->objects;
				ksort( $objects );
				foreach ( $objects as $key => $value ) {
					if ( 0 === strpos( $key, $prefix ) ) {
						$contents .= $value;
					}
				}
				$this->objects[ $name ] = $contents;
			}
		);
	}
	public function uploadObject( $name, $data ) {
		if ( 'upload' === $this->failure ) {
			throw new RuntimeException( 'Upload denied' );
		}
		$this->objects[ $name ] = is_resource( $data ) ? stream_get_contents( $data ) : $data;
	}
	public function objectList( $params ) {
		$this->lists[] = $params;
		$items         = array();
		$objects       = $this->objects;
		ksort( $objects );
		foreach ( $objects as $name => $contents ) {
			if ( 0 !== strpos( $name, $params['prefix'] ) || $name <= ( $params['marker'] ?? '' ) ) {
				continue;
			}
			$items[] = (object) array(
				'name'  => $name,
				'bytes' => strlen( $contents ),
			);
		}
		return new Provider_Test_Swift_Objects( array_slice( $items, 0, $params['limit'] ?? count( $items ) ) );
	}
	public function getObject( $file, $headers ) {
		if ( 'download' === $this->failure ) {
			throw new RuntimeException( 'Download denied' );
		}
		$this->headers = $headers;
		return new class($this->objects[ $file ]) {
			private $contents;
			public function __construct( $contents ) {
				$this->contents = $contents; }
			public function getContent() {
				return $this->contents; }
		};
	}
}

foreach ( array(
	'openstack'  => 'Provider_Test_OpenStack',
	'cloudfiles' => 'Provider_Test_CloudFiles',
) as $id => $class ) {
	$provider = new $class();
	$options  = array_merge(
		$provider->get_default_options(),
		array(
			'user'     => 'test-user',
			'password' => 'test-password',
			'apikey'   => 'test-key',
			'path'     => 'backups',
			'tenant'   => 'test-project',
		)
	);
	$provider->set_options( array(), false, 'test-instance' );
	provider_expect( 'no_settings' === $provider->listfiles()->get_error_code(), "$id listing must reject missing credentials" );
	$provider->set_options( $options );
	$container         = new Provider_Test_Swift_Container();
	$provider->service = $container;
	$name              = 'backup_binary.zip';
	$contents          = "Swift archive\0\xff";
	file_put_contents( $fixture . '/' . $name, $contents );
	// Exercise the provider's small-file fallback, then its chunk callbacks below.
	$backupplus = new class() extends Provider_Test_Job {
		public function chunked_upload( $provider, $file, ...$args ) {
			return 1; }
	};
	$data       = $provider->backup( array( $name ) );
	provider_expect( array( $name ) === $backupplus->uploaded && $contents === $container->objects[ $name ], "$id must upload binary archives and record success" );
	provider_expect( 'test-user' === $provider->service_options['user'], "$id must pass instance credentials to the service" );
	provider_expect( $container === $data['object'] && 'backups' === $data['container'], "$id must return the deletion context" );
	provider_expect(
		array(
			array(
				'name' => $name,
				'size' => strlen( $contents ),
			),
		) === $provider->listfiles(),
		"$id must return remote names and sizes"
	);
	provider_expect( array() === $provider->listfiles( 'unmatched' ), "$id must pass listing filters" );
	provider_expect( $provider->download( $name ) && strlen( $contents ) === end( $backupplus->downloads )['size'], "$id must download using the remote object size" );
	provider_expect( false === $provider->download( 'backup_missing.zip' ), "$id must reject missing downloads" );
	$headers = array( 'Range' => 'bytes=0-5' );
	provider_expect( $contents === $provider->chunked_download( $name, $headers, $container ) && $headers === $container->headers, "$id must forward download range headers" );
	$container->failure = 'download';
	provider_expect( false === $provider->chunked_download( $name, array(), $container ), "$id must catch download errors" );
	$container->failure   = 'upload';
	$backupplus->uploaded = array();
	$provider->backup( array( $name ) );
	provider_expect( array() === $backupplus->uploaded, "$id must not mark failed uploads complete" );
	$container->failure = null;
	// Check pagination past the service's 1000 object page boundary.
	$container->objects = array();
	for ( $index = 0; $index < 1001; $index++ ) {
		$container->objects[ sprintf( 'backup_%04d.zip', $index ) ] = 'data';
	}
	$container->lists = array();
	provider_expect( 1001 === count( $provider->listfiles() ), "$id must fetch all listing pages" );
	provider_expect( 2 === count( $container->lists ) && 'backup_0999.zip' === $container->lists[1]['marker'], "$id must advance the pagination marker" );
	$container->objects = array();
	$fp                 = fopen( 'php://temp', 'w+' );
	fwrite( $fp, $contents );
	rewind( $fp );
	provider_expect( true === $provider->chunked_upload( $name, $fp, 1, strlen( $contents ), 0, strlen( $contents ) - 1, strlen( $contents ) ), "$id must upload a segment" );
	fclose( $fp );
	provider_expect( true === $provider->chunked_upload_finish( $name ) && $contents === $container->objects[ $name ], "$id must assemble segments using a manifest" );
	provider_expect( 'backups/chunk-do-not-delete-' . $name . '_' === $container->headers['X-Object-Manifest'], "$id manifest must identify its own container and segments" );
	$container->failure = 'manifest';
	provider_expect( false === $provider->chunked_upload_finish( $name ), "$id must fail when manifest creation fails" );
	$container->failure = 'delete';
	provider_expect( 'file_delete_error' === $provider->delete( $name ), "$id must report deletion errors" );
	$container->failure = null;
	provider_expect( true === $provider->delete( array( $name ), $data ) && array() === $container->objects, "$id must delete both segments and manifest" );
	$container->failure = 'container';
	provider_expect( 'no_access' === $provider->listfiles()->get_error_code() && 'container_access_error' === $provider->delete( $name ), "$id must report inaccessible containers" );
	$provider->service_error = true;
	provider_expect( 'no_access' === $provider->listfiles()->get_error_code() && false === $provider->backup( array( $name ) ) && false === $provider->download( $name ) && 'service_unavailable' === $provider->delete( $name ), "$id must handle service failures in every operation" );
	echo "PASS: $id uploads, paginated listing, downloads, manifests, segment cleanup and failures\n";
}
