<?php
/**
 * Plugin SQL is written only as constant templates for BackupPlus_SQL (see includes/class-sql.php).
 *
 * The check fails when plugin code:
 *
 * - passes anything but one constant template to an SQL call: an instance method with a sink name on any object, a
 *   static one on BackupPlus_SQL or a database class (also through "\", self::, static:: and parent::), or a mysqli or
 *   mysql function;
 * - passes a dump statement executor (script(), sql_exec()) anything but a plain variable that holds no assembled SQL, a
 *   constant template, or a template rendered by BackupPlus_SQL;
 * - calls ->prepare() (client-side escaping, not a prepared statement), or $wpdb's insert(), update(), delete() and
 *   replace(), which put the table name into the statement as text;
 * - calls a method by a computed name, or names an SQL method or function as a callable string or array, because the
 *   check cannot see what such a call runs;
 * - joins SQL text with anything that is not a constant: "." chains, ".=" onto a variable that holds SQL text,
 *   interpolated strings and heredocs (also when a variable that holds SQL text is interpolated), elements added to an
 *   array that then holds SQL text, and implode(), join(), strtr(), str_replace(), str_ireplace(), substr_replace(),
 *   sprintf() and vsprintf() called with SQL text or with a variable that holds it (for sprintf(), SQL text in the format,
 *   or a variable holding SQL in any argument), and preg_replace() that puts a value into SQL text or is given a variable
 *   that holds SQL (rewriting a dump statement with a constant replacement is allowed);
 * - passes non-constant SQL as a mysqli init command (MYSQLI_INIT_COMMAND);
 * - refers to BackupPlus_SQL_Fragment other than in instanceof and type declarations, so a fragment comes only from
 *   BackupPlus_SQL::fragment().
 *
 * Text counts as SQL when it contains a keyword written in capitals, or SQL syntax in any case: a keyword followed by
 * what that keyword takes ("delete from", "where id =", "set names", "/*!40101", "--execute="). A variable joined with
 * the text stands for any value, and a constant that this file defines stands for its text. Single lowercase words are
 * not taken for SQL, because they are also English; the first argument of a preg_*() function is a pattern, not SQL.
 *
 * Statements read from backup files reach the server only through the dump executors, which take a plain variable. A
 * statement rendered by BackupPlus_SQL::render() is a value: the dump writer appends the dump's own text to one.
 *
 * Test code (tests/, scripts/, dev/) builds SQL for fixtures and is not checked; tests/Unit/Sql covers this check.
 *
 * Usage: php scripts/check-sql.php [directory]
 */

final class BackupPlus_SQL_Check {

	/** Method name => 1-based position of the SQL argument. */
	const METHOD_SINKS = array(
		'query'         => 1,
		'get_var'       => 1,
		'get_row'       => 1,
		'get_col'       => 1,
		'get_results'   => 1,
		'rows'          => 1,
		'row'           => 1,
		'value'         => 1,
		'column'        => 1,
		'execute'       => 1,
		'prepared'      => 1,
		'render'        => 1,
		'fragment'      => 1,
		'real_query'    => 1,
		'multi_query'   => 1,
		'send_query'    => 1,
		'exec'          => 1,
		'execute_query' => 1,
	);

	/** Sink names that other libraries use for something else (phpseclib's SSH exec()): sinks only on a database receiver. */
	const RECEIVER_SINKS = array( 'exec' );

	/** Function name => 1-based position of the SQL argument. */
	const FUNCTION_SINKS = array(
		'mysqli_query'           => 2,
		'mysqli_prepare'         => 2,
		'mysqli_real_query'      => 2,
		'mysqli_multi_query'     => 2,
		'mysqli_send_query'      => 2,
		'mysqli_stmt_prepare'    => 2,
		'mysqli_execute_query'   => 2,
		'mysql_query'            => 1,
		'mysql_unbuffered_query' => 1,
		'mysql_db_query'         => 2,
	);

	/** Methods that run one statement read from a dump. */
	const EXECUTORS = array(
		'script'   => 1,
		'sql_exec' => 1,
	);

	/** $wpdb methods that write the table name into the statement. */
	const WPDB_WRITERS = array( 'insert', 'update', 'delete', 'replace' );

	/** Functions that assemble strings from parts. */
	const BUILDERS = array( 'sprintf', 'vsprintf', 'implode', 'join', 'strtr', 'str_replace', 'str_ireplace', 'substr_replace', 'preg_replace', 'preg_replace_callback' );

	/** Receivers and classes that are database connections. */
	const DATABASE_RECEIVER = '/wpdb|mysqli|dbh|\bpdo\b/i';
	const DATABASE_CLASS    = '/\A(?:BackupPlus_SQL|wpdb|mysqli|PDO)\z|(?:\A|_)(?:SQL|DB|Database|WPDB|Mysqli)(?:_|\z)/i';

	/** Statement and clause keywords, matched in capitals at a token boundary. */
	const KEYWORDS = '(?<![A-Za-z0-9_$@.])(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|RENAME|TRUNCATE|SHOW|DESCRIBE|LOCK|UNLOCK|GRANT|REVOKE|KILL|SET|CALL|USE|WITH|EXPLAIN|DO|LOAD|HANDLER|PREPARE|EXECUTE|DEALLOCATE|FLUSH|OPTIMIZE|ANALYZE|CHECK|REPAIR|CHECKSUM|BEGIN|START|COMMIT|ROLLBACK|SAVEPOINT|XA|PURGE|RESET|INSTALL|UNINSTALL|FROM|WHERE|INTO|VALUES|JOIN|LIMIT|HAVING|UNION|ORDER\s+BY|GROUP\s+BY|TABLES?|DATABASE|SCHEMA|NAMES|COLLATE|CHARSET|ENGINE|DEFINER|AND|OR)(?![A-Za-z0-9_])';

