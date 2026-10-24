<?php
/**
 * Plugin SQL is written only as constant templates for BackupPlus_SQL (see includes/class-sql.php).
 *
 * Fails when plugin code passes anything but one constant string to an SQL call, calls $wpdb->prepare() (client-side
 * escaping, not a prepared statement), constructs a BackupPlus_SQL_Fragment other than through BackupPlus_SQL::fragment(),
 * or concatenates/interpolates text that starts an SQL statement. Statements read
 * from backup files reach the server only through the dump executors listed below, which take a plain variable.
 *
 * Usage: php scripts/check-sql.php [directory]
 */

$root = realpath( isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ ) );

// The one audited place that turns templates into statements.
$exempt_files = array( 'includes/class-sql.php' );
$skip         = '#^(vendor|node_modules|scripts|dev|\.cache|\.git|methods/googledrive/Google|methods/dropbox/Dropbox2|methods/cloudfiles/sdk)/#';
// Method or function name => 1-based position of the SQL argument.
$sinks     = array(
	'query'                  => 1,
	'get_var'                => 1,
	'get_row'                => 1,
	'get_col'                => 1,
	'get_results'            => 1,
	'rows'                   => 1,
	'row'                    => 1,
	'value'                  => 1,
	'column'                 => 1,
	'execute'                => 1,
	'prepared'               => 1,
	'render'                 => 1,
	'fragment'               => 1,
	'mysqli_query'           => 2,
	'mysqli_prepare'         => 2,
	'mysqli_real_query'      => 2,
	'mysqli_multi_query'     => 2,
	'mysql_query'            => 1,
	'mysql_unbuffered_query' => 1,
);
$executors = array(
	'script'   => 1,
	'sql_exec' => 1,
);
$keywords  = '/\A\s*\(?\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|RENAME|TRUNCATE|SHOW|DESCRIBE|LOCK|UNLOCK|GRANT|REVOKE|KILL|SET|CALL|USE)\b/';

$failures = array();
$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( 'php' !== $file->getExtension() || preg_match( $skip, $relative ) || in_array( $relative, $exempt_files, true ) ) { continue; }
	$tokens    = array_values(
		array_filter(
			token_get_all( file_get_contents( $file->getPathname() ) ),
			function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	$count     = count( $tokens );
	$depth     = 0;
	$pending   = null;
	$functions = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		// Track the enclosing function so a sink-named wrapper may forward its own template parameter unchanged.
		if ( is_array( $token ) && T_FUNCTION === $token[0] && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
			$pending = array(
				'name'  => strtolower( $tokens[ $i + 1 ][1] ),
				'param' => null,
			);
			for ( $j = $i + 2; $j < $count && ')' !== $tokens[ $j ]; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_VARIABLE === $tokens[ $j ][0] ) {
					$pending['param'] = $tokens[ $j ][1];
					break;
				}
			}
		} elseif ( ';' === $token ) {
			// Signatures never contain ";": this was an abstract or interface method without a body.
			$pending = null;
		}
		if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$depth;
			if ( null !== $pending && '{' === $token ) {
				$functions[] = $pending + array( 'depth' => $depth );
				$pending     = null;
			}
		} elseif ( '}' === $token ) {
			if ( $functions && end( $functions )['depth'] === $depth ) { array_pop( $functions ); }
			--$depth;
		}
		if ( is_array( $token ) && T_NEW === $token[0] && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && 0 === strcasecmp( ltrim( $tokens[ $i + 1 ][1], '\\' ), 'BackupPlus_SQL_Fragment' ) ) {
			$failures[] = $relative . ':' . $token[2] . ' constructs a fragment from text; use BackupPlus_SQL::fragment().';
		}
		if ( is_array( $token ) && T_STRING === $token[0] && isset( $tokens[ $i + 1 ] ) && '(' === $tokens[ $i + 1 ] ) {
			$name     = strtolower( $token[1] );
			$previous = $i > 0 ? $tokens[ $i - 1 ] : null;
			// Instance calls, plus BackupPlus_SQL statics; other classes' static query()/prepare() build HTTP requests.
			$static = is_array( $previous ) && T_DOUBLE_COLON === $previous[0];
			$method = ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? T_NULLSAFE_OBJECT_OPERATOR : -1 ), true ) ) || ( $static && is_array( $tokens[ $i - 2 ] ) && 'BackupPlus_SQL' === $tokens[ $i - 2 ][1] );
			if ( $static && ! $method ) { continue; }
			if ( ! $method && in_array( $name, array( 'sprintf', 'vsprintf' ), true ) ) {
				$format = sql_argument( $tokens, $i + 1, 1 );
				if ( $format && sql_constant( $format ) && preg_match( $keywords . 'i', substr( $format[0][1], 1, -1 ) ) ) { $failures[] = $relative . ':' . $token[2] . ' builds SQL with ' . $token[1] . '().'; }
				continue;
			}
			$defined = is_array( $previous ) && T_FUNCTION === $previous[0];
			if ( $defined ) { continue; }
			if ( $method && 'prepare' === $name ) {
				$failures[] = $relative . ':' . $token[2] . ' uses ->prepare(); bind values with BackupPlus_SQL instead.';
				continue;
			}
			$position = isset( $sinks[ $name ] ) ? $sinks[ $name ] : ( isset( $executors[ $name ] ) ? $executors[ $name ] : 0 );
			if ( ! $position || ( ! $method && 0 !== strpos( $name, 'mysql' ) ) ) { continue; }
			$argument = sql_argument( $tokens, $i + 1, $position );
			if ( null === $argument ) { continue; }
			$current    = $functions ? end( $functions ) : null;
			$forwarding = $current && isset( $sinks[ $current['name'] ] ) && 1 === count( $argument ) && is_array( $argument[0] ) && T_VARIABLE === $argument[0][0] && $current['param'] === $argument[0][1];
			if ( sql_constant( $argument ) || sql_render( $argument ) || $forwarding || ( isset( $executors[ $name ] ) && sql_variable( $argument ) ) ) { continue; }
			$failures[] = $relative . ':' . $token[2] . ' passes non-constant SQL to ' . $token[1] . '().';
		}
		// Statement text assembled outside the sinks: a keyword-led string joined with "." or interpolated.
		$text = null;
		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$joined = ( isset( $tokens[ $i + 1 ] ) && '.' === $tokens[ $i + 1 ] ) || ( isset( $tokens[ $i - 1 ] ) && in_array( $tokens[ $i - 1 ], array( '.', '.=' ), true ) ) || ( isset( $tokens[ $i - 1 ] ) && is_array( $tokens[ $i - 1 ] ) && T_CONCAT_EQUAL === $tokens[ $i - 1 ][0] );
			$text   = $joined ? substr( $token[1], 1, -1 ) : null;
		} elseif ( '"' === $token && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && T_ENCAPSED_AND_WHITESPACE === $tokens[ $i + 1 ][0] ) {
			$text = $tokens[ $i + 1 ][1];
		} elseif ( is_array( $token ) && T_START_HEREDOC === $token[0] && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && T_ENCAPSED_AND_WHITESPACE === $tokens[ $i + 1 ][0] ) {
			$text = $tokens[ $i + 1 ][1];
		}
		if ( null !== $text && preg_match( $keywords, $text ) ) {
			$failures[] = $relative . ':' . ( is_array( $token ) ? $token[2] : sql_line( $tokens, $i ) ) . ' builds SQL by concatenation or interpolation.';
		}
	}
}

