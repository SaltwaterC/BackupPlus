<?php
/**
 * Build the BackupPlus plugin package: the files a WordPress site runs, as installed from a zip or published to the
 * WordPress.org plugin directory.
 *
 * The package holds the files tracked by git at a commit (HEAD by default), minus those that .distignore at that commit
 * excludes. It is written to build/backupplus/ and zipped as build/backupplus-VERSION.zip, with a backupplus/ top folder
 * as WordPress expects. File times are the commit time and entries are sorted, so a commit always gives the same zip.
 *
 * The build fails when:
 *
 * - the plugin header and readme.txt disagree (Version and Stable tag, Requires PHP, Requires at least, the plugin name), or
 *   the Text Domain is not the slug;
 * - a versioned minified asset was built for another version (run make build-assets after changing Version);
 * - the package contains a hidden file, or lacks the plugin file, readme.txt or a license notice;
 * - a file the package refers to was left out: BACKUPPLUS_DIR, BACKUPPLUS_URL, __DIR__ and dirname( __FILE__ ) paths and
 *   backupplus_try_include_file() arguments in PHP, url() in CSS and links in Markdown. A reference counts when it
 *   resolves in the repository and not in the package, so files the plugin creates at run time are not reported. A path
 *   that is followed by "." (concatenation) only needs some packaged file under it. A file the same PHP file tests for with
 *   is_file(), file_exists() or is_readable() on BACKUPPLUS_DIR . '/path' is optional and may be excluded.
 *
 * Usage: php scripts/build-dist.php [--ref=REF | --worktree] [--out=DIR] [--no-zip]
 *
 *   --ref=REF   Package the commit REF (default HEAD). Uncommitted changes are not included.
 *   --worktree  Package the working tree as `git add -A` would commit it: uncommitted changes and new files that git does
 *               not ignore. For local testing only; release from a commit.
 *   --out=DIR   Output directory (default build/). Its backupplus/ folder and zip are replaced.
 *   --no-zip    Only write the folder.
 */

declare(strict_types=1);

const BACKUPPLUS_DIST_SLUG = 'backupplus';

// Files the package must contain whatever .distignore says.
const BACKUPPLUS_DIST_REQUIRED = array( 'backupplus.php', 'readme.txt', 'LICENSE', 'licenses/THIRD-PARTY-LICENSES.md', 'uninstall.php' );

/**
 * Stop with an error message.
 *
 * @param String $message What went wrong
 */
function dist_fail( string $message ): void {
	fwrite( STDERR, 'build-dist: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Run a command and return its output; stop if it fails.
 *
 * @param String[] $command Program and arguments
 * @param String   $cwd     Working directory
 *
 * @return String
 */
function dist_run( array $command, string $cwd ): string {
	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		dist_fail( 'cannot run ' . $command[0] );
	}
	$output = stream_get_contents( $pipes[1] );
	$errors = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		dist_fail( implode( ' ', $command ) . ' failed: ' . trim( $errors ) );
	}
	return $output;
}

/**
 * Split NUL-separated command output.
 *
 * @param String $output Output of a command run with -z
 *
 * @return String[]
 */
function dist_split_nul( string $output ): array {
	return array_values( array_filter( explode( "\0", $output ), 'strlen' ) );
}

/**
 * Translate a .distignore pattern into a regular expression over root-relative paths.
 *
 * @param String $pattern One pattern line
 *
 * @return String
 */
function dist_pattern_regex( string $pattern ): string {
	$directory = '/' === substr( $pattern, -1 );
	$pattern   = rtrim( $pattern, '/' );
	$anchored  = false !== strpos( $pattern, '/' );
	$pattern   = ltrim( $pattern, '/' );
	$regex     = '';
	$length    = strlen( $pattern );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $pattern[ $i ];
		if ( '*' === $char && '*' === ( $pattern[ $i + 1 ] ?? '' ) ) {
			// "**/" matches no folder or any number of them; any other "**" matches anything.
			if ( '/' === ( $pattern[ $i + 2 ] ?? '' ) ) {
				$regex .= '(?:.*/)?';
				$i     += 2;
			} else {
				$regex .= '.*';
				++$i;
			}
		} elseif ( '*' === $char ) {
			$regex .= '[^/]*';
		} elseif ( '?' === $char ) {
			$regex .= '[^/]';
		} else {
			$regex .= preg_quote( $char, '#' );
		}
	}
	// A pattern matches the path itself or, as a folder, everything under it. A file pattern ending in / only matches folders.
	$tail = $directory ? '/.*' : '(?:/.*)?';
	return '#^' . ( $anchored ? '' : '(?:.*/)?' ) . $regex . $tail . '$#s';
}

