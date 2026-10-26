<?php
/**
 * Base class for tests that run one of the standalone scripts in its own process.
 *
 * Each script is a test case with its own result. Scripts of a class that allows concurrency run side by side in a
 * small process pool; the others run strictly one after the other.
 *
 * Scripts that need WordPress use the placeholders %SITE% (container) and %PORT% (the port of its URL) in their command and environment. Each
 * such script leases one of the isolated test sites (dev/test-sites.sh) for as long as it runs, so scripts of different
 * classes and even of different PHPUnit processes use different sites and never see each other's options or files.
 */

namespace BackupPlus\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class ScriptTestCase extends TestCase {

	/**
	 * @var array<string, array{command: string[], environment: array<string, string>, process: resource|null, file: string, output: string, exit: int|null, deadline: int, timed_out: bool, site: int|null, lease: resource|null, started: float}>
	 */
	private static array $jobs = array();

	/**
	 * The scripts of the class.
	 *
	 * @return array<string, array{0: string[], 1?: array<string, string>}> Label => command (no shell) and variables added to the environment.
	 */
	abstract protected static function commands(): array;

	/** How many scripts of the class may run at once. Anything that shares the WordPress site must stay at 1. */
	protected static function concurrency(): int {
		return 1;
	}

	/** Seconds before a script is killed. */
	protected static function timeout(): int {
		return 1800;
	}

	/** The upper bound for concurrency, from TEST_JOBS or half the CPUs. */
	protected static function jobs_limit(): int {
		$jobs = (int) getenv( 'TEST_JOBS' );
		if ( $jobs < 1 ) {
			$cpus = (int) trim( (string) @shell_exec( 'nproc 2>/dev/null' ) );
			$jobs = max( 1, intdiv( max( 1, $cpus ), 2 ) );
		}
		return $jobs;
	}

	/** Data provider: one dataset per script. */
	public static function scripts(): iterable {
		foreach ( array_keys( static::commands() ) as $label ) {
			yield $label => array( $label );
		}
	}

