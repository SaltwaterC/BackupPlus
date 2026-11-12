<?php
/**
 * BPS-20: the administrator power tools are gone, template variables cannot replace the template file, and foreign tar
 * archives are read with the plugin's own tar reader instead of a PEAR library that is not shipped.
 */

namespace BackupPlus\Tests\Unit\Backup;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AdminHardeningTest extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/** Every PHP and JavaScript source the plugin ships, without bundled libraries. */
	private static function sources(): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = substr( $file->getPathname(), strlen( self::root() ) + 1 );
			if ( preg_match( '#^(?:vendor|node_modules|tests|scripts|dev|docs|\.cache|\.git)/#', $path ) || ! preg_match( '/\.(?:php|js)\z/', $path ) ) {
				continue;
			}
			$files[ $path ] = file_get_contents( $file->getPathname() );
		}
		return $files;
	}

	public function test_the_power_tools_are_gone(): void {
		foreach ( self::sources() as $path => $source ) {
			foreach ( array( 'call_wordpress_action', 'call_wp_action', 'callwpaction', 'httpget', 'backupplus_broadcastaction', 'get_outgoing_ip_address', 'admin_post_backupplus_decrypt_database' ) as $removed ) {
				$this->assertStringNotContainsString( $removed, $source, "$path must not offer $removed" );
			}
		}
		$this->assertFileDoesNotExist( self::root() . '/includes/admin/trait-http.php' );
	}

	public function test_template_variables_cannot_replace_the_template_file(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once self::root() . '/includes/admin/trait-templates.php';
		$directory = sys_get_temp_dir() . '/bp-templates-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		file_put_contents( $directory . '/intended.php', '<?php echo "intended:" . $value . ":" . ( isset( $extract_these ) && is_array( $extract_these ) ? "array" : "lost" );' );
		file_put_contents( $directory . '/other.php', '<?php echo "other";' );
		$admin = new class() {
			use \BackupPlus_Admin_Templates;
		};
		$run   = new \ReflectionMethod( $admin, 'run_template' );
		ob_start();
		try {
			$run->invoke(
				$admin,
				$directory . '/intended.php',
				array(
					'value'         => 'v',
					'template_file' => $directory . '/other.php',
					'extract_these' => 'replaced',
				)
			);
		} finally {
			$output = ob_get_clean();
			unlink( $directory . '/intended.php' );
			unlink( $directory . '/other.php' );
			rmdir( $directory );
		}
		$this->assertSame( 'intended:v:array', $output, 'A variable named template_file or extract_these must not change what is included' );
	}

	public function test_foreign_tar_archives_do_not_need_pear(): void {
		$unpack = file_get_contents( self::root() . '/includes/restorer/trait-unpack.php' );
		$this->assertStringNotContainsString( 'new UpdraftPlus_Archive_Tar', $unpack );
		$this->assertStringNotContainsString( 'includes/PEAR/', $unpack );
		$this->assertStringContainsString( "'tar'", $unpack, 'Foreign tar archives are read with BackupPlus_Tar' );
	}
}