/**
 * Read .distignore into regular expressions.
 *
 * @param String $file Path of .distignore
 *
 * @return String[] Regular expression for each pattern, keyed by the pattern
 */
function dist_read_ignore( string $file ): array {
	if ( ! is_file( $file ) ) {
		dist_fail( 'no .distignore in the packaged tree; commit it, or build with --worktree' );
	}
	$patterns = array();
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( '!' === $line[0] ) {
			dist_fail( '.distignore: negated patterns are not supported: ' . $line );
		}
		$patterns[ $line ] = dist_pattern_regex( $line );
	}
	return $patterns;
}

/**
 * Normalise a relative path: resolve "." and "..", drop duplicate slashes.
 *
 * @param String $path Relative path
 *
 * @return String|null The path, or null if it leaves the plugin folder
 */
function dist_normalise( string $path ): ?string {
	$parts = array();
	foreach ( explode( '/', $path ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			if ( ! $parts ) {
				return null;
			}
			array_pop( $parts );
			continue;
		}
		$parts[] = $part;
	}
	return implode( '/', $parts );
}

/**
 * Whether a file set contains a path as a file or folder, or, for a prefix, any file starting with it.
 *
 * @param String  $path   Normalised path
 * @param Boolean $prefix Whether the path is only the start of a file name
 * @param Array   $files  Set of files (path => true)
 * @param String  $joined All files, one per line, for prefix searches
 *
 * @return Boolean
 */
function dist_resolves( string $path, bool $prefix, array $files, string $joined ): bool {
	if ( '' === $path ) {
		return true;
	}
	if ( isset( $files[ $path ] ) ) {
		return true;
	}
	$needle = $prefix ? $path : $path . '/';
	return false !== strpos( $joined, "\n" . $needle );
}

/**
 * The file references in one packaged file.
 *
 * @param String $file    Root-relative path
 * @param String $content File contents
 *
 * @return Array[] Each with path (normalised) and prefix (Boolean)
 */
function dist_references( string $file, string $content ): array {
	$directory  = dirname( $file );
	$directory  = '.' === $directory ? '' : $directory . '/';
	$references = array();
	$add        = function ( string $path, bool $prefix, bool $relative ) use ( &$references, $directory ) {
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $path ) ) {
			return;
		}
		$path = preg_replace( '/[?#].*$/s', '', $path );
		$path = dist_normalise( ( $relative ? $directory : '' ) . $path );
		if ( null !== $path ) {
			$references[] = array(
				'path'   => $path,
				'prefix' => $prefix,
			);
		}
	};
	$extension  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	if ( 'php' === $extension ) {
		$quoted = '([\'"])([^\'"$\\\\]*)\2(\s*\.)?';
		preg_match_all( '/\b(BACKUPPLUS_DIR|BACKUPPLUS_URL|__DIR__|dirname\(\s*__FILE__\s*\))\s*\.\s*' . $quoted . '/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$relative = 0 !== strpos( $match[1], 'BACKUPPLUS_' );
			$add( $match[3], ! empty( $match[4] ), $relative );
		}
		preg_match_all( '/\bbackupplus_try_include_file\(\s*' . $quoted . '/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$add( $match[2], ! empty( $match[3] ), false );
		}
		// A file that the code tests for, as in is_file( BACKUPPLUS_DIR . '/x' ), is optional: the package may leave it out.
		$optional = array();
		preg_match_all( '/\b(?:is_file|file_exists|is_readable)\(\s*BACKUPPLUS_DIR\s*\.\s*([\'"])(\/[^\'"$\\\\]*)\1\s*\)/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$optional[ (string) dist_normalise( $match[2] ) ] = true;
		}
		$references = array_values(
			array_filter(
				$references,
				function ( $reference ) use ( $optional ) {
					return ! isset( $optional[ $reference['path'] ] );
				}
			)
		);
	} elseif ( 'css' === $extension ) {
		preg_match_all( '/url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)/i', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$add( $match[2], false, true );
		}
	} elseif ( 'md' === $extension ) {
		preg_match_all( '/\]\(([^)\s]+)\)/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$add( $match[1], false, true );
		}
	}
	return $references;
}

