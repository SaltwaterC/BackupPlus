<?php

/**
 * Build the versioned minified assets requested by BackupPlus.
 *
 * The minified files in assets-src/ are the stable build inputs. This script
 * derives generated filenames from the plugin header, matching
 * BackupPlus::get_backupplus_file_version().
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$mode = $argv[1] ?? '--build';

if ( ! in_array( $mode, array( '--build', '--check' ), true ) ) {
	fwrite( STDERR, "Usage: php scripts/build-assets.php [--build|--check]\n" );
	exit( 2 );
}

$pluginFile     = $root . '/backupplus.php';
$pluginContents = is_file( $pluginFile ) ? file_get_contents( $pluginFile ) : false;

if ( false === $pluginContents || ! preg_match( '/^Version:\s*([0-9A-Za-z.+-]+)\s*$/m', $pluginContents, $versionMatch ) ) {
	fwrite( STDERR, "Could not read a valid Version from backupplus.php.\n" );
	exit( 1 );
}

$versionParts      = explode( '.', $versionMatch[1] );
$assetVersionParts = array_slice( $versionParts, 0, 3 );
if ( count( $assetVersionParts ) < 3 ) {
	fwrite( STDERR, "The Version in backupplus.php must have at least three dot-separated parts.\n" );
	exit( 1 );
}
foreach ( $assetVersionParts as $versionPart ) {
	if ( ! preg_match( '/^[0-9A-Za-z+-]+$/', $versionPart ) ) {
		fwrite( STDERR, "The Version in backupplus.php cannot be used in an asset filename.\n" );
		exit( 1 );
	}
}

$assetVersion = implode( '-', $assetVersionParts );

// These are the checked-in minified bundles referenced through get_backupplus_file_version().
// assets-src/includes/backupplus-admin-common.min.js is generated from includes/admin-common/ by scripts/build-admin-common.mjs,
// and the admin, deactivation and notices stylesheets in assets-src/css/ from css/ by scripts/build-css.mjs.
// Scripts without a minified bundle use their existing source-file fallback.
$assets = array(
	array(
		'source'     => 'assets-src/css/backupplus-admin.min.css',
		'output'     => 'css/backupplus-admin-%s.min.css',
		'source_map' => true,
	),
	array(
		'source'     => 'assets-src/css/backupplus-deactivation.min.css',
		'output'     => 'css/backupplus-deactivation-%s.min.css',
		'source_map' => true,
	),
	array(
		'source'     => 'assets-src/css/backupplus-notices.min.css',
		'output'     => 'css/backupplus-notices-%s.min.css',
		'source_map' => true,
	),
	array(
		'source'     => 'assets-src/css/backupplus-tour.min.css',
		'output'     => 'css/backupplus-tour-%s.min.css',
		'source_map' => true,
	),
	array(
		'source'     => 'assets-src/includes/jquery-ui.custom-v1.11.4.min.css',
		'output'     => 'includes/jquery-ui.custom-v1.11.4-%s.min.css',
		'source_map' => true,
	),
	array(
		'source'     => 'assets-src/includes/jquery-ui.custom-v1.12.1.min.css',
		'output'     => 'includes/jquery-ui.custom-v1.12.1-%s.min.css',
		'source_map' => true,
	),
	array(
		'source' => 'assets-src/includes/backupplus-admin-common.min.js',
		'output' => 'includes/backupplus-admin-common-%s.min.js',
	),
	array(
		'source' => 'assets-src/includes/jquery-ui.dialog.extended/jquery-ui.dialog.extended.min.js',
		'output' => 'includes/jquery-ui.dialog.extended/jquery-ui.dialog.extended-%s.min.js',
	),
	array(
		'source'     => 'assets-src/includes/labelauty/jquery-labelauty.min.css',
		'output'     => 'includes/labelauty/jquery-labelauty-%s.min.css',
		'source_map' => true,
	),
	array(
		'source' => 'assets-src/includes/labelauty/jquery-labelauty.min.js',
		'output' => 'includes/labelauty/jquery-labelauty-%s.min.js',
	),
	array(
		'source' => 'assets-src/js/backupplus-admin-restore.min.js',
		'output' => 'js/backupplus-admin-restore-%s.min.js',
	),
	array(
		'source' => 'assets-src/js/backupplus-deactivation.min.js',
		'output' => 'js/backupplus-deactivation-%s.min.js',
	),
);

$expectedFiles = array();
$errors        = array();

foreach ( $assets as $asset ) {
	$sourcePath      = $root . '/' . $asset['source'];
	$outputPath      = sprintf( $asset['output'], $assetVersion );
	$destinationPath = $root . '/' . $outputPath;
	$sourceContents  = is_file( $sourcePath ) ? file_get_contents( $sourcePath ) : false;

	if ( false === $sourceContents ) {
		$errors[] = 'Missing asset source: ' . $asset['source'];
		continue;
	}

	if ( isset( $asset['source_map'] ) && $asset['source_map'] ) {
		$sourceMapPath           = $sourcePath . '.map';
		$sourceMapContents       = is_file( $sourceMapPath ) ? file_get_contents( $sourceMapPath ) : false;
		$sourceMapUrl            = basename( $outputPath ) . '.map';
		$sourceMapReferenceCount = 0;

		if ( false === $sourceMapContents ) {
			$errors[] = 'Missing source map: ' . $asset['source'] . '.map';
			continue;
		}

		$sourceContents = preg_replace_callback(
			'/sourceMappingURL=([^*\s]+)/',
			static function ( $matches ) use ( $sourceMapUrl ) {
				return 'sourceMappingURL=' . $sourceMapUrl;
			},
			$sourceContents,
			-1,
			$sourceMapReferenceCount
		);

		$sourceMap = json_decode( $sourceMapContents, true );
		if ( ! is_array( $sourceMap ) || ! isset( $sourceMap['version'] ) || 3 !== $sourceMap['version'] ) {
			$errors[] = 'Invalid source map JSON: ' . $asset['source'] . '.map';
			continue;
		}

		if ( 1 !== $sourceMapReferenceCount ) {
			$errors[] = 'Expected one sourceMappingURL in ' . $asset['source'] . '.';
			continue;
		}

		$sourceMap['file'] = basename( $outputPath );
		$builtSourceMap    = json_encode( $sourceMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $builtSourceMap ) {
			$errors[] = 'Could not encode source map: ' . $asset['source'] . '.map';
			continue;
		}

		$expectedFiles[ $outputPath . '.map' ] = $builtSourceMap;
	}

	$expectedFiles[ $outputPath ] = $sourceContents;
}

if ( ! empty( $errors ) ) {
	foreach ( $errors as $error ) {
		fwrite( STDERR, $error . "\n" );
	}
	exit( 1 );
}

$outOfDate = array();
foreach ( $expectedFiles as $relativePath => $expectedContents ) {
	$destinationPath = $root . '/' . $relativePath;
	$currentContents = is_file( $destinationPath ) ? file_get_contents( $destinationPath ) : false;
	if ( $expectedContents !== $currentContents ) {
		$outOfDate[] = $relativePath;
	}
}

// Search only the generated filename families listed above, leaving canonical
// inputs and unrelated upstream/vendor assets alone.
$staleFiles = array();
foreach ( $assets as $asset ) {
	$outputPattern = sprintf( $asset['output'], '*' );
	$patterns      = array( $outputPattern );
	if ( isset( $asset['source_map'] ) && $asset['source_map'] ) {
		$patterns[] = $outputPattern . '.map';
	}

	foreach ( $patterns as $pattern ) {
		foreach ( glob( $root . '/' . $pattern ) ?: array() as $candidate ) {
			$relativePath = substr( $candidate, strlen( $root ) + 1 );
			if ( ! isset( $expectedFiles[ $relativePath ] ) ) {
				$staleFiles[] = $relativePath;
			}
		}
	}
}

if ( '--check' === $mode ) {
	if ( empty( $outOfDate ) && empty( $staleFiles ) ) {
		echo 'Versioned assets are current for ' . $versionMatch[1] . ".\n";
		exit( 0 );
	}

	foreach ( $outOfDate as $relativePath ) {
		fwrite( STDERR, 'Out of date or missing: ' . $relativePath . "\n" );
	}
	foreach ( $staleFiles as $relativePath ) {
		fwrite( STDERR, 'Stale generated asset: ' . $relativePath . "\n" );
	}
	fwrite( STDERR, "Run `make build-assets` to rebuild the versioned assets.\n" );
	exit( 1 );
}

foreach ( $expectedFiles as $relativePath => $contents ) {
	$destinationPath      = $root . '/' . $relativePath;
	$destinationDirectory = dirname( $destinationPath );
	if ( ! is_dir( $destinationDirectory ) && ! mkdir( $destinationDirectory, 0777, true ) && ! is_dir( $destinationDirectory ) ) {
		fwrite( STDERR, 'Could not create directory: ' . $destinationDirectory . "\n" );
		exit( 1 );
	}

	if ( false === file_put_contents( $destinationPath, $contents ) ) {
		fwrite( STDERR, 'Could not write asset: ' . $relativePath . "\n" );
		exit( 1 );
	}
}

foreach ( $staleFiles as $relativePath ) {
	if ( ! unlink( $root . '/' . $relativePath ) ) {
		fwrite( STDERR, 'Could not remove stale asset: ' . $relativePath . "\n" );
		exit( 1 );
	}
}

echo 'Built ' . count( $assets ) . ' versioned assets for BackupPlus ' . $versionMatch[1] . ".\n";
