<?php
/**
 * BPS-12 in real WordPress: dump literals come from BackupPlus_SQL::quote(), which escapes with the connection's own
 * character set, and under GBK, GB18030, Big5, SJIS and CP932 writes values with bytes of 0x80 or more as hexadecimal
 * literals. Each literal is run as an INSERT on the same connection, as a restore would, and the rows read back must be the
 * ones that were dumped, byte for byte. A value ending in a character whose second byte is a backslash must not let the
 * next value out of its string; the same dump escaped the previous way is shown to do that. After a connection with such a
 * character set, %i identifiers refuse bytes of 0x80 or more.
 *
 * It needs no table of the site; the table is temporary and belongs to the connection that makes it.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-dump-escaping.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function dump_escaping_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

require dirname( __DIR__ ) . '/backupplus.php';

// The previous escaping, to show that the values below would have escaped from their strings.
$previous = function ( $value ) {
	return "'" . str_replace( array( "\x00", "\x0a", "\x0d", "\x1a" ), array( '\0', '\n', '\r', '\Z' ), str_replace( '\'', '\\\'', str_replace( '\\', '\\\\', $value ) ) ) . "'";
};

$connect = function ( $charset ) {
	$host   = DB_HOST;
	$port   = null;
	$socket = null;
	if ( preg_match( '/\A(.*?):(\d+)\z/', $host, $matches ) ) {
		$host = $matches[1];
		$port = (int) $matches[2];
	} elseif ( preg_match( '/\A(.*?):(\/.*)\z/', $host, $matches ) ) {
		$host   = $matches[1];
		$socket = $matches[2];
	}
	$link = mysqli_init();
	dump_escaping_expect( $link && mysqli_real_connect( $link, $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ), 'No connection to the database: ' . mysqli_connect_error() );
	if ( ! mysqli_set_charset( $link, $charset ) ) {
		mysqli_close( $link );
		return null;
	}
	mysqli_query( $link, "SET SESSION sql_mode = ''" );
	return $link;
};

// The values are bytes of the connection character set. In GBK 0x81 0x5C is one character, 0x81 0x27 is not.
$values_for = array(
	'utf8mb4' => array( "plain", "it's", 'back\\slash', "line\nbreak\r\n", "nul\x00byte", "ctrl\x1a", 'quote"d', "caf\xc3\xa9", "\xf0\x9f\x98\x80 emoji", '\\', "'", '', "end\\" ),
	'gbk'     => array( "plain", "it's", "ends\x81\x5c", "\x81\x5c", "\x81\x5c\x81\x5c", "\xa1\x5c'", "mid\x81\x5cdle", 'back\\slash', "line\nbreak\r\n", "nul\x00byte", "\xd6\xd0\xce\xc4" ),
	'big5'    => array( "plain", "ends\xa4\x5c", "\xa4\x5c\xa4\x5c", "it's", "\xa4\x40\xa4\x41" ),
	'sjis'    => array( "plain", "ends\x83\x5c", "\x83\x5c\x83\x5c", "it's", "\x82\xa0\x82\xa2" ),
	'cp932'   => array( "plain", "ends\x83\x5c", "\x83\x5c\x83\x5c", "it's", "\x82\xa0\x82\xa2" ),
	'gb18030' => array( "plain", "ends\x81\x5c", "\x81\x5c\x81\x5c", "it's", "\xd6\xd0\xce\xc4", "\x81\x30\x81\x30" ),
);

// The row that an attacker adds through the stored values: a second row, in the same INSERT, after the injected quote.
$injection_for = function ( $charset ) {
	return "), (99, 'injected";
};

$round_trip = function ( $link, $charset, $values, $escape ) {
	mysqli_query( $link, 'DROP TEMPORARY TABLE IF EXISTS backupplus_dump_escaping' );
	dump_escaping_expect( mysqli_query( $link, 'CREATE TEMPORARY TABLE backupplus_dump_escaping (id INT NOT NULL, v VARBINARY(255) NULL) ENGINE=InnoDB' ), 'The table could not be created: ' . mysqli_error( $link ) );
	$rows = array();
	foreach ( $values as $i => $value ) {
		$rows[] = '(' . ( $i + 1 ) . ', ' . $escape( $value ) . ')';
	}
	$statement = 'INSERT INTO backupplus_dump_escaping VALUES ' . implode( ",\n ", $rows ) . ';';
	if ( ! mysqli_query( $link, $statement ) ) {
		return array(
			'error' => mysqli_error( $link ),
			'rows'  => null,
		);
	}
	$result = mysqli_query( $link, 'SELECT id, v FROM backupplus_dump_escaping ORDER BY id' );
	$found  = array();
	while ( ( $row = mysqli_fetch_assoc( $result ) ) ) {
		$found[ (int) $row['id'] ] = $row['v'];
	}
	mysqli_free_result( $result );
	return array(
		'error' => '',
		'rows'  => $found,
	);
};

$checked = 0;
foreach ( array_keys( $values_for ) as $charset ) {
	$link = $connect( $charset );
	if ( null === $link ) {
		// MariaDB has no GB18030; the others must be there
		dump_escaping_expect( 'gb18030' === $charset, "The server has no $charset character set" );
		continue;
	}
	$sql = new BackupPlus_SQL( $link );
	dump_escaping_expect( strtolower( $sql->charset() ) === $charset, "The connection uses {$sql->charset()}, not $charset" );
	$multibyte = BackupPlus_SQL::charset_has_multibyte_trail( $charset );

	$values = $values_for[ $charset ];
	// Every value is followed by the one that would be injected, as a stored value of a commenter could be.
	$values[] = $injection_for( $charset );
	$values[] = "last'";

	$result = $round_trip( $link, $charset, $values, array( $sql, 'quote' ) );
	dump_escaping_expect( '' === $result['error'], "$charset: the dump did not run: " . $result['error'] );
	dump_escaping_expect( count( $values ) === count( $result['rows'] ), "$charset: " . count( $result['rows'] ) . ' rows were restored for ' . count( $values ) . ' values' );
	foreach ( $values as $i => $value ) {
		dump_escaping_expect( $result['rows'][ $i + 1 ] === $value, "$charset: value $i came back as " . bin2hex( (string) $result['rows'][ $i + 1 ] ) . ', not ' . bin2hex( $value ) );
	}
	dump_escaping_expect( ! isset( $result['rows'][99] ), "$charset: a row was injected" );

	// Only the characters that need it are written as hexadecimal; the others stay readable
	dump_escaping_expect( "'plain'" === $sql->quote( 'plain' ), "$charset: plain text is not quoted as text" );
	dump_escaping_expect( null !== $sql->quote( "it's" ) && "'it\\'s'" === $sql->quote( "it's" ), "$charset: a quote is not escaped" );
	if ( $multibyte ) {
		$hex = $sql->quote( "ends\x81\x5c" );
		dump_escaping_expect( 0 === strpos( $hex, "_$charset 0x" ) && ctype_xdigit( substr( $hex, strlen( $charset ) + 4 ) ), "$charset: a value with bytes of 0x80 or more is not a hexadecimal literal: $hex" );
		dump_escaping_expect( "'plain'" === $sql->quote( 'plain' ), "$charset: ASCII became hexadecimal" );
	} else {
		dump_escaping_expect( false === strpos( $sql->quote( "caf\xc3\xa9" ), '0x' ), "$charset: text with bytes of 0x80 or more is hexadecimal on a safe character set" );
	}

	// The previous escaping, where it was wrong, must fail in the way that the finding describes
	if ( $multibyte && in_array( $charset, array( 'gbk', 'big5', 'sjis', 'cp932', 'gb18030' ), true ) ) {
		$old   = $round_trip( $link, $charset, $values, $previous );
		$wrong = '' !== $old['error'] || count( $old['rows'] ) !== count( $values ) || isset( $old['rows'][99] ) || $old['rows'] !== $result['rows'];
		dump_escaping_expect( $wrong, "$charset: the previous escaping restored the values correctly, so this check does not show the finding" );
	}

	if ( 'utf8mb4' === $charset ) {
		// A utf8 connection keeps non-ASCII identifiers (until a connection with another set of rules is made)
		dump_escaping_expect( '`caf' . "\xc3\xa9" . '`' === BackupPlus_SQL::identifier( "caf\xc3\xa9" ), 'utf8mb4: a non-ASCII identifier is refused' );
	}
	++$checked;
	mysqli_close( $link );
}

// After a connection with a character set whose second byte can be a backslash or backtick
$refused = 0;
foreach ( array( "caf\xc3\xa9", "\x81\x5c", "ab\xa9" ) as $name ) {
	try {
		BackupPlus_SQL::identifier( $name );
	} catch ( InvalidArgumentException $error ) {
		++$refused;
	}
}
dump_escaping_expect( 3 === $refused, "%i accepted $refused of 3 identifiers with bytes of 0x80 or more after a GBK connection; " . ( 3 - $refused ) . ' got through' );
dump_escaping_expect( '`wp_options`' === BackupPlus_SQL::identifier( 'wp_options' ), 'An ASCII identifier is refused' );
dump_escaping_expect( '`a``b`' === BackupPlus_SQL::identifier( 'a`b' ), 'A backtick is not doubled' );

// The header names the character set that the values were escaped for
$link = $connect( 'gbk' );
$name = ( new BackupPlus_SQL( $link ) )->charset();
dump_escaping_expect( 'gbk' === strtolower( $name ), 'The character set of the connection is not reported' );
mysqli_close( $link );

// The dumper uses it
$tables = file_get_contents( BACKUPPLUS_DIR . '/includes/backup/trait-database-tables.php' );
dump_escaping_expect( false !== strpos( $tables, '$sql->quote( $value )' ), 'The table dumper does not use BackupPlus_SQL::quote()' );
dump_escaping_expect( false === strpos( $tables, "str_replace( '\\\\', '\\\\\\\\'" ), 'The table dumper still escapes with str_replace()' );
$database = file_get_contents( BACKUPPLUS_DIR . '/includes/backup/trait-database.php' );
dump_escaping_expect( false !== strpos( $database, 'SET NAMES %i */;\\n", array( $this->sql()->charset() )' ), 'The dump header does not name the character set of the connection' );

WP_CLI::success( "Dump escaping is correct for $checked character sets, with the previous escaping failing where it was wrong" );