/**
 * Read the header fields of the plugin file or readme.txt.
 *
 * @param String $content File contents
 *
 * @return String[] Field values keyed by field name
 */
function dist_headers( string $content ): array {
	preg_match_all( '/^[ \t\/*#@]*([A-Za-z][A-Za-z ]*[A-Za-z]):[ \t]*(.*?)\s*$/m', substr( $content, 0, 8192 ), $matches, PREG_SET_ORDER );
	$headers = array();
	foreach ( $matches as $match ) {
		if ( ! isset( $headers[ $match[1] ] ) ) {
			$headers[ $match[1] ] = $match[2];
		}
	}
	return $headers;
}

/**
 * Check the plugin and readme headers and the versioned assets, and return the version.
 *
 * @param String   $source Root of the packaged tree
 * @param String[] $files  Packaged files
 * @param String[] $notes  Warnings, appended to
 *
 * @return String
 */
function dist_check_metadata( string $source, array $files, array &$notes ): string {
	$plugin  = dist_headers( (string) file_get_contents( $source . '/backupplus.php' ) );
	$readme  = (string) file_get_contents( $source . '/readme.txt' );
	$fields  = dist_headers( $readme );
	$version = $plugin['Version'] ?? '';
	if ( ! preg_match( '/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version ) ) {
		dist_fail( 'backupplus.php: Version must be numeric, as in 1.2.3: ' . $version );
	}
	if ( ( $fields['Stable tag'] ?? '' ) !== $version ) {
		dist_fail( 'readme.txt: Stable tag ' . ( $fields['Stable tag'] ?? '(missing)' ) . ' does not match Version ' . $version . ' in backupplus.php' );
	}
	foreach ( array( 'Requires PHP', 'Requires at least' ) as $field ) {
		if ( empty( $plugin[ $field ] ) || ( $fields[ $field ] ?? '' ) !== $plugin[ $field ] ) {
			dist_fail( 'readme.txt and backupplus.php must both declare the same ' . $field );
		}
	}
	// WordPress.org warns when the readme names the plugin differently from the header.
	if ( ! preg_match( '/^===\s*(.*?)\s*===/', $readme, $match ) || $match[1] !== ( $plugin['Plugin Name'] ?? '' ) ) {
		dist_fail( 'the readme.txt title must be the Plugin Name in backupplus.php: ' . ( $plugin['Plugin Name'] ?? '(missing)' ) );
	}
	if ( ( $plugin['Text Domain'] ?? '' ) !== BACKUPPLUS_DIST_SLUG ) {
		dist_fail( 'backupplus.php: Text Domain must be the plugin slug, ' . BACKUPPLUS_DIST_SLUG );
	}
	if ( empty( $fields['Contributors'] ) ) {
		$notes[] = 'readme.txt has no Contributors line; WordPress.org lists the plugin under the committing account only.';
	}
	if ( count( array_filter( array_map( 'trim', explode( ',', $fields['Tags'] ?? '' ) ) ) ) > 5 ) {
		$notes[] = 'readme.txt has more than 5 Tags; WordPress.org shows only the first 5.';
	}
	// The short description is the first paragraph after the header block, up to the first section.
	if ( preg_match( '/^===[^\n]*===\n(?:[^\n]+\n)*\n+(.*?)\n\s*(?:\n|==)/s', $readme, $match ) && strlen( trim( $match[1] ) ) > 150 ) {
		$notes[] = 'readme.txt short description is ' . strlen( trim( $match[1] ) ) . ' characters; WordPress.org truncates it at 150.';
	}

	$asset_version = implode( '-', array_slice( explode( '.', $version ), 0, 3 ) );
	$current       = 0;
	foreach ( $files as $file ) {
		if ( preg_match( '/-(\d+-\d+-\d+)\.min\.[a-z]+(?:\.map)?$/', $file, $match ) ) {
			if ( $match[1] !== $asset_version ) {
				dist_fail( $file . ' is built for another version; run make build-assets and remove the old versioned assets' );
			}
			++$current;
		}
	}
	if ( 0 === $current ) {
		dist_fail( 'no minified assets for version ' . $version . '; run make build-assets' );
	}
	return $version;
}

/**
 * A composer.json for the package that requires each Composer package bundled in its vendor/ folder, at the installed
 * version (a development branch at its installed commit), from vendor/composer/installed.json.
 *
 * @param String   $package     Package folder
 * @param String[] $files       Packaged files, root-relative
 * @param String   $requires_php The plugin's Requires PHP
 *
 * @return String|null JSON, or null if the package bundles no Composer packages
 */
function dist_composer_manifest( string $package, array $files, string $requires_php ): ?string {
	if ( ! in_array( 'vendor/composer/installed.json', $files, true ) ) {
		return null;
	}
	$installed = json_decode( (string) file_get_contents( $package . '/vendor/composer/installed.json' ), true );
	$packages  = isset( $installed['packages'] ) ? $installed['packages'] : $installed;
	if ( ! is_array( $packages ) ) {
		dist_fail( 'vendor/composer/installed.json cannot be read' );
	}
	$joined  = "\n" . implode( "\n", $files ) . "\n";
	$require = array( 'php' => '>=' . $requires_php );
	foreach ( $packages as $installed_package ) {
		// Packages that Composer installed outside vendor/, or that the package leaves out, are not bundled.
		$path = dist_normalise( 'vendor/composer/' . ( $installed_package['install-path'] ?? '' ) );
		if ( null === $path || false === strpos( $joined, "\n" . $path . '/' ) ) {
			continue;
		}
		$constraint = $installed_package['version'];
		if ( 0 === strpos( $constraint, 'dev-' ) ) {
			$reference   = $installed_package['source']['reference'] ?? ( $installed_package['dist']['reference'] ?? '' );
			$constraint .= '' === $reference ? '' : '#' . $reference;
		}
		$require[ $installed_package['name'] ] = $constraint;
	}
	ksort( $require, SORT_STRING );
	$manifest = array(
		'name'        => 'backupplus/backupplus',
		'description' => 'BackupPlus WordPress plugin. Lists the Composer packages bundled in vendor/ at their installed versions; generated from vendor/composer/installed.json by the build.',
		'type'        => 'wordpress-plugin',
		'license'     => 'GPL-3.0-only',
		'require'     => array( 'php' => $require['php'] ) + $require,
	);
	return json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Write a zip of the package folder, with sorted entries and the packaged files' times, so a commit build is reproducible.
 *
 * @param String   $package Package folder
 * @param String[] $files   Packaged files, root-relative
 * @param String   $zip     Zip file to write
 * @param Integer  $time    Modification time of the folder entries
 */
function dist_zip( string $package, array $files, string $zip, int $time ): void {
	if ( ! class_exists( 'ZipArchive' ) ) {
		dist_fail( 'the PHP zip extension is required to write the zip; use --no-zip to only build the folder' );
	}
	if ( is_file( $zip ) && ! unlink( $zip ) ) {
		dist_fail( 'cannot replace ' . $zip );
	}
	$folders = array();
	foreach ( $files as $file ) {
		for ( $folder = dirname( $file ); '.' !== $folder; $folder = dirname( $folder ) ) {
			$folders[ $folder ] = true;
		}
	}
	$entries = array( BACKUPPLUS_DIST_SLUG . '/' => null );
	foreach ( array_keys( $folders ) as $folder ) {
		$entries[ BACKUPPLUS_DIST_SLUG . '/' . $folder . '/' ] = null;
	}
	foreach ( $files as $file ) {
		$entries[ BACKUPPLUS_DIST_SLUG . '/' . $file ] = $package . '/' . $file;
	}
	ksort( $entries, SORT_STRING );

	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
		dist_fail( 'cannot create ' . $zip );
	}
	foreach ( $entries as $name => $path ) {
		$added = null === $path ? $archive->addEmptyDir( rtrim( $name, '/' ) ) : $archive->addFile( $path, $name );
		if ( ! $added ) {
			dist_fail( 'cannot add ' . $name . ' to the zip' );
		}
		$mode = null === $path ? 040755 : 0100644;
		$archive->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $mode << 16 );
		$archive->setMtimeName( $name, null === $path ? $time : (int) filemtime( $path ) );
	}
	if ( ! $archive->close() ) {
		dist_fail( 'cannot write ' . $zip );
	}
}

