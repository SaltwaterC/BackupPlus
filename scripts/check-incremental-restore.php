<?php
/** Restores real full and incremental chains of the content directory and WordPress core to each restore point, using disposable destinations. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function restore_chain_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$token     = bin2hex( random_bytes( 6 ) );
$directory = get_temp_dir() . 'backupplus-restore-chain-' . $token;
mkdir( $directory );
mkdir( $directory . '/archives' );
$test_history         = array();
$test_cron            = array();
$nonces               = array();
$encryption           = array( 'mode' => 'none' );
$original_bookkeeping = array();
foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_restore_in_progress' ) as $name ) {
	$original_bookkeeping[ $name ] = get_option( $name, null );
}

add_filter(
	'pre_option_backupplus_backup_history',
	function () use ( &$test_history ) {
		return $test_history;
	},
	100
);
add_filter(
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$test_history ) {
		$test_history = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_cron',
	function () use ( &$test_cron ) {
		return $test_cron;
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) use ( &$test_cron ) {
		$test_cron = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory . '/archives';
	},
	100
);
add_filter(
	'backupplus_get_option',
	function ( $value, $name ) use ( &$encryption ) {
		$overrides = array(
			'backupplus_service'                => array(),
			'backupplus_delete_local'           => 0,
			'backupplus_debug_mode'             => false,
			'backupplus_email'                  => '',
			'backupplus_database_encryption'    => $encryption,
			'backupplus_interval'               => 'manual',
			'backupplus_interval_database'      => 'manual',
			'backupplus_retain'                 => 2,
			'backupplus_include_wpcore'         => 1,
			'backupplus_include_wpcore_exclude' => 'wp-content',
			'backupplus_include_others'         => 1,
		);
		return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $value;
	},
	100,
	2
);
$remove_tree = function ( $path ) use ( &$remove_tree ) {
	if ( is_file( $path ) || is_link( $path ) ) { unlink( $path );
		return; }
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) { $remove_tree( $path . '/' . $entry ); }
	}
	rmdir( $path );
};
register_shutdown_function(
	function () use ( $directory, &$nonces, $remove_tree, $original_bookkeeping ) {
		foreach ( $nonces as $nonce ) { delete_site_option( 'backupplus_jobdata_' . $nonce ); }
		foreach ( $original_bookkeeping as $name => $value ) {
			if ( null === $value ) { delete_option( $name );
			} else { update_option( $name, $value ); }
		}
		if ( is_dir( $directory ) ) {
			$remove_tree( $directory );
		}
	}
);
global $backupplus;
require dirname( __DIR__ ) . '/backupplus.php';
require_once BACKUPPLUS_DIR . '/backup.php';
class Restore_Chain_Test_BackupPlus extends BackupPlus {
	public function get_semaphore_lock( $files, $database ) {
		return true; }
}

// The destinations must sit on the same filesystem as the restorer's working folder in wp-content/upgrade.
$marker      = 'zz-restore-check-' . $token; // Default exclusions skip content folders named backup*
$content_dir = WP_CONTENT_DIR . '/' . $marker;
$core_names  = array( 'keep', 'change', 'gone', 'new1', 'new2' );
$cleanup     = function () use ( $remove_tree, $marker, $core_names ) {
	foreach ( glob( WP_CONTENT_DIR . '/' . $marker . '*' ) as $path ) { $remove_tree( $path ); }
	foreach ( $core_names as $name ) { @unlink( ABSPATH . $marker . '-' . $name . '.txt' ); }
};
register_shutdown_function( $cleanup );
try {
	mkdir( $content_dir );
	mkdir( $content_dir . '/sub' );
	file_put_contents( $content_dir . '/keep.txt', 'keep' );
	file_put_contents( $content_dir . '/change.txt', 'v1' );
	file_put_contents( $content_dir . '/gone.txt', 'gone' );
	file_put_contents( $content_dir . '/sub/x.txt', 'x' );
	// A dangling symlink raises a warning on every run, which must not stop the chain from being reused.
	symlink( $content_dir . '/missing-target', $content_dir . '/dangling' );
	$core_file = function ( $name ) use ( $marker ) {
		return ABSPATH . $marker . '-' . $name . '.txt';
	};
	file_put_contents( $core_file( 'keep' ), 'keep' );
	file_put_contents( $core_file( 'change' ), 'v1' );
	file_put_contents( $core_file( 'gone' ), 'gone' );
	$entities = array( 'others', 'wpcore' );
	$run      = function ( $timestamp ) use ( &$backupplus, &$nonces, $entities ) {
		$backupplus = new Restore_Chain_Test_BackupPlus();
		$nonce      = bin2hex( random_bytes( 6 ) );
		$nonces[]   = $nonce;
		$ok         = $backupplus->boot_backup(
			1,
			0,
			$entities,
			true,
			'none',
			array(
				'incremental'   => true,
				'use_nonce'     => $nonce,
				'use_timestamp' => $timestamp,
			)
		);
		restore_chain_expect( $ok, 'Backup must run successfully' );
		restore_chain_expect( 0 === $backupplus->error_count(), 'Backup must have no errors: ' . json_encode( $backupplus->errors ) );
	};
	$start    = time() - 3600;
	$run( $start );
	restore_chain_expect( 'backup' === $backupplus->jobdata_get( 'job_type' ), 'First incremental request must create a full backup' );
	file_put_contents( $content_dir . '/change.txt', 'v2' );
	unlink( $content_dir . '/gone.txt' );
	file_put_contents( $content_dir . '/new1.txt', 'new1' );
	file_put_contents( $core_file( 'change' ), 'v2' );
	unlink( $core_file( 'gone' ) );
	file_put_contents( $core_file( 'new1' ), 'new1' );
	$run( $start + 60 );
	restore_chain_expect( 'incremental' === $backupplus->jobdata_get( 'job_type' ), 'Second backup must be incremental' );
	file_put_contents( $content_dir . '/change.txt', 'v3' );
	unlink( $content_dir . '/new1.txt' );
	file_put_contents( $content_dir . '/new2.txt', 'new2' );
	file_put_contents( $core_file( 'change' ), 'v3' );
	unlink( $core_file( 'new1' ) );
	file_put_contents( $core_file( 'new2' ), 'new2' );
	$run( $start + 120 );
	restore_chain_expect( 'incremental' === $backupplus->jobdata_get( 'job_type' ), 'Third backup must be incremental' );
	restore_chain_expect( 1 === count( $test_history ), 'The increments must stay in the history entry of their full backup' );
	$set = BackupPlus_Backup_History::get_history( array_key_first( $test_history ) );
	restore_chain_expect( ! empty( $set['incremental']['complete'] ), 'The chain must be complete, even when the source tree has dangling symlinks' );
	foreach ( $entities as $entity ) {
		restore_chain_expect( 3 === count( $set[ $entity ] ), ucfirst( $entity ) . ' must hold the full backup and both increments' );
	}
	$points = array_keys( $set['incremental_sets'] );
	restore_chain_expect( 3 === count( $points ), 'The chain must offer three restore points' );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	require_once BACKUPPLUS_DIR . '/restorer.php';
	$restore_chain = function ( $entity, $destination, $point ) use ( $set ) {
		$options  = $point ? array( 'backupplus_incremental_restore_point' => $point ) : array();
		$restorer = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $set, false, $options );
		$backup   = new ReflectionProperty( $restorer, 'ud_backup_set' );
		if ( PHP_VERSION_ID < 80100 ) {
			$backup->setAccessible( true );
		}
		$files = $backup->getValue( $restorer )[ $entity ];
		ksort( $files );
		$info = array( 'path' => $destination );
		$pre  = ( new ReflectionMethod( $restorer, 'pre_restore_backup' ) )->invoke( $restorer, $files, $entity, $info );
		restore_chain_expect( ! is_wp_error( $pre ) && false !== $pre, 'Restore preflight must succeed' );
		$restore = new ReflectionMethod( $restorer, 'restore_backup' );
		$applied = 0;
		ob_start();
		foreach ( $files as $index => $file ) {
			( new ReflectionProperty( $restorer, 'current_index' ) )->setValue( $restorer, $index );
			( new ReflectionProperty( $restorer, 'current_type' ) )->setValue( $restorer, $entity );
			++$applied;
			$last   = count( $files ) === $applied;
			$result = $restore->invoke( $restorer, $file, $entity, $info, $last, $last );
			if ( true !== $result ) {
				ob_end_clean();
				throw new RuntimeException( 'Archive restore must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : json_encode( $result ) ) );
			}
		}
		ob_end_clean();
		remove_filter( 'backupplus_logline', array( $restorer, 'backupplus_logline' ), 10 );
		return $applied;
	};
	$read          = function ( $path ) {
		return is_file( $path ) ? file_get_contents( $path ) : null;
	};
	$tree          = function ( $root ) use ( &$tree ) {
		$found = array();
		foreach ( is_dir( $root ) ? scandir( $root ) : array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				if ( is_dir( $root . '/' . $entry ) ) {
					foreach ( $tree( $root . '/' . $entry ) as $path => $content ) { $found[ $entry . '/' . $path ] = $content; }
				} else {
					$found[ $entry ] = filesize( $root . '/' . $entry ) < 40 ? file_get_contents( $root . '/' . $entry ) : filesize( $root . '/' . $entry ) . ' bytes';
				}
			}
		}
		return $found;
	};
	$expected      = array(
		'latest' => array(
			'point'    => 0,
			'archives' => 3,
			'change'   => 'v3',
			'present'  => array( 'keep', 'new2' ),
			'absent'   => array( 'gone', 'new1' ),
		),
		'inc1'   => array(
			'point'    => $points[1],
			'archives' => 2,
			'change'   => 'v2',
			'present'  => array( 'keep', 'new1' ),
			'absent'   => array( 'gone', 'new2' ),
		),
		'full'   => array(
			'point'    => $points[0],
			'archives' => 1,
			'change'   => 'v1',
			'present'  => array( 'keep', 'gone' ),
			'absent'   => array( 'new1', 'new2' ),
		),
	);
	foreach ( $expected as $label => $want ) {
		// Others: the first archive moves the existing directory aside, increments merge into the restored one.
		$destination = WP_CONTENT_DIR . '/' . $marker . '-others-' . $label;
		mkdir( $destination );
		mkdir( $destination . '/' . $marker );
		file_put_contents( $destination . '/' . $marker . '/change.txt', 'LIVE' );
		file_put_contents( $destination . '/' . $marker . '/live-only.txt', 'live only' );
		file_put_contents( $destination . '/live-loose.txt', 'live loose' );
		restore_chain_expect( $want['archives'] === $restore_chain( 'others', $destination, $want['point'] ), "Others restore to $label must apply " . $want['archives'] . ' archive(s)' );
		$restored = $destination . '/' . $marker;
		restore_chain_expect( $want['change'] === $read( $restored . '/change.txt' ), "Others restore to $label must hold the {$want['change']} version of the changed file: " . json_encode( $tree( $destination ) ) );
		restore_chain_expect( 'x' === $read( $restored . '/sub/x.txt' ), "Others restore to $label must keep files from the full backup in subdirectories" );
		foreach ( $want['present'] as $name ) {
			restore_chain_expect( null !== $read( $restored . '/' . $name . '.txt' ), "Others restore to $label must contain $name.txt" );
		}
		foreach ( $want['absent'] as $name ) {
			restore_chain_expect( null === $read( $restored . '/' . $name . '.txt' ), "Others restore to $label must not contain $name.txt" );
		}
		restore_chain_expect( 'LIVE' === $read( $restored . '-old/change.txt' ) && 'live only' === $read( $restored . '-old/live-only.txt' ), "Others restore to $label must keep the replaced live directory as -old" );
		restore_chain_expect( 'live loose' === $read( $destination . '/live-loose.txt' ), "Others restore to $label must leave live items that are not in the backup" );
		restore_chain_expect( ! file_exists( $destination . '/backupplus-manifest.json' ), "Others restore to $label must not copy restore metadata into site files" );

		// Core: every archive merges over the live files, nothing is moved aside and wp-config.php is preserved.
		$destination = WP_CONTENT_DIR . '/' . $marker . '-wpcore-' . $label;
		mkdir( $destination );
		file_put_contents( $destination . '/' . $marker . '-change.txt', 'LIVE' );
		file_put_contents( $destination . '/' . $marker . '-live-only.txt', 'live only' );
		file_put_contents( $destination . '/wp-config.php', 'LIVE CONFIG' );
		restore_chain_expect( $want['archives'] === $restore_chain( 'wpcore', $destination, $want['point'] ), "Core restore to $label must apply " . $want['archives'] . ' archive(s)' );
		restore_chain_expect( $want['change'] === $read( $destination . '/' . $marker . '-change.txt' ), "Core restore to $label must hold the {$want['change']} version of the changed file" );
		foreach ( $want['present'] as $name ) {
			restore_chain_expect( null !== $read( $destination . '/' . $marker . '-' . $name . '.txt' ), "Core restore to $label must contain $name" );
		}
		foreach ( $want['absent'] as $name ) {
			restore_chain_expect( null === $read( $destination . '/' . $marker . '-' . $name . '.txt' ), "Core restore to $label must not contain $name" );
		}
		restore_chain_expect( 'live only' === $read( $destination . '/' . $marker . '-live-only.txt' ), "Core restore to $label must leave live files that are not in the backup" );
		restore_chain_expect( 'LIVE CONFIG' === $read( $destination . '/wp-config.php' ), "Core restore to $label must preserve wp-config.php" );
		restore_chain_expect( is_file( $destination . '/wp-includes/version.php' ), "Core restore to $label must restore core files" );
		restore_chain_expect( array() === glob( $destination . '/*-old' ), "Core restore to $label must not make -old copies" );
		restore_chain_expect( ! file_exists( $destination . '/backupplus-manifest.json' ), "Core restore to $label must not copy restore metadata into site files" );
	}
} finally {
	// Shutdown handlers do not run when a failed check ends in a fatal error, so clean up here as well.
	$cleanup();
	if ( is_dir( $directory ) ) { $remove_tree( $directory ); }
}
echo "WordPress incremental restore chain checks passed.\n";
