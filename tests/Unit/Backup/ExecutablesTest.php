<?php
/**
 * BPS-20: programs are found only in absolute folders that others cannot write to, and configured values reach a shell command
 * line only escaped or matched against a strict pattern. zip -@ never receives a name with a line break.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_BinZip;
use BackupPlus_Executables;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ExecutablesTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once dirname( __DIR__, 3 ) . '/includes/class-executables.php';
		$this->directory = sys_get_temp_dir() . '/bp-executables-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0755 );
	}

	protected function tearDown(): void {
		$remove = function ( $path ) use ( &$remove ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
				return;
			}
			chmod( $path, 0755 );
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
				$remove( $path . '/' . $entry );
			}
			rmdir( $path );
		};
		$remove( $this->directory );
	}

	private function program( string $folder, int $mode = 0755 ): string {
		if ( ! is_dir( $folder ) ) {
			mkdir( $folder, 0755, true );
		}
		$path = $folder . '/tool';
		file_put_contents( $path, "#!/bin/sh\n" );
		chmod( $path, $mode );
		return $path;
	}

	public function test_relative_empty_and_world_writable_path_entries_are_skipped(): void {
		$this->requirePosix();
		$shared = $this->directory . '/shared';
		mkdir( $shared );
		chmod( $shared, 0777 );
		$found = BackupPlus_Executables::directories( '', implode( PATH_SEPARATOR, array( '', '.', 'bin', $shared, '/usr/bin' ) ) );
		$this->assertNotContains( realpath( $shared ), $found, 'A world-writable folder must not supply programs' );
		$this->assertNotContains( realpath( '.' ), $found, 'The current folder must not supply programs' );
		foreach ( $found as $directory ) {
			$this->assertStringStartsWith( '/', $directory, 'Only absolute folders may supply programs' );
		}
		$this->assertContains( realpath( '/usr/bin' ), $found, 'A system folder on PATH is still searched' );
		$this->assertSame( array_values( array_unique( $found ) ), $found, 'Each folder is listed once' );
	}

	public function test_a_folder_the_php_user_can_write_is_trusted_only_when_configured(): void {
		$this->requirePosix();
		$owned = $this->directory . '/owned';
		$tool  = $this->program( $owned );
		$this->assertFalse( BackupPlus_Executables::trusted_directory( $owned ), 'A folder found on PATH that PHP can write must not supply programs' );
		$this->assertSame( realpath( $owned ), BackupPlus_Executables::trusted_directory( $owned, true ), 'A folder the site owner configured may be writable by PHP' );
		$this->assertFalse( BackupPlus_Executables::trusted_binary( $tool ), 'A program PHP can replace must not be found by search' );
		$this->assertTrue( BackupPlus_Executables::trusted_binary( $tool, true ), 'A configured program may be writable by PHP' );
		$this->assertContains( realpath( $owned ), BackupPlus_Executables::directories( $owned, '' ), 'The configured folder is searched first' );
		$this->assertSame( realpath( $owned ), BackupPlus_Executables::directories( $owned, '' )[0], 'The configured folder is searched first' );
	}

	public function test_world_writable_programs_and_folders_are_never_trusted(): void {
		$this->requirePosix();
		$this->assertFalse( BackupPlus_Executables::trusted_binary( $this->program( $this->directory . '/a', 0777 ), true ), 'A world-writable program must not run' );
		$shared = $this->directory . '/b';
		$tool   = $this->program( $shared );
		chmod( $shared, 0777 );
		$this->assertFalse( BackupPlus_Executables::trusted_binary( $tool, true ), 'A program in a world-writable folder must not run' );
		$link_folder = $this->directory . '/c';
		mkdir( $link_folder );
		symlink( $tool, $link_folder . '/tool' );
		$this->assertFalse( BackupPlus_Executables::trusted_binary( $link_folder . '/tool', true ), 'Links are followed before the checks' );
	}

	public function test_relative_and_malformed_program_paths_are_refused(): void {
		foreach ( array( 'mysqldump', './mysqldump', '', "/usr/bin/zip\n/tmp/x", "/usr/bin/zip\0" ) as $path ) {
			$this->assertFalse( BackupPlus_Executables::trusted_binary( $path, true ), json_encode( $path ) . ' must be refused' );
		}
		$this->assertFalse( BackupPlus_Executables::trusted_directory( 'relative', true ) );
	}

	public function test_program_paths_are_quoted_for_the_shell(): void {
		$this->requirePosix();
		$this->assertSame( "'/opt/my tools/zip'", BackupPlus_Executables::shell_program( '/opt/my tools/zip' ) );
		$this->assertSame( "'/opt/x;touch y/zip'", BackupPlus_Executables::shell_program( '/opt/x;touch y/zip' ) );
	}

	public function test_zip_options_are_split_only_when_every_option_is_plain(): void {
		$this->assertSame( array( '-9', '-n', '.zip:.gz' ), BackupPlus_Executables::split_options( ' -9  -n	.zip:.gz ' ) );
		foreach ( array( '-9; touch /tmp/x', '-9 $(id)', '-9 `id`', "-9 'a b'", '-9 | tee x', "-9\n-n", '-9 >x' ) as $value ) {
			$this->assertNull( BackupPlus_Executables::split_options( $value ), json_encode( $value ) . ' must not be split' );
		}
	}

	public function test_max_allowed_packet_falls_back_unless_it_is_a_size(): void {
		$this->assertSame( '12M', BackupPlus_Executables::max_allowed_packet( '12M' ), 'Without the constant, the default applies' );
		define( 'BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET', '64M --init-command=x' );
		$this->assertSame( '12M', BackupPlus_Executables::max_allowed_packet( '12M' ), 'A value that is not a size is ignored' );
	}

	public function test_only_a_program_list_the_owner_set_counts_as_configured(): void {
		$this->assertFalse( BackupPlus_Executables::configured( 'BACKUPPLUS_ZIP_EXECUTABLE' ), 'An undefined list is not configured' );
		define( 'BACKUPPLUS_ZIP_EXECUTABLE', '/usr/bin/zip' );
		define( 'BACKUPPLUS_ZIP_EXECUTABLE_BUILT_IN', true );
		$this->assertFalse( BackupPlus_Executables::configured( 'BACKUPPLUS_ZIP_EXECUTABLE' ), 'The built-in list must not be trusted like a configured one' );
		define( 'BACKUPPLUS_MYSQLDUMP_EXECUTABLE', '/opt/db/bin/mysqldump' );
		$this->assertTrue( BackupPlus_Executables::configured( 'BACKUPPLUS_MYSQLDUMP_EXECUTABLE' ), 'A list set in wp-config.php is configured' );
	}

	public function test_zip_names_with_line_breaks_are_not_sent_to_zip(): void {
		require_once dirname( __DIR__, 3 ) . '/includes/class-zip.php';
		foreach ( array( "a\nb", "a\rb", "a\r\n" ) as $name ) {
			$this->assertTrue( BackupPlus_BinZip::line_breaking( $name ), json_encode( $name ) . ' would be split by zip -@' );
		}
		$this->assertFalse( BackupPlus_BinZip::line_breaking( 'wp-content/uploads/a b.jpg' ) );
	}

	private function requirePosix(): void {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$this->markTestSkipped( 'POSIX permissions' );
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root owns every folder' );
		}
	}
}