	public static function setUpBeforeClass(): void {
		self::$jobs = array();
		foreach ( static::commands() as $label => $spec ) {
			self::$jobs[ $label ] = array(
				'command'     => $spec[0],
				'environment' => $spec[1] ?? array(),
				'process'     => null,
				'file'        => '',
				'output'      => '',
				'exit'        => null,
				'deadline'    => 0,
				'timed_out'   => false,
				'site'        => null,
				'lease'       => null,
				'started'     => 0.0,
			);
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ( self::$jobs as &$job ) {
			if ( is_resource( $job['process'] ) ) {
				proc_terminate( $job['process'], 9 );
				proc_close( $job['process'] );
			}
			if ( '' !== $job['file'] && is_file( $job['file'] ) ) {
				unlink( $job['file'] );
			}
			self::release_site( $job );
		}
		unset( $job );
		self::$jobs = array();
	}

	/** Absolute path of the plugin directory, which is also the working directory of every script. */
	protected static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** The container runtime the host-driven suites use; the Makefile passes the same variable on. */
	protected static function podman(): string {
		return getenv( 'PODMAN' ) ?: 'podman';
	}

	/**
	 * The test sites: [ container, port ] each. WP_TEST_CONTAINER (and BACKUPPLUS_TEST_URL) pins the suites to one site of
	 * your choosing, for example the development one; otherwise there are TEST_SITES (default 8) isolated sites.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	protected static function sites(): array {
		$pinned = getenv( 'WP_TEST_CONTAINER' );
		if ( $pinned ) {
			$port = (int) ( parse_url( (string) getenv( 'BACKUPPLUS_TEST_URL' ), PHP_URL_PORT ) ?: 8080 );
			return array( array( $pinned, $port ) );
		}
		$sites = array();
		for ( $n = 1, $count = max( 1, (int) ( getenv( 'TEST_SITES' ) ?: 8 ) ); $n <= $count; $n++ ) {
			$sites[] = array( 'backupplus-test-' . $n . '-wordpress', 18080 + $n );
		}
		return $sites;
	}

	/** Placeholders that make a script lease a site while it runs. */
	protected static function site_environment(): array {
		return array(
			'PODMAN'              => self::podman(),
			'WP_TEST_CONTAINER'   => '%SITE%',
			'BACKUPPLUS_TEST_URL' => 'http://localhost:%PORT%',
		);
	}

	/** Container placeholder for commands that run wp or other tools inside the site. */
	protected static function site_container(): string {
		return '%SITE%';
	}

	/** Take the first free site; the lock lasts as long as the returned handle is open, also across processes. */
	private static function lease_site(): ?array {
		foreach ( self::sites() as $index => $site ) {
			$handle = fopen( sys_get_temp_dir() . '/backupplus-' . $site[0] . '.lock', 'c' );
			if ( false !== $handle && flock( $handle, LOCK_EX | LOCK_NB ) ) {
				return array( $index, $handle );
			}
			if ( false !== $handle ) {
				fclose( $handle );
			}
		}
		return null;
	}

	private static function release_site( array &$job ): void {
		if ( is_resource( $job['lease'] ) ) {
			flock( $job['lease'], LOCK_UN );
			fclose( $job['lease'] );
		}
		$job['lease'] = null;
	}

	private static function needs_site( array $job ): bool {
		foreach ( array_merge( $job['command'], array_values( $job['environment'] ) ) as $value ) {
			if ( false !== strpos( $value, '%SITE%' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wait for the script and fail with its output unless it exited with 0.
	 *
	 * Scripts keep their own stubs, ini flags and assertions, so a script that passes standalone passes here.
	 */
	protected function assertScriptPasses( string $label ): void {
		$this->assertArrayHasKey( $label, self::$jobs, 'Unknown script: ' . $label );
		$printable = implode( ' ', self::$jobs[ $label ]['command'] );
		self::wait_for( $label );
		$job = self::$jobs[ $label ];

		$this->assertFalse( $job['timed_out'], 'Timed out after ' . static::timeout() . "s: {$printable}\n" . self::tail( $job['output'] ) );
		$this->assertSame( 0, $job['exit'], "Exit status {$job['exit']}: {$printable}\n" . self::tail( $job['output'] ) );
	}

	/** Start the script if it is not running yet and wait until it has finished. */
	private static function wait_for( string $label ): void {
		while ( null === self::$jobs[ $label ]['exit'] ) {
			self::advance( $label );
			if ( null === self::$jobs[ $label ]['exit'] ) {
				usleep( 50000 );
			}
		}
	}

	/** Collect finished scripts, then start what fits: the awaited script first, then the ones after it. */
	private static function advance( string $awaited ): void {
		$running = self::collect();
		$limit   = max( 1, min( static::concurrency(), self::jobs_limit() ) );
		// Running the whole class, start scripts ahead of the awaited one; a --filter run starts only what is asked for.
		$ahead      = ! in_array( '--filter', $_SERVER['argv'] ?? array(), true );
		$candidates = $ahead ? array_unique( array_merge( array( $awaited ), array_keys( self::$jobs ) ) ) : array( $awaited );
		foreach ( $candidates as $label ) {
			if ( $running >= $limit ) {
				break;
			}
			if ( null === self::$jobs[ $label ]['exit'] && null === self::$jobs[ $label ]['process'] && self::start( $label ) ) {
				++$running;
			}
		}
	}

	/**
	 * Record the result of every script that has finished or ran out of time.
	 *
	 * @return int How many scripts are still running.
	 */
	private static function collect(): int {
		$running = 0;
		foreach ( self::$jobs as $label => &$job ) {
			if ( ! is_resource( $job['process'] ) ) {
				continue;
			}
			$status = proc_get_status( $job['process'] );
			if ( $status['running'] && time() > $job['deadline'] ) {
				$job['timed_out'] = true;
				proc_terminate( $job['process'], 9 );
				usleep( 100000 );
				$status = proc_get_status( $job['process'] );
			}
			if ( $status['running'] ) {
				++$running;
				continue;
			}
			proc_close( $job['process'] );
			$job['process'] = null;
			$job['exit']    = $job['timed_out'] ? -1 : $status['exitcode'];
			$job['output']  = (string) file_get_contents( $job['file'] );
			unlink( $job['file'] );
			$job['file'] = '';
			self::release_site( $job );
			self::log_timing( $label, $job );
		}
		unset( $job );
		return $running;
	}

	/** Start the script unless it waits for a free site; true when a process (or its failure) was recorded. */
	private static function start( string $label ): bool {
		$job         = &self::$jobs[ $label ];
		$command     = $job['command'];
		$environment = $job['environment'];
		if ( self::needs_site( $job ) ) {
			$lease = self::lease_site();
			if ( null === $lease ) {
				return false;
			}
			list( $index, $job['lease'] ) = $lease;
			$job['site']                  = $index;
			$site                         = self::sites()[ $index ];
			$replace                      = function ( $value ) use ( $site ) {
				return str_replace( array( '%SITE%', '%PORT%' ), array( $site[0], (string) $site[1] ), $value );
			};
			$command                      = array_map( $replace, $command );
			$environment                  = array_map( $replace, $environment );
		}
		$file    = tempnam( sys_get_temp_dir(), 'backupplus-test-' );
		$process = proc_open(
			$command,
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', $file, 'a' ),
				2 => array( 'file', $file, 'a' ),
			),
			$pipes,
			self::root(),
			array_merge( getenv(), $environment )
		);
		if ( ! is_resource( $process ) ) {
			$job['exit']   = -1;
			$job['output'] = 'Could not start: ' . implode( ' ', $command );
			unlink( $file );
			self::release_site( $job );
			return true;
		}
		$job['process']  = $process;
		$job['file']     = $file;
		$job['deadline'] = time() + static::timeout();
		$job['started']  = microtime( true );
		return true;
	}

	/** With BACKUPPLUS_TEST_TIMINGS=<file>, append how long every script took and on which site, to find the long poles. */
	private static function log_timing( string $label, array $job ): void {
		$file = getenv( 'BACKUPPLUS_TEST_TIMINGS' );
		if ( $file ) {
			file_put_contents( $file, sprintf( "%.1f\t%s\t%s\t%s\n", microtime( true ) - $job['started'], static::class, $label, null === $job['site'] ? '-' : $job['site'] + 1 ), FILE_APPEND | LOCK_EX );
		}
	}

	/** The end of the output, which is where a script reports what failed. */
	private static function tail( string $output ): string {
		$lines = explode( "\n", rtrim( $output ) );
		return implode( "\n", array_slice( $lines, -60 ) );
	}
}
