<?php
/**
 * The statement check of the external database importer (BPS-18): statements the BackupPlus PHP exporter and mysqldump
 * or mariadb-dump write pass, while database switches, names qualified with another schema, dynamic SQL, global settings
 * and other statement kinds are refused, including when hidden behind comments, executable comments or sql_mode.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_External_SQL_Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ExternalSqlGuardTest extends TestCase {

	private const SCHEMAS = array( 'information_schema', 'performance_schema', 'mysql', 'sys', 'target', 'Other', 'session' );

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/backup-sources-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-sql.php';
		require_once BACKUPPLUS_DIR . '/includes/class-external-sql-guard.php';
	}

	private function guard( string $version = '8.0.36', string $sql_mode = 'NO_ENGINE_SUBSTITUTION', string $charset = 'utf8mb4' ): BackupPlus_External_SQL_Guard {
		return new BackupPlus_External_SQL_Guard( 'target', self::SCHEMAS, $version, $sql_mode, $charset );
	}

	public static function accepted(): array {
		return array(
			'PHP exporter header'      => array( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */", 'set' ),
			'drop table'               => array( 'DROP TABLE IF EXISTS `wp_posts`', 'drop table' ),
			'create table'             => array( "CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'create table' ),
			'insert with dotted data'  => array( "INSERT INTO `wp_options` VALUES (1,'siteurl','http://other.example/a.b',3.14),(2,'x','other.t','yes')", 'insert' ),
			'disable keys'             => array( '/*!40000 ALTER TABLE `wp_posts` DISABLE KEYS */', 'alter table' ),
			'lock tables'              => array( 'LOCK TABLES `wp_posts` WRITE', 'lock tables' ),
			'unlock tables'            => array( 'UNLOCK TABLES', 'unlock tables' ),
			'mariadb-dump autocommit'  => array( 'SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0', 'set' ),
			'mariadb-dump commit'      => array( 'COMMIT', 'commit' ),
			'session variable'         => array( 'SET @@SESSION.SQL_LOG_BIN= 0', 'set' ),
			'dotted user variable'     => array( 'SET @other.t = 1', 'set' ),
			'PHP exporter trigger'     => array( 'CREATE TRIGGER `bump` BEFORE INSERT ON `wp_posts` FOR EACH ROW BEGIN SET NEW.post_title = CONCAT(NEW.post_title, \';\'); SET @n = @n + 1; END', 'create trigger' ),
			'routine with definer'     => array( "CREATE DEFINER=`wp`@`localhost` PROCEDURE `count_posts`(OUT n INT)\nBEGIN\n  SELECT COUNT(*) INTO n FROM `wp_posts` p WHERE p.post_status = 'publish';\nEND", 'create procedure' ),
			'function with definer'    => array( "CREATE DEFINER='wp'@'%' FUNCTION `f`(x INT) RETURNS int(11) DETERMINISTIC RETURN x + 1", 'create function' ),
			'mysqldump view'           => array( '/*!50001 CREATE ALGORITHM=UNDEFINED */ /*!50013 DEFINER=`wp`@`localhost` SQL SECURITY DEFINER */ /*!50001 VIEW `v` AS select `wp_posts`.`ID` AS `ID` from `wp_posts` */', 'create view' ),
			'view qualified by target' => array( 'CREATE OR REPLACE VIEW `v` AS SELECT `target`.`wp_posts`.`ID` FROM `target`.`wp_posts`', 'create view' ),
			'mysqldump trigger'        => array( '/*!50003 CREATE*/ /*!50017 DEFINER=`wp`@`localhost`*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `wp_posts` FOR EACH ROW SET NEW.post_title = UPPER(NEW.post_title) */', 'create trigger' ),
			'mysqldump view stand-in'  => array( '/*!50001 DROP VIEW IF EXISTS `v`*/', 'drop view' ),
			'leading comments'         => array( "# Table: wp_posts\n-- note\n/* plain */ DROP TABLE IF EXISTS `wp_posts`", 'drop table' ),
			'skipped versioned USE'    => array( '/*!90000 USE `other` */ INSERT INTO `t` VALUES (1)', 'insert' ),
			'MariaDB-only on MySQL'    => array( '/*M!100616 USE other */ INSERT INTO `t` VALUES (1)', 'insert' ),
			'MariaDB sandbox comment'  => array( "/*M!999999\\- enable the sandbox mode */ \n/*!40101 SET NAMES utf8mb4 */", 'set' ),
			'alias that is no schema'  => array( 'INSERT INTO `t` SELECT a.ID FROM `wp_posts` a', 'insert' ),
			'only a skipped comment'   => array( '/*!99999 USE other */', '' ),
			'saved binary logging'     => array( 'SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN', 'set' ),
			'restored binary logging'  => array( 'SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN', 'set' ),
			'binary logging off'       => array( 'SET SESSION sql_log_bin = OFF, @a = 1', 'set' ),
			'engine without equals'    => array( 'CREATE TABLE `t` (`id` int) ENGINE MyISAM', 'create table' ),
			'engine column inserted'   => array( 'INSERT INTO `t` (engine, connection, debug) VALUES (\'federated\', \'x\', 1)', 'insert' ),
			'engine user variable'     => array( "CREATE PROCEDURE p() BEGIN SET @engine = 'FEDERATED'; END", 'create procedure' ),
		);
	}

	#[DataProvider( 'accepted' )]
	public function test_statements_of_external_dumps_are_accepted( string $sql, string $kind ): void {
		$this->assertSame( $kind, $this->guard()->inspect( $sql ) );
	}

	public static function refused(): array {
		return array(
			'USE'                                => array( 'USE `other`' ),
			'USE after a block comment'          => array( '/* x */ USE other' ),
			'USE after a line comment'           => array( "-- x\nUSE other" ),
			'USE in an executable comment'       => array( '/*!40101 USE other */' ),
			'USE in a bare executable comment'   => array( '/*! USE other */' ),
			'CREATE DATABASE'                    => array( 'CREATE DATABASE `x`' ),
			'DROP SCHEMA'                        => array( '/*!40000 DROP SCHEMA other */' ),
			'ALTER DATABASE'                     => array( 'ALTER DATABASE other CHARACTER SET latin1' ),
			'qualified DROP TABLE'               => array( 'DROP TABLE other.t' ),
			'qualified INSERT, quoted'           => array( 'INSERT INTO `other`.`t` VALUES (1)' ),
			'qualifier case differs'             => array( 'INSERT INTO OTHER.t VALUES (1)' ),
			'qualifier with spaces'              => array( 'INSERT INTO other . t VALUES (1)' ),
			'qualifier split by a comment'       => array( 'INSERT INTO other/* */./* */t VALUES (1)' ),
			'reading another schema'             => array( 'INSERT INTO `t` SELECT * FROM mysql.user' ),
			'three-part column'                  => array( 'CREATE VIEW v AS SELECT other.t.c FROM t' ),
			'CREATE TABLE LIKE another schema'   => array( 'CREATE TABLE `t` LIKE `other`.`t`' ),
			'RENAME through ALTER'               => array( 'ALTER TABLE `t` RENAME TO other.t' ),
			'trigger writing another schema'     => array( 'CREATE TRIGGER x AFTER INSERT ON t FOR EACH ROW INSERT INTO other.log VALUES (NEW.id)' ),
			'qualified name inside exec comment' => array( 'INSERT INTO t /*!40101 SELECT * FROM other.t */' ),
			'PREPARE'                            => array( "PREPARE s FROM 'DROP TABLE other.t'" ),
			'EXECUTE IMMEDIATE'                  => array( "EXECUTE IMMEDIATE CONCAT('DROP TABLE oth', 'er.t')" ),
			'dynamic SQL in a procedure'         => array( "CREATE PROCEDURE p() BEGIN SET @q = CONCAT('DROP TABLE oth', 'er.t'); PREPARE s FROM @q; EXECUTE s; END" ),
			'CALL'                               => array( 'CALL p()' ),
			'CREATE EVENT'                       => array( 'CREATE EVENT e ON SCHEDULE AT CURRENT_TIMESTAMP DO CALL p()' ),
			'SET GLOBAL'                         => array( "SET GLOBAL general_log_file = '/var/www/x.php'" ),
			'SET @@GLOBAL'                       => array( 'SET @@GLOBAL.event_scheduler = 1' ),
			'SET PERSIST in exec comment'        => array( '/*!80000 SET PERSIST max_connections = 1 */' ),
			'SET PASSWORD'                       => array( "SET PASSWORD = 'x'" ),
			'SET STATEMENT FOR'                  => array( 'SET STATEMENT max_statement_time=1 FOR DROP DATABASE other' ),
			'CREATE USER'                        => array( "CREATE USER 'x'@'%' IDENTIFIED BY 'y'" ),
			'non-reserved word as definer'       => array( "CREATE DEFINER=function@localhost USER 'x'@'%'" ),
			'GRANT'                              => array( 'GRANT ALL ON *.* TO x' ),
			'TRUNCATE'                           => array( 'TRUNCATE other.t' ),
			'COMMIT with more'                   => array( 'COMMIT AND CHAIN' ),
			'second statement'                   => array( 'INSERT INTO t VALUES (1); DROP TABLE t2' ),
			'reading a server file'              => array( "INSERT INTO t VALUES (LOAD_FILE('/etc/passwd'))" ),
			'nested comment in exec comment'     => array( '/*!40101 SET @a = 1 /* x */ */' ),
			'line comment in exec comment'       => array( "/*!40101 SET @a = 1 -- */\n, @b = 2 */" ),
			'ambiguous MySQL version'            => array( '/*!400001 USE other */' ),
			'short version'                      => array( '/*!4 USE other */' ),
			'unterminated exec comment'          => array( '/*!40101 SET @a = 1' ),
			'FEDERATED table'                    => array( "CREATE TABLE t (id int) ENGINE=FEDERATED CONNECTION='mysql://u:p@db.example/other/t'" ),
			'FEDERATED in exec comment'          => array( "CREATE TABLE t (id int) /*!50000 ENGINE = `federated` */" ),
			'MariaDB CONNECT table'              => array( "CREATE TABLE t (id int) ENGINE=CONNECT TABLE_TYPE=MYSQL" ),
			'SPIDER partition'                   => array( 'CREATE TABLE t (id int) PARTITION BY HASH (id) (PARTITION p0 ENGINE SPIDER)' ),
			'engine name in quotes'              => array( "CREATE TABLE t (id int) ENGINE='FEDERATED'" ),
			'ALTER to FEDERATED'                 => array( 'ALTER TABLE t ENGINE=FEDERATED' ),
			'CONNECTION without equals'          => array( "ALTER TABLE t CONNECTION 'srv'" ),
			'DATA DIRECTORY'                     => array( "CREATE TABLE t (id int) ENGINE=InnoDB DATA DIRECTORY='/var/www/html'" ),
			'INDEX DIRECTORY'                    => array( "CREATE TABLE t (id int) ENGINE=MyISAM INDEX DIRECTORY = '/tmp'" ),
			'default engine FEDERATED'           => array( 'SET default_storage_engine = FEDERATED' ),
			'binary logging on'                  => array( 'SET @@SESSION.SQL_LOG_BIN = 1' ),
			'binary logging by expression'       => array( 'SET sql_log_bin = 0 + 1' ),
			'binary logging from a sysvar'       => array( 'SET sql_log_bin := @@autocommit' ),
			'gtid_next'                          => array( "SET @@SESSION.GTID_NEXT = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa:1'" ),
			'pseudo_thread_id later in SET'      => array( 'SET @a = 1, @b = 2, SESSION pseudo_thread_id = 5' ),
			'gtid_next in a procedure'           => array( "CREATE PROCEDURE p() BEGIN SET gtid_next = 'AUTOMATIC'; END" ),
		);
	}

	#[DataProvider( 'refused' )]
	public function test_statements_that_could_leave_the_target_schema_are_refused( string $sql ): void {
		$this->expectException( RuntimeException::class );
		$this->guard()->inspect( $sql );
	}

	public function test_mariadb_runs_its_own_executable_comments(): void {
		$guard = $this->guard( '10.11.6-MariaDB-0+deb12u1' );
		$this->assertSame( 'set', $guard->inspect( '/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */' ) );
		$this->assertSame( 'insert', $guard->inspect( '/*M!110000 USE other */ INSERT INTO `t` VALUES (1)' ) );
		$this->expectException( RuntimeException::class );
		$guard->inspect( '/*M!100000 USE other */ INSERT INTO `t` VALUES (1)' );
	}

	public function test_ansi_quotes_make_double_quotes_names(): void {
		$sql = 'INSERT INTO "other"."t" VALUES (1)';
		$this->assertSame( 'insert', $this->guard()->inspect( $sql ), 'Without ANSI_QUOTES these are strings and the server refuses the syntax.' );
		$guard = $this->guard();
		$guard->session( 'REAL_AS_FLOAT,PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ONLY_FULL_GROUP_BY,ANSI', 'utf8mb4' );
		$this->expectException( RuntimeException::class );
		$guard->inspect( $sql );
	}

	public function test_no_backslash_escapes_ends_strings_at_a_backslash(): void {
		$sql = "INSERT INTO t VALUES ('\\', other.x, \\'')";
		$this->assertSame( 'insert', $this->guard()->inspect( $sql ), 'With backslash escapes the name is inside one string.' );
		$this->expectException( RuntimeException::class );
		$this->guard( '8.0.36', 'NO_BACKSLASH_ESCAPES' )->inspect( $sql );
	}

	public function test_character_sets_with_backslash_trail_bytes_are_refused(): void {
		$guard = $this->guard();
		$this->expectException( RuntimeException::class );
		$guard->session( '', 'gbk' );
	}

	public function test_only_schemas_other_than_the_target_are_refused(): void {
		$guard = new BackupPlus_External_SQL_Guard( 'Target', array( 'target', 'wp' ), '8.0.36', '', 'utf8mb4' );
		$this->assertSame( 'drop table', $guard->inspect( 'DROP TABLE `TARGET`.`t`' ) );
		$this->assertSame( 'create view', $guard->inspect( 'CREATE VIEW v AS SELECT other.c FROM t AS other' ), 'An alias is not refused when no schema has its name.' );
		$this->expectException( RuntimeException::class );
		$guard->inspect( 'DROP TABLE wp.t' );
	}

	public function test_the_refusal_names_the_other_schema(): void {
		$this->expectExceptionMessage( 'refers to the schema other' );
		$this->guard()->inspect( 'DROP TABLE `Other`.`t`' );
	}
}
