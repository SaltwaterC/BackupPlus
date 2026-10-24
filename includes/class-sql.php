<?php

/**
 * Every SQL statement BackupPlus writes goes through here.
 *
 * Callers pass one constant template. Values are bound server-side with prepared statements; identifiers and
 * account names, which MySQL cannot bind, are validated and quoted only in this class. scripts/check-sql.php
 * rejects any other way of building executed SQL.
 *
 * Template placeholders, ignored inside quoted literals:
 *   ?   the next value; an array value expands to a comma-separated list, for IN (...)
 *   %i  the next identifier; an array names a qualified identifier such as database.table
 *   %a  the next account as array( user, host )
 *   %d  the next integer, for statements the server cannot prepare (KILL, LIMIT in text-protocol statements)
 *   %f  the next fragment, from BackupPlus_SQL::fragment()
 *   %l  the next non-empty array of fragments, joined with commas (column lists, SET assignments)
 *   %w  the next non-empty array of fragments, each parenthesised and joined with AND (conditions)
 *
 * A fragment is a compiled template carrying its own bound values, for statements whose shape varies in length.
 */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_SQL {
	private $link;
	private $error    = '';
	private $errno    = 0;
	private $affected = 0;

	public function __construct( $link ) {
		if ( ! $link instanceof mysqli ) { throw new RuntimeException( 'BackupPlus requires a mysqli database connection.' ); }
		$this->link = $link;
	}

	/** WordPress's own connection. A database drop-in without a mysqli handle is refused rather than sent escaped SQL. */
	public static function wpdb() {
		global $wpdb;
		return new self( $wpdb->dbh );
	}

	public function link() {
		return $this->link;
	}

	public function last_error() {
		return $this->error;
	}

	public function last_errno() {
		return $this->errno;
	}

	public function insert_id() {
		return (int) mysqli_insert_id( $this->link );
	}

	/** Rows as objects (OBJECT), associative arrays (ARRAY_A) or lists (ARRAY_N), holding strings or null; false on error. */
	public function rows( $template, $parts = array(), $values = array(), $output = OBJECT ) {
		$result = $this->run( $template, $parts, $values );
		if ( ! is_array( $result ) ) { return false === $result ? false : array(); }
		if ( ARRAY_A === $output ) { return $result; }
		return array_map(
			function ( $row ) use ( $output ) {
				return ARRAY_N === $output ? array_values( $row ) : (object) $row;
			},
			$result
		);
	}

	public function row( $template, $parts = array(), $values = array(), $output = OBJECT ) {
		$rows = $this->rows( $template, $parts, $values, $output );
		return $rows ? $rows[0] : ( false === $rows ? false : null );
	}

	public function value( $template, $parts = array(), $values = array() ) {
		$row = $this->row( $template, $parts, $values, ARRAY_N );
		return $row ? $row[0] : ( false === $row ? false : null );
	}

	public function column( $template, $parts = array(), $values = array() ) {
		$rows = $this->rows( $template, $parts, $values, ARRAY_N );
		return false === $rows ? false : array_map(
			function ( $row ) {
				return $row[0];
			},
			$rows
		);
	}

	/** Statements without a result set: affected rows, or false on error. */
	public function execute( $template, $parts = array(), $values = array() ) {
		return false === $this->run( $template, $parts, $values ) ? false : $this->affected;
	}

	/**
	 * Execute one complete statement read from a backup file. Restoring a dump means running its SQL text; this is the
	 * only method that executes SQL not written as a BackupPlus template.
	 */
	public function script( $statement ) {
		$this->error = '';
		$this->errno = 0;
		$result      = mysqli_query( $this->link, $statement );
		if ( false === $result ) {
			$this->error = mysqli_error( $this->link );
			$this->errno = mysqli_errno( $this->link );
			return false;
		}
		if ( $result instanceof mysqli_result ) { mysqli_free_result( $result ); }
		return (int) mysqli_affected_rows( $this->link );
	}

	/** Part of a statement, compiled from its own constant template, for %f, %l and %w. */
	public static function fragment( $template, $parts = array(), $values = array() ) {
		list( $sql, $bound ) = self::compile( $template, $parts, $values );
		return new BackupPlus_SQL_Fragment( $sql, $bound );
	}

	/** Statement text for native clients that cannot take bound values: identifiers, accounts and integers only. */
	public static function render( $template, $parts = array() ) {
		list( $sql, $bound ) = self::compile( $template, $parts, array() );
		if ( $bound ) { throw new LogicException( 'Rendered statements cannot carry bound values.' ); }
		return $sql;
	}

	public static function identifier( $name ) {
		if ( is_array( $name ) ) { return implode( '.', array_map( array( __CLASS__, 'identifier' ), $name ) ); }
		// The server limit is 64 characters; names that are not UTF-8 are counted in bytes.
		$length = is_string( $name ) && preg_match( '//u', $name ) ? preg_match_all( '/./su', $name ) : ( is_string( $name ) ? strlen( $name ) : 0 );
		if ( ! is_string( $name ) || '' === $name || $length > 64 || false !== strpos( $name, "\0" ) ) { throw new InvalidArgumentException( 'Unsupported SQL identifier.' ); }
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function account( $account ) {
		if ( ! is_array( $account ) || 2 !== count( $account ) ) { throw new InvalidArgumentException( 'Unsupported database account.' ); }
		list( $user, $host ) = array_values( $account );
		if ( ! is_string( $user ) || ! preg_match( '/\A[A-Za-z0-9_.$-]{1,80}\z/D', $user ) || ! is_string( $host ) || ! preg_match( '/\A[A-Za-z0-9_.:%\/-]{1,255}\z/D', $host ) ) { throw new InvalidArgumentException( 'Unsupported database account.' ); }
		return "'" . $user . "'@'" . $host . "'";
	}

	private function run( $template, $parts, $values ) {
		$this->error    = '';
		$this->errno    = 0;
		$this->affected = 0;
		try { list( $sql, $bound ) = self::compile( $template, $parts, $values ); }
		catch ( InvalidArgumentException $error ) {
			$this->error = $error->getMessage();
			return false;
		}
		if ( ! $bound ) {
			$result = mysqli_query( $this->link, $sql );
			if ( false === $result ) {
				$this->error = mysqli_error( $this->link );
				$this->errno = mysqli_errno( $this->link );
				return false;
			}
			$this->affected = (int) mysqli_affected_rows( $this->link );
			return $result instanceof mysqli_result ? self::fetch( $result ) : true;
		}
		$statement = mysqli_prepare( $this->link, $sql );
		if ( ! $statement ) {
			$this->error = mysqli_error( $this->link );
			$this->errno = mysqli_errno( $this->link );
			return false;
		}
		try {
			$types = '';
			foreach ( $bound as $index => $value ) {
				if ( is_bool( $value ) ) { $bound[ $index ] = (int) $value; }
				$types .= is_int( $bound[ $index ] ) ? 'i' : ( is_float( $bound[ $index ] ) ? 'd' : 's' );
				if ( ! is_int( $bound[ $index ] ) && ! is_float( $bound[ $index ] ) && null !== $bound[ $index ] ) { $bound[ $index ] = (string) $bound[ $index ]; }
			}
			mysqli_stmt_bind_param( $statement, $types, ...$bound );
			if ( ! mysqli_stmt_execute( $statement ) ) {
				$this->error = mysqli_stmt_error( $statement );
				$this->errno = mysqli_stmt_errno( $statement );
				return false;
			}
			$this->affected = (int) mysqli_stmt_affected_rows( $statement );
			$result         = mysqli_stmt_get_result( $statement );
			return $result ? self::fetch( $result ) : true;
		} finally { mysqli_stmt_close( $statement ); }
	}

	private static function fetch( $result ) {
		$rows = array();
		while ( null !== ( $row = mysqli_fetch_assoc( $result ) ) ) {
			foreach ( $row as $name => $value ) { $row[ $name ] = null === $value ? null : (string) $value; }
			$rows[] = $row;
		}
		mysqli_free_result( $result );
		return $rows;
	}

	/** Returns the statement text and the flat list of values to bind. */
	private static function compile( $template, $parts, $values ) {
		if ( ! is_string( $template ) ) { throw new InvalidArgumentException( 'SQL templates must be strings.' ); }
		$parts  = array_values( $parts );
		$values = array_values( $values );
		$sql    = '';
		$bound  = array();
		$quote  = null;
		$length = strlen( $template );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $template[ $i ];
			if ( null !== $quote ) {
				$sql .= $c;
				if ( '\\' === $c && '`' !== $quote && $i + 1 < $length ) { $sql .= $template[ ++$i ]; }
				elseif ( $c === $quote ) { $quote = null; }
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$quote = $c;
				$sql  .= $c;
			} elseif ( '?' === $c ) {
				if ( ! $values ) { throw new InvalidArgumentException( 'Missing SQL value.' ); }
				$value = array_shift( $values );
				if ( is_array( $value ) ) {
					if ( ! $value ) { throw new InvalidArgumentException( 'Empty SQL value list.' ); }
					$sql  .= implode( ', ', array_fill( 0, count( $value ), '?' ) );
					$bound = array_merge( $bound, array_values( $value ) );
				} else {
					$sql    .= '?';
					$bound[] = $value;
				}
			} elseif ( '%' === $c && $i + 1 < $length && in_array( $template[ $i + 1 ], array( 'i', 'a', 'd', 'f', 'l', 'w' ), true ) ) {
				if ( ! $parts ) { throw new InvalidArgumentException( 'Missing SQL identifier.' ); }
				$part = array_shift( $parts );
				$kind = $template[ ++$i ];
				if ( 'i' === $kind ) { $sql .= self::identifier( $part ); }
				elseif ( 'a' === $kind ) { $sql .= self::account( $part ); }
				elseif ( 'd' === $kind ) {
					// Digits are written as given: unsigned BIGINT values exceed PHP_INT_MAX.
					if ( ! is_int( $part ) && ! ( is_string( $part ) && preg_match( '/\A-?[0-9]{1,20}\z/D', $part ) ) ) { throw new InvalidArgumentException( 'Unsupported SQL integer.' ); }
					$sql .= (string) $part;
				} else {
					$fragments = 'f' === $kind ? array( $part ) : $part;
					if ( ! is_array( $fragments ) || ! $fragments ) { throw new InvalidArgumentException( 'Empty SQL fragment list.' ); }
					$texts = array();
					foreach ( $fragments as $fragment ) {
						if ( ! $fragment instanceof BackupPlus_SQL_Fragment ) { throw new InvalidArgumentException( 'Unsupported SQL fragment.' ); }
						$texts[] = 'w' === $kind ? '(' . $fragment->sql() . ')' : $fragment->sql();
						$bound   = array_merge( $bound, $fragment->values() );
					}
					$sql .= implode( 'w' === $kind ? ' AND ' : ', ', $texts );
				}
			} else {
				$sql .= $c;
			}
		}
		if ( null !== $quote || $parts || $values ) { throw new InvalidArgumentException( 'SQL template and arguments do not match.' ); }
		return array( $sql, $bound );
	}
}

/** Created only by BackupPlus_SQL::fragment(); scripts/check-sql.php rejects constructing one anywhere else. */
final class BackupPlus_SQL_Fragment {
	private $sql;
	private $values;

	public function __construct( $sql, $values ) {
		$this->sql    = $sql;
		$this->values = $values;
	}

	public function sql() {
		return $this->sql;
	}

	public function values() {
		return $this->values;
	}

	/** The statement with its placeholders, for logs; bound values are not included. */
	public function __toString() {
		return $this->sql;
	}
}
