<?php
/**
 * BPS-04: text from remote storage, archives and dumps reaches the browser as text. scripts/check-xss-sinks.php renders the
 * server-side sinks in WordPress; these checks cover the browser side, which has no PHP to run, by reading the shipped sources.
 */

namespace BackupPlus\Tests\Unit\Backup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminMarkupEscapingTest extends TestCase {

	private static function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
	}

	/** Every file that instantiates jstree, including the bundled script the browser loads. */
	public static function jstree_files(): array {
		$files = array();
		foreach ( array( 'includes/admin-common', 'js', 'includes', 'assets-src/includes' ) as $directory ) {
			foreach ( glob( dirname( __DIR__, 3 ) . '/' . $directory . '/*.js' ) as $path ) {
				if ( false !== strpos( basename( $path ), 'jstree' ) && 0 !== strpos( basename( $path ), 'backupplus-' ) ) {
					continue; // The library itself.
				}
				if ( preg_match( '/\.jstree\s*\(\s*\{/', (string) file_get_contents( $path ) ) ) {
					$files[ substr( $path, strlen( dirname( __DIR__, 3 ) ) + 1 ) ] = array( substr( $path, strlen( dirname( __DIR__, 3 ) ) + 1 ) );
				}
			}
		}
		return $files;
	}

	#[DataProvider( 'jstree_files' )]
	public function test_every_jstree_instance_renders_node_names_as_text( string $path ): void {
		$source = self::source( $path );
		$count  = preg_match_all( '/\.jstree\s*\(\s*\{/', $source );
		$forced = preg_match_all( '/\.jstree\s*\(\s*\{\s*(?:\/\/[^\n]*\n\s*)?["\']?core["\']?\s*:\s*\{\s*(?:\/\/[^\n]*\n\s*)?["\']?force_text["\']?\s*:\s*(?:true|!0)/', $source );
		$this->assertGreaterThan( 0, $count );
		$this->assertSame( $count, $forced, "$path creates a jstree without core.force_text, so archive and file names would be parsed as HTML." );
	}

	public function test_raw_backup_info_is_no_longer_decoded_in_either_layer(): void {
		$this->assertStringNotContainsString( 'html_entity_decode', self::source( 'includes/commands/trait-backups.php' ) );
		$this->assertStringNotContainsString( 'textArea.innerHTML', self::source( 'includes/admin-common/ready-tools.js' ) );
		$this->assertStringNotContainsString( 'esc_attr( $rawbackup )', self::source( 'includes/admin/trait-existing-backups.php' ) );
	}

	public function test_restore_progress_labels_escape_the_names_from_the_dump(): void {
		$source = self::source( 'js/backupplus-admin-restore.js' );
		$this->assertStringNotContainsString( "'<strong>'+(restore_data.data.table)+'</strong>'", $source );
		$this->assertStringNotContainsString( "'<strong>'+(restore_data.data.routine_name)+'</strong>'", $source );
		$this->assertStringContainsString( "jQuery('<div>').text(restore_data.data.table).html()", $source );
		$this->assertStringContainsString( "jQuery('<div>').text(restore_data.data.routine_name).html()", $source );
		// The browser loads the minified build, which is generated from a separate source.
		foreach ( array( 'assets-src/js/backupplus-admin-restore.min.js', 'js/backupplus-admin-restore-3-0-0.min.js' ) as $path ) {
			$minified = self::source( $path );
			$this->assertStringNotContainsString( '"<strong>"+e.data.table+"</strong>"', $minified, $path );
			$this->assertStringNotContainsString( '"<strong>"+e.data.routine_name+"</strong>"', $minified, $path );
			$this->assertStringContainsString( 'text(e.data.table).html()', $minified, $path );
			$this->assertStringContainsString( 'text(e.data.routine_name).html()', $minified, $path );
		}
	}
}
