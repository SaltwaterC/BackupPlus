<?php
/**
 * BPS-19: scripts/check-sql.php catches each way of reintroducing assembled SQL that the review found it missed, and
 * leaves alone the constant templates, dump executors and non-SQL text the plugin uses.
 *
 * Each fixture in tests/Support/check-sql is checked on its own. A failing fixture marks every line the check must
 * report with an "expect" comment; a passing fixture must produce no report at all.
 */

namespace BackupPlus\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlConstructionCheckTest extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/** Exit status and reported "line => message" of the check run on one fixture. */
	private static function check( string $fixture ): array {
		$directory = sys_get_temp_dir() . '/backupplus-check-sql-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		copy( $fixture, $directory . '/fixture.php' );
		$process = proc_open(
			array( PHP_BINARY, self::root() . '/scripts/check-sql.php', $directory ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$output  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		unlink( $directory . '/fixture.php' );
		rmdir( $directory );
		$reported = array();
		foreach ( explode( "\n", $output ) as $line ) {
			if ( preg_match( '/\Afixture\.php:(\d+) (.+)\z/', $line, $match ) ) { $reported[ (int) $match[1] ] = $match[2]; }
		}
		return array( $status, $reported, $output );
	}

	private static function fixtures( string $kind ): array {
		$fixtures = array();
		foreach ( glob( self::root() . '/tests/Support/check-sql/' . $kind . '/*.inc' ) as $path ) {
			$fixtures[ basename( $path, '.inc' ) ] = array( $path );
		}
		return $fixtures;
	}

	public static function failing(): array {
		return self::fixtures( 'fail' );
	}

	public static function passing(): array {
		return self::fixtures( 'pass' );
	}

	#[DataProvider( 'failing' )]
	public function test_each_pattern_is_reported_on_its_line( string $fixture ): void {
		$expected = array();
		foreach ( file( $fixture ) as $index => $line ) {
			if ( preg_match( '#// expect\b|/\* expect \*/#', $line ) ) { $expected[] = $index + 1; }
		}
		$this->assertNotEmpty( $expected, 'The fixture marks no line.' );
		list( $status, $reported, $output ) = self::check( $fixture );
		$this->assertSame( 1, $status, $output );
		$this->assertSame( $expected, array_keys( $reported ), $output );
	}

	#[DataProvider( 'passing' )]
	public function test_templates_and_text_that_is_not_sql_pass( string $fixture ): void {
		list( $status, $reported, $output ) = self::check( $fixture );
		$this->assertSame( array(), $reported, $output );
		$this->assertSame( 0, $status, $output );
	}

	public function test_the_messages_name_the_problem(): void {
		$messages = array();
		foreach ( self::failing() as $fixture ) { $messages = array_merge( $messages, array_values( self::check( $fixture[0] )[1] ) ); }
		foreach ( array(
			'appends a value to SQL text with ".=".',
			'passes SQL assembled from text and values to script().',
			'builds SQL by concatenation or interpolation.',
			'calls a method by a computed name',
			'passes an SQL method around as a callable',
			'names an SQL function as a callable',
			'passes non-constant SQL to fragment().',
			'uses $wpdb->insert()',
			'uses ->prepare()',
			'builds SQL with implode().',
			'builds SQL with sprintf().',
			'refers to BackupPlus_SQL_Fragment',
			'names BackupPlus_SQL_Fragment in a string',
		) as $message ) {
			$found = array_filter(
				$messages,
				function ( $reported ) use ( $message ) {
					return false !== strpos( $reported, $message );
				}
			);
			$this->assertNotEmpty( $found, $message );
		}
	}

	public function test_the_plugin_passes(): void {
		$process = proc_open(
			array( PHP_BINARY, self::root() . '/scripts/check-sql.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$output  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $output );
	}
}