	/** SQL syntax in any case. "?" stands for a value joined with the text. */
	const SYNTAX = array(
		'\b(?:insert|replace)\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*into\b',
		'\bdelete\s+(?:(?:low_priority|quick|ignore)\s+)*from\b',
		'\bupdate\s+(?:(?:low_priority|ignore)\s+)*[`\w.?]+\s+set\b',
		'\bselect\s+(?:(?:distinct|all|sql_\w+|high_priority|straight_join)\s+)*(?:\*|count\s*\(|@@|`|\?\s*(?:,|from\b)|[\w.`]+\s*,)',
		'\bselect\b[^;]*\bfrom\s+(?:`|\?|[\w]+\s+(?:where|limit|order|group|join)\b)',
		'\b(?:create|drop|alter|truncate|rename|lock|unlock|optimize|analyze|check|repair|checksum|flush)\s+(?:(?:temporary|or\s+replace|online|offline|ignore|no_write_to_binlog|local|algorithm\s*=\s*\w+|definer\s*=\s*\S+|sql\s+security\s+\w+)\s+)*(?:table|tables|database|schema|view|trigger|procedure|function|index|user|role|event|server|tablespace|logs|privileges)\b\s*(?:if\b|`|\?|\w+\s*(?:\(|;|\z|,|to\b|rename\b|add\b|drop\b|modify\b|change\b|write\b|read\b|engine\b))',
		'\bshow\s+(?:(?:full|global|session|extended|storage)\s+)?(?:tables|columns|fields|create|grants|variables|status|databases|schemas|index|indexes|keys|processlist|master|binary|binlog|slave|replica|triggers|engines|engine|warnings|errors|character\s+set|collation|table\s+status|function|procedure|events|privileges|plugins|open\s+tables)\b',
		'\bset\s+(?:names|session|global|persist|persist_only|local|@@?\w|autocommit|foreign_key_checks|unique_checks|sql_mode|sql_log_bin|character\s+set|charset|transaction|password|time_zone|role|default\s+role|statement)\b',
		'\bwhere\s+[`\w?.]+\s*(?:=|<|>|!=|<>|\bin\b|\blike\b|\bis\b|\bbetween\b|\bregexp\b|\bnot\b)',
		'\b(?:order|group)\s+by\s+[`\w?]',
		'\blimit\s+(?:\d|\?)',
		'\bvalues\s*\(',
		'\b(?:inner\s+|left\s+|right\s+|cross\s+|straight_)?join\s+[`\w?.]+\s+(?:(?:as\s+)?\w+\s+)?(?:on|using)\b',
		'\bunion\s+(?:all\s+|distinct\s+)?select\b',
		'\bon\s+duplicate\s+key\b',
		'\bif\s+(?:not\s+)?exists\b',
		'\bdefault\s+(?:charset|character\s+set)\s*=',
		'(?<![\w&?;-])collate\s*=',
		'(?<![\w&?;-])engine\s*=\s*(?:\?|innodb|myisam|aria|memory|archive|csv)',
		'@@(?:session\.|global\.)?\w',
		'\/\*[!M]!?\d{5,6}',
		'\bstart\s+transaction\b',
		'\bxa\s+(?:start|begin|end|prepare|commit|rollback|recover)\b',
		'\bload\s+(?:data|xml)\b',
		'\bhandler\s+[`\w?]+\s+(?:open|read|close)\b',
		'\bprepare\s+\w+\s+from\b',
		'\bexecute\s+\w+(?:\s+using\b|\s*;|\s*\z)',
		'\bdeallocate\s+prepare\b',
		'\bexplain\s+(?:select|insert|update|delete|replace|format|extended|analyze|partitions)\b',
		'\bwith\s+(?:recursive\s+)?[`\w]+\s+as\s*\(',
		'\bcall\s+[`\w.]+\s*\(',
		'\bkill\s+(?:query\s+|connection\s+)?(?:\d|\?)',
		'\bdo\s+(?:sleep|get_lock|release_lock|\d)',
		'\buse\s+`',
		'\bgrant\s+[\w\s,]+\s+on\s+',
		'\brevoke\s+[\w\s,]+\s+(?:on|from)\s+',
		'\bpurge\s+(?:binary|master)\s+logs\b',
		'\b(?:begin|commit|rollback)\s*(?:work\s*)?;',
		'\bsavepoint\s+[`\w?]',
		'(?:\A|\s)--(?:execute|init-command)=',
	);

	/** Tokens that end an operand of ".": everything that binds less tightly. */
	const BOUNDARIES = array( ';', ',', '=', '?', ':', '<', '>', '|', '^', '&', '!' );

	private $failures = array();
	private $file;
	private $tokens;
	private $count;

	/** Per-function state: name, SQL parameter, and what each variable holds ('sql', 'built'). */
	private $scopes;
	private $classes;

	/** Constants given a string literal in this file (const NAME = '...', define( 'NAME', '...' )): name => text. */
	private $constants;

	public function file( $relative, $source ) {
		$this->file      = $relative;
		$this->tokens    = self::normalize( token_get_all( $source ) );
		$this->count     = count( $this->tokens );
		$this->scopes    = array(
			array(
				'name'  => null,
				'param' => null,
				'vars'  => array(),
				'depth' => 0,
			),
		);
		$this->classes   = array();
		$this->constants = array();
		for ( $i = 0; $i + 3 < $this->count; $i++ ) {
			if ( $this->is( $i, T_CONST ) && $this->is( $i + 1, T_STRING ) && $this->is( $i + 2, '=' ) && $this->is( $i + 3, T_CONSTANT_ENCAPSED_STRING ) ) {
				$this->constants[ $this->tokens[ $i + 1 ][1] ] = substr( $this->tokens[ $i + 3 ][1], 1, -1 );
			} elseif ( $this->is( $i, T_STRING, 'define' ) && $this->is( $i + 1, '(' ) && $this->is( $i + 2, T_CONSTANT_ENCAPSED_STRING ) && $this->is( $i + 3, ',' ) && $this->is( $i + 4, T_CONSTANT_ENCAPSED_STRING ) ) {
				$this->constants[ substr( $this->tokens[ $i + 2 ][1], 1, -1 ) ] = substr( $this->tokens[ $i + 4 ][1], 1, -1 );
			}
		}
		$this->scan();
	}

	public function failures() {
		return $this->failures;
	}

