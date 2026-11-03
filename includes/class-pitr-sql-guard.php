<?php

/**
 * Checks a logical dump before PITR source recovery imports it as the restore account, and writes the copy that is imported.
 *
 * The restore account holds server-level privileges, so the dump may contain only the statements that mysqldump and
 * mariadb-dump write for one database. Statements are split the way the mysql client splits them; any input that the
 * client and the server could read differently from this reader is refused. Statements that change accounts, grants,
 * plugins, global variables or other databases are refused before anything is dropped. Every view, trigger, routine
 * and event is defined under the WordPress database account, and views and routines run with the invoker's
 * privileges, as the ordinary restorer does when it imports as that account.
 */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_PITR_SQL_Guard {

	const CHUNK = 1048576;

	/** Data is written through in pieces of this size; other statements are held whole up to the limit below. */
	const FLUSH = 4194304;

	const STATEMENT_LIMIT = 67108864;

	/** Client character sets whose multibyte characters can contain a quote or backslash byte. */
	const UNSAFE_CHARSETS = array( 'big5', 'cp932', 'gbk', 'gb18030', 'sjis', 'ucs2', 'utf16', 'utf16le', 'utf32' );

	const ENGINES = array( 'INNODB', 'MYISAM', 'ARIA', 'MEMORY', 'HEAP' );

	private $in;
	private $out;
	private $engine;
	private $database;
	private $definer;
	private $whole;
	private $version;
	private $buffer    = '';
	private $pos       = 0;
	private $start     = 0;
	private $eof       = false;
	private $delimiter = ';';

	/** Null outside an executable comment; otherwise whether the server runs its content. */
	private $exec = null;

	/** The active tokens of the current statement: array( type, value, start, end ). */
	private $tokens = array();
	private $data   = false;

	/** Where the current statement's first token or versioned comment starts; null before it. */
	private $seen = null;

	/** User variables holding a saved value of sql_mode or character_set_client, and those written any other way. */
	private $pinned  = array();
	private $tainted = array();
	private $options = null;

	/**
	 * Write the checked copy of $input to $output, or throw before writing anything usable.
	 *
	 * @param array $settings engine, database, definer (array( user, host )), whole (the dump recreates the
	 *                        database) and version (the server version string).
	 * @return array|null The CREATE DATABASE options of a whole-database dump: charset, collation, encryption.
	 */
	public static function sanitize( $input, $output, $settings ) {
		$guard = new self( $settings );
		BackupPlus_PITR_Process::private_file( $output );
		$guard->in  = fopen( $input, 'rb' );
		$guard->out = fopen( $output, 'wb' );
		try {
			if ( ! $guard->in || ! $guard->out ) { throw new RuntimeException( 'Could not open the recovery SQL for checking.' ); }
			$guard->scan();
			if ( $guard->whole && null === $guard->options ) { throw new RuntimeException( 'The recovery SQL does not define its database. No database replacement was started.' ); }
			if ( ! fclose( $guard->out ) ) { throw new RuntimeException( 'Could not write the checked recovery SQL.' ); }
			$guard->out = null;
		} catch ( Throwable $error ) {
			if ( $guard->out ) { fclose( $guard->out ); }
			$guard->out = null;
			unlink( $output );
			throw $error;
		} finally {
			if ( $guard->in ) { fclose( $guard->in ); }
			if ( $guard->out ) { fclose( $guard->out ); }
		}
		return $guard->options;
	}

	private function __construct( $settings ) {
		$this->engine   = $settings['engine'];
		$this->database = $settings['database'];
		try { $this->definer = BackupPlus_SQL::render( 'DEFINER=%a', array( $settings['definer'] ) ) . ' '; }
		catch ( InvalidArgumentException $error ) { throw new RuntimeException( 'The WordPress database account name is not supported as the owner of restored database objects.' ); }
		$this->whole = ! empty( $settings['whole'] );
		if ( ! in_array( $this->engine, array( 'mysql', 'mariadb' ), true ) || ! is_string( $this->database ) || ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $this->database ) || ! preg_match( '/\A([0-9]+)\.([0-9]+)\.([0-9]+)/', (string) $settings['version'], $version ) ) {
			throw new RuntimeException( 'Invalid recovery SQL check settings.' );
		}
		$this->version = 10000 * (int) $version[1] + 100 * (int) $version[2] + (int) $version[3];
	}

	private function refuse( $what ) {
		throw new RuntimeException( 'The recovery SQL contains ' . $what . ', which a database dump of this site does not need. No database replacement was started.' );
	}

	private function fill() {
		if ( $this->eof ) { return false; }
		$data = fread( $this->in, self::CHUNK );
		if ( false === $data ) { throw new RuntimeException( 'Could not read the recovery SQL.' ); }
		if ( '' === $data && feof( $this->in ) ) {
			$this->eof = true;
			return false;
		}
		$this->buffer .= $data;
		return true;
	}

	/** At least $count bytes from $offset, unless the input ends first. */
	private function need( $count, $offset = null ) {
		$offset = null === $offset ? $this->pos : $offset;
		while ( strlen( $this->buffer ) - $offset < $count && $this->fill() ) {}
	}

	private function write( $text ) {
		if ( '' !== $text && fwrite( $this->out, $text ) !== strlen( $text ) ) { throw new RuntimeException( 'Could not write the checked recovery SQL.' ); }
	}

	private function compact() {
		$this->buffer = (string) substr( $this->buffer, $this->pos );
		$this->pos    = 0;
		$this->start  = 0;
	}

	private function reset() {
		$this->tokens = array();
		$this->seen   = null;
		$this->data   = false;
		$this->compact();
	}

	private function at_delimiter() {
		$this->need( strlen( $this->delimiter ) );
		return 0 === substr_compare( $this->buffer, $this->delimiter, $this->pos, strlen( $this->delimiter ) );
	}

	private function scan() {
		while ( true ) {
			$this->need( 16 );
			if ( $this->pos >= strlen( $this->buffer ) ) { break; }
			if ( $this->data ) {
				$this->data_segment();
				continue;
			}
			if ( $this->pos - $this->start > self::STATEMENT_LIMIT ) { $this->refuse( 'an oversized statement' ); }
			$c    = $this->buffer[ $this->pos ];
			$next = isset( $this->buffer[ $this->pos + 1 ] ) ? $this->buffer[ $this->pos + 1 ] : '';
			if ( $this->at_delimiter() ) {
				if ( null !== $this->exec ) { $this->refuse( 'a statement delimiter inside a versioned comment' ); }
				$this->finish();
			} elseif ( false !== strpos( " \t\r\n\f\v", $c ) ) {
				$this->pos += strspn( $this->buffer, " \t\r\n\f\v", $this->pos );
			} elseif ( '#' === $c || ( '-' === $c && '-' === $next && $this->dash_comment() ) ) {
				if ( null !== $this->exec ) { $this->refuse( 'a line comment inside a versioned comment' ); }
				$this->line_comment();
			} elseif ( '/' === $c && '*' === $next ) {
				$this->open_comment();
			} elseif ( '*' === $c && '/' === $next && null !== $this->exec ) {
				$this->exec = null;
				$this->pos += 2;
			} elseif ( '\\' === $c ) {
				// MariaDB dumps start with the client command that enables its sandbox mode; no other client command is accepted.
				if ( 'mariadb' !== $this->engine || '-' !== $next ) { $this->refuse( 'a client command' ); }
				$this->mark( $this->pos );
				$this->pos += 2;
			} elseif ( "'" === $c || '"' === $c || '`' === $c ) {
				$begin = $this->pos;
				$end   = $this->quoted( $c );
				$this->token( '`' === $c ? 'i' : 's', $begin, $end );
			} elseif ( '@' === $c ) {
				$this->variable();
			} elseif ( ctype_digit( $c ) ) {
				$this->need( 1024 );
				preg_match( '/\G(?:0[xX][0-9A-Fa-f]+|0[bB][01]+|[0-9]+(?:\.[0-9]*)?(?:[eE][-+]?[0-9]+)?)/', $this->buffer, $match, 0, $this->pos );
				$this->token( 'n', $this->pos, $this->pos + strlen( $match[0] ) );
			} elseif ( preg_match( '/[A-Za-z_$\x80-\xff]/', $c ) ) {
				$this->word();
			} elseif ( ':' === $c && '=' === $next ) {
				$this->token( 'p', $this->pos, $this->pos + 2 );
			} else {
				$this->token( 'p', $this->pos, $this->pos + 1 );
			}
		}
		if ( null !== $this->exec ) { $this->refuse( 'an unterminated versioned comment' ); }
		// The client runs a final statement that has no delimiter.
		if ( $this->data ) {
			$this->write( substr( $this->buffer, $this->start ) );
		} elseif ( null !== $this->seen ) {
			$this->finish( 0 );
		} else {
			$this->write( substr( $this->buffer, $this->start ) );
		}
	}

	/** "--" starts a comment only before whitespace or the end; the server also accepts other control characters. */
	private function dash_comment() {
		$this->need( 3 );
		$third = isset( $this->buffer[ $this->pos + 2 ] ) ? $this->buffer[ $this->pos + 2 ] : '';
		if ( '' === $third || false !== strpos( " \t\r\n\f\v", $third ) ) { return true; }
		if ( ord( $third ) < 32 ) { $this->refuse( 'an ambiguous comment' ); }
		return false;
	}

	private function line_comment() {
		while ( false === ( $end = strpos( $this->buffer, "\n", $this->pos ) ) && $this->fill() ) {}
		$this->pos = false === $end ? strlen( $this->buffer ) : $end + 1;
	}

	private function open_comment() {
		$this->need( 16 );
		$third  = isset( $this->buffer[ $this->pos + 2 ] ) ? $this->buffer[ $this->pos + 2 ] : '';
		$fourth = isset( $this->buffer[ $this->pos + 3 ] ) ? $this->buffer[ $this->pos + 3 ] : '';
		if ( null !== $this->exec ) { $this->refuse( 'a comment nested in a versioned comment' ); }
		if ( '+' === $third ) { $this->refuse( 'an optimizer hint' ); }
		if ( '!' === $third || ( 'mariadb' === $this->engine && 'M' === $third && '!' === $fourth ) ) {
			$at     = $this->pos + ( '!' === $third ? 3 : 4 );
			$digits = strspn( $this->buffer, '0123456789', $at );
			$after  = isset( $this->buffer[ $at + $digits ] ) ? $this->buffer[ $at + $digits ] : '';
			if ( ! in_array( $digits, 'mysql' === $this->engine ? array( 0, 5 ) : array( 0, 5, 6 ), true ) || ( $digits && false === strpos( 'mariadb' === $this->engine ? " \t\r\n\\" : " \t\r\n", $after ) ) ) {
				$this->refuse( 'a versioned comment of unsupported form' );
			}
			$this->exec = ! $digits || (int) substr( $this->buffer, $at, $digits ) <= $this->version;
			$this->mark( $this->pos );
			$this->pos = $at + $digits;
			return;
		}
		while ( false === ( $end = strpos( $this->buffer, '*/', $this->pos + 2 ) ) && $this->fill() ) {}
		if ( false === $end ) { $this->refuse( 'an unterminated comment' ); }
		$this->pos = $end + 2;
	}

	/** Skip a quoted string or identifier starting at the current position; returns the offset after it. */
	private function quoted( $quote ) {
		$p     = $this->pos + 1;
		$stops = '`' === $quote ? '`' : $quote . '\\';
		while ( true ) {
			$p += strcspn( $this->buffer, $stops, $p );
			if ( $p >= strlen( $this->buffer ) ) {
				if ( $this->data && $p - $this->start > self::FLUSH ) {
					$this->write( substr( $this->buffer, $this->start, $p - $this->start ) );
					$this->buffer = '';
					$p            = 0;
					$this->pos    = 0;
					$this->start  = 0;
				}
				if ( ! $this->fill() ) { $this->refuse( 'an unterminated string' ); }
				continue;
			}
			$this->need( 2, $p );
			if ( '\\' === $this->buffer[ $p ] ) {
				if ( $p + 1 >= strlen( $this->buffer ) ) { $this->refuse( 'an unterminated string' ); }
				$p += 2;
				continue;
			}
			if ( isset( $this->buffer[ $p + 1 ] ) && $quote === $this->buffer[ $p + 1 ] ) {
				$p += 2;
				continue;
			}
			$end = $p + 1;
			// A server skipping a versioned comment ends it at the first "*/", even inside quotes.
			if ( null !== $this->exec && false !== strpos( substr( $this->buffer, $this->pos, $end - $this->pos ), '*/' ) ) { $this->refuse( 'a comment terminator inside a versioned comment string' ); }
			return $end;
		}
	}

	private function variable() {
		$this->need( 3 );
		$next = isset( $this->buffer[ $this->pos + 1 ] ) ? $this->buffer[ $this->pos + 1 ] : '';
		if ( '@' === $next ) {
			$this->need( 256 );
			if ( ! preg_match( '/\G@@[A-Za-z_][A-Za-z0-9_$]*(?:\.[A-Za-z_][A-Za-z0-9_$]*)?/', $this->buffer, $match, 0, $this->pos ) ) { $this->refuse( 'an unsupported system variable reference' ); }
			$this->token( 'g', $this->pos, $this->pos + strlen( $match[0] ) );
		} elseif ( "'" === $next || '"' === $next || '`' === $next ) {
			$begin = $this->pos;
			++$this->pos;
			$end       = $this->quoted( $next );
			$this->pos = $begin;
			$this->token( 'v', $begin, $end );
		} else {
			static $chars = null;
			if ( null === $chars ) { $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$.' . implode( '', array_map( 'chr', range( 128, 255 ) ) ); }
			$this->need( 256 );
			$length = strspn( $this->buffer, $chars, $this->pos + 1 );
			$this->token( $length ? 'v' : 'p', $this->pos, $this->pos + 1 + $length );
		}
	}

	private function word() {
		static $chars = null;
		if ( null === $chars ) { $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$' . implode( '', array_map( 'chr', range( 128, 255 ) ) ); }
		do {
			$length = strspn( $this->buffer, $chars, $this->pos );
		} while ( $this->pos + $length >= strlen( $this->buffer ) && $this->fill() );
		$end = $this->pos + $length;
		$this->need( 1, $end );
		// The client treats "delimiter " as its command wherever it appears outside quotes and comments.
		if ( $length >= 9 && 'delimiter' === strtolower( substr( $this->buffer, $end - 9, 9 ) ) && isset( $this->buffer[ $end ] ) && ' ' === $this->buffer[ $end ] ) {
			if ( 9 !== $length || null !== $this->seen || null !== $this->exec ) { $this->refuse( 'a delimiter command inside a statement' ); }
			$this->delimiter_command( $end );
			return;
		}
		$word = strtoupper( substr( $this->buffer, $this->pos, $length ) );
		$this->token( 'w', $this->pos, $end );
		// Row data is checked and written through as it is read, without holding the statement.
		if ( null === $this->exec && ( 'VALUES' === $word || 'VALUE' === $word ) && in_array( $this->keyword( $this->tokens, 0 ), array( 'INSERT', 'REPLACE' ), true ) ) { $this->begin_data(); }
	}

	private function delimiter_command( $end ) {
		while ( false === ( $line = strpos( $this->buffer, "\n", $end ) ) && $this->fill() ) {}
		$stop = false === $line ? strlen( $this->buffer ) : $line;
		if ( ! preg_match( '/\A +(;;|;)[ \t\r]*\z/', substr( $this->buffer, $end, $stop - $end ), $match ) ) { $this->refuse( 'an unsupported delimiter' ); }
		$this->delimiter = $match[1];
		$this->pos       = false === $line ? $stop : $line + 1;
		$this->write( substr( $this->buffer, $this->start, $this->pos - $this->start ) );
		$this->reset();
	}

	/** Tokens in a comment the server skips are not part of the statement it runs. */
	private function mark( $offset ) {
		if ( null === $this->seen ) { $this->seen = $offset; }
	}

	private function token( $type, $start, $end ) {
		$this->mark( $start );
		$this->pos = $end;
		if ( false === $this->exec ) { return; }
		if ( 'w' === $type || 'n' === $type || 'p' === $type || 'g' === $type ) {
			$value = substr( $this->buffer, $start, $end - $start );
		} elseif ( 'v' === $type ) {
			$value = substr( $this->buffer, $start + 1, $end - $start - 1 );
		} else {
			$value = substr( $this->buffer, $start + 1, $end - $start - 2 );
			if ( 'i' === $type ) { $value = str_replace( '``', '`', $value ); }
		}
		$this->tokens[] = array( $type, $value, $start, $end );
	}

	private function keyword( $t, $index ) {
		return isset( $t[ $index ] ) && 'w' === $t[ $index ][0] ? strtoupper( $t[ $index ][1] ) : null;
	}

	private function punct( $t, $index, $char ) {
		return isset( $t[ $index ] ) && 'p' === $t[ $index ][0] && $char === $t[ $index ][1];
	}

	private function describe( $t ) {
		$words = array();
		foreach ( array_slice( $t, 0, 3 ) as $token ) {
			if ( 'w' !== $token[0] ) { break; }
			$words[] = preg_replace( '/[^A-Z_]/', '', strtoupper( $token[1] ) );
		}
		return $words ? 'the statement "' . implode( ' ', $words ) . ' ..."' : 'an unrecognized statement';
	}

	private function finish( $length = null ) {
		$length = null === $length ? strlen( $this->delimiter ) : $length;
		$result = $this->analyse( $this->tokens );
		$raw    = substr( $this->buffer, $this->start, $this->pos - $this->start );
		if ( empty( $result['drop'] ) ) {
			$edits = isset( $result['edits'] ) ? $result['edits'] : array();
			usort(
				$edits,
				function ( $a, $b ) {
					return $b[0] - $a[0];
				}
			);
			foreach ( $edits as $edit ) {
				$raw = substr_replace( $raw, $edit[2], $edit[0] - $this->start, $edit[1] - $edit[0] );
			}
			$this->write( $raw . substr( $this->buffer, $this->pos, $length ) );
			if ( ! empty( $result['append'] ) ) { $this->write( "\n" . $result['append'] . $this->delimiter . "\n" ); }
		} else {
			// Comments before a dropped statement stay; they hold the dump's metadata.
			$this->write( substr( $raw, 0, $this->seen - $this->start ) );
		}
		$this->pos += $length;
		$this->reset();
	}

	/** Any use of a user variable other than saving or restoring a mode may change it. */
	private function taint( $t, $skip = array() ) {
		foreach ( $t as $index => $token ) {
			if ( 'v' !== $token[0] || in_array( $index, $skip, true ) ) { continue; }
			$name = $this->variable_name( $token );
			if ( null === $name ) { $this->tainted['*'] = true; }
			else { $this->tainted[ $name ] = true; }
		}
	}

	/** The server folds case and accents in user variable names, so only plain ASCII names are tracked. */
	private function variable_name( $token ) {
		$name = $token[1];
		if ( '' !== $name && false !== strpos( '\'"`', $name[0] ) ) { $name = substr( $name, 1, -1 ); }
		return preg_match( '/\A[A-Za-z0-9_$.]+\z/D', $name ) ? strtolower( $name ) : null;
	}

	private function usable( $token, $system ) {
		$name = $this->variable_name( $token );
		return null !== $name && empty( $this->tainted['*'] ) && empty( $this->tainted[ $name ] ) && isset( $this->pinned[ $name ] ) && $system === $this->pinned[ $name ];
	}

	private function analyse( $t ) {
		if ( ! $t ) { return array(); }
		switch ( $this->keyword( $t, 0 ) ) {
			case 'SET':
				$this->set_statement( $t );
				return array();
			case 'CREATE':
				$result = $this->create_statement( $t );
				break;
			case 'DROP':
				$this->drop_statement( $t );
				$result = array();
				break;
			case 'ALTER':
				$this->alter_statement( $t );
				$result = array();
				break;
			case 'LOCK':
				if ( ! in_array( $this->keyword( $t, 1 ), array( 'TABLES', 'TABLE' ), true ) ) { $this->refuse( $this->describe( $t ) ); }
				$this->foreign( $t );
				$result = array();
				break;
			case 'UNLOCK':
				if ( 2 !== count( $t ) || ! in_array( $this->keyword( $t, 1 ), array( 'TABLES', 'TABLE' ), true ) ) { $this->refuse( $this->describe( $t ) ); }
				$result = array();
				break;
			case 'USE':
				if ( 2 !== count( $t ) || ! in_array( $t[1][0], array( 'i', 'w' ), true ) || $this->database !== $t[1][1] ) { $this->refuse( 'a switch to another database' ); }
				$result = array( 'drop' => true );
				break;
			case 'SELECT':
				$this->setval( $t );
				$result = array();
				break;
			case 'COMMIT':
				if ( 1 !== count( $t ) ) { $this->refuse( $this->describe( $t ) ); }
				$result = array();
				break;
			case 'START':
				if ( 2 !== count( $t ) || 'TRANSACTION' !== $this->keyword( $t, 1 ) ) { $this->refuse( $this->describe( $t ) ); }
				$result = array();
				break;
			default:
				$this->refuse( $this->describe( $t ) );
		}
		$this->taint( $t, isset( $result['hosts'] ) ? $result['hosts'] : array() );
		unset( $result['hosts'] );
		return $result;
	}

	/** A qualified name a.b or a.b.c must start with this database. */
	private function foreign( $t, $from = 0 ) {
		for ( $k = $from + 1; $k < count( $t ) - 1; $k++ ) {
			if ( ! $this->punct( $t, $k, '.' ) || ! $this->name_token( $t, $k - 1 ) || ! $this->name_token( $t, $k + 1 ) ) { continue; }
			$j = $k - 1;
			while ( $j - 2 >= $from && $this->punct( $t, $j - 1, '.' ) && $this->name_token( $t, $j - 2 ) ) { $j -= 2; }
			if ( $this->database !== $t[ $j ][1] ) { $this->refuse( 'a reference to another database' ); }
		}
	}

	private function name_token( $t, $index ) {
		return isset( $t[ $index ] ) && ( 'i' === $t[ $index ][0] || ( 'w' === $t[ $index ][0] && ! ctype_digit( $t[ $index ][1][0] ) ) );
	}

	/** An object name, optionally qualified with this database; returns array( name, next index ). */
	private function object_name( $t, $index ) {
		if ( 'IF' === $this->keyword( $t, $index ) ) {
			$index += 'NOT' === $this->keyword( $t, $index + 1 ) ? 3 : 2;
		}
		if ( ! $this->name_token( $t, $index ) ) { $this->refuse( $this->describe( $t ) ); }
		if ( $this->punct( $t, $index + 1, '.' ) ) {
			if ( $this->database !== $t[ $index ][1] || ! $this->name_token( $t, $index + 2 ) ) { $this->refuse( 'a reference to another database' ); }
			$index += 2;
		}
		return array( $t[ $index ][1], $index + 1 );
	}

	private function set_statement( $t ) {
		$first = $this->keyword( $t, 1 );
		if ( in_array( $first, array( 'PASSWORD', 'ROLE', 'DEFAULT', 'STATEMENT', 'RESOURCE' ), true ) ) { $this->refuse( 'a SET ' . $first . ' statement' ); }
		if ( in_array( $first, array( 'NAMES', 'CHARSET', 'CHARACTER' ), true ) ) {
			$index = 'CHARACTER' === $first ? 3 : 2;
			if ( 'CHARACTER' === $first && 'SET' !== $this->keyword( $t, 2 ) ) { $this->refuse( $this->describe( $t ) ); }
			$this->charset( array_slice( $t, $index, 1 ), null );
			if ( 'NAMES' === $first && 'COLLATE' === $this->keyword( $t, $index + 1 ) ) {
				if ( ! isset( $t[ $index + 2 ] ) || ! in_array( $t[ $index + 2 ][0], array( 'w', 's' ), true ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/D', $t[ $index + 2 ][1] ) ) { $this->refuse( 'an unsupported collation' ); }
				$index += 2;
			}
			if ( count( $t ) !== $index + 1 ) { $this->refuse( $this->describe( $t ) ); }
			return;
		}
		// Assignments are split at top-level commas and evaluated in order.
		$items = array( array() );
		$depth = 0;
		foreach ( array_slice( $t, 1 ) as $token ) {
			if ( 'p' === $token[0] && '(' === $token[1] ) { ++$depth; }
			if ( 'p' === $token[0] && ')' === $token[1] ) { --$depth; }
			if ( 0 === $depth && 'p' === $token[0] && ',' === $token[1] ) {
				$items[] = array();
				continue;
			}
			$items[ count( $items ) - 1 ][] = $token;
		}
		foreach ( $items as $item ) {
			$i     = 0;
			$scope = $this->keyword( $item, 0 );
			if ( in_array( $scope, array( 'GLOBAL', 'PERSIST', 'PERSIST_ONLY' ), true ) ) { $this->refuse( 'a change to global server settings' ); }
			if ( in_array( $scope, array( 'SESSION', 'LOCAL' ), true ) ) { $i = 1; }
			if ( 'TRANSACTION' === $this->keyword( $item, $i ) ) {
				$this->taint( $item );
				continue;
			}
			if ( ! isset( $item[ $i ] ) ) { $this->refuse( $this->describe( $t ) ); }
			$target = $item[ $i ];
			if ( 'v' === $target[0] ) {
				$rhs = array_slice( $item, $i + 2 );
				if ( ! $this->punct( $item, $i + 1, '=' ) && ! $this->punct( $item, $i + 1, ':=' ) ) { $this->refuse( $this->describe( $t ) ); }
				$name = $this->variable_name( $target );
				if ( null !== $name && 1 === count( $rhs ) && 'g' === $rhs[0][0] ) {
					$this->pinned[ $name ] = $this->system_name( substr( $rhs[0][1], 2 ) );
				} else {
					$this->taint( $item );
				}
				continue;
			}
			if ( 'g' === $target[0] ) {
				$system = $this->system_name( substr( $target[1], 2 ) );
			} elseif ( 'w' === $target[0] && preg_match( '/\A[A-Za-z_][A-Za-z0-9_$]*\z/D', $target[1] ) ) {
				$system = $target[1];
				if ( $this->punct( $item, $i + 1, '.' ) && isset( $item[ $i + 2 ] ) && 'w' === $item[ $i + 2 ][0] ) {
					$system .= '.' . $item[ $i + 2 ][1];
					$i      += 2;
				}
				$system = $this->system_name( $system );
			} else {
				$this->refuse( $this->describe( $t ) );
			}
			if ( 0 === strpos( $system, 'global.' ) ) { $this->refuse( 'a change to global server settings' ); }
			if ( ! $this->punct( $item, $i + 1, '=' ) && ! $this->punct( $item, $i + 1, ':=' ) ) { $this->refuse( $this->describe( $t ) ); }
			$rhs = array_slice( $item, $i + 2 );
			if ( 'sql_mode' === $system ) {
				$this->sql_mode( $rhs );
			} elseif ( 'character_set_client' === $system ) {
				$this->charset( $rhs, $system );
			} else {
				$this->taint( $rhs );
			}
		}
	}

	/** A system variable name without its session scope; global scopes become "global.". */
	private function system_name( $name ) {
		$parts = explode( '.', strtolower( $name ) );
		if ( 2 === count( $parts ) && in_array( $parts[0], array( 'session', 'local' ), true ) ) { return $parts[1]; }
		if ( 2 === count( $parts ) && in_array( $parts[0], array( 'global', 'persist', 'persist_only' ), true ) ) { return 'global.' . $parts[1]; }
		return implode( '.', $parts );
	}

	/**
	 * Modes that change how quotes and backslashes are read would let the server split statements differently
	 * from the client and this reader.
	 */
	private function sql_mode( $rhs ) {
		if ( 1 === count( $rhs ) ) {
			$value = $rhs[0];
			if ( 's' === $value[0] && preg_match( '/\A[A-Za-z0-9_,]*\z/D', $value[1] ) && ! preg_match( '/ANSI|NO_BACKSLASH_ESCAPES|ORACLE|DB2|MSSQL|POSTGRESQL|MAXDB/i', $value[1] ) ) { return; }
			if ( 'w' === $value[0] && 'DEFAULT' === strtoupper( $value[1] ) ) { return; }
			if ( 'v' === $value[0] && $this->usable( $value, 'sql_mode' ) ) { return; }
		}
		$this->refuse( 'an unsupported SQL mode' );
	}

	private function charset( $rhs, $system ) {
		if ( 1 === count( $rhs ) ) {
			$value = $rhs[0];
			if ( in_array( $value[0], array( 'w', 's', 'i' ), true ) && preg_match( '/\A[A-Za-z0-9_]+\z/D', $value[1] ) && ! in_array( strtolower( $value[1] ), self::UNSAFE_CHARSETS, true ) ) { return; }
			if ( null !== $system && 'v' === $value[0] && $this->usable( $value, $system ) ) { return; }
		}
		$this->refuse( 'an unsupported client character set' );
	}

	private function create_statement( $t ) {
		$i = 1;
		if ( 'OR' === $this->keyword( $t, $i ) ) {
			if ( 'REPLACE' !== $this->keyword( $t, $i + 1 ) ) { $this->refuse( $this->describe( $t ) ); }
			$i += 2;
		}
		$algorithm = false;
		$security  = false;
		$remove    = array();
		$hosts     = array();
		if ( 'ALGORITHM' === $this->keyword( $t, $i ) ) {
			if ( ! $this->punct( $t, $i + 1, '=' ) || null === $this->keyword( $t, $i + 2 ) ) { $this->refuse( $this->describe( $t ) ); }
			$i        += 3;
			$algorithm = true;
		}
		if ( 'DEFINER' === $this->keyword( $t, $i ) ) {
			$from             = $i;
			list( $i, $host ) = $this->account( $t, $i + 1 );
			$remove[]         = array( $t[ $from ][2], $t[ $i - 1 ][3], '' );
			$hosts[]          = $host;
		}
		if ( 'SQL' === $this->keyword( $t, $i ) ) {
			if ( 'SECURITY' !== $this->keyword( $t, $i + 1 ) || ! in_array( $this->keyword( $t, $i + 2 ), array( 'DEFINER', 'INVOKER' ), true ) ) { $this->refuse( $this->describe( $t ) ); }
			$remove[] = array( $t[ $i ][2], $t[ $i + 2 ][3], '' );
			$i       += 3;
			$security = true;
		}
		$keyword   = $i;
		$aggregate = 'AGGREGATE' === $this->keyword( $t, $i );
		if ( $aggregate ) { ++$i; }
		$kind = $this->keyword( $t, $i );
		if ( in_array( $kind, array( 'DATABASE', 'SCHEMA', 'TABLE', 'SEQUENCE' ), true ) && ( $algorithm || $remove || $aggregate ) ) { $this->refuse( $this->describe( $t ) ); }
		if ( 'DATABASE' === $kind || 'SCHEMA' === $kind ) {
			if ( ! $this->whole || null !== $this->options ) { $this->refuse( 'a database definition' ); }
			$j = $i + 1;
			if ( 'IF' === $this->keyword( $t, $j ) ) {
				if ( 'NOT' !== $this->keyword( $t, $j + 1 ) || 'EXISTS' !== $this->keyword( $t, $j + 2 ) ) { $this->refuse( $this->describe( $t ) ); }
				$j += 3;
			}
			if ( ! $this->name_token( $t, $j ) || $this->database !== $t[ $j ][1] ) { $this->refuse( 'a definition of another database' ); }
			$this->options = $this->database_options( $t, $j + 1, true );
			return array( 'drop' => true );
		}
		if ( 'TABLE' === $kind || 'SEQUENCE' === $kind ) {
			list( , $j ) = $this->object_name( $t, $i + 1 );
			$this->foreign( $t );
			for ( $k = $j; $k < count( $t ); $k++ ) {
				$word = $this->keyword( $t, $k );
				if ( in_array( $word, array( 'SELECT', 'TABLE', 'DIRECTORY', 'CONNECTION', 'SONAME' ), true ) ) { $this->refuse( 'a table definition that reads other data or files' ); }
				if ( 'ENGINE' === $word ) {
					$value = $this->punct( $t, $k + 1, '=' ) ? $k + 2 : $k + 1;
					if ( ! isset( $t[ $value ] ) || ! in_array( strtoupper( $t[ $value ][1] ), self::ENGINES, true ) ) { $this->refuse( 'an unsupported storage engine' ); }
				}
			}
			return array();
		}
		if ( ! in_array( $kind, array( 'VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT' ), true ) || ( $aggregate && 'FUNCTION' !== $kind ) || ( ( $algorithm || $security ) && 'VIEW' !== $kind ) ) {
			$this->refuse( $this->describe( $t ) );
		}
		list( $name, $j ) = $this->object_name( $t, $i + 1 );
		foreach ( $t as $token ) {
			if ( 'w' === $token[0] && 'SONAME' === strtoupper( $token[1] ) ) { $this->refuse( 'a loadable function' ); }
		}
		if ( 'TRIGGER' === $kind ) {
			for ( $k = $j; $k < count( $t ) && 'ON' !== $this->keyword( $t, $k ); $k++ ) {}
			$this->object_name( $t, $k + 1 );
		}
		// Every program runs as the site's account: a missing DEFINER clause would make it the restore account.
		$remove[] = array( $t[ $keyword ][2], $t[ $keyword ][2], $this->definer . ( 'VIEW' === $kind ? 'SQL SECURITY INVOKER ' : '' ) );
		$result   = array(
			'edits' => $remove,
			'hosts' => array_filter( $hosts, 'is_int' ),
		);
		try {
			if ( 'PROCEDURE' === $kind ) {
				$result['append'] = BackupPlus_SQL::render( 'ALTER PROCEDURE %i SQL SECURITY INVOKER', array( array( $this->database, $name ) ) );
			} elseif ( 'FUNCTION' === $kind ) {
				$result['append'] = BackupPlus_SQL::render( 'ALTER FUNCTION %i SQL SECURITY INVOKER', array( array( $this->database, $name ) ) );
			}
		} catch ( InvalidArgumentException $error ) {
			$this->refuse( 'an unsupported routine name' );
		}
		return $result;
	}

	/**
	 * The account after DEFINER: user@host, a quoted form of it, a role or CURRENT_USER. Returns the next index and
	 * the index of a host read as a user variable token ("@'host'"), which is not a variable.
	 */
	private function account( $t, $i ) {
		if ( ! $this->punct( $t, $i, '=' ) ) { $this->refuse( 'an unsupported definer' ); }
		++$i;
		if ( 'CURRENT_USER' === $this->keyword( $t, $i ) ) {
			return array( $this->punct( $t, $i + 1, '(' ) && $this->punct( $t, $i + 2, ')' ) ? $i + 3 : $i + 1, null );
		}
		if ( ! isset( $t[ $i ] ) || ! in_array( $t[ $i ][0], array( 'i', 's', 'w' ), true ) ) { $this->refuse( 'an unsupported definer' ); }
		if ( isset( $t[ $i + 1 ] ) && 'v' === $t[ $i + 1 ][0] ) { return array( $i + 2, $i + 1 ); }
		if ( $this->punct( $t, $i + 1, '@' ) ) {
			if ( ! isset( $t[ $i + 2 ] ) || ! in_array( $t[ $i + 2 ][0], array( 'i', 's', 'w' ), true ) ) { $this->refuse( 'an unsupported definer' ); }
			return array( $i + 3, null );
		}
		return array( $i + 1, null );
	}

	/** Character set, collation and encryption options of CREATE or ALTER DATABASE. */
	private function database_options( $t, $j, $create ) {
		$options = array(
			'charset'    => null,
			'collation'  => null,
			'encryption' => null,
		);
		while ( $j < count( $t ) ) {
			$word = $this->keyword( $t, $j );
			if ( 'DEFAULT' === $word ) {
				++$j;
				continue;
			}
			if ( 'CHARACTER' === $word && 'SET' === $this->keyword( $t, $j + 1 ) ) {
				$key = 'charset';
				$j  += 2;
			} elseif ( 'CHARSET' === $word ) {
				$key = 'charset';
				++$j;
			} elseif ( 'COLLATE' === $word ) {
				$key = 'collation';
				++$j;
			} elseif ( 'ENCRYPTION' === $word && $create ) {
				$key = 'encryption';
				++$j;
			} else {
				$this->refuse( 'an unsupported database option' );
			}
			if ( $this->punct( $t, $j, '=' ) ) { ++$j; }
			if ( ! isset( $t[ $j ] ) || ! in_array( $t[ $j ][0], array( 'w', 's', 'i' ), true ) || ! preg_match( 'encryption' === $key ? '/\A[YNyn]\z/D' : '/\A[A-Za-z0-9_]{1,64}\z/D', $t[ $j ][1] ) ) { $this->refuse( 'an unsupported database option' ); }
			$options[ $key ] = 'encryption' === $key ? strtoupper( $t[ $j ][1] ) : $t[ $j ][1];
			++$j;
		}
		return $options;
	}

	private function drop_statement( $t ) {
		if ( ! in_array( $this->keyword( $t, 1 ), array( 'TABLE', 'TABLES', 'VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'SEQUENCE' ), true ) ) { $this->refuse( $this->describe( $t ) ); }
		$this->object_name( $t, 2 );
		$this->foreign( $t );
	}

	private function alter_statement( $t ) {
		$kind = $this->keyword( $t, 1 );
		if ( 'TABLE' === $kind ) {
			list( , $j ) = $this->object_name( $t, 2 );
			if ( count( $t ) !== $j + 2 || ! in_array( $this->keyword( $t, $j ), array( 'DISABLE', 'ENABLE' ), true ) || 'KEYS' !== $this->keyword( $t, $j + 1 ) ) { $this->refuse( 'a table change other than index maintenance' ); }
			return;
		}
		if ( 'DATABASE' !== $kind && 'SCHEMA' !== $kind ) { $this->refuse( $this->describe( $t ) ); }
		$j = 2;
		if ( $this->name_token( $t, $j ) && ! in_array( $this->keyword( $t, $j ), array( 'DEFAULT', 'CHARACTER', 'CHARSET', 'COLLATE' ), true ) ) {
			if ( $this->database !== $t[ $j ][1] ) { $this->refuse( 'a change to another database' ); }
			++$j;
		}
		$this->database_options( $t, $j, false );
	}

	/** MariaDB dumps restore sequence positions with SELECT SETVAL(name, value, round, used). */
	private function setval( $t ) {
		if ( 'SETVAL' === $this->keyword( $t, 1 ) && $this->punct( $t, 2, '(' ) ) {
			list( , $j ) = $this->object_name( $t, 3 );
			for ( $arguments = 0; $arguments < 3; $arguments++ ) {
				if ( ! $this->punct( $t, $j, ',' ) ) { break; }
				$j += $this->punct( $t, $j + 1, '-' ) ? 2 : 1;
				if ( ! isset( $t[ $j ] ) || 'n' !== $t[ $j ][0] ) { break; }
				++$j;
			}
			if ( 3 === $arguments && $this->punct( $t, $j, ')' ) && count( $t ) === $j + 1 ) { return; }
		}
		$this->refuse( $this->describe( $t ) );
	}

	/** INSERT or REPLACE [IGNORE] [INTO] table [(columns)] VALUES: what follows is data only. */
	private function begin_data() {
		$t = $this->tokens;
		$i = 1;
		while ( in_array( $this->keyword( $t, $i ), array( 'IGNORE', 'INTO' ), true ) ) { ++$i; }
		list( , $i ) = $this->object_name( $t, $i );
		if ( $this->punct( $t, $i, '(' ) ) {
			do {
				if ( ! $this->name_token( $t, $i + 1 ) || ! ( $this->punct( $t, $i + 2, ',' ) || $this->punct( $t, $i + 2, ')' ) ) ) { $this->refuse( 'an unsupported row insert' ); }
				$i += 2;
			} while ( ! $this->punct( $t, $i, ')' ) );
			++$i;
		}
		if ( count( $t ) !== $i + 1 ) { $this->refuse( 'an unsupported row insert' ); }
		$this->taint( $t );
		$this->write( substr( $this->buffer, $this->start, $this->pos - $this->start ) );
		$this->start = $this->pos;
		$this->data  = true;
	}

	/**
	 * Row values may hold only literals: strings, numbers, NULL, DEFAULT, character set introducers and the hex, bit
	 * and national string prefixes. Function calls, subqueries, variables and identifiers are refused.
	 */
	private function data_segment() {
		do {
			$length = strcspn( $this->buffer, "'\"`#/;@\\", $this->pos );
		} while ( $this->pos + $length >= strlen( $this->buffer ) && $this->fill() );
		$this->need( 2, $this->pos + $length );
		if ( $length ) {
			$segment = substr( $this->buffer, $this->pos, $length );
			if ( preg_match( '/--(?:[ \t\r\n\f\x0b]|\z)/', $segment ) ) { $this->refuse( 'a comment inside row data' ); }
			preg_match( '/\G(?:[ \t\r\n\f\x0b(),+\-]++|(?i:NULL|DEFAULT)(?![A-Za-z0-9_$])|_[A-Za-z0-9]++(?=[ \t\r\n]*+(?:[\'"]|0[xX]))|[xXbBnN](?=\')|0[xX][0-9A-Fa-f]++(?![A-Za-z0-9_$.])|0[bB][01]++(?![A-Za-z0-9_$.])|[0-9]++(?:\.[0-9]*+)?(?:[eE][-+]?[0-9]++)?(?![A-Za-z0-9_$.]))*+/', $this->buffer, $match, 0, $this->pos );
			if ( strlen( $match[0] ) !== $length ) { $this->refuse( 'an expression inside row data' ); }
			$this->pos += $length;
		}
		if ( $this->pos >= strlen( $this->buffer ) ) { return; }
		$c = $this->buffer[ $this->pos ];
		if ( "'" === $c || '"' === $c ) {
			$this->pos = $this->quoted( $c );
		} elseif ( ';' === $c && $this->at_delimiter() ) {
			$this->pos += strlen( $this->delimiter );
			$this->write( substr( $this->buffer, $this->start, $this->pos - $this->start ) );
			$this->reset();
			return;
		} else {
			$this->refuse( 'an expression inside row data' );
		}
		if ( $this->pos - $this->start > self::FLUSH ) {
			$this->write( substr( $this->buffer, $this->start, $this->pos - $this->start ) );
			$this->compact();
		}
	}
}
