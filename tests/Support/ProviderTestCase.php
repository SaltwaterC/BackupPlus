<?php
/**
 * Base class for the storage provider tests.
 *
 * The real providers run against shims for the WordPress functions and a job object; only the network boundary is
 * faked. The shims are defined once per process, the state they keep is reset before every test.
 */

namespace BackupPlus\Tests\Support;

use BackupPlus_BackupModule;
use BackupPlus_Options;
use BackupPlus_Storage_Provider_Factory;
use Cloud_Test_HTTP;
use PHPUnit\Framework\Exception as PHPUnitException;
use PHPUnit\Framework\TestCase;
use Provider_Test_Job;
use RuntimeException;

abstract class ProviderTestCase extends TestCase {

	/** Directory that doubles as ABSPATH and as the backups directory of the job. */
	protected string $fixture = '';

	protected Provider_Test_Job $job;

	protected Cloud_Test_HTTP $http;

	/** Callbacks registered through the add_filter()/add_action() shims, by hook and priority. */
	protected array $filters = array();

	/** Messages captured by the wp_mail() shim. */
	protected array $mail = array();

	protected bool $mail_success = true;

	/** Arguments of every request that went through the wp_remote_request() shim. */
	protected array $requests = array();

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/provider-stubs.php';
	}

	protected function setUp(): void {
		$this->fixture = rtrim( ABSPATH, '/' );
		foreach ( glob( $this->fixture . '/{,.}*[!.]', GLOB_BRACE ) as $file ) {
			unlink( $file );
		}

		BackupPlus_Options::$options = array();

		$this->filters                    = array();
		$this->mail                       = array();
		$this->mail_success               = true;
		$GLOBALS['provider_filters']      = &$this->filters;
		$GLOBALS['provider_mail']         = &$this->mail;
		$GLOBALS['provider_mail_success'] = &$this->mail_success;
		$this->requests                   = array();
		$GLOBALS['cloud_request_args']    = &$this->requests;
		$this->http                       = new Cloud_Test_HTTP();
		$GLOBALS['cloud_http']            = $this->http;
		$this->job                        = new Provider_Test_Job();
		$this->job->backup_methods        = BackupPlus_Storage_Provider_Factory::get_methods();
		$GLOBALS['backupplus']            = $this->job;
	}

	/** The provider as the factory builds it, with its default options set. */
	protected function create_provider( string $id ): BackupPlus_BackupModule {
		$provider = BackupPlus_Storage_Provider_Factory::create( $id, false );
		$this->assertInstanceOf( BackupPlus_BackupModule::class, $provider, 'Factory could not construct ' . $id . ': ' . ( is_wp_error( $provider ) ? $provider->get_error_message() : 'invalid object' ) );
		$this->assertFalse( is_a( $provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ), $id . ' must have an implementation' );
		$provider->set_options( $provider->get_default_options(), false, 'test-instance' );
		return $provider;
	}

	/** Run the callback, which must throw a RuntimeException, and return that exception. */
	protected function thrown( callable $callback, string $message ): RuntimeException {
		try {
			$callback();
		} catch ( PHPUnitException $failure ) {
			// A failed assertion inside a fake service is a RuntimeException too, but never the expected error.
			throw $failure;
		} catch ( RuntimeException $error ) {
			$this->addToAssertionCount( 1 );
			return $error;
		}
		$this->fail( $message );
	}
}
