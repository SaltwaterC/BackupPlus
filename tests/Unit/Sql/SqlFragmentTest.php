<?php
/**
 * BPS-19: a BackupPlus_SQL_Fragment exists only as compiled by BackupPlus_SQL::fragment(). Constructing one, unserializing
 * one, or making one with Reflection does not yield a fragment that a template accepts.
 */

namespace BackupPlus\Tests\Unit\Sql;

use BackupPlus_SQL;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SqlFragmentTest extends TestCase {

	private const FRAGMENT = 'BackupPlus_SQL_Fragment';

	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' ); }
		require_once dirname( __DIR__, 3 ) . '/includes/class-sql.php';
	}

	public function test_fragments_compile_from_templates(): void {
		$fragment = BackupPlus_SQL::fragment( '%i = ?', array( 'name' ), array( 'value' ) );
		$this->assertSame( '`name` = ?', $fragment->sql() );
		$this->assertSame( array( 'value' ), $fragment->values() );
		$this->assertSame( 'SELECT * FROM `t` WHERE (`name` IS NULL)', BackupPlus_SQL::render( 'SELECT * FROM %i WHERE %w', array( 't', array( BackupPlus_SQL::fragment( '%i IS NULL', array( 'name' ) ) ) ) ) );
	}

	public function test_the_constructor_is_private(): void {
		$class = self::FRAGMENT;
		$this->expectException( Error::class );
		new $class( 'DROP TABLE wp_users', array() );
	}

	public function test_a_fragment_cannot_be_unserialized(): void {
		$name       = self::FRAGMENT;
		$serialized = 'O:' . strlen( $name ) . ':"' . $name . '":2:{s:' . ( strlen( $name ) + 5 ) . ':"' . "\0" . $name . "\0" . 'sql";s:19:"DROP TABLE wp_users";s:' . ( strlen( $name ) + 8 ) . ':"' . "\0" . $name . "\0" . 'values";a:0:{}}';
		$this->expectException( LogicException::class );
		unserialize( $serialized );
	}

	public function test_an_instance_made_without_the_constructor_is_refused(): void {
		$forged = ( new ReflectionClass( self::FRAGMENT ) )->newInstanceWithoutConstructor();
		$this->expectException( InvalidArgumentException::class );
		BackupPlus_SQL::render( 'SELECT %f', array( $forged ) );
	}
}
