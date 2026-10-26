<?php
/** Fake Swift service for the OpenStack and Rackspace Cloud Files provider tests. Non-namespaced, loaded once per process. */

require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
require_once BACKUPPLUS_DIR . '/methods/openstack.php';
require_once BACKUPPLUS_DIR . '/methods/cloudfiles.php';

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
		\PHPUnit\Framework\Assert::assertSame( 'backups', $name, 'Swift operations must use the configured container' );
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

/** Job whose chunked uploads are not supported, to exercise the provider's small-file fallback. */
class Provider_Test_Swift_Job extends Provider_Test_Job {
	public function chunked_upload( $provider, $file, ...$args ) {
		return 1; }
}
