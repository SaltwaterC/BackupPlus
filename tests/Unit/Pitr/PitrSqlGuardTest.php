<?php
/**
 * The check that PITR source recovery runs on a logical dump before the restore account imports it (BPS-03): real
 * mysqldump and mariadb-dump output passes with its objects handed to the WordPress account, while statements that
 * change accounts, grants, server state or other databases, and text that would make the mysql client split
 * statements differently from the check, are refused before anything is written.
 */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_PITR_SQL_Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrSqlGuardTest extends PitrTestCase {

	private const HEAD = "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `wp` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\nUSE `wp`;\n";

	protected function setUp(): void {
		parent::setUp();
		$root = dirname( __DIR__, 3 );
		require_once $root . '/includes/class-sql.php';
		require_once $root . '/includes/class-pitr-process.php';
		require_once $root . '/includes/class-pitr-sql-guard.php';
	}

	/** Checks $sql as a whole-database (or selected-table) dump and returns the options and the written copy. */
	private function check( string $sql, string $engine = 'mysql', string $version = '8.4.6', bool $whole = true ): array {
		$input  = $this->directory . '/input-' . bin2hex( random_bytes( 4 ) ) . '.sql';
		$output = $input . '.checked';
		file_put_contents( $input, $sql );
		$options = BackupPlus_PITR_SQL_Guard::sanitize(
			$input,
			$output,
			array(
				'engine'   => $engine,
				'database' => 'wp',
				'definer'  => array( 'pitr_application', '%' ),
				'whole'    => $whole,
				'version'  => $version,
			)
		);
		return array( $options, file_get_contents( $output ) );
	}

	private function refused( string $sql, string $engine = 'mysql', string $version = '8.4.6', bool $whole = true ): string {
		$error = $this->assertRejected(
			function () use ( $sql, $engine, $version, $whole ) {
				$this->check( $sql, $engine, $version, $whole );
			},
			'The check accepted ' . json_encode( $sql )
		);
		$this->assertStringContainsString( 'No database replacement was started.', $error->getMessage() );
		$this->assertSame( array(), glob( $this->directory . '/*.checked' ), 'A refused dump must not leave a checked copy behind.' );
		return $error->getMessage();
	}

	public static function dumps(): array {
		return array(
			'MySQL 8.4 mysqldump'       => array( 'mysql-8.4.sql', 'mysql', '8.4.6', array( 'utf8mb4', 'utf8mb4_0900_ai_ci', 'N' ) ),
			'MariaDB 11.4 mariadb-dump' => array( 'mariadb-11.4.sql', 'mariadb', '11.4.8', array( 'utf8mb4', 'utf8mb4_uca1400_ai_ci', null ) ),
		);
	}

	#[DataProvider( 'dumps' )]
	public function test_real_dumps_pass_with_objects_owned_by_the_site_account( string $file, string $engine, string $version, array $database ): void {
		$sql                       = file_get_contents( dirname( __DIR__, 2 ) . '/Support/pitr-dumps/' . $file );
		list( $options, $checked ) = $this->check( $sql, $engine, $version );

		$this->assertSame( array_combine( array( 'charset', 'collation', 'encryption' ), $database ), $options );
		// The database is created by the restore with these options, and the import is bound to it by --database.
		$this->assertStringNotContainsString( 'CREATE DATABASE', $checked );
		$this->assertDoesNotMatchRegularExpression( '/^USE /m', $checked );
		// Every view, trigger, event and routine belongs to the site account, never to the dump's definer.
		$this->assertStringNotContainsString( '`root`@`localhost`', $checked );
		$this->assertStringNotContainsString( 'DEFINER=`', $checked );
		$this->assertStringNotContainsString( 'SQL SECURITY DEFINER', $checked );
		$this->assertSame( 6, substr_count( $checked, "DEFINER='pitr_application'@'%'" ) );
		$this->assertSame( 2, substr_count( $checked, "DEFINER='pitr_application'@'%' SQL SECURITY INVOKER VIEW `pitr_view`" ) );
		$this->assertStringContainsString( "ALTER PROCEDURE `wp`.`pitr_proc` SQL SECURITY INVOKER;;\n", $checked );
		$this->assertStringContainsString( "ALTER FUNCTION `wp`.`f` SQL SECURITY INVOKER;;\n", $checked );
		// Row data is copied byte for byte, and the comments before dropped statements stay.
		preg_match_all( '/^INSERT .*$/m', $sql, $rows );
		$this->assertNotEmpty( $rows[0] );
		foreach ( $rows[0] as $row ) {
			$this->assertStringContainsString( $row . "\n", $checked );
		}
		$this->assertStringContainsString( "-- Current Database: `wp`\n", $checked );
	}

	public function test_selected_table_dumps_pass_without_a_database(): void {
		list( $options, $checked ) = $this->check( "SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (id int) ENGINE=InnoDB;\nINSERT INTO `t` VALUES (1);\n", 'mysql', '8.4.6', false );
		$this->assertNull( $options );
		$this->assertStringContainsString( 'INSERT INTO `t` VALUES (1);', $checked );
	}

	public static function accepted(): array {
		return array(
			'rows'                                     => array( "INSERT INTO `t` VALUES (1,'a'),(2,-1.5e-10),(3,NULL),(4,0x61),(5,_binary 'a\\'b;c'),(6,X'00');\n" ),
			'an escaped quote keeps the rest a string' => array( "INSERT INTO t VALUES ('a\\'); GRANT ALL ON *.* TO x; -- ');\n" ),
			'a variable named like the client command' => array( "SET @my_delimiter = 1;\n" ),
			'a routine delimiter'                      => array( "DELIMITER ;;\nSET @a=1;;\nDELIMITER ;\n" ),
			'a MariaDB sequence position'              => array( "SELECT SETVAL(`s`, 1001, 0, 0);\n" ),
			'a view of another database'               => array( "CREATE VIEW v AS SELECT * FROM mysql.user;\n" ),
			'a disabled MySQL comment on MariaDB'      => array( "/*!90000 SET @a=1 */;\n", 'mariadb', '11.4.8' ),
		);
	}

	#[DataProvider( 'accepted' )]
	public function test_ordinary_statements_pass( string $sql, string $engine = 'mysql', string $version = '8.4.6' ): void {
		list( , $checked ) = $this->check( self::HEAD . $sql, $engine, $version );
		$this->assertStringNotContainsString( 'USE `wp`', $checked );
	}

	public function test_a_view_without_a_definer_is_given_the_site_account_and_invoker_security(): void {
		list( , $checked ) = $this->check( "CREATE DATABASE `wp`;\nCREATE VIEW v AS SELECT 1;\n" );
		$this->assertStringContainsString( "CREATE DEFINER='pitr_application'@'%' SQL SECURITY INVOKER VIEW v AS SELECT 1;", $checked );
	}

	/** Statements that change accounts, grants or server state, reach outside the database, or run code. */
	public static function privileged(): array {
		return array(
			'create user'                => array( "CREATE USER 'x'@'%' IDENTIFIED BY 'p';\n" ),
			'alter user'                 => array( "ALTER USER 'root'@'localhost' IDENTIFIED BY 'p';\n" ),
			'drop user'                  => array( "DROP USER 'x';\n" ),
			'rename user'                => array( "RENAME USER a TO b;\n" ),
			'create role'                => array( "CREATE ROLE r;\n" ),
			'grant'                      => array( "GRANT ALL ON *.* TO 'x'@'%';\n" ),
			'revoke'                     => array( "REVOKE ALL ON *.* FROM 'x'@'%';\n" ),
			'set global'                 => array( "SET GLOBAL max_connections=1;\n" ),
			'set @@global'               => array( "SET @@global.max_connections=1;\n" ),
			'set persist'                => array( "SET PERSIST max_connections=1;\n" ),
			'global after session'       => array( "SET SESSION a=1, GLOBAL b=2;\n" ),
			'set password'               => array( "SET PASSWORD FOR 'root'@'localhost' = 'x';\n" ),
			'set default role'           => array( "SET DEFAULT ROLE ALL TO x;\n" ),
			'set role'                   => array( "SET ROLE ALL;\n" ),
			'set statement'              => array( "SET STATEMENT max_statement_time=1 FOR SELECT 1;\n" ),
			'install plugin'             => array( "INSTALL PLUGIN x SONAME 'x.so';\n" ),
			'install soname'             => array( "INSTALL SONAME 'x';\n" ),
			'user defined function'      => array( "CREATE FUNCTION f RETURNS STRING SONAME 'x.so';\n" ),
			'create server'              => array( "CREATE SERVER s FOREIGN DATA WRAPPER mysql OPTIONS (HOST 'h');\n" ),
			'create tablespace'          => array( "CREATE TABLESPACE ts ADD DATAFILE 'x.ibd';\n" ),
			'load data'                  => array( "LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t;\n" ),
			'select into outfile'        => array( "SELECT 1 INTO OUTFILE '/tmp/x';\n" ),
			'call'                       => array( "CALL sys.ps_setup_enable_thread(1);\n" ),
			'prepare'                    => array( "PREPARE s FROM 'GRANT ALL ON *.* TO x';\n" ),
			'do'                         => array( "DO 1;\n" ),
			'flush'                      => array( "FLUSH PRIVILEGES;\n" ),
			'binlog'                     => array( "BINLOG 'AAAA';\n" ),
			'kill'                       => array( "KILL 1;\n" ),
			'shutdown'                   => array( "SHUTDOWN;\n" ),
			'change replication source'  => array( "CHANGE REPLICATION SOURCE TO SOURCE_HOST='x';\n" ),
			'handler'                    => array( "HANDLER t OPEN;\n" ),
			'lock instance'              => array( "LOCK INSTANCE FOR BACKUP;\n" ),
			'lock another database'      => array( "LOCK TABLES mysql.user WRITE;\n" ),
			'insert into mysql.user'     => array( "INSERT INTO mysql.user VALUES (1);\n" ),
			'insert quoted mysql.user'   => array( "INSERT INTO `mysql`.`user` VALUES (1);\n" ),
			'create table elsewhere'     => array( "CREATE TABLE other.t (id int);\n" ),
			'create table as select'     => array( "CREATE TABLE t SELECT * FROM mysql.user;\n" ),
			'create table engine select' => array( "CREATE TABLE t (id int) ENGINE=InnoDB AS SELECT 1;\n" ),
			'federated table'            => array( "CREATE TABLE t (id int) ENGINE=FEDERATED CONNECTION='mysql://x';\n" ),
			'data directory'             => array( "CREATE TABLE t (id int) DATA DIRECTORY='/tmp';\n" ),
			'alter table'                => array( "ALTER TABLE t ADD COLUMN x int;\n" ),
			'drop database'              => array( "DROP DATABASE wp;\n" ),
			'use another database'       => array( "USE mysql;\n" ),
			'second database'            => array( "CREATE DATABASE other;\n" ),
			'subquery in rows'           => array( "INSERT INTO t VALUES ((SELECT authentication_string FROM mysql.user LIMIT 1));\n" ),
			'function in rows'           => array( "INSERT INTO t VALUES (sys.f());\n" ),
			'variable in rows'           => array( "INSERT INTO t VALUES (@x);\n" ),
			'on duplicate key update'    => array( "INSERT INTO t VALUES (1) ON DUPLICATE KEY UPDATE a=1;\n" ),
			'insert select'              => array( "INSERT INTO t SELECT * FROM mysql.user;\n" ),
			'setval with a call'         => array( "SELECT SETVAL(`s`, 1001, 0, sleep(1));\n" ),
			'grant in MariaDB comment'   => array( "/*M!100000 GRANT ALL ON *.* TO x */;\n", 'mariadb', '11.4.8' ),
			'grant in versioned comment' => array( "/*!90000 GRANT ALL ON *.* TO x */;\n", 'mysql', '9.1.0' ),
		);
	}

	/** Text that a server or the mysql client reads differently from a simple statement splitter. */
	public static function desynchronising(): array {
		return array(
			'shell command'                     => array( "\\! touch /tmp/pwned\n" ),
			'system command'                    => array( "system touch /tmp/pwned;\n" ),
			'client charset command'            => array( "charset gbk\n" ),
			'multibyte client charset'          => array( "SET NAMES gbk;\n" ),
			'NO_BACKSLASH_ESCAPES'              => array( "SET sql_mode='NO_BACKSLASH_ESCAPES';\n" ),
			'escaped sql mode'                  => array( "SET sql_mode='NO_B\\ACKSLASH_ESCAPES';\n" ),
			'hexadecimal sql mode'              => array( "SET sql_mode=0x4E4F;\n" ),
			'ANSI quotes'                       => array( "/*!40101 SET SQL_MODE='ANSI' */;\n" ),
			'saved mode overwritten'            => array( "SET @saved_sql_mode = @@sql_mode;\nSET @saved_sql_mode = 'NO_BACKSLASH_ESCAPES';\nSET sql_mode = @saved_sql_mode;\n" ),
			'saved mode set by a trigger'       => array( "SET @saved_sql_mode = @@sql_mode;\nDELIMITER ;;\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW SET @saved_sql_mode='ANSI';;\nDELIMITER ;\nSET sql_mode = @saved_sql_mode;\n" ),
			'saved mode under another case'     => array( "SET @saved_sql_mode = @@sql_mode;\nSET @`SAVED_sql_mode` = 'ANSI';\nSET sql_mode = @saved_sql_mode;\n" ),
			'saved mode under an escaped name'  => array( "SET @saved_sql_mode = @@sql_mode;\nSET @'s\\aved_sql_mode' = 'ANSI';\nSET sql_mode = @saved_sql_mode;\n" ),
			'mode from an unknown variable'     => array( "SET sql_mode = @x;\n" ),
			'disabled comment hides a grant'    => array( "/*!99999 SET @a = 1 + */ GRANT ALL ON *.* TO x;\n" ),
			'comment end inside a string'       => array( "/*!50003 SET @a = '*/ GRANT ALL ON *.* TO x; -- ' */;\n" ),
			'delimiter inside a statement'      => array( "SET @a = 1 delimiter ;;\n" ),
			'unsupported delimiter'             => array( "DELIMITER //\n" ),
			'dash comment with a control byte'  => array( "SET @a = 1 --\x01 x; GRANT ALL ON *.* TO x;\n" ),
			'optimizer hint'                    => array( "SELECT /*+ x */ 1;\n" ),
			'nested comment'                    => array( "/*!50003 SET @a = /* x */ 1 */;\n" ),
			'hash comment in versioned comment' => array( "/*!99999 x # */ GRANT ALL ON *.* TO x;\n*/;\n" ),
			'six digit MySQL version'           => array( "/*!100000 SET @a=1 */;\n" ),
			'escaped backslash ends the string' => array( "INSERT INTO t VALUES ('a\\\\'); GRANT ALL ON *.* TO x; -- ');\n" ),
			'unterminated string'               => array( "INSERT INTO t VALUES ('abc);\n" ),
		);
	}

	#[DataProvider( 'privileged' )]
	#[DataProvider( 'desynchronising' )]
	public function test_dangerous_or_ambiguous_sql_is_refused( string $sql, string $engine = 'mysql', string $version = '8.4.6' ): void {
		$this->refused( self::HEAD . $sql, $engine, $version );
	}

	public function test_the_database_definition_must_name_this_database_once(): void {
		$this->assertStringContainsString( 'does not define its database', $this->refused( "INSERT INTO t VALUES (1);\n" ) );
		$this->refused( "CREATE DATABASE `other`;\n" );
		$this->refused( "CREATE DATABASE `wp`;\nCREATE DATABASE `wp`;\n" );
		$this->refused( "CREATE DATABASE `wp`;\n", 'mysql', '8.4.6', false );
	}

	public function test_comments_for_another_engine_or_a_later_version_are_inert(): void {
		// MySQL treats the MariaDB form as an ordinary comment, and neither server runs a later version's comment.
		foreach ( array( "/*M!100000 GRANT ALL ON *.* TO x */;\n", "/*!90000 GRANT ALL ON *.* TO x */;\n" ) as $comment ) {
			$this->assertStringContainsString( $comment, $this->check( self::HEAD . $comment )[1] );
		}
	}
}
