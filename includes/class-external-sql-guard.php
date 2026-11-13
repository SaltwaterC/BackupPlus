<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text, escaped where they are displayed (esc_html(), jQuery .text(), the escaped log, WP-CLI).
/**
 * Statement checks for the external database importer (BPS-18).
 *
 * A dump restored to an external database must change only the schema it is restored to. Each statement is read the way the
 * server will read it: comments are dropped, executable comments are kept or dropped according to the server version, and
 * strings and quoted names follow the session's sql_mode. The statement must then be one of the kinds the BackupPlus exporters
 * write, must not name another schema the target account can see, and must not use dynamic SQL or change global settings.
 *
 * This is defence in depth. The boundary is the target account: grant it privileges on the target schema only (see
 * docs/external-databases.md).
 */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_External_SQL_Guard {

	/** Objects a dump may create or drop. Events are not exported, and would run later on the server's schedule. */
	const OBJECTS = array( 'table', 'view', 'trigger', 'procedure', 'function' );

	/** Objects whose bodies hold their own semicolons. */
	const PROGRAMS = array( 'trigger', 'procedure', 'function' );

	/** Bare words refused anywhere: dynamic SQL, which hides names in strings; global settings; server file access. */
	const REFUSED_WORDS = array( 'prepare', 'execute', 'global', 'persist', 'persist_only', 'load_file', 'outfile', 'dumpfile' );

	/** Words that may follow SET and do not set a session variable. STATEMENT (MariaDB) would run another statement. */
	const REFUSED_SET = array( 'password', 'role', 'default', 'resource', 'statement' );

	/** Storage engines that reach another server or files the target account does not own. */
	const REFUSED_ENGINES = array( 'federated', 'federatedx', 'connect', 'spider' );

	/** Session settings a dump must not change: replication identity and internals, and debugging. */
	const REFUSED_VARIABLES = array( 'gtid_next', 'gtid_seq_no', 'gtid_domain_id', 'server_id', 'pseudo_thread_id', 'pseudo_slave_mode', 'pseudo_replica_mode', 'sql_log_off', 'skip_replication', 'wsrep_on', 'wsrep_osu_method', 'debug', 'debug_dbug', 'debug_sync' );

	/** Names whose value is a storage engine. */
	const ENGINE_NAMES = array( 'engine', 'default_storage_engine', 'default_tmp_storage_engine', 'storage_engine' );

	/** The bytes of an unquoted name. */
	const WORD_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';

	/** Tokens kept for the statement kind; the longest permitted CREATE prefix is far shorter. */
	const HEAD_TOKENS = 32;

	private $database;
	private $schemas = array();
	private $version;
	private $mariadb;
	private $ansi_quotes          = false;
	private $no_backslash_escapes = false;
	private $word_bytes;

	// State for the statement being read.
	private $head;
	private $previous;
	private $before_previous;
	private $semicolon;
	private $expect;
	private $assigned;

	/**
	 * @param string $database       The target schema.
	 * @param array  $schemas        Every schema the target account can see; the target itself is ignored.
	 * @param string $server_version The server's VERSION().
	 * @param string $sql_mode       The session sql_mode.
	 * @param string $charset        The session character_set_client.
	 */
	public function __construct( $database, $schemas, $server_version, $sql_mode, $charset ) {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)/', (string) $server_version, $m ) ) { throw new RuntimeException( 'Could not read the version of the target database server.' ); }
		$this->database = self::fold( $database );
		foreach ( (array) $schemas as $schema ) {
			$schema = self::fold( $schema );
			if ( $schema !== $this->database ) { $this->schemas[ $schema ] = true; }
		}
		$this->version    = (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3];
		$this->mariadb    = false !== stripos( $server_version, 'mariadb' );
		$this->word_bytes = self::WORD_BYTES . implode( '', array_map( 'chr', range( 0x80, 0xff ) ) );
		$this->session( $sql_mode, $charset );
	}

	/** A guard for an open connection to the target schema. */
	public static function for_connection( BackupPlus_SQL $sql, $database ) {
		$schemas = $sql->column( 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA' );
		$version = $sql->value( 'SELECT VERSION()' );
		if ( ! is_array( $schemas ) || ! is_string( $version ) ) { throw new RuntimeException( 'Could not list the schemas of the target database server.' ); }
		list( $sql_mode, $charset ) = self::read_session( $sql );
		return new self( $database, $schemas, $version, $sql_mode, $charset );
	}

	/** Read the session settings again after a statement that may have changed them. */
	public function refresh( BackupPlus_SQL $sql ) {
		list( $sql_mode, $charset ) = self::read_session( $sql );
		$this->session( $sql_mode, $charset );
	}

	private static function read_session( BackupPlus_SQL $sql ) {
		$row = $sql->row( 'SELECT @@SESSION.sql_mode, @@SESSION.character_set_client', array(), array(), ARRAY_N );
		if ( ! is_array( $row ) || 2 !== count( $row ) ) { throw new RuntimeException( 'Could not read the session settings of the target database.' ); }
		return $row;
	}

	/** The session settings that decide how the server reads quotes. */
	public function session( $sql_mode, $charset ) {
		// Under these character sets a backslash byte can end a two-byte character, so quotes cannot be found byte by byte.
		if ( BackupPlus_SQL::charset_has_multibyte_trail( $charset ) ) { throw new RuntimeException( 'The external database dump uses the character set ' . $charset . ', which an external restore does not support.' ); }
		$modes                      = array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) $sql_mode ) ) );
		$this->ansi_quotes          = in_array( 'ANSI_QUOTES', $modes, true ) || in_array( 'ANSI', $modes, true );
		$this->no_backslash_escapes = in_array( 'NO_BACKSLASH_ESCAPES', $modes, true );
	}

	/**
	 * Check one statement before it runs.
	 *
	 * @return string The statement kind, such as 'set', 'insert' or 'drop table'; empty for a statement with nothing to run.
	 * @throws RuntimeException When the statement is not permitted.
	 */
	public function inspect( $statement ) {
		$this->head            = array();
		$this->previous        = null;
		$this->before_previous = null;
		$this->semicolon       = false;
		$this->expect          = null;
		$this->assigned        = null;
		$this->scan( (string) $statement );
		if ( ! $this->head ) { return ''; }
		$kind = $this->kind( $this->head );
		if ( $this->semicolon && ! in_array( substr( $kind, 7 ), self::PROGRAMS, true ) ) { throw new RuntimeException( 'An external restore runs one statement at a time; this statement contains another.' ); }
		return $kind;
	}

	/** Names of schemas compare in lower case: the server may fold them, and refusing too much is safe. */
	private static function fold( $name ) {
		return strtolower( (string) $name );
	}

	private function scan( $sql ) {
		$length     = strlen( $sql );
		$executable = false;
		$adjacent   = false; // Whether the next token directly follows the previous one; whitespace and comments separate.
		$i          = 0;
		while ( $i < $length ) {
			$space = strspn( $sql, " \t\r\n\f\v", $i );
			if ( $space ) {
				$i       += $space;
				$adjacent = false;
				continue;
			}
			$c    = $sql[ $i ];
			$next = $i + 1 < $length ? $sql[ $i + 1 ] : '';
			if ( '#' === $c || ( '-' === $c && '-' === $next && ( $i + 2 >= $length || ord( $sql[ $i + 2 ] ) <= 32 ) ) ) {
				// Inside an executable comment the server's end of such a comment is not certain.
				if ( $executable ) { throw new RuntimeException( 'A line comment inside an executable comment is not permitted in an external restore.' ); }
				$end      = strpos( $sql, "\n", $i );
				$i        = false === $end ? $length : $end;
				$adjacent = false;
				continue;
			}
			if ( '/' === $c && '*' === $next ) {
				if ( $executable ) { throw new RuntimeException( 'Nested comments are not permitted in an external restore.' ); }
				$content = $this->comment( $sql, $i );
				if ( null === $content ) {
					$end = strpos( $sql, '*/', $i + 2 );
					if ( false === $end ) { throw new RuntimeException( 'Unterminated comment in the external database dump.' ); }
					$content = $end + 2;
				} else {
					$executable = true;
				}
				$i        = $content;
				$adjacent = false;
				continue;
			}
			if ( $executable && '*' === $c && '/' === $next ) {
				$executable = false;
				$i         += 2;
				$adjacent   = false;
				continue;
			}
			if ( "'" === $c || ( '"' === $c && ! $this->ansi_quotes ) ) {
				$end = $this->quoted_end( $sql, $i, $c, ! $this->no_backslash_escapes );
				$this->token( 's', '', $adjacent );
			} elseif ( '`' === $c || '"' === $c ) {
				$end = $this->quoted_end( $sql, $i, $c, false );
				$this->token( 'i', self::fold( str_replace( $c . $c, $c, substr( $sql, $i + 1, $end - $i - 2 ) ) ), $adjacent );
			} elseif ( '@' === $c ) {
				$end = '@' === $next ? $i + 2 : $i + 1;
				$this->token( 'p', substr( $sql, $i, $end - $i ), $adjacent );
			} else {
				$end = $i + strspn( $sql, $this->word_bytes, $i );
				if ( $end > $i ) {
					$this->token( 'w', self::fold( substr( $sql, $i, $end - $i ) ), $adjacent );
				} else {
					$end = $i + 1;
					$this->token( 'p', $c, $adjacent );
				}
			}
			$i        = $end;
			$adjacent = true;
		}
		if ( $executable ) { throw new RuntimeException( 'Unterminated executable comment in the external database dump.' ); }
	}

	/** The offset after a quoted string or name that starts at $start. */
	private function quoted_end( $sql, $start, $quote, $backslash ) {
		$length = strlen( $sql );
		$stops  = $backslash ? $quote . '\\' : $quote;
		$j      = $start + 1;
		while ( true ) {
			$k = $j + strcspn( $sql, $stops, $j );
			if ( $k >= $length ) { throw new RuntimeException( 'Unterminated quoted text in the external database dump.' ); }
			if ( '\\' === $sql[ $k ] ) {
				$j = $k + 2;
				continue;
			}
			if ( $k + 1 < $length && $quote === $sql[ $k + 1 ] ) {
				$j = $k + 2;
				continue;
			}
			return $k + 1;
		}
	}

	/**
	 * A comment at $start: the offset of its content when the server runs it, or null when the server skips it.
	 * MySQL runs /*!NNNNN and MariaDB also /*M!NNNNNN when the version is at most its own. MySQL reads five digits and MariaDB
	 * five or six, so a version the two would read differently is refused when either reading would run.
	 */
	private function comment( $sql, $start ) {
		if ( ! preg_match( '/\G\/\*(M?)!(\d*)/', $sql, $m, 0, $start ) ) { return null; }
		$content = $start + strlen( $m[0] );
		if ( 'M' === $m[1] && ! $this->mariadb ) { return null; }
		$digits = $m[2];
		$count  = strlen( $digits );
		if ( 0 === $count ) { return $content; }
		if ( $this->mariadb ) {
			if ( 5 === $count || 6 === $count ) { return (int) $digits <= $this->version ? $content : null; }
		} elseif ( 5 === $count ) {
			return (int) $digits <= $this->version ? $content : null;
		} elseif ( $count > 5 && (int) substr( $digits, 0, 5 ) > $this->version && (int) substr( $digits, 0, 6 ) > $this->version ) {
			return null;
		}
		throw new RuntimeException( 'An executable comment with an ambiguous version is not permitted in an external restore.' );
	}

	/**
	 * One token: 'w' an unquoted word, 'i' a quoted name, 's' a string, 'p' punctuation. Words and names are in lower case.
	 * $adjacent is true when nothing separates the token from the previous one.
	 */
	private function token( $type, $value, $adjacent ) {
		$name     = 'w' === $type || 'i' === $type;
		$variable = false;
		$previous = $this->previous;
		if ( $name && $adjacent && $previous && 'p' === $previous[0] ) {
			if ( '@' === $previous[1] || '@@' === $previous[1] ) {
				// A user or system variable, or the host of an account.
				$variable = true;
				if ( '@@' === $previous[1] && in_array( $value, array( 'global', 'persist', 'persist_only' ), true ) ) { throw new RuntimeException( 'Changing global server settings is not permitted in an external restore.' ); }
			} elseif ( '.' === $previous[1] && $previous[2] && $this->before_previous && $this->before_previous[3] ) {
				$variable = true; // The rest of a dotted variable name, such as @@session.sql_mode.
			}
		}
		if ( $name && ! $variable && $previous && 'p' === $previous[0] && '.' === $previous[1] ) {
			$qualifier = $this->before_previous;
			if ( $qualifier && ( 'w' === $qualifier[0] || 'i' === $qualifier[0] ) && ! $qualifier[3] && isset( $this->schemas[ $qualifier[1] ] ) ) {
				throw new RuntimeException( 'The external database dump refers to the schema ' . $qualifier[1] . '; an external restore may change only its target schema.' );
			}
		}
		if ( 'w' === $type && ! $variable && in_array( $value, self::REFUSED_WORDS, true ) ) { throw new RuntimeException( 'The word ' . strtoupper( $value ) . ' is not permitted in an external restore.' ); }
		if ( 'p' === $type && ';' === $value ) { $this->semicolon = true; }
		$this->setting( $type, $value, $variable ? ( '@' === $previous[1] ? 'user' : 'system' ) : '' );
		$token                 = array( $type, $value, $adjacent, $variable );
		$this->before_previous = $previous;
		$this->previous        = $token;
		if ( count( $this->head ) < self::HEAD_TOKENS ) { $this->head[] = $token; }
	}

	/**
	 * Table options and session settings the statement kind does not show: an engine or option that reaches another server
	 * or a folder outside the data directory, binary logging set to anything but off or its saved value, and replication
	 * internals. $variable is 'user' for the name of a user variable, 'system' for that of a system variable, else ''.
	 */
	private function setting( $type, $value, $variable ) {
		$verb         = $this->head ? $this->head[0][1] : $value;
		$expect       = $this->expect;
		$name         = $this->assigned;
		$value_token  = 'w' === $type || 'i' === $type || 's' === $type;
		$this->expect = null;
		switch ( $expect ) {
			case 'engine':
				if ( 's' === $type || ( $value_token && in_array( $value, self::REFUSED_ENGINES, true ) ) ) {
					throw new RuntimeException( 'Storage engines that reach another server, and engine names in quotes, are not permitted in an external restore.' );
				}
				return;
			case 'log_bin':
				if ( 'w' === $type && in_array( $value, array( '0', 'off', 'false' ), true ) ) {
					$this->expect = 'end';
					return;
				}
				if ( 'p' === $type && '@' === $value ) {
					$this->expect = 'saved';
					return;
				}
				break;
			case 'saved':
				if ( 'user' === $variable ) {
					$this->expect = 'end';
					return;
				}
				break;
			case 'end':
				if ( 'p' === $type && ( ',' === $value || ';' === $value ) ) { return; }
				break;
			case 'assign':
				if ( 'p' === $type && ':' === $value ) {
					$this->expect = 'assign';
					return;
				}
				if ( 'connection' === $name && ( 's' === $type || ( 'p' === $type && '=' === $value ) ) ) {
					throw new RuntimeException( 'A table that connects to another server is not permitted in an external restore.' );
				}
				if ( in_array( $name, self::ENGINE_NAMES, true ) ) {
					if ( 'p' === $type && '=' === $value ) {
						$this->expect = 'engine';
						return;
					}
					if ( 'engine' === $name && $value_token ) {
						$this->expect = 'engine';
						$this->setting( $type, $value, $variable );
						return;
					}
				} elseif ( 'p' === $type && '=' === $value ) {
					if ( 'sql_log_bin' === $name ) {
						$this->expect = 'log_bin';
						return;
					}
					if ( in_array( $name, self::REFUSED_VARIABLES, true ) ) {
						throw new RuntimeException( 'Setting ' . $name . ' is not permitted in an external restore.' );
					}
				}
				break;
		}
		if ( in_array( $expect, array( 'log_bin', 'saved', 'end' ), true ) ) {
			throw new RuntimeException( 'An external restore may only turn binary logging off, or set it back to a saved value.' );
		}
		if ( 'w' !== $type && 'i' !== $type ) { return; }
		$table   = 'create' === $verb || 'alter' === $verb;
		$program = 'create' === $verb || 'set' === $verb;
		if ( 'user' !== $variable && ( ( $table && in_array( $value, array( 'engine', 'connection' ), true ) ) || ( $program && ( 'sql_log_bin' === $value || in_array( $value, self::ENGINE_NAMES, true ) || in_array( $value, self::REFUSED_VARIABLES, true ) ) ) ) ) {
			$this->expect   = 'assign';
			$this->assigned = $value;
		}
		if ( $table && 'w' === $type && 'directory' === $value && $this->previous && 'w' === $this->previous[0] && in_array( $this->previous[1], array( 'data', 'index' ), true ) ) {
			throw new RuntimeException( 'DATA DIRECTORY and INDEX DIRECTORY are not permitted in an external restore.' );
		}
	}

	/** The statement kind, from its first tokens; anything the exporters do not write is refused. */
	private function kind( $head ) {
		$words = array();
		foreach ( $head as $token ) { $words[] = 'w' === $token[0] ? $token[1] : null; }
		$verb = $words[0];
		$at   = 1;
		switch ( $verb ) {
			case 'set':
				if ( isset( $words[1] ) && in_array( $words[1], self::REFUSED_SET, true ) ) { break; }
				return 'set';
			case 'insert':
			case 'replace':
				return $verb;
			case 'commit':
				// mariadb-dump writes each table's rows between SET AUTOCOMMIT=0 and COMMIT.
				if ( 1 === count( $head ) ) { return $verb; }
				break;
			case 'lock':
			case 'unlock':
				if ( isset( $words[1] ) && in_array( $words[1], array( 'table', 'tables' ), true ) ) { return $verb . ' tables'; }
				break;
			case 'alter':
				while ( isset( $words[ $at ] ) && in_array( $words[ $at ], array( 'online', 'ignore' ), true ) ) { ++$at; }
				if ( isset( $words[ $at ] ) && 'table' === $words[ $at ] ) { return 'alter table'; }
				break;
			case 'drop':
				if ( isset( $words[ $at ] ) && 'temporary' === $words[ $at ] ) { ++$at; }
				if ( isset( $words[ $at ] ) && in_array( $words[ $at ], self::OBJECTS, true ) ) { return 'drop ' . $words[ $at ]; }
				break;
			case 'create':
				$object = $this->created_object( $head, $words );
				if ( null !== $object ) { return 'create ' . $object; }
				break;
		}
		$second = isset( $words[1] ) && in_array( $verb, array( 'create', 'drop', 'alter', 'set', 'lock', 'unlock' ), true ) ? ' ' . $words[1] : '';
		if ( 'use' === $verb || in_array( trim( $second ), array( 'database', 'schema' ), true ) ) { throw new RuntimeException( 'USE and statements that create, change or drop a database are not permitted in an external restore.' ); }
		$name = null === $verb ? 'that starts with ' . $head[0][1] : strtoupper( $verb . $second );
		throw new RuntimeException( 'The statement ' . $name . ' is not one an external database dump contains, and is not permitted in an external restore.' );
	}

	/** The object of CREATE [OR REPLACE] [ALGORITHM = x] [DEFINER = account] [SQL SECURITY x] [TEMPORARY] [AGGREGATE], or null. */
	private function created_object( $head, $words ) {
		$at    = 1;
		$count = count( $head );
		if ( isset( $words[1], $words[2] ) && 'or' === $words[1] && 'replace' === $words[2] ) { $at = 3; }
		while ( $at < $count ) {
			$word = $words[ $at ];
			if ( 'algorithm' === $word && self::is( $head, $at + 1, 'p', '=' ) && isset( $words[ $at + 2 ] ) ) {
				$at += 3;
			} elseif ( 'sql' === $word && isset( $words[ $at + 1 ], $words[ $at + 2 ] ) && 'security' === $words[ $at + 1 ] ) {
				$at += 3;
			} elseif ( 'temporary' === $word || 'aggregate' === $word ) {
				++$at;
			} elseif ( 'definer' === $word && self::is( $head, $at + 1, 'p', '=' ) ) {
				$at = $this->account_end( $head, $at + 2 );
				if ( null === $at ) { return null; }
			} else {
				return null !== $word && in_array( $word, self::OBJECTS, true ) ? $word : null;
			}
		}
		return null;
	}

	/** The token after an account: CURRENT_USER[()], or a name with an optional @host. */
	private function account_end( $head, $at ) {
		if ( ! isset( $head[ $at ] ) ) { return null; }
		if ( self::is( $head, $at, 'w', 'current_user' ) ) {
			return self::is( $head, $at + 1, 'p', '(' ) && self::is( $head, $at + 2, 'p', ')' ) ? $at + 3 : $at + 1;
		}
		if ( ! in_array( $head[ $at ][0], array( 'w', 'i', 's' ), true ) ) { return null; }
		++$at;
		if ( self::is( $head, $at, 'p', '@' ) ) {
			if ( ! isset( $head[ $at + 1 ] ) || ! in_array( $head[ $at + 1 ][0], array( 'w', 'i', 's' ), true ) ) { return null; }
			$at += 2;
		}
		return $at;
	}

	private static function is( $head, $at, $type, $value ) {
		return isset( $head[ $at ] ) && $type === $head[ $at ][0] && $value === $head[ $at ][1];
	}
}