/**
 * Remove a folder tree.
 *
 * @param String $path Folder
 */
function dist_remove_tree( string $path ): void {
	if ( ! is_dir( $path ) || is_link( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $path );
}

$root    = dirname( __DIR__ );
$options = getopt( '', array( 'ref:', 'worktree', 'out:', 'no-zip', 'help' ), $rest_index );
if ( isset( $options['help'] ) || count( $argv ) !== $rest_index ) {
	fwrite( STDERR, "Usage: php scripts/build-dist.php [--ref=REF | --worktree] [--out=DIR] [--no-zip]\n" );
	exit( isset( $options['help'] ) ? 0 : 2 );
}
$worktree = isset( $options['worktree'] );
if ( $worktree && isset( $options['ref'] ) ) {
	dist_fail( '--ref and --worktree cannot be combined' );
}
$ref = (string) ( $options['ref'] ?? 'HEAD' );
$out = (string) ( $options['out'] ?? $root . '/build' );
if ( '/' !== $out[0] ) {
	$out = getcwd() . '/' . $out;
}
$out = rtrim( $out, '/' );
if ( ! is_dir( $out ) && ! mkdir( $out, 0777, true ) ) {
	dist_fail( 'cannot create ' . $out );
}
$out = (string) realpath( $out );
if ( $out === $root || 0 === strpos( $root . '/', $out . '/' ) ) {
	dist_fail( '--out must not be the repository or a folder above it' );
}

// The tracked files and the tree to copy them from.
$commit = trim( dist_run( array( 'git', 'rev-parse', '--verify', '--end-of-options', ( $worktree ? 'HEAD' : $ref ) . '^{commit}' ), $root ) );
$time   = (int) trim( dist_run( array( 'git', 'log', '-1', '--format=%ct', $commit ), $root ) );
if ( $worktree ) {
	$source  = $root;
	$deleted = array_flip( dist_split_nul( dist_run( array( 'git', 'ls-files', '-z', '--deleted' ), $root ) ) );
	$tracked = array_values( array_filter( dist_split_nul( dist_run( array( 'git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard' ), $root ) ), fn( $file ) => ! isset( $deleted[ $file ] ) ) );
	$dirty   = '' !== trim( dist_run( array( 'git', 'status', '--porcelain' ), $root ) );
} else {
	$source = $out . '/.source';
	dist_remove_tree( $source );
	mkdir( $source );
	register_shutdown_function( 'dist_remove_tree', $source );
	$tar = $out . '/.source.tar';
	dist_run( array( 'git', 'archive', '--format=tar', '--output=' . $tar, $commit ), $root );
	dist_run( array( 'tar', '-xf', $tar, '-C', $source ), $root );
	unlink( $tar );
	$tracked = dist_split_nul( dist_run( array( 'git', 'ls-tree', '-r', '-z', '--name-only', $commit ), $root ) );
	$dirty   = false;
}
sort( $tracked, SORT_STRING );

// Select the packaged files.
$ignore   = dist_read_ignore( $source . '/.distignore' );
$files    = array();
$excluded = 0;
foreach ( $tracked as $file ) {
	if ( is_link( $source . '/' . $file ) ) {
		dist_fail( $file . ' is a symbolic link; the package cannot contain links' );
	}
	foreach ( $ignore as $regex ) {
		if ( preg_match( $regex, $file ) ) {
			++$excluded;
			continue 2;
		}
	}
	$files[] = $file;
}
$unused = array_keys(
	array_filter(
		$ignore,
		function ( $regex ) use ( $tracked ) {
			return ! preg_grep( $regex, $tracked );
		}
	)
);

foreach ( BACKUPPLUS_DIST_REQUIRED as $file ) {
	if ( ! in_array( $file, $files, true ) ) {
		dist_fail( $file . ' must be in the package' );
	}
}
foreach ( $files as $file ) {
	if ( preg_match( '#(?:^|/)\.#', $file ) ) {
		dist_fail( $file . ' is a hidden file; exclude it in .distignore' );
	}
}

$notes               = array();
$version             = dist_check_metadata( $source, $files, $notes );
$plugin_requires_php = dist_headers( (string) file_get_contents( $source . '/backupplus.php' ) )['Requires PHP'];

// Every reference that resolves in the repository must resolve in the package.
$tracked_set    = array_fill_keys( $tracked, true );
$tracked_joined = "\n" . implode( "\n", $tracked ) . "\n";
$files_set      = array_fill_keys( $files, true );
$files_joined   = "\n" . implode( "\n", $files ) . "\n";
$missing        = array();
foreach ( $files as $file ) {
	if ( ! preg_match( '/\.(?:php|css|md)$/i', $file ) ) {
		continue;
	}
	foreach ( dist_references( $file, (string) file_get_contents( $source . '/' . $file ) ) as $reference ) {
		if ( dist_resolves( $reference['path'], $reference['prefix'], $tracked_set, $tracked_joined ) && ! dist_resolves( $reference['path'], $reference['prefix'], $files_set, $files_joined ) ) {
			$missing[] = $file . ' refers to ' . $reference['path'] . ( $reference['prefix'] ? '*' : '' ) . ', which .distignore excludes';
		}
	}
}
if ( $missing ) {
	dist_fail( "files the package needs are excluded:\n  " . implode( "\n  ", array_unique( $missing ) ) );
}

// Copy the package.
$package = $out . '/' . BACKUPPLUS_DIST_SLUG;
dist_remove_tree( $package );
$bytes = 0;
foreach ( $files as $file ) {
	$target = $package . '/' . $file;
	if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0755, true ) ) {
		dist_fail( 'cannot create the folder of ' . $target );
	}
	if ( ! copy( $source . '/' . $file, $target ) ) {
		dist_fail( 'cannot copy ' . $file );
	}
	chmod( $target, 0644 );
	// A commit build stamps the commit time, so it is reproducible. A working-tree build keeps each file's own time: with
	// one fixed time, an edit that keeps a file's size would look unchanged to rsync and similar tools.
	touch( $target, $worktree ? (int) filemtime( $source . '/' . $file ) : $time );
	$bytes += filesize( $target );
}
// The repository's composer.json only installs development tools, so the package gets one that lists the bundled vendor/
// packages instead, as WordPress.org asks of plugins that ship a Composer vendor folder.
$manifest = dist_composer_manifest( $package, $files, $plugin_requires_php );
if ( null !== $manifest ) {
	file_put_contents( $package . '/composer.json', $manifest );
	chmod( $package . '/composer.json', 0644 );
	touch( $package . '/composer.json', $time );
	$files[] = 'composer.json';
	sort( $files, SORT_STRING );
	$bytes += strlen( $manifest );
}
$zip = $out . '/' . BACKUPPLUS_DIST_SLUG . '-' . $version . '.zip';
if ( ! isset( $options['no-zip'] ) ) {
	dist_zip( $package, $files, $zip, $time );
	file_put_contents( $zip . '.sha256', hash_file( 'sha256', $zip ) . '  ' . basename( $zip ) . "\n" );
}

printf( "BackupPlus %s from %s%s\n", $version, $worktree ? 'the working tree at ' : '', substr( $commit, 0, 12 ) );
if ( $dirty ) {
	echo "  The working tree has uncommitted changes; do not publish this build.\n";
}
printf( "  %d files (%.1f MiB), %d tracked files excluded\n", count( $files ), $bytes / 1048576, $excluded );
echo '  Folder: ' . $package . "\n";
if ( ! isset( $options['no-zip'] ) ) {
	printf( "  Zip:    %s (%.1f MiB)\n", $zip, filesize( $zip ) / 1048576 );
}
foreach ( $unused as $pattern ) {
	echo "  Note: .distignore pattern matches no tracked file: $pattern\n";
}
foreach ( $notes as $note ) {
	echo "  Note: $note\n";
}
