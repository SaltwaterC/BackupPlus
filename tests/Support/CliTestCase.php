<?php
/**
 * Base class for the CLI tests: the WordPress and WP-CLI shims, a private directory with an archives folder and the
 * option and cron state the shims read.
 */

namespace BackupPlus\Tests\Support;

use CLI_Test_Backup;
use CLI_Test_DB;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_CLI;

abstract class CliTestCase extends TestCase {

	protected string $directory = '';

	/** The options store of the shims (bound to $GLOBALS['options']). */
	protected array $options = array();

	/** The cron array of the shims (bound to $GLOBALS['cron']). */
	protected array $cron = array();

	protected CLI_Test_Backup $backupplus;

	protected function setUp(): void {
		require_once __DIR__ . '/cli-stubs.php';
		$this->options      = array();
		$this->cron         = array();
		$GLOBALS['options'] = &$this->options;
		$GLOBALS['cron']    = &$this->cron;
		WP_CLI::$runs       = 0;
		$this->directory    = sys_get_temp_dir() . '/backupplus-cli-unit-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory );
		mkdir( $this->directory . '/archives' );
		$this->backupplus            = new CLI_Test_Backup();
		$this->backupplus->directory = $this->directory;
		$GLOBALS['backupplus']       = $this->backupplus;
		$GLOBALS['wpdb']             = new CLI_Test_DB();
	}

	protected function tearDown(): void {
		$this->remove( $this->directory );
	}

	protected function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) {
				$this->remove( $path . '/' . $name );
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/** The callback must fail with an invalid argument or a runtime error. */
	protected function assertRejected( callable $callback, string $message ): void {
		$thrown = null;
		try {
			$callback();
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			$thrown = $error;
		}
		$this->assertNotNull( $thrown, $message );
	}
}
