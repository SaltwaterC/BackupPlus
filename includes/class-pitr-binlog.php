<?php

/** Bounded, checksum-checked v4 binary-log inspection. Unknown events fail closed. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_Binlog {

	private static function read( $handle, $length ) {
		$data = '';
		while ( strlen( $data ) < $length && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 1048576, $length - strlen( $data ) ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		if ( strlen( $data ) !== $length ) {
			throw new RuntimeException( 'Truncated binary-log event.' );
		}
		return $data;
	}

	/** Normalize only the mutable format-description flags/checksum in source anchors. */
	public static function prefix_hash( $path, $position ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Source binary log is unavailable.' );
		}
		try {
			if ( $position < 4 || filesize( $path ) < $position || "\xfebin" !== self::read( $handle, 4 ) ) {
				throw new RuntimeException( 'Invalid binary-log anchor position.' );
			}
			$hash = hash_init( 'sha256' );
			hash_update( $hash, "\xfebin" );
			$remaining = $position - 4;
			if ( $remaining ) {
				$header = self::read( $handle, 19 );
				$size   = unpack( 'V', substr( $header, 9, 4 ) )[1];
				if ( 15 !== ord( $header[4] ) || $size < 81 || $size > 65536 || $size > $remaining ) {
					throw new RuntimeException( 'Invalid binary-log format description.' );
				}
				$event     = $header . self::read( $handle, $size - 19 );
				$event[17] = "\x00";
				$event[18] = "\x00";
				$event     = substr( $event, 0, -4 ) . "\x00\x00\x00\x00";
				hash_update( $hash, $event );
				$remaining -= $size;
			}
			while ( $remaining ) {
				$chunk = self::read( $handle, min( 1048576, $remaining ) );
				hash_update( $hash, $chunk );
				$remaining -= strlen( $chunk );
			}
			return hash_final( $hash );
		} finally {
			fclose( $handle );
		}
	}

	private static function sql_tokens( $sql ) {
		// Strip string literals, retain quoted identifiers, reject executable comments.
		$tokens = array();
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; ) {
			$c = $sql[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$quote  = $c;
				$value  = '';
				$closed = false;
				++$i;
				while ( $i < $length ) {
					$c = $sql[ $i++ ];
					if ( '\\' === $c && '`' !== $quote ) {
						++$i;
						continue;
					}
					if ( $c === $quote ) {
						if ( $i < $length && $sql[ $i ] === $quote ) {
							$value .= $quote;
							++$i;
							continue;
						}
						$closed = true;
						break;
					}
					$value .= $c;
				}
				if ( ! $closed ) {
					throw new RuntimeException( 'Unterminated binary-log SQL token.' );
				}
				$tokens[] = '`' === $quote ? $value : '?';
				continue;
			}
			if ( '/' === $c && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end || in_array( substr( $sql, $i + 2, 1 ), array( '!', 'M' ), true ) ) {
					throw new RuntimeException( 'Executable comments in binary-log DDL are unsupported.' );
				}
				$i = $end + 2;
				continue;
			}
			if ( '#' === $c || ( '-' === $c && substr( $sql, $i, 2 ) === '--' ) ) {
				$end = strpos( $sql, "\n", $i );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}
			if ( preg_match( '/\G[A-Za-z0-9_$]+/A', $sql, $m, 0, $i ) ) {
				$tokens[] = $m[0];
				$i       += strlen( $m[0] );
			} else {
				$tokens[] = $c;
				++$i;
			}
		}
		return $tokens;
	}

	/** Query events may change only the declared schema; complex/unsafe SQL is rejected. */
	public static function query( $body, $database ) {
		if ( strlen( $body ) < 14 ) {
			throw new RuntimeException( 'Malformed binary-log query.' );
		}
		$db_length     = ord( $body[8] );
		$status_length = unpack( 'v', substr( $body, 11, 2 ) )[1];
		$offset        = 13 + $status_length;
		if ( strlen( $body ) < $offset + $db_length + 1 ) {
			throw new RuntimeException( 'Malformed binary-log query database.' );
		}
		$db  = substr( $body, $offset, $db_length );
		$sql = trim( substr( $body, $offset + $db_length + 1 ) );
		if ( preg_match( '/\A(?:BEGIN|COMMIT|ROLLBACK)\z/i', $sql ) ) {
			return strtoupper( $sql );
		}
		if ( $database !== $db ) {
			throw new RuntimeException( 'Binary log changes a database outside the baseline scope.' );
		}
		$tokens = self::sql_tokens( $sql );
		$upper  = array_map( 'strtoupper', $tokens );
		if ( count( $upper ) < 3 || ! in_array( $upper[0], array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' ), true ) || ! in_array( $upper[1], array( 'TABLE', 'INDEX', 'UNIQUE' ), true ) ) {
			throw new RuntimeException( 'Unsupported statement event; PITR requires ROW logging and supported table DDL.' );
		}
		foreach ( $tokens as $i => $token ) {
			if ( '.' === $token && ( ! isset( $tokens[ $i - 1 ] ) || $database !== $tokens[ $i - 1 ] ) ) {
				throw new RuntimeException( 'Cross-schema or ambiguous qualified binary-log DDL is unsupported.' );
			}
			if ( in_array( strtoupper( $token ), array( 'SELECT', 'OUTFILE', 'INFILE', 'DATA', 'DIRECTORY', 'ENGINE', 'TABLESPACE', 'CONNECTION', 'SERVER' ), true ) ) {
				if ( 'ENGINE' === strtoupper( $token ) && isset( $upper[ $i + 1 ], $upper[ $i + 2 ] ) && '=' === $upper[ $i + 1 ] && 'INNODB' === $upper[ $i + 2 ] ) {
					continue;
				}
				throw new RuntimeException( 'Unsupported side effect or dependency in binary-log DDL.' );
			}
		}
		return 'DDL';
	}

	/** Return exact committed positions plus the verified successor rotate coordinate. */
	private static function capture_query( $body, $tenant = null ) {
		if ( strlen( $body ) < 14 ) { throw new RuntimeException( 'Malformed binary-log query.' ); }
		$offset = 13 + unpack( 'v', substr( $body, 11, 2 ) )[1];
		if ( strlen( $body ) < $offset + ord( $body[8] ) + 1 ) { throw new RuntimeException( 'Malformed binary-log query database.' ); }
		$db  = substr( $body, $offset, ord( $body[8] ) );
		$sql = trim( substr( $body, $offset + ord( $body[8] ) + 1 ) );
		if ( preg_match( '/\A(?:(?:CREATE|ALTER|DROP|RENAME)\s+USER\b|GRANT\b|REVOKE\b)/i', $sql ) ) { return array(
			'database' => $db,
			'kind'     => 'CONTROL',
			'offset'   => $offset + ord( $body[8] ) + 1,
		); }
		if ( null !== $tenant && $db !== $tenant && ! preg_match( '/\A(?:BEGIN|COMMIT|ROLLBACK)\z/i', $sql ) ) {
			$tokens = self::sql_tokens( $sql );
			foreach ( $tokens as $i => $token ) {
				if ( '.' === $token && isset( $tokens[ $i - 1 ] ) && $tenant === $tokens[ $i - 1 ] ) { throw new RuntimeException( 'A foreign-default statement references the recovery database. Cross-schema DDL requires a coordinated recovery scope.' ); }
			}
			if ( isset( $tokens[0], $tokens[1], $tokens[2] ) && in_array( strtoupper( $tokens[0] ), array( 'CREATE', 'ALTER', 'DROP' ), true ) && in_array( strtoupper( $tokens[1] ), array( 'DATABASE', 'SCHEMA' ), true ) && in_array( $tenant, array_slice( $tokens, 2 ), true ) ) { throw new RuntimeException( 'Database-level DDL is outside the supported application scope.' ); }
			return array(
				'database' => $db,
				'kind'     => 'FOREIGN',
				'offset'   => $offset + ord( $body[8] ) + 1,
			);
		}
		$user_token = "(?:'[A-Za-z0-9_.$-]+'|`[A-Za-z0-9_.$-]+`)";
		$host_token = "(?:'[A-Za-z0-9_.:%/-]+'|`[A-Za-z0-9_.:%/-]+`)";
		$event      = '~\\AALTER\\s+(?:DEFINER\\s*=\\s*' . $user_token . '\\s*@\\s*' . $host_token . '\\s+)?EVENT\\s+(?:`?' . preg_quote( $db, '~' ) . '`?\\.)?`?[A-Za-z0-9_$-]+`?\\s+(?:ENABLE|DISABLE(?:\\s+ON\\s+SLAVE)?)\\s*;?\\z~i';
		return array(
			'database' => $db,
			'kind'     => preg_match( $event, $sql ) ? 'DDL' : self::query( $body, $db ),
			'event'    => (bool) preg_match( $event, $sql ),
			'offset'   => $offset + ord( $body[8] ) + 1,
		);
	}

	/** Strip server account administration only in a private replay copy, preserving source offsets. */
	public static function scoped_replay_copy( $input, $output, $database, $reject_events = false ) {
		BackupPlus_PITR_Process::private_file( $output );
		if ( ! copy( $input, $output ) ) { throw new RuntimeException( 'Could not stage an isolated replay log.' ); }
		$handle = fopen( $output, 'r+b' );
		try {
			if ( "\xfebin" !== self::read( $handle, 4 ) ) { throw new RuntimeException( 'Invalid replay log signature.' ); }
			$position = 4;
			while ( $position < filesize( $output ) ) {
				$header = self::read( $handle, 19 );
				$event  = unpack( 'Vtimestamp/Ctype/Vserver_id/Vsize/Vnext/vflags', $header );
				if ( $event['size'] < 23 || $position + $event['size'] > filesize( $output ) ) { throw new RuntimeException( 'Invalid isolated replay event length.' ); }
				if ( 2 === $event['type'] ) {
					if ( $event['size'] > 1048599 ) { throw new RuntimeException( 'Replay query exceeds the supported metadata limit.' ); }
					$body = self::read( $handle, $event['size'] - 23 );
					$crc  = self::read( $handle, 4 );
					if ( ! hash_equals( strrev( hash( 'crc32b', $header . $body, true ) ), $crc ) ) { throw new RuntimeException( 'Replay query checksum mismatch.' ); }
					$query = self::capture_query( $body, $database );
					if ( $reject_events && ! empty( $query['event'] ) && $database === $query['database'] ) { throw new RuntimeException( 'Direct source recovery of database event changes is not supported yet. No replacement was started; use an isolated historical export.' ); }
					if ( 'CONTROL' === $query['kind'] ) {
						$length = strlen( $body ) - $query['offset'];
						if ( $length < 4 ) { throw new RuntimeException( 'Invalid account-control event.' ); }
						$body = substr( $body, 0, $query['offset'] ) . 'DO 0' . str_repeat( ' ', $length - 4 );
						$data = $body . strrev( hash( 'crc32b', $header . $body, true ) );
						fseek( $handle, $position + 19 );
						if ( fwrite( $handle, $data ) !== strlen( $data ) ) { throw new RuntimeException( 'Could not write the isolated control-event replacement.' ); }
					}
				}
				$position += $event['size'];
				fseek( $handle, $position );
			}
			fflush( $handle );
		} finally { fclose( $handle ); }
		return $output;
	}

	public static function inspect( $path, $start, $database, $engine, $reject_ddl = false, $monitor = null, $shared_scope = false ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Binary-log segment is unavailable.' );
		}
		$position       = 4;
		$format         = false;
		$at_start       = 4 === $start;
		$in_transaction = false;
		$group          = false;
		$rotated        = null;
		$boundaries     = array();
		$maps           = array();
		$scopes         = array();
		try {
			if ( "\xfebin" !== self::read( $handle, 4 ) ) {
				throw new RuntimeException( 'Invalid binary-log signature.' );
			}
			while ( $position < filesize( $path ) ) {
				if ( $monitor ) { call_user_func( $monitor ); }
				$header = self::read( $handle, 19 );
				$h      = unpack( 'Vtimestamp/Ctype/Vserver_id/Vsize/Vnext/vflags', $header );
				if ( $h['size'] < 23 || $position + $h['size'] > filesize( $path ) || ( $h['next'] && $h['next'] !== $position + $h['size'] ) ) {
					throw new RuntimeException( 'Invalid binary-log event length or position.' );
				}
				if ( $rotated ) {
					throw new RuntimeException( 'Unexpected events after binary-log rotation.' );
				}
				$hash = hash_init( 'crc32b' );
				hash_update( $hash, $header );
				$remaining = $h['size'] - 23;
				$body      = '';
				while ( $remaining ) {
					if ( $monitor ) { call_user_func( $monitor ); }
					$chunk = self::read( $handle, min( 1048576, $remaining ) );
					hash_update( $hash, $chunk );
					if ( strlen( $body ) < 1048576 ) {
						$body .= substr( $chunk, 0, 1048576 - strlen( $body ) );
					}
					$remaining -= strlen( $chunk );
				}
				$checksum = self::read( $handle, 4 );
				if ( ! hash_equals( strrev( hash_final( $hash, true ) ), $checksum ) ) {
					throw new RuntimeException( 'Binary-log event checksum mismatch.' );
				}
				$type = $h['type'];
				$end  = $position + $h['size'];
				if ( 2 === $type && $h['size'] - 23 > 1048576 ) {
					throw new RuntimeException( 'Binary-log query exceeds the supported metadata limit.' );
				}
				if ( 15 === $type ) {
					if ( $format || 4 !== $position || strlen( $body ) < 58 || 4 !== unpack( 'v', $body )[1] || 19 !== ord( $body[56] ) || 1 !== ord( substr( $body, -1 ) ) ) {
						throw new RuntimeException( 'Only v4 binary logs with CRC32 are supported.' );
					}
					$format = true;
				} elseif ( ! $format ) {
					throw new RuntimeException( 'Binary-log format description is missing.' );
				}
				if ( $position === $start ) {
					$at_start = true;
				}
				if ( $position >= $start ) {
					if ( ! $at_start ) {
						throw new RuntimeException( 'Replay start is not an event boundary.' );
					}
					if ( in_array( $type, array( 33, 34, 162 ), true ) ) {
						if ( $group || $in_transaction || ( 'mysql' === $engine && 162 === $type ) || ( 'mariadb' === $engine && 162 !== $type ) ) {
							throw new RuntimeException( 'Invalid binary-log transaction group.' );
						}
						$group = true;
						// MariaDB's GTID event itself opens non-standalone transactions; there is no BEGIN query event.
						if ( 162 === $type ) {
							if ( strlen( $body ) < 13 ) {
								throw new RuntimeException( 'Truncated MariaDB GTID event.' );
							} $in_transaction = ! ( ord( $body[12] ) & 1 );
						}
					} elseif ( 2 === $type ) {
						if ( $h['size'] - 23 > 1048576 ) {
							throw new RuntimeException( 'Binary-log query exceeds inspection limit.' );
						}
						$query_database = $database;
						if ( $shared_scope ) {
							$captured_query = self::capture_query( $body, $database );
							$query_database = $captured_query['database'];
						}
						$query = $shared_scope ? $captured_query['kind'] : self::query( $body, $query_database );
						if ( $reject_ddl && 'DDL' === $query && $database === $query_database ) { throw new RuntimeException( 'Schema changed during the online snapshot; the dump cannot be a PITR baseline.' ); }
						if ( 'BEGIN' === $query ) {
							if ( $in_transaction ) {
								throw new RuntimeException( 'Nested binary-log transaction.' );
							} $in_transaction = true;
						} elseif ( in_array( $query, array( 'DDL', 'CONTROL', 'FOREIGN' ), true ) && $in_transaction ) {
							throw new RuntimeException( 'DDL inside a binary-log transaction is unsupported.' );
						} else {
							if ( $shared_scope && isset( $scopes[ $database ] ) && count( $scopes ) > 1 ) { throw new RuntimeException( 'Cross-schema transactions involving this database require a coordinated multi-database recovery scope.' ); }
							$boundaries[]   = array(
								'position'  => $end,
								'timestamp' => $h['timestamp'],
							);
							$in_transaction = false;
							$group          = false;
							$maps           = array();
							$scopes         = array();
						}
					} elseif ( 19 === $type ) {
						if ( ! $in_transaction || strlen( $body ) < 10 || strlen( $body ) < 10 + ord( $body[8] ) || ( ! $shared_scope && substr( $body, 9, ord( $body[8] ) ) !== $database ) ) {
							throw new RuntimeException( 'Row event references a database outside the full snapshot.' );
						}
						$maps[ bin2hex( substr( $body, 0, 6 ) ) ]      = true;
						$scopes[ substr( $body, 9, ord( $body[8] ) ) ] = true;
					} elseif ( in_array( $type, array( 23, 24, 25, 30, 31, 32 ), true ) ) {
						if ( ! $in_transaction || ! isset( $maps[ bin2hex( substr( $body, 0, 6 ) ) ] ) ) {
							throw new RuntimeException( 'Row event has no validated table mapping.' );
						}
					} elseif ( 16 === $type ) {
						if ( ! $in_transaction ) {
							throw new RuntimeException( 'Commit event has no transaction.' );
						}
						if ( $shared_scope && isset( $scopes[ $database ] ) && count( $scopes ) > 1 ) { throw new RuntimeException( 'Cross-schema transactions involving this database require a coordinated multi-database recovery scope.' ); }
						$boundaries[]   = array(
							'position'  => $end,
							'timestamp' => $h['timestamp'],
						);
						$in_transaction = false;
						$group          = false;
						$maps           = array();
						$scopes         = array();
					} elseif ( 4 === $type ) {
						if ( $in_transaction || $group || strlen( $body ) < 9 ) {
							throw new RuntimeException( 'Binary log rotates inside an incomplete transaction.' );
						}
						$parts = unpack( 'Vlow/Vhigh', substr( $body, 0, 8 ) );
						if ( $parts['high'] ) {
							throw new RuntimeException( 'Unsupported rotate position.' );
						}
						$rotated = BackupPlus_PITR_Manifest::coordinate(
							array(
								'file'     => substr( $body, 8 ),
								'position' => $parts['low'],
							)
						);
					} elseif ( ! in_array( $type, 'mysql' === $engine ? array( 3, 15, 35 ) : array( 3, 15, 160, 161, 163 ), true ) ) {
						throw new RuntimeException( 'Unsupported binary-log event type: ' . $type );
					}
				}
				$position = $end;
			}
			if ( ! $at_start || $in_transaction || $group || ! $rotated ) {
				throw new RuntimeException( 'Binary-log segment is not closed and transactionally complete.' );
			}
			return array(
				'end'        => $position,
				'next'       => $rotated,
				'boundaries' => $boundaries,
			);
		} finally {
			fclose( $handle );
		}
	}
}
