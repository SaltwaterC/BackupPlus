<?php
/** Which tables a restore into one site of a network replaces, and which site it restores into. */
namespace BackupPlus\Tests\Unit\Restore;

use BackupPlus_Multisite_Restore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MultisiteRestoreTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/multisite-restore-stubs.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-multisite-restore.php';
	}

	public function test_the_mode_and_target_come_from_the_restore_options(): void {
		$this->assertSame( 'network', BackupPlus_Multisite_Restore::mode( array() ) );
		$this->assertSame( 'network', BackupPlus_Multisite_Restore::mode( array( 'backupplus_restore_ms_whichsites' => '0' ) ) );
		$this->assertSame( 'site', BackupPlus_Multisite_Restore::mode( array( 'backupplus_restore_ms_whichsites' => '3' ) ) );
		$this->assertSame( 3, BackupPlus_Multisite_Restore::target( array( 'backupplus_restore_ms_whichsites' => '3' ) ) );
		$this->assertSame( 'import', BackupPlus_Multisite_Restore::mode( array( 'backupplus_restore_ms_import' => 'new' ) ) );
		$this->assertSame( 0, BackupPlus_Multisite_Restore::target( array( 'backupplus_restore_ms_import' => 'new' ) ), 'A new site has no ID until it is created' );
		$this->assertSame( 4, BackupPlus_Multisite_Restore::target( array( 'backupplus_restore_ms_import' => 4 ) ) );
	}

	public static function tables(): iterable {
		// [restore options, table in the backup, whether it is restored]
		$site = array( 'backupplus_restore_ms_whichsites' => 2 );
		$main = array( 'backupplus_restore_ms_whichsites' => 1 );
		$new  = array( 'backupplus_restore_ms_import' => 5 );
		yield 'a whole network: every table' => array( array(), 'wp_users', true );
		yield 'a whole network: tables without the prefix' => array( array(), 'other_table', true );
		yield 'a site: its tables' => array( $site, 'wp_2_posts', true );
		yield "a site: another site's tables" => array( $site, 'wp_3_posts', false );
		yield "a site: the main site's tables" => array( $site, 'wp_posts', false );
		yield 'a site: the users' => array( $site, 'wp_users', false );
		yield 'a site: a table of site 20, not 2' => array( $site, 'wp_20_posts', false );
		yield 'the main site: its tables' => array( $main, 'wp_options', true );
		yield "the main site: another site's tables" => array( $main, 'wp_2_options', false );
		yield 'the main site: the users' => array( $main, 'wp_usermeta', false );
		yield 'the main site: the sites' => array( $main, 'wp_blogs', false );
		yield 'the main site: network options' => array( $main, 'wp_sitemeta', false );
		yield 'the main site: tables without the prefix' => array( $main, 'other_table', false );
		yield 'an import: the site tables' => array( $new, 'wp_posts', true );
		yield 'an import: the users, to merge' => array( $new, 'wp_users', true );
		yield 'an import: tables without the prefix' => array( $new, 'other_table', false );
	}

	#[DataProvider( 'tables' )]
	public function test_a_restore_into_one_site_replaces_only_its_tables( array $options, string $table, bool $restored ): void {
		$unprefixed = 0 === strpos( $table, 'wp_' ) ? substr( $table, 3 ) : $table;
		$this->assertSame( $restored, BackupPlus_Multisite_Restore::restore_this_table( true, $unprefixed, $options, $table, 'wp_' ) );
	}

	public static function old_folders(): iterable {
		// [restore options, the old folders and their entries, the folder that blocks a restore from the dashboard]
		$old    = '/content/uploads-old';
		$others = array(
			$old              => array( 'sites' ),
			$old . '/sites'   => array( '3' ),
			$old . '/sites/3' => array( '2026' ),
		);
		yield 'a site: its own kept uploads' => array( array( 'backupplus_restore_ms_whichsites' => 3 ), $others, $old . '/sites/3' );
		yield "a site: another site's kept uploads" => array( array( 'backupplus_restore_ms_whichsites' => 2 ), $others, false );
		yield "the main site: other sites' kept uploads" => array( array( 'backupplus_restore_ms_whichsites' => 1 ), $others, false );
		yield 'the main site: its own kept uploads' => array( array( 'backupplus_restore_ms_whichsites' => 1 ), array( $old => array( 'sites', '2026' ) ), $old );
		yield 'an import over a site: its kept uploads' => array( array( 'backupplus_restore_ms_import' => 3 ), $others, $old . '/sites/3' );
		yield 'an import into a new site: none' => array( array( 'backupplus_restore_ms_import' => 'new' ), $others, false );
		yield 'an emptied folder' => array( array( 'backupplus_restore_ms_whichsites' => 3 ), array( $old . '/sites/3' => array() ), false );
	}

	/** A restore into one site is refused for that site's kept uploads only, not for those kept by restores of the network or of other sites. */
	#[DataProvider( 'old_folders' )]
	public function test_only_the_sites_own_kept_uploads_block_a_restore( array $options, array $directories, string|false $blocking ): void {
		$GLOBALS['wp_filesystem'] = new \Multisite_Restore_Test_Filesystem( $directories );
		BackupPlus_Multisite_Restore::record_options( $options );
		$this->assertSame( $blocking, BackupPlus_Multisite_Restore::existing_old_folder( '/backups/uploads-old', 'uploads', '/content/uploads/' ) );
	}

	public function test_a_whole_network_restore_keeps_the_usual_check(): void {
		BackupPlus_Multisite_Restore::record_options( array() );
		$this->assertSame( '/backups/uploads-old', BackupPlus_Multisite_Restore::existing_old_folder( '/backups/uploads-old', 'uploads', '/content/uploads/' ) );
		BackupPlus_Multisite_Restore::record_options( array( 'backupplus_restore_ms_whichsites' => 3 ) );
		$this->assertSame( '/backups/plugins-old', BackupPlus_Multisite_Restore::existing_old_folder( '/backups/plugins-old', 'plugins', '/content/plugins/' ) );
	}

	public function test_a_table_already_excluded_stays_excluded(): void {
		$this->assertFalse( BackupPlus_Multisite_Restore::restore_this_table( false, '2_posts', array( 'backupplus_restore_ms_whichsites' => 2 ), 'wp_2_posts', 'wp_' ) );
	}
}
