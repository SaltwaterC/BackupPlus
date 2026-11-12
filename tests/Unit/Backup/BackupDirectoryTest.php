<?php
/**
 * BPS-07: the backup directory setting accepts only a single folder name inside wp-content that is not a WordPress, plugin,
 * theme or uploads folder, and the protection files written into the backup directory never replace existing files.
 *
 * scripts/check-backup-directory.php covers the same rules in WordPress, through the settings save and update_option().
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Backup_Directory;
use BackupPlus_Core_Entities;
use BackupPlus_Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BackupDirectoryTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/bp-backup-dir-' . bin2hex( random_bytes( 8 ) );
		define( 'ABSPATH', $this->root . '/' );
		define( 'WP_CONTENT_DIR', $this->root . '/wp-content' );
		foreach ( array( 'plugins', 'mu-plugins', 'themes', 'uploads' ) as $folder ) {
			mkdir( WP_CONTENT_DIR . '/' . $folder, 0700, true );
		}
		mkdir( $this->root . '/outside', 0700 );
		require_once dirname( __DIR__, 2 ) . '/Support/storage-directory-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/Support/backup-directory-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/core/trait-entities.php';
		require_once BACKUPPLUS_DIR . '/includes/class-storage-directory.php';
		require_once BACKUPPLUS_DIR . '/includes/class-backup-directory.php';
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', 'current-backups' );
	}

	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	private function resolver(): object {
		return new class {
			use BackupPlus_Core_Entities;
			public $backup_dir;
		};
	}

	public static function accepted(): array {
		return array(
			'name'                => array( 'my-backups', 'my-backups' ),
			'name with a slash'   => array( 'my-backups/', 'my-backups' ),
			'content prefix'      => array( 'wp-content/my-backups', 'my-backups' ),
			'absolute in content' => array( '{content}/my-backups', 'my-backups' ),
			'default'             => array( '', '' ),
		);
	}

	#[DataProvider( 'accepted' )]
	public function test_a_folder_name_inside_wp_content_is_accepted( string $value, string $stored ): void {
		$this->assertSame( $stored, BackupPlus_Backup_Directory::sanitize( str_replace( '{content}', WP_CONTENT_DIR, $value ) ) );
		$this->assertSame( array(), $GLOBALS['backup_directory_settings_errors'] );
	}

	public static function refused(): array {
		return array(
			'finding example'     => array( 'mu-plugins' ),
			'content prefix'      => array( 'wp-content/mu-plugins' ),
			'plugins'             => array( 'plugins' ),
			'themes'              => array( 'themes' ),
			'uploads'             => array( 'uploads' ),
			'case variant'        => array( 'Uploads' ),
			'languages'           => array( 'languages' ),
			'credential keys'     => array( 'backupplus-private-0123456789abcdef0123456789abcdef' ),
			'web root'            => array( '..' ),
			'traversal'           => array( '../outside' ),
			'nested'              => array( 'uploads/backups' ),
			'hidden'              => array( '.backups' ),
			'absolute outside'    => array( '{root}/outside' ),
			'absolute web root'   => array( '{root}' ),
			'absolute in plugins' => array( '{content}/plugins/backups' ),
			'content directory'   => array( '{content}' ),
			'file system root'    => array( '/' ),
			'NUL'                 => array( "my-backups\0" ),
			'newline'             => array( "my-backups\n.php" ),
			'Windows separator'   => array( '..\\outside' ),
			'not a string'        => array( array( 'my-backups' ) ),
		);
	}

	#[DataProvider( 'refused' )]
	public function test_other_locations_keep_the_saved_value( $value ): void {
		if ( is_string( $value ) ) {
			$value = str_replace( array( '{content}', '{root}' ), array( WP_CONTENT_DIR, $this->root ), $value );
		}
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( $value ) );
		$this->assertCount( 1, $GLOBALS['backup_directory_settings_errors'] );
	}

	public function test_a_link_that_leaves_wp_content_is_refused(): void {
		symlink( $this->root . '/outside', WP_CONTENT_DIR . '/linked' );
		symlink( WP_CONTENT_DIR . '/mu-plugins', WP_CONTENT_DIR . '/loader' );
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( 'linked' ) );
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( 'loader' ) );
		$this->assertCount( 2, $GLOBALS['backup_directory_settings_errors'] );
	}

	public function test_a_folder_with_its_own_protection_files_is_refused(): void {
		mkdir( WP_CONTENT_DIR . '/site-index' );
		file_put_contents( WP_CONTENT_DIR . '/site-index/index.html', '<html><body>Welcome</body></html>' );
		mkdir( WP_CONTENT_DIR . '/site-rules' );
		file_put_contents( WP_CONTENT_DIR . '/site-rules/.htaccess', "RewriteEngine On\n" );
		mkdir( WP_CONTENT_DIR . '/empty-rules' );
		file_put_contents( WP_CONTENT_DIR . '/empty-rules/.htaccess', '' );
		file_put_contents( WP_CONTENT_DIR . '/a-file', 'x' );
		foreach ( array( 'site-index', 'site-rules', 'empty-rules', 'a-file' ) as $name ) {
			$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( $name ), $name );
		}
		$this->assertSame( '<html><body>Welcome</body></html>', file_get_contents( WP_CONTENT_DIR . '/site-index/index.html' ) );
		$this->assertSame( "RewriteEngine On\n", file_get_contents( WP_CONTENT_DIR . '/site-rules/.htaccess' ) );
	}

	public function test_an_existing_backup_folder_is_accepted(): void {
		mkdir( WP_CONTENT_DIR . '/old-backups' );
		BackupPlus_Backup_Directory::protect( WP_CONTENT_DIR . '/old-backups' );
		mkdir( WP_CONTENT_DIR . '/updraft' );
		file_put_contents( WP_CONTENT_DIR . '/updraft/index.html', '<html><body><a href="https://updraftplus.com">WordPress backups by UpdraftPlus</a></body></html>' );
		file_put_contents( WP_CONTENT_DIR . '/updraft/.htaccess', "# Apache 2.4\nRequire all denied\n" );
		mkdir( WP_CONTENT_DIR . '/unprotected' );
		file_put_contents( WP_CONTENT_DIR . '/unprotected/backup_2024-01-01-0000_site_abcdef012345-db.gz', 'x' );
		$this->assertSame( 'old-backups', BackupPlus_Backup_Directory::sanitize( 'old-backups' ) );
		$this->assertSame( 'updraft', BackupPlus_Backup_Directory::sanitize( 'updraft' ) );
		$this->assertSame( 'unprotected', BackupPlus_Backup_Directory::sanitize( 'unprotected' ) );
		$this->assertSame( array(), $GLOBALS['backup_directory_settings_errors'] );
	}

	public function test_another_plugins_folder_and_drop_in_names_are_refused(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', 'current-backups' );
		mkdir( WP_CONTENT_DIR . '/et-cache' );
		file_put_contents( WP_CONTENT_DIR . '/et-cache/style.css', 'x' );
		mkdir( WP_CONTENT_DIR . '/empty-folder' );
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( 'et-cache' ), 'A folder with files BackupPlus did not create must not be taken over' );
		$this->assertSame( 'empty-folder', BackupPlus_Backup_Directory::sanitize( 'empty-folder' ), 'An empty folder may be used' );
		foreach ( array( 'db.php', 'object-cache.php', 'advanced-cache.PHP', 'x.phtml' ) as $name ) {
			$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( $name ), "$name is loaded or run by WordPress or the web server" );
		}
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/et-cache/.htaccess', 'A refused folder must not be protected' );
	}

	public function test_an_unchanged_saved_value_is_kept(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $this->root . '/outside' );
		$this->assertSame( $this->root . '/outside', BackupPlus_Backup_Directory::sanitize( $this->root . '/outside/' ) );
		$this->assertSame( array(), $GLOBALS['backup_directory_settings_errors'] );
		$this->assertSame( $this->root . '/outside', $this->resolver()->backups_dir_location() );
	}

	public function test_the_constant_sets_the_location_and_the_setting_cannot_change_it(): void {
		define( 'BACKUPPLUS_BACKUP_DIRECTORY', $this->root . '/outside/backups/' );
		$this->assertSame( $this->root . '/outside/backups', $this->resolver()->backups_dir_location() );
		$this->assertFileExists( $this->root . '/outside/backups/.htaccess' );
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( $this->root . '/outside/backups' ) );
		$this->assertSame( array(), $GLOBALS['backup_directory_settings_errors'] );
		$this->assertSame( 'current-backups', BackupPlus_Backup_Directory::sanitize( 'my-backups' ) );
		$this->assertCount( 1, $GLOBALS['backup_directory_settings_errors'] );
	}

	public function test_the_constant_cannot_name_a_folder_wordpress_loads_code_from(): void {
		define( 'BACKUPPLUS_BACKUP_DIRECTORY', WP_CONTENT_DIR . '/mu-plugins' );
		$path = $this->resolver()->backups_dir_location();
		$this->assertMatchesRegularExpression( '#^' . preg_quote( WP_CONTENT_DIR, '#' ) . '/backupplus-[a-f0-9]{32}$#', $path );
		$this->assertSame( 'current-backups', BackupPlus_Options::get_backupplus_option( 'backupplus_dir' ), 'The setting is left alone while the constant is defined.' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/mu-plugins/index.html' );
	}

	public static function unsafe_saved_values(): array {
		return array(
			'mu-plugins'          => array( 'mu-plugins', 'wp-content/mu-plugins' ),
			'absolute plugins'    => array( '{content}/plugins', 'wp-content/plugins' ),
			'inside a theme root' => array( '{content}/themes/x', 'wp-content/themes/x' ),
			'web root'            => array( '{root}', '' ),
			'content directory'   => array( '{content}', 'wp-content' ),
			'file system root'    => array( '/', null ),
		);
	}

	#[DataProvider( 'unsafe_saved_values' )]
	public function test_an_unsafe_value_saved_before_the_fix_falls_back_to_the_default( string $saved, ?string $untouched ): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', str_replace( array( '{content}', '{root}' ), array( WP_CONTENT_DIR, $this->root ), $saved ) );
		$path = $this->resolver()->backups_dir_location();
		$this->assertMatchesRegularExpression( '#^' . preg_quote( WP_CONTENT_DIR, '#' ) . '/backupplus-[a-f0-9]{32}$#', $path );
		$this->assertSame( basename( $path ), BackupPlus_Options::get_backupplus_option( 'backupplus_dir' ) );
		if ( null !== $untouched ) {
			foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $guard ) {
				$this->assertFileDoesNotExist( rtrim( $this->root . '/' . $untouched, '/' ) . '/' . $guard );
			}
		}
	}

	public function test_protection_files_are_created_but_never_replaced(): void {
		$directory = WP_CONTENT_DIR . '/current-backups';
		mkdir( $directory );
		file_put_contents( $directory . '/index.html', 'site page' );
		symlink( $this->root . '/outside/target', $directory . '/web.config' );
		$this->assertSame( $directory, $this->resolver()->backups_dir_location() );
		$this->assertSame( 'site page', file_get_contents( $directory . '/index.html' ) );
		$this->assertSame( \BackupPlus_Web_Guard::HTACCESS, file_get_contents( $directory . '/.htaccess' ) );
		$this->assertFileDoesNotExist( $this->root . '/outside/target', 'A dangling link is not followed.' );
	}

	/** BPS-20: the Apache 2.2 file an earlier version wrote is brought up to date; a site's own rules are not. */
	public function test_an_outdated_htaccess_is_upgraded_and_the_folder_is_still_accepted(): void {
		foreach ( array( 'old-backups', 'own-rules' ) as $name ) {
			mkdir( WP_CONTENT_DIR . '/' . $name );
			file_put_contents( WP_CONTENT_DIR . '/' . $name . '/index.html', BackupPlus_Backup_Directory::INDEX_HTML );
		}
		file_put_contents( WP_CONTENT_DIR . '/old-backups/.htaccess', 'deny from all' );
		file_put_contents( WP_CONTENT_DIR . '/own-rules/.htaccess', "# Site rules\nOrder deny,allow\nDeny from all\n" );
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', 'old-backups' );
		$this->assertSame( WP_CONTENT_DIR . '/old-backups', $this->resolver()->backups_dir_location() );
		$this->assertSame( \BackupPlus_Web_Guard::HTACCESS, file_get_contents( WP_CONTENT_DIR . '/old-backups/.htaccess' ) );
		$this->assertSame( 'old-backups', BackupPlus_Backup_Directory::sanitize( 'old-backups' ) );
		BackupPlus_Backup_Directory::protect( WP_CONTENT_DIR . '/own-rules' );
		$this->assertSame( "# Site rules\nOrder deny,allow\nDeny from all\n", file_get_contents( WP_CONTENT_DIR . '/own-rules/.htaccess' ) );
		$this->assertSame( 'own-rules', BackupPlus_Backup_Directory::sanitize( 'own-rules' ) );
		$this->assertSame( array(), $GLOBALS['backup_directory_settings_errors'] );
	}

	/** A folder from very old versions, stored relative to ABSPATH, is still found once its .htaccess has been upgraded. */
	public function test_a_legacy_folder_relative_to_the_web_root_survives_the_upgrade(): void {
		mkdir( $this->root . '/legacy-backups' );
		file_put_contents( $this->root . '/legacy-backups/index.html', BackupPlus_Backup_Directory::INDEX_HTML );
		file_put_contents( $this->root . '/legacy-backups/.htaccess', "deny from all\n" );
		$this->assertSame( $this->root . '/legacy-backups', BackupPlus_Backup_Directory::resolve( 'legacy-backups' ) );
		BackupPlus_Backup_Directory::protect( $this->root . '/legacy-backups' );
		$this->assertSame( \BackupPlus_Web_Guard::HTACCESS, file_get_contents( $this->root . '/legacy-backups/.htaccess' ) );
		$this->assertSame( $this->root . '/legacy-backups', BackupPlus_Backup_Directory::resolve( 'legacy-backups' ) );
	}
}
