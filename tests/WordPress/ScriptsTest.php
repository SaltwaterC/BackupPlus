<?php
/**
 * Scripts that need real WordPress. They run inside a test site with wp eval-file. Each script leases a whole site while it
 * runs, because the scripts change the site's database, options and backup directory; with several sites they run side by side.
 */

namespace BackupPlus\Tests\WordPress;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScriptsTest extends ScriptTestCase {

	/** Storage providers declare themselves with a "Provider ID:" line in methods/*.php. */
	private static function provider_ids(): array {
		$ids = array();
		foreach ( glob( self::root() . '/methods/*.php' ) as $path ) {
			if ( preg_match_all( '/^[ \t]*\* Provider ID: (\S+)/m', (string) file_get_contents( $path ), $matches ) ) {
				$ids = array_merge( $ids, $matches[1] );
			}
		}
		return $ids;
	}

	/** The four point-in-time recovery cases take their own sites, whatever is left runs these. */
	protected static function concurrency(): int {
		return max( 1, count( self::sites() ) - 4 );
	}

	protected static function commands(): array {
		$wp_eval  = function ( array $arguments ): array {
			$script = array_shift( $arguments );
			return array( array_merge( array( self::podman(), 'exec', self::site_container(), 'wp', '--skip-plugins=backupplus', 'eval-file', 'wp-content/plugins/backupplus/scripts/' . $script ), $arguments ) );
		};
		$commands = array();
		foreach ( array( array( 'check-pitr-settings.php' ), array( 'check-admin-markup.php' ) ) as $eval ) {
			$commands[ implode( ' ', $eval ) ] = $wp_eval( $eval );
		}
		$commands['check-cli-restore.php (host)'] = array( array( PHP_BINARY, 'scripts/integration/check-cli-restore.php' ), self::site_environment() );
		$evals                                    = array(
			array( 'check-cli.php' ),
			array( 'check-backup-sources.php' ),
			array( 'check-backup-sources.php', 'pclzip' ),
			array( 'check-pre-update-backups.php' ),
			array( 'check-incremental-backups.php' ),
			array( 'check-incremental-restore.php' ),
			array( 'check-backup-schedule.php' ),
			array( 'check-storage-limits.php' ),
			array( 'check-temporary-cleanup.php' ),
			array( 'check-jobdata-write-volume.php' ),
			array( 'check-credential-storage.php' ),
			array( 'check-dropbox-token-storage.php' ),
			array( 'check-age-encryption.php' ),
			array( 'test-storage-providers.php' ),
		);
		// As the Makefile always ran them: each provider on its own and then restricted to it, and finally all of them together.
		foreach ( self::provider_ids() as $provider ) {
			$evals[] = array( 'test-storage-providers.php', $provider );
			$evals[] = array( 'test-storage-providers.php', 'only:' . $provider );
		}
		$evals[] = array( 'test-storage-providers.php', 'all' );
		foreach ( $evals as $eval ) {
			$commands[ implode( ' ', $eval ) ] = $wp_eval( $eval );
		}
		return $commands;
	}

	#[DataProvider( 'scripts' )]
	public function test_script_passes( string $label ): void {
		$this->assertScriptPasses( $label );
	}
}