	/** Tokens as array( type, text, line ) without whitespace and comments; "\Name" becomes "Name". */
	private static function normalize( $raw ) {
		$tokens = array();
		$line   = 1;
		$count  = count( $raw );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $raw[ $i ];
			if ( is_array( $token ) ) {
				$line = $token[2];
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
				if ( T_NS_SEPARATOR === $token[0] ) { continue; }
				if ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) {
					$tokens[] = array( T_STRING, ltrim( $token[1], '\\' ), $line );
					continue;
				}
				if ( ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] ) || ( defined( 'T_NAME_RELATIVE' ) && T_NAME_RELATIVE === $token[0] ) ) {
					$tokens[] = array( T_STRING, $token[1], $line );
					continue;
				}
				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $token[0] ) {
					$tokens[] = array( T_OBJECT_OPERATOR, '?->', $line );
					continue;
				}
				$tokens[] = $token;
			} else {
				$tokens[] = array( $token, $token, $line );
			}
		}
		return $tokens;
	}

	private function fail( $index, $message ) {
		$key = $this->file . ':' . $this->tokens[ $index ][2];
		if ( ! isset( $this->failures[ $key ] ) ) { $this->failures[ $key ] = $key . ' ' . $message; }
	}

	private function is( $index, $type, $text = null ) {
		if ( $index < 0 || $index >= $this->count || $this->tokens[ $index ][0] !== $type ) { return false; }
		return null === $text || 0 === strcasecmp( $this->tokens[ $index ][1], $text );
	}

	/** Whether text contains SQL: a keyword in capitals, or SQL syntax in any case. */
	public static function sql_like( $text ) {
		// Regular expressions about SQL are not SQL: (?:, \s, \A, \z, \b, \d and \w never occur in a statement.
		if ( preg_match( '/\(\?[:!=<]|\\\\[sSAzbdw]/', $text ) ) { return false; }
		if ( preg_match( '/' . self::KEYWORDS . '/', $text ) ) { return true; }
		foreach ( self::SYNTAX as $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $text ) ) { return true; }
		}
		return false;
	}

	private function scope() {
		return $this->scopes[ count( $this->scopes ) - 1 ];
	}

	private function kind( $name ) {
		$scope = $this->scope();
		return null !== $name && isset( $scope['vars'][ $name ] ) ? $scope['vars'][ $name ] : null;
	}

	private function remember( $name, $kind ) {
		$top = count( $this->scopes ) - 1;
		if ( null === $kind ) {
			unset( $this->scopes[ $top ]['vars'][ $name ] );
		} else {
			$this->scopes[ $top ]['vars'][ $name ] = $kind;
		}
	}

	/** Index of the bracket that closes the one at $open. */
	private function closing( $open ) {
		$depth = 0;
		for ( $i = $open; $i < $this->count; $i++ ) {
			$type = $this->tokens[ $i ][0];
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				++$depth;
			} elseif ( in_array( $type, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) { return $i; }
			}
		}
		return $this->count - 1;
	}

	/** Index of the token that ends a quoted string or heredoc opening at $open. */
	private function string_end( $open ) {
		$close = '"' === $this->tokens[ $open ][0] ? '"' : T_END_HEREDOC;
		for ( $i = $open + 1; $i < $this->count; $i++ ) {
			$type = $this->tokens[ $i ][0];
			if ( T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				$i = $this->closing( $i );
			} elseif ( $type === $close ) {
				return $i;
			}
		}
		return $this->count - 1;
	}

	/** Name of a plain variable or $this property spanning $start..$end, or null. */
	private function variable( $start, $end ) {
		if ( $start === $end && $this->is( $start, T_VARIABLE ) ) { return $this->tokens[ $start ][1]; }
		if ( $end === $start + 2 && $this->is( $start, T_VARIABLE, '$this' ) && $this->is( $start + 1, T_OBJECT_OPERATOR ) && $this->is( $end, T_STRING ) ) {
			return '$this->' . $this->tokens[ $end ][1];
		}
		return null;
	}

	/** Whether $start..$end is a BackupPlus_SQL::render() or ::fragment() call, whose template is checked as a sink. */
	private function rendered( $start, $end ) {
		return $end > $start + 3 && $this->is( $start, T_STRING, 'BackupPlus_SQL' ) && $this->is( $start + 1, T_DOUBLE_COLON ) && ( $this->is( $start + 2, T_STRING, 'render' ) || $this->is( $start + 2, T_STRING, 'fragment' ) ) && $this->is( $start + 3, '(' ) && $this->closing( $start + 3 ) === $end;
	}

	/** A constant: NAME, Class::NAME, a magic constant or a number. Not a runtime value; its text is known when this file gives it. */
	private function constant_name( $start, $end ) {
		$magic = array( T_LNUMBER, T_DNUMBER, T_CLASS_C, T_LINE, T_FILE, T_DIR, T_FUNC_C, T_METHOD_C, T_NS_C, T_TRAIT_C );
		if ( $start === $end ) { return in_array( $this->tokens[ $start ][0], $magic, true ) || ( $this->is( $start, T_STRING ) && ! $this->is( $start + 1, '(' ) && ! $this->is( $start - 1, T_OBJECT_OPERATOR ) ); }
		return $end === $start + 2 && ( $this->is( $start, T_STRING ) || $this->is( $start, T_STATIC ) ) && $this->is( $start + 1, T_DOUBLE_COLON ) && ( $this->is( $end, T_STRING ) || $this->is( $end, T_CLASS ) ) && ! $this->is( $end + 1, '(' );
	}

	/**
	 * One operand of a "." chain: array( kind, text ). Kinds: 'literal' (constant text), 'string' (interpolated text with
	 * values), 'sql' (a variable that holds SQL text), 'value' (anything else). A template rendered by BackupPlus_SQL is a
	 * value: dump writers append the dump's own text to it, and it reaches an executor only as a plain variable.
	 */
	private function operand( $start, $end ) {
		$first = $this->tokens[ $start ][0];
		if ( $start === $end && T_CONSTANT_ENCAPSED_STRING === $first ) { return array( 'literal', substr( $this->tokens[ $start ][1], 1, -1 ) ); }
		if ( ( '"' === $first || T_START_HEREDOC === $first ) && $this->string_end( $start ) === $end ) {
			$text   = '';
			$values = false;
			$holds  = false;
			for ( $i = $start + 1; $i < $end; $i++ ) {
				$type = $this->tokens[ $i ][0];
				if ( T_ENCAPSED_AND_WHITESPACE === $type ) {
					$text .= $this->tokens[ $i ][1];
				} else {
					$values = true;
					$text  .= '?';
					if ( T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
						$i = $this->closing( $i );
					} elseif ( T_VARIABLE === $type ) {
						// A variable that holds SQL text, interpolated, assembles SQL like "." does
						if ( null !== $this->kind( $this->variable( $i, $this->is( $i + 1, T_OBJECT_OPERATOR ) && $this->is( $i + 2, T_STRING ) ? $i + 2 : $i ) ) ) { $holds = true; }
						// $var->prop and $var[key] inside a string.
						while ( $i + 2 < $end && ( $this->is( $i + 1, T_OBJECT_OPERATOR ) || $this->is( $i + 1, '[' ) ) ) {
							$i = $this->is( $i + 1, '[' ) ? $this->closing( $i + 1 ) : $i + 2;
						}
					}
				}
			}
			return array( $holds ? 'sqlstring' : ( $values ? 'string' : 'literal' ), $text );
		}
		if ( null !== $this->kind( $this->variable( $start, $end ) ) ) { return array( 'sql', '?' ); }
		if ( $this->constant_name( $start, $end ) ) {
			$name = $this->tokens[ $end ][1];
			return array( 'literal', isset( $this->constants[ $name ] ) ? $this->constants[ $name ] : '' );
		}
		return array( 'value', '?' );
	}

	/**
	 * Operands of the expression $start..$end at its own nesting level. Each branch of a ternary is a chain of its own;
	 * the condition is not part of the value.
	 */
	private function operands( $start, $end ) {
		$operands = array();
		$from     = $start;
		$question = null;
		for ( $i = $start; $i <= $end + 1; $i++ ) {
			if ( $i <= $end ) {
				$type = $this->tokens[ $i ][0];
				if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
					$i = $this->closing( $i );
					continue;
				}
				if ( '"' === $type || T_START_HEREDOC === $type ) {
					$i = $this->string_end( $i );
					continue;
				}
				if ( '?' === $type && null === $question ) {
					$question = $i;
					$operands = array();
					$from     = $i + 1;
					continue;
				}
				if ( '.' !== $type && ! ( ':' === $type && null !== $question ) ) { continue; }
			}
			if ( $from <= $i - 1 ) { $operands[] = $this->operand( $from, $i - 1 ); }
			$from = $i + 1;
		}
		return $operands;
	}

	/** array( text with "?" for values, whether a value is joined, whether a variable holding SQL is joined ). */
	private static function joined( $operands ) {
		$text  = '';
		$value = false;
		$sql   = false;
		foreach ( $operands as $operand ) {
			$text .= $operand[1];
			if ( 'literal' !== $operand[0] ) { $value = true; }
			if ( 'sql' === $operand[0] || 'sqlstring' === $operand[0] ) { $sql = true; }
		}
		return array( $text, $value, $sql );
	}

	/** End of the expression starting at $start: the first ";", ",", "=>" or unmatched closing bracket. */
	private function expression_end( $start ) {
		for ( $i = $start; $i < $this->count; $i++ ) {
			$type = $this->tokens[ $i ][0];
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$i = $this->closing( $i );
			} elseif ( '"' === $type || T_START_HEREDOC === $type ) {
				$i = $this->string_end( $i );
			} elseif ( in_array( $type, array( ';', ',', ')', ']', '}', T_DOUBLE_ARROW, T_CLOSE_TAG, T_AS ), true ) ) {
				return $i - 1;
			}
		}
		return $this->count - 1;
	}

	/** End of the "." chain starting at $start: the first operator that binds less tightly, or an unmatched closing bracket. */
	private function chain_end( $start ) {
		for ( $i = $start; $i < $this->count; $i++ ) {
			$type = $this->tokens[ $i ][0];
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$i = $this->closing( $i );
			} elseif ( '"' === $type || T_START_HEREDOC === $type ) {
				$i = $this->string_end( $i );
			} elseif ( in_array( $type, array( ')', ']', '}' ), true ) || in_array( $type, self::BOUNDARIES, true ) || $this->boundary( $i ) ) {
				return $i - 1;
			}
		}
		return $this->count - 1;
	}

	/** Start of the operand chain that ends just before the "." or ".=" at $index, at the same nesting level. */
	private function chain_start( $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$type = $this->tokens[ $i ][0];
			if ( in_array( $type, array( ')', ']', '}' ), true ) ) {
				$depth = 0;
				for ( ; $i >= 0; $i-- ) {
					$inner = $this->tokens[ $i ][0];
					if ( in_array( $inner, array( ')', ']', '}' ), true ) ) {
						++$depth;
					} elseif ( in_array( $inner, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
						--$depth;
						if ( 0 === $depth ) { break; }
					}
				}
				continue;
			}
			if ( '"' === $type || T_END_HEREDOC === $type ) {
				$open = '"' === $type ? '"' : T_START_HEREDOC;
				for ( $i--; $i >= 0 && $this->tokens[ $i ][0] !== $open; $i-- ) {}
				continue;
			}
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) || in_array( $type, self::BOUNDARIES, true ) || $this->boundary( $i ) ) {
				return $i + 1;
			}
		}
		return 0;
	}

	/** Operators and keywords that bind less tightly than ".", besides the single-character ones. */
	private function boundary( $index ) {
		static $types = null;
		if ( null === $types ) {
			$types = array();
			foreach ( array( 'T_DOUBLE_ARROW', 'T_PLUS_EQUAL', 'T_MINUS_EQUAL', 'T_MUL_EQUAL', 'T_DIV_EQUAL', 'T_CONCAT_EQUAL', 'T_MOD_EQUAL', 'T_AND_EQUAL', 'T_OR_EQUAL', 'T_XOR_EQUAL', 'T_SL_EQUAL', 'T_SR_EQUAL', 'T_POW_EQUAL', 'T_COALESCE_EQUAL', 'T_COALESCE', 'T_BOOLEAN_AND', 'T_BOOLEAN_OR', 'T_LOGICAL_AND', 'T_LOGICAL_OR', 'T_LOGICAL_XOR', 'T_IS_EQUAL', 'T_IS_NOT_EQUAL', 'T_IS_IDENTICAL', 'T_IS_NOT_IDENTICAL', 'T_IS_SMALLER_OR_EQUAL', 'T_IS_GREATER_OR_EQUAL', 'T_SPACESHIP', 'T_RETURN', 'T_ECHO', 'T_PRINT', 'T_CASE', 'T_THROW', 'T_YIELD', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML', 'T_AS', 'T_ELSE', 'T_DO', 'T_EXIT', 'T_INCLUDE', 'T_INCLUDE_ONCE', 'T_REQUIRE', 'T_REQUIRE_ONCE' ) as $name ) {
				if ( defined( $name ) ) { $types[] = constant( $name ); }
			}
		}
		return in_array( $this->tokens[ $index ][0], $types, true );
	}

	/** Lower-case name of the function or method called with the "(" at $open, or null. */
	private function callee( $open ) {
		if ( $open < 1 ) { return null; }
		$previous = $this->tokens[ $open - 1 ];
		return T_STRING === $previous[0] || T_ARRAY === $previous[0] || T_ISSET === $previous[0] || T_LIST === $previous[0] ? strtolower( $previous[1] ) : null;
	}

	/** Whether $index lies in the first argument of a preg_*() call, a regular expression rather than SQL. */
	private function in_pattern( $index ) {
		$depth = 0;
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$type = $this->tokens[ $i ][0];
			if ( in_array( $type, array( ')', ']', '}' ), true ) ) {
				++$depth;
			} elseif ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				if ( 0 === $depth ) {
					$callee = '(' === $type ? $this->callee( $i ) : null;
					return null !== $callee && 0 === strpos( $callee, 'preg_' ) && ! $this->is( $i - 2, T_OBJECT_OPERATOR ) && ! $this->is( $i - 2, T_DOUBLE_COLON );
				}
				--$depth;
			} elseif ( ( ',' === $type || ';' === $type ) && 0 === $depth ) {
				return false;
			}
		}
		return false;
	}

	/** The receiver text before the "->" at $arrow, such as $wpdb, $this->wpdb_obj or $GLOBALS['wpdb']. */
	private function receiver( $arrow ) {
		$text = '';
		for ( $i = $arrow - 1; $i >= 0; $i-- ) {
			$type = $this->tokens[ $i ][0];
			if ( ']' === $type || ')' === $type ) {
				$depth = 0;
				for ( ; $i >= 0; $i-- ) {
					$text = $this->tokens[ $i ][1] . $text;
					if ( in_array( $this->tokens[ $i ][0], array( ']', ')' ), true ) ) { ++$depth; }
					if ( in_array( $this->tokens[ $i ][0], array( '[', '(' ), true ) && 0 === --$depth ) { break; }
				}
				continue;
			}
			if ( ! in_array( $type, array( T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) { break; }
			$text = $this->tokens[ $i ][1] . $text;
		}
		return $text;
	}

	/** Whether a receiver is a database connection, judged by its last name: $wpdb, $this->wpdb_obj->dbh, $GLOBALS['wpdb']. */
	private static function database_receiver( $text ) {
		$parts = preg_split( '/->|::|\[/', $text );
		return (bool) preg_match( self::DATABASE_RECEIVER, trim( (string) end( $parts ), '$\'"]' ) );
	}

	/** The class that self::, static:: or parent:: before the "::" at $colons names. */
	private function static_class( $colons ) {
		return $this->static_class_name( $this->tokens[ $colons - 1 ][1] );
	}

	private function static_class_name( $name ) {
		$class = $this->classes ? end( $this->classes ) : null;
		if ( in_array( strtolower( $name ), array( 'self', 'static' ), true ) ) { return $class ? $class['name'] : $name; }
		if ( 'parent' === strtolower( $name ) ) { return $class && $class['parent'] ? $class['parent'] : $name; }
		return $name;
	}

	private function scan() {
		$depth         = 0;
		$pending       = null;
		$pending_class = null;
		for ( $i = 0; $i < $this->count; $i++ ) {
			$type = $this->tokens[ $i ][0];

			// Functions and closures have their own variables; a sink-named function may forward its own SQL parameter.
			if ( T_FUNCTION === $type || ( defined( 'T_FN' ) && T_FN === $type ) ) {
				$named   = $this->is( $i + 1, T_STRING ) || ( $this->is( $i + 1, '&' ) && $this->is( $i + 2, T_STRING ) );
				$pending = array(
					'name'  => $named ? strtolower( $this->tokens[ $this->is( $i + 1, '&' ) ? $i + 2 : $i + 1 ][1] ) : null,
					'param' => null,
					'vars'  => array(),
				);
				for ( $j = $i + 1; $j < $this->count && ! $this->is( $j, ')' ); $j++ ) {
					if ( $this->is( $j, T_VARIABLE ) ) {
						$pending['param'] = $this->tokens[ $j ][1];
						break;
					}
				}
			} elseif ( ';' === $type ) {
				// Signatures never contain ";": this was an abstract or interface method without a body.
				$pending = null;
			}
			if ( in_array( $type, array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) || ( defined( 'T_ENUM' ) && T_ENUM === $type ) ) {
				if ( ! $this->is( $i - 1, T_DOUBLE_COLON ) && $this->is( $i + 1, T_STRING ) ) {
					$pending_class = array(
						'name'   => $this->tokens[ $i + 1 ][1],
						'parent' => $this->is( $i + 2, T_EXTENDS ) && $this->is( $i + 3, T_STRING ) ? $this->tokens[ $i + 3 ][1] : null,
					);
				}
			}

			if ( '{' === $type || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				++$depth;
				if ( '{' === $type && null !== $pending ) {
					$this->scopes[] = $pending + array( 'depth' => $depth );
					$pending        = null;
				} elseif ( '{' === $type && null !== $pending_class ) {
					$this->classes[] = $pending_class + array( 'depth' => $depth );
					$pending_class   = null;
				}
			} elseif ( '}' === $type ) {
				if ( count( $this->scopes ) > 1 && $this->scope()['depth'] === $depth ) { array_pop( $this->scopes ); }
				if ( $this->classes && end( $this->classes )['depth'] === $depth ) { array_pop( $this->classes ); }
				--$depth;
			}

			$this->fragment_reference( $i );
			$this->callable_reference( $i );
			if ( T_STRING === $type && $this->is( $i + 1, '(' ) ) { $this->call( $i ); }
			if ( ( T_OBJECT_OPERATOR === $type || T_DOUBLE_COLON === $type ) && $this->dynamic_call( $i ) ) {
				$this->fail( $i, 'calls a method by a computed name; the SQL check cannot see what it runs.' );
			}
			if ( '=' === $type || T_CONCAT_EQUAL === $type ) { $this->assignment( $i ); }
			if ( '.' === $type ) { $this->concatenation( $i ); }
			if ( '"' === $type || T_START_HEREDOC === $type ) { $this->interpolation( $i ); }
		}
	}

	/** BackupPlus_SQL_Fragment may be named only by instanceof and type declarations. */
	private function fragment_reference( $i ) {
		$token = $this->tokens[ $i ];
		if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && false !== stripos( $token[1], 'BackupPlus_SQL_Fragment' ) ) {
			$this->fail( $i, 'names BackupPlus_SQL_Fragment in a string; fragments come only from BackupPlus_SQL::fragment().' );
			return;
		}
		if ( T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'BackupPlus_SQL_Fragment' ) ) { return; }
		if ( $this->is( $i - 1, T_INSTANCEOF ) ) { return; }
		$next = $i + 1 < $this->count ? $this->tokens[ $i + 1 ][0] : null;
		if ( in_array( $next, array( T_VARIABLE, T_ELLIPSIS, '&', '|' ), true ) ) { return; }
		if ( $this->is( $i - 1, '|' ) || ( $this->is( $i - 1, ':' ) && $this->is( $i - 2, ')' ) ) || ( $this->is( $i - 1, '?' ) && ( $this->is( $i - 2, ':' ) || $this->is( $i - 2, '(' ) || $this->is( $i - 2, ',' ) ) ) ) { return; }
		$this->fail( $i, 'refers to BackupPlus_SQL_Fragment; fragments come only from BackupPlus_SQL::fragment().' );
	}

	/**
	 * SQL functions and methods named as callables: 'mysqli_query', 'wpdb::query', array( $wpdb, $method ), and an SQL
	 * method of an object or SQL class, array( $db, 'query' ) or array( 'BackupPlus_SQL', 'fragment' ).
	 */
	private function callable_reference( $i ) {
		$token = $this->tokens[ $i ];
		if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$text = strtolower( ltrim( substr( $token[1], 1, -1 ), '\\' ) );
			$test = $this->is( $i - 1, '(' ) && $this->is( $i + 1, ')' ) && 'function_exists' === $this->callee( $i - 1 );
			if ( ! $test && ( isset( self::FUNCTION_SINKS[ $text ] ) || preg_match( '/\A(?:backupplus_sql|wpdb|mysqli|pdo)::/', $text ) ) ) {
				$this->fail( $i, 'names an SQL function as a callable; call it with a constant template instead.' );
			}
			return;
		}
		$array = T_ARRAY === $token[0] && $this->is( $i + 1, '(' );
		$short = '[' === $token[0] && ! ( $i > 0 && in_array( $this->tokens[ $i - 1 ][0], array( T_VARIABLE, ']', ')', '}', T_STRING, T_CONSTANT_ENCAPSED_STRING ), true ) );
		if ( ! $array && ! $short ) { return; }
		$open     = $array ? $i + 1 : $i;
		$close    = $this->closing( $open );
		$elements = array();
		$from     = $open + 1;
		for ( $j = $open + 1; $j <= $close; $j++ ) {
			$type = $this->tokens[ $j ][0];
			if ( T_DOUBLE_ARROW === $type ) { return; }
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$j = $this->closing( $j );
				continue;
			}
			if ( ',' === $type || $j === $close ) {
				if ( $from <= $j - 1 ) { $elements[] = array( $from, $j - 1 ); }
				$from = $j + 1;
			}
		}
		if ( 2 !== count( $elements ) ) { return; }
		list( $first, $last ) = $elements[0];
		$object               = '';
		for ( $j = $first; $j <= $last; $j++ ) { $object .= $this->tokens[ $j ][1]; }
		// The object: a variable or property chain, or a class named by a string, Name::class or __CLASS__.
		if ( $this->plain( $first, $last ) ) {
			$database = self::database_receiver( $object );
			$other    = '$this' !== $object;
		} elseif ( $first === $last && $this->is( $first, T_CONSTANT_ENCAPSED_STRING ) ) {
			$database = (bool) preg_match( '/\A(?:BackupPlus_SQL|wpdb|mysqli|PDO)\z/i', trim( $object, '\'"\\' ) );
			$other    = $database;
		} elseif ( $first === $last && $this->is( $first, T_CLASS_C ) ) {
			$database = (bool) preg_match( self::DATABASE_CLASS, $this->static_class_name( 'self' ) );
			$other    = $database;
		} elseif ( $last === $first + 2 && $this->is( $first, T_STRING ) && $this->is( $first + 1, T_DOUBLE_COLON ) && $this->is( $last, T_CLASS ) ) {
			$database = (bool) preg_match( self::DATABASE_CLASS, $this->static_class_name( $this->tokens[ $first ][1] ) );
			$other    = $database;
		} else {
			return;
		}
		list( $first, $last ) = $elements[1];
		$method               = $first === $last && $this->is( $first, T_CONSTANT_ENCAPSED_STRING ) ? strtolower( substr( $this->tokens[ $first ][1], 1, -1 ) ) : null;
		$sink                 = null !== $method && ( isset( self::METHOD_SINKS[ $method ] ) || isset( self::EXECUTORS[ $method ] ) || 'prepare' === $method || ( $database && in_array( $method, self::WPDB_WRITERS, true ) ) );
		if ( ( $database && ( null === $method || $sink ) ) || ( $other && $sink ) ) {
			$this->fail( $i, 'passes an SQL method around as a callable; call it with a constant template instead.' );
		}
	}

	/** "->$name(", "->{...}(" and "::$name(". */
	private function dynamic_call( $i ) {
		if ( $this->is( $i + 1, T_VARIABLE ) ) { return $this->is( $i + 2, '(' ); }
		if ( $this->is( $i + 1, '{' ) ) { return $this->is( $this->closing( $i + 1 ) + 1, '(' ); }
		return false;
	}

	/** A call to the name at $i. */
	private function call( $i ) {
		$name     = strtolower( $this->tokens[ $i ][1] );
		$previous = $i > 0 ? $this->tokens[ $i - 1 ][0] : null;
		if ( T_FUNCTION === $previous || T_NEW === $previous || ( defined( 'T_FN' ) && T_FN === $previous ) ) { return; }
		$method = T_OBJECT_OPERATOR === $previous;
		$static = T_DOUBLE_COLON === $previous;
		$open   = $i + 1;

		if ( ! $method && ! $static ) {
			if ( in_array( $name, self::BUILDERS, true ) ) { $this->builder( $i, $open ); }
			if ( isset( self::FUNCTION_SINKS[ $name ] ) ) { $this->sink( $i, $open, self::FUNCTION_SINKS[ $name ], false ); }
			// The init command is a statement the server runs on connecting
			if ( 'mysqli_options' === $name && $this->init_command( $open, 2 ) ) { $this->sink( $i, $open, 3, false ); }
			return;
		}
		if ( $method && 'options' === $name && $this->init_command( $open, 1 ) ) { $this->sink( $i, $open, 2, false ); }
		if ( $static ) {
			$class = $this->static_class( $i - 1 );
			if ( ! preg_match( self::DATABASE_CLASS, $class ) ) { return; }
		}
		if ( 'prepare' === $name ) {
			$this->fail( $i, 'uses ->prepare(); bind values with BackupPlus_SQL instead.' );
			return;
		}
		$database = $method && self::database_receiver( $this->receiver( $i - 1 ) );
		if ( $database && in_array( $name, self::WPDB_WRITERS, true ) ) {
			$this->fail( $i, 'uses $wpdb->' . $name . '(), which writes the table name into the statement; use BackupPlus_SQL instead.' );
			return;
		}
		if ( in_array( $name, self::RECEIVER_SINKS, true ) && ! $database ) { return; }
		if ( isset( self::METHOD_SINKS[ $name ] ) ) { $this->sink( $i, $open, self::METHOD_SINKS[ $name ], false ); }
		if ( $method && isset( self::EXECUTORS[ $name ] ) ) { $this->sink( $i, $open, self::EXECUTORS[ $name ], true ); }
	}

	/** Whether the n-th argument of the call opening at $open is MYSQLI_INIT_COMMAND. */
	private function init_command( $open, $position ) {
		$argument = $this->argument( $open, $position );
		return null !== $argument && $argument[0] === $argument[1] && $this->is( $argument[0], T_STRING ) && 'MYSQLI_INIT_COMMAND' === ltrim( $this->tokens[ $argument[0] ][1], '\\' );
	}

	/** Tokens of the n-th argument of the call opening at $open, as array( start, end ), or null. */
	private function argument( $open, $position ) {
		$close = $this->closing( $open );
		$index = 1;
		$from  = $open + 1;
		for ( $j = $open + 1; $j <= $close; $j++ ) {
			$type = $this->tokens[ $j ][0];
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$j = $this->closing( $j );
				continue;
			}
			if ( '"' === $type || T_START_HEREDOC === $type ) {
				$j = $this->string_end( $j );
				continue;
			}
			if ( ',' === $type || $j === $close ) {
				if ( $index === $position ) { return $from <= $j - 1 ? array( $from, $j - 1 ) : null; }
				++$index;
				$from = $j + 1;
			}
		}
		return null;
	}

	/** One constant string, or a choice between two: condition ? 'constant' : 'constant'. */
	private function constant( $start, $end ) {
		if ( $start === $end ) { return $this->is( $start, T_CONSTANT_ENCAPSED_STRING ); }
		return $end - $start >= 4 && $this->is( $end, T_CONSTANT_ENCAPSED_STRING ) && $this->is( $end - 1, ':' ) && $this->is( $end - 2, T_CONSTANT_ENCAPSED_STRING ) && $this->is( $end - 3, '?' ) && ! $this->contains( $start, $end - 4, '?' );
	}

	private function contains( $start, $end, $type ) {
		for ( $j = $start; $j <= $end; $j++ ) {
			if ( $this->tokens[ $j ][0] === $type ) { return true; }
		}
		return false;
	}

	/** A variable, property or array element: never a concatenation or interpolated string. */
	private function plain( $start, $end ) {
		if ( ! $this->is( $start, T_VARIABLE ) ) { return false; }
		for ( $j = $start; $j <= $end; $j++ ) {
			if ( ! in_array( $this->tokens[ $j ][0], array( T_VARIABLE, T_OBJECT_OPERATOR, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, '[', ']' ), true ) ) { return false; }
		}
		return true;
	}

	private function sink( $i, $open, $position, $executor ) {
		$argument = $this->argument( $open, $position );
		if ( null === $argument ) { return; }
		list( $start, $end ) = $argument;
		if ( $this->constant( $start, $end ) || $this->rendered( $start, $end ) ) { return; }
		$name    = $this->variable( $start, $end );
		$scope   = $this->scope();
		$forward = $scope['name'] && ( isset( self::METHOD_SINKS[ $scope['name'] ] ) || isset( self::FUNCTION_SINKS[ $scope['name'] ] ) || isset( self::EXECUTORS[ $scope['name'] ] ) ) && null !== $name && $scope['param'] === $name;
		if ( $forward && 'built' !== $this->kind( $name ) ) { return; }
		if ( $executor && $this->plain( $start, $end ) ) {
			if ( 'built' !== $this->kind( $name ) ) { return; }
			$this->fail( $i, 'passes SQL assembled from text and values to ' . $this->tokens[ $i ][1] . '().' );
			return;
		}
		$this->fail( $i, 'passes non-constant SQL to ' . $this->tokens[ $i ][1] . '().' );
	}

	/** implode(), sprintf() and the like with SQL text or a variable that holds it. */
	private function builder( $i, $open ) {
		$close = $this->closing( $open );
		$name  = strtolower( $this->tokens[ $i ][1] );
		// sprintf() takes its SQL text from the format; its other arguments are values, such as a statement named in a message,
		// but a variable that holds SQL text is assembled into the result wherever it is passed.
		if ( in_array( $name, array( 'sprintf', 'vsprintf' ), true ) ) {
			$format = $this->argument( $open, 1 );
			if ( null === $format ) { return; }
			for ( $j = $format[1] + 1; $j < $close; $j++ ) {
				if ( T_VARIABLE === $this->tokens[ $j ][0] && null !== $this->kind( $this->variable( $j, $this->is( $j + 1, T_OBJECT_OPERATOR ) ? $j + 2 : $j ) ) ) {
					$this->fail( $i, 'builds SQL with ' . $this->tokens[ $i ][1] . '().' );
					return;
				}
			}
			list( $open, $close ) = array( $format[0] - 1, $format[1] + 1 );
		} elseif ( 0 === strpos( $name, 'preg_' ) ) {
			// Rewriting a dump statement with a constant replacement is not assembly. A replacement that is a value, or a
			// variable that holds SQL text anywhere, puts values into SQL text: preg_replace( '/T/', $t, 'DELETE FROM T' ).
			$replacement = $this->argument( $open, 2 );
			$subject     = $this->argument( $open, 3 );
			if ( null === $replacement || null === $subject ) { return; }
			$value = 'preg_replace' === $name && ! $this->constant( $replacement[0], $replacement[1] );
			foreach ( array( $replacement, $subject ) as $part ) {
				for ( $j = $part[0]; $j <= $part[1]; $j++ ) {
					$token = $this->tokens[ $j ];
					$held  = T_VARIABLE === $token[0] && null !== $this->kind( $this->variable( $j, $this->is( $j + 1, T_OBJECT_OPERATOR ) ? $j + 2 : $j ) );
					$text  = T_CONSTANT_ENCAPSED_STRING === $token[0] && self::sql_like( substr( $token[1], 1, -1 ) );
					if ( $held || ( $text && $value ) ) {
						$this->fail( $i, 'builds SQL with ' . $this->tokens[ $i ][1] . '().' );
						return;
					}
				}
			}
			return;
		}
		for ( $j = $open + 1; $j < $close; $j++ ) {
			$token = $this->tokens[ $j ];
			$text  = T_CONSTANT_ENCAPSED_STRING === $token[0] ? substr( $token[1], 1, -1 ) : ( T_ENCAPSED_AND_WHITESPACE === $token[0] ? $token[1] : null );
			$sql   = null !== $text ? self::sql_like( $text ) : ( T_VARIABLE === $token[0] && null !== $this->kind( $this->variable( $j, $this->is( $j + 1, T_OBJECT_OPERATOR ) ? $j + 2 : $j ) ) );
			if ( $sql ) {
				$this->fail( $i, 'builds SQL with ' . $this->tokens[ $i ][1] . '().' );
				return;
			}
		}
	}

	/** "$var = ..." records whether the variable holds SQL; "$var .= ..." onto SQL text with a value is assembled SQL. */
	private function assignment( $i ) {
		$name = null;
		if ( $this->is( $i - 1, T_VARIABLE ) && ! ( $i > 1 && in_array( $this->tokens[ $i - 2 ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, '$' ), true ) ) ) {
			$name = $this->tokens[ $i - 1 ][1];
		} elseif ( $this->is( $i - 1, ']' ) && null !== ( $base = $this->element_base( $i - 1 ) ) ) {
			// $parts[] = 'DELETE FROM ...': the array holds SQL text, which implode() and the like would assemble
			$end = $this->expression_end( $i + 1 );
			if ( $end < $i + 1 ) { return; }
			list( $text, $value, $sql ) = self::joined( $this->operands( $i + 1, $end ) );
			if ( $sql || self::sql_like( $text ) ) {
				$this->remember( $base, $value || 'built' === $this->kind( $base ) ? 'built' : 'sql' );
			}
			if ( T_CONCAT_EQUAL === $this->tokens[ $i ][0] && ( $sql || self::sql_like( $text ) ) && $value && ! $this->in_pattern( $i ) ) {
				$this->fail( $i, 'appends a value to SQL text with ".=".' );
			}
			return;
		} elseif ( $this->is( $i - 1, T_STRING ) && $this->is( $i - 2, T_OBJECT_OPERATOR ) && $this->is( $i - 3, T_VARIABLE, '$this' ) && ! $this->is( $i - 4, T_OBJECT_OPERATOR ) ) {
			$name = '$this->' . $this->tokens[ $i - 1 ][1];
		}
		$end = $this->expression_end( $i + 1 );
		if ( $end < $i + 1 ) { return; }
		$operands                   = $this->operands( $i + 1, $end );
		list( $text, $value, $sql ) = self::joined( $operands );
		if ( '=' === $this->tokens[ $i ][0] ) {
			if ( null === $name ) { return; }
			$single = $this->variable( $i + 1, $end );
			if ( null !== $single ) {
				$this->remember( $name, $this->kind( $single ) );
			} else {
				$this->remember( $name, $sql || self::sql_like( $text ) ? ( $value ? 'built' : 'sql' ) : null );
			}
			return;
		}
		$before = null !== $name ? $this->kind( $name ) : null;
		if ( ( null !== $before || $sql || self::sql_like( $text ) ) && $value && ! $this->in_pattern( $i ) ) {
			$this->fail( $i, 'appends a value to SQL text with ".=".' );
			if ( null !== $name ) { $this->remember( $name, 'built' ); }
		} elseif ( null !== $name && null === $before && self::sql_like( $text ) ) {
			$this->remember( $name, 'sql' );
		}
	}

	/** The variable whose element ends with the "]" at $close: $parts[...] or $parts[], or null. */
	private function element_base( $close ) {
		$depth = 0;
		for ( $j = $close; $j >= 0; $j-- ) {
			$type = $this->tokens[ $j ][0];
			if ( ']' === $type ) { ++$depth; }
			if ( '[' === $type && 0 === --$depth ) {
				if ( $this->is( $j - 1, ']' ) ) { return $this->element_base( $j - 1 ); }
				return $this->is( $j - 1, T_VARIABLE ) && ! $this->is( $j - 2, T_OBJECT_OPERATOR ) && ! $this->is( $j - 2, T_DOUBLE_COLON ) ? $this->tokens[ $j - 1 ][1] : null;
			}
		}
		return null;
	}

	/** The "." at $i joins its chain; the chain is checked once, from its first ".". */
	private function concatenation( $i ) {
		$start = $this->chain_start( $i );
		for ( $j = $start; $j < $i; $j++ ) {
			$type = $this->tokens[ $j ][0];
			if ( in_array( $type, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$j = $this->closing( $j );
			} elseif ( '"' === $type || T_START_HEREDOC === $type ) {
				$j = $this->string_end( $j );
			} elseif ( '.' === $type ) {
				return;
			}
		}
		$this->check_joined( $start, $this->operands( $start, $this->chain_end( $start ) ) );
	}

	/** An interpolated string or heredoc on its own, or inside a "." chain (which the chain check covers). */
	private function interpolation( $i ) {
		$end = $this->string_end( $i );
		if ( $this->is( $i - 1, '.' ) || $this->is( $end + 1, '.' ) ) { return; }
		$this->check_joined( $i, array( $this->operand( $i, $end ) ) );
	}

	private function check_joined( $start, $operands ) {
		list( $text, $value, $sql ) = self::joined( $operands );
		if ( ! $value ) { return; }
		if ( ! ( self::sql_like( $text ) || ( $sql && ( count( $operands ) > 1 || 'sqlstring' === $operands[0][0] ) ) ) ) { return; }
		if ( $this->in_pattern( $start ) ) { return; }
		// A sink-named wrapper may hand its own SQL parameter to a native client: '--execute=' . $sql.
		$scope = $this->scope();
		if ( 2 === count( $operands ) && 'literal' === $operands[0][0] && preg_match( '/\A--execute=\z/', $operands[0][1] ) && isset( self::METHOD_SINKS[ (string) $scope['name'] ] ) ) {
			$end = $this->chain_end( $start );
			if ( $this->variable( $end, $end ) === $scope['param'] && 'built' !== $this->kind( $scope['param'] ) ) { return; }
		}
		$this->fail( $start, 'builds SQL by concatenation or interpolation.' );
	}
}

$root  = realpath( isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ ) );
$check = new BackupPlus_SQL_Check();
// The one audited place that turns templates into statements.
$exempt_files = array( 'includes/class-sql.php' );
$skip         = '#^(vendor|node_modules|scripts|tests|dev|\.cache|\.git|methods/googledrive/Google|methods/dropbox/Dropbox2|methods/cloudfiles/sdk)/#';
$files        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$paths        = array();
foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( 'php' !== $file->getExtension() || preg_match( $skip, $relative ) || in_array( $relative, $exempt_files, true ) ) { continue; }
	$paths[ $relative ] = $file->getPathname();
}
ksort( $paths );
foreach ( $paths as $relative => $path ) { $check->file( $relative, file_get_contents( $path ) ); }

$failures = $check->failures();
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" . count( $failures ) . " SQL construction violation(s).\n" );
	exit( 1 );
}
echo "SQL construction check passed.\n";
