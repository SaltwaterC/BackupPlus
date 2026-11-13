<?php
/** The restore log records WordPress's upgrader messages, which are HTML, as plain text. */
namespace BackupPlus\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RestorerSkinTest extends TestCase {

	private object $log;

	protected function setUp(): void {
		eval(
			'
			define( "ABSPATH", "/" );
			define( "BACKUPPLUS_DIR", ' . var_export( dirname( __DIR__, 3 ), true ) . ' );
			class WP_Upgrader_Skin {
				public $upgrader;
			}
			function wp_strip_all_tags( $text ) {
				return trim( strip_tags( $text ) );
			}
			function backupplus_try_include_file( $path, $mode ) {
				require_once BACKUPPLUS_DIR . "/" . $path;
			}
			'
		);
		$this->log             = new class() {
			public array $lines = array();
			public function get_wordpress_version() {
				return "6.8";
			}
			public function log( $line, $level = "notice" ) {
				$this->lines[] = array( $line, $level );
			}
		};
		$GLOBALS['backupplus'] = $this->log;
		require_once dirname( __DIR__, 3 ) . '/includes/backupplus-restorer-skin.php';
	}

	public function test_upgrader_messages_are_logged_as_plain_text(): void {
		$skin           = new \BackupPlus_Restorer_Skin();
		$skin->upgrader = (object) array( 'strings' => array( 'maintenance_end' => 'Disabling Maintenance mode&#8230;' ) );
		$skin->feedback( 'maintenance_end' );
		$skin->feedback( '<strong>Unpacking</strong> &amp; checking 100% of the files' );
		$skin->feedback( '' );
		$this->assertSame(
			array(
				array( 'Disabling Maintenance mode…', 'notice' ),
				array( 'Disabling Maintenance mode…', 'notice-restore' ),
				array( 'Unpacking & checking 100% of the files', 'notice' ),
				array( 'Unpacking & checking 100% of the files', 'notice-restore' ),
			),
			$this->log->lines
		);
	}
}