/** Tokens of the n-th call argument, or null when the call has fewer arguments. */
function sql_argument( $tokens, $open, $position ) {
	$depth     = 0;
	$index     = 1;
	$collected = array();
	$count     = count( $tokens );
	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( in_array( $token, array( '(', '[', '{' ), true ) || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$depth;
			if ( 1 === $depth ) { continue; }
		} elseif ( in_array( $token, array( ')', ']', '}' ), true ) ) {
			--$depth;
			if ( 0 === $depth ) { break; }
		} elseif ( ',' === $token && 1 === $depth ) {
			++$index;
			continue;
		}
		if ( $index === $position ) { $collected[] = $token; }
	}
	return $collected ? $collected : null;
}

/** One constant string, or a choice between two: condition ? 'constant' : 'constant'. */
function sql_constant( $argument ) {
	$literal = function ( $token ) {
		return is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0];
	};
	if ( 1 === count( $argument ) ) { return $literal( $argument[0] ); }
	$count = count( $argument );
	return $count > 4 && $literal( $argument[ $count - 1 ] ) && ':' === $argument[ $count - 2 ] && $literal( $argument[ $count - 3 ] ) && '?' === $argument[ $count - 4 ] && ! in_array( '?', array_slice( $argument, 0, $count - 4 ), true );
}

/** BackupPlus_SQL::render( 'constant template', ... ) composes identifiers only; its own template is checked as a sink. */
function sql_render( $argument ) {
	return count( $argument ) > 4 && is_array( $argument[0] ) && 'BackupPlus_SQL' === $argument[0][1] && is_array( $argument[1] ) && T_DOUBLE_COLON === $argument[1][0] && is_array( $argument[2] ) && 'render' === $argument[2][1] && '(' === $argument[3] && ')' === end( $argument );
}

/** A variable, property or array element: never a concatenation or interpolated string. */
function sql_variable( $argument ) {
	foreach ( $argument as $token ) {
		if ( is_array( $token ) ? ! in_array( $token[0], array( T_VARIABLE, T_OBJECT_OPERATOR, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER ), true ) : ! in_array( $token, array( '[', ']' ), true ) ) { return false; }
	}
	return is_array( $argument[0] ) && T_VARIABLE === $argument[0][0];
}

function sql_line( $tokens, $i ) {
	for ( ; $i < count( $tokens ); $i++ ) { if ( is_array( $tokens[ $i ] ) ) { return $tokens[ $i ][2]; }
	}
	return 0;
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" . count( $failures ) . " SQL construction violation(s).\n" );
	exit( 1 );
}
echo "SQL construction check passed.\n";
