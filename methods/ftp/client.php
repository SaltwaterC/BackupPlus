<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming FTP operations require PHP streams and the cURL transport directly.

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/**
 * FTP and explicit FTPS transport using libcurl, without PHP's FTP extension.
 */
class BackupPlus_ftp_wrapper {

	const ASCII  = 1;
	const BINARY = 2;

	private $conn_id;
	private $host;
	private $username;
	private $password;
	private $port;
	private $working_directory = '';
	private $last_error        = '';

	public $timeout          = 60;
	public $passive          = true;
	public $system_type      = '';
	public $ssl              = false;
	public $use_server_certs = false;
	public $disable_verify   = false;
	public $login_type       = 'non-encrypted';

	public function __construct( $host, $username, $password, $port = 21 ) {
		$this->host     = $host;
		$this->username = $username;
		$this->password = $password;
		$this->port     = $port;
	}

	/**
	 * List missing cURL capabilities, also used by the provider's settings UI.
	 *
	 * @param Boolean $ssl - whether TLS support is required
	 * @return Array
	 */
	public static function get_support_errors( $ssl = false ) {
		$missing = array();
		foreach ( array( 'curl_init', 'curl_reset', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_error', 'curl_errno', 'curl_version' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function;
			}
		}
		if ( $missing ) {
			return $missing;
		}
		$version = curl_version();
		if ( ! in_array( 'ftp', $version['protocols'], true ) ) {
			$missing[] = 'libcurl FTP';
		}
		if ( $ssl && ( ! ( CURL_VERSION_SSL & $version['features'] ) || ! in_array( 'ftps', $version['protocols'], true ) ) ) {
			$missing[] = 'libcurl FTPS (SSL/TLS)';
		}
		return $missing;
	}

	public function get_error() {
		return $this->last_error;
	}

	public function connect() {
		$missing = self::get_support_errors( $this->ssl );
		if ( $missing ) {
			$this->last_error = implode( ', ', $missing );
			return false;
		}
		$this->conn_id           = curl_init();
		$this->working_directory = '';
		$system_type             = '';
		$options                 = array(
			CURLOPT_NOBODY         => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_QUOTE          => array( '*SYST' ),
			CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$system_type ) {
				if ( preg_match( '/^215[ -](\S+)/', $line, $matches ) ) {
					$system_type = $matches[1];
				}
				return strlen( $line );
			},
		);
		if ( false === $this->request( '', $options, true ) ) {
			return false;
		}
		$this->system_type       = $system_type;
		$this->working_directory = curl_getinfo( $this->conn_id, CURLINFO_FTP_ENTRY_PATH );
		if ( ! $this->working_directory ) {
			$this->working_directory = '/';
		}
		$this->login_type = $this->ssl ? 'encrypted (explicit TLS)' : 'non-encrypted';
		return true;
	}

	/**
	 * Resolve paths against the login directory, preserving FTP's absolute paths.
	 * Reject control characters before constructing raw FTP commands.
	 */
	private function resolve_path( $path ) {
		if ( preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			$this->last_error = __( 'FTP paths must not contain control characters.', 'backupplus' );
			return false;
		}
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
			$path = rtrim( $this->working_directory, '/' ) . '/' . $path;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part ) {
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		return '/' . implode( '/', $parts );
	}

	/**
	 * Reset request options while retaining libcurl's connection cache.
	 * Credentials and paths are passed separately so URL metacharacters stay literal.
	 *
	 * @return String|Boolean - response body (possibly empty), or false on failure
	 */
	private function request( $path, $options = array(), $directory = false ) {
		$this->last_error = '';
		if ( ! $this->conn_id ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x20\x7f\/\\\\?#@%]/', $this->host ) || $this->port < 1 || $this->port > 65535 ) {
			$this->last_error = __( 'Invalid FTP server or port.', 'backupplus' );
			return false;
		}
		$host = $this->host;
		if ( false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ) {
			$host = '[' . $host . ']';
		}
		$url = 'ftp://' . $host . ':' . $this->port . '/';
		if ( '' !== $path || '' !== $this->working_directory ) {
			$path = $this->resolve_path( $path );
			if ( false === $path ) {
				return false;
			}
			// A second slash tells libcurl to use an absolute FTP path.
			$url .= implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
			if ( $directory && '/' !== substr( $url, -1 ) ) {
				$url .= '/';
			}
		}
		curl_reset( $this->conn_id );
		$defaults = array(
			CURLOPT_URL                  => $url,
			CURLOPT_USERNAME             => $this->username,
			CURLOPT_PASSWORD             => $this->password,
			CURLOPT_RETURNTRANSFER       => true,
			CURLOPT_CONNECTTIMEOUT       => 20,
			CURLOPT_FTP_RESPONSE_TIMEOUT => $this->timeout,
			CURLOPT_LOW_SPEED_LIMIT      => 1,
			CURLOPT_LOW_SPEED_TIME       => $this->timeout,
			CURLOPT_FTP_FILEMETHOD       => CURLFTPMETHOD_SINGLECWD,
			CURLOPT_USE_SSL              => $this->ssl ? CURLUSESSL_ALL : CURLUSESSL_NONE,
			CURLOPT_SSL_VERIFYPEER       => ! $this->disable_verify,
			CURLOPT_SSL_VERIFYHOST       => $this->disable_verify ? 0 : 2,
			// Preserve the native transport's direct connections, independent of proxy environment variables.
			CURLOPT_PROXY                => '',
		);
		if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
			$defaults[ CURLOPT_PROTOCOLS_STR ] = 'ftp';
		} else {
			$defaults[ CURLOPT_PROTOCOLS ] = CURLPROTO_FTP;
		}
		if ( ! $this->passive ) {
			$defaults[ CURLOPT_FTPPORT ] = '-';
		}
		if ( $this->ssl ) {
			$defaults[ CURLOPT_FTPSSLAUTH ] = CURLFTPAUTH_TLS;
			$ca_file                        = $this->use_server_certs ? ini_get( 'curl.cainfo' ) : BACKUPPLUS_DIR . '/includes/cacert.pem';
			if ( $ca_file ) {
				$defaults[ CURLOPT_CAINFO ] = $ca_file;
			}
		}
		if ( ! curl_setopt_array( $this->conn_id, array_replace( $defaults, $options ) ) ) {
			$this->last_error = __( 'Unable to configure the FTP cURL request.', 'backupplus' );
			return false;
		}
		$result = curl_exec( $this->conn_id );
		if ( false === $result ) {
			$this->last_error = curl_error( $this->conn_id ) . ' (' . curl_errno( $this->conn_id ) . ')';
		}
		return $result;
	}

	public function put( $local_file_path, $remote_file_path, $mode = self::BINARY, $resume = false, $backupplus = false ) {
		clearstatcache( true, $local_file_path );
		$file_size = filesize( $local_file_path );
		if ( false === $file_size ) {
			return false;
		}
		$existing_size = $resume ? max( 0, $this->size( $remote_file_path ) ) : 0;
		if ( $existing_size > 0 && $existing_size >= $file_size ) {
			return true;
		}
		$fh = fopen( $local_file_path, 'rb' );
		if ( false === $fh ) {
			return false;
		}
		// PHP owns the input stream; libcurl cannot seek this stream itself.
		if ( $existing_size && 0 !== fseek( $fh, $existing_size ) ) {
			fclose( $fh );
			return false;
		}
		$options = array(
			CURLOPT_UPLOAD           => true,
			CURLOPT_INFILE           => $fh,
			CURLOPT_INFILESIZE_LARGE => $file_size - $existing_size,
			CURLOPT_APPEND           => $existing_size > 0,
			CURLOPT_TRANSFERTEXT     => self::ASCII === $mode,
		);
		if ( is_a( $backupplus, 'BackupPlus' ) ) {
			$last_recorded                 = $existing_size;
			$options[ CURLOPT_NOPROGRESS ] = false;
			$progress_option               = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
			$options[ $progress_option ]   = static function ( $handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $backupplus, $local_file_path, $existing_size, $file_size, &$last_recorded ) {
				$position     = min( $file_size, $existing_size + $uploaded );
				$record_after = $last_recorded > 4194304 ? 2097152 : ( $last_recorded > 2097152 ? 1048576 : 524288 );
				if ( $position - $last_recorded > $record_after ) {
					$last_recorded = $position;
					$backupplus->record_uploaded_chunk( round( 100 * $position / max( 1, $file_size ), 1 ), '', $local_file_path );
				}
				return 0;
			};
		}
		try {
			$result = $this->request( $remote_file_path, $options );
		} finally {
			fclose( $fh );
		}
		if ( false === $result && is_a( $backupplus, 'BackupPlus' ) ) {
			$backupplus->log( 'FTP upload: ' . $this->get_error() );
		}
		return false !== $result;
	}

	public function get( $local_file_path, $remote_file_path, $mode = self::BINARY, $resume = false, $backupplus = false ) {
		clearstatcache( true, $local_file_path );
		$offset = $resume && file_exists( $local_file_path ) ? filesize( $local_file_path ) : 0;
		if ( $offset ) {
			$remote_size = $this->size( $remote_file_path );
			if ( $remote_size === $offset ) {
				return true;
			}
			if ( $remote_size >= 0 && $offset > $remote_size ) {
				$this->last_error = __( 'The local FTP download is larger than the remote file.', 'backupplus' );
				return false;
			}
		}
		$fh = fopen( $local_file_path, $offset ? 'ab' : 'wb' );
		if ( false === $fh ) {
			return false;
		}
		$options = array(
			CURLOPT_FILE         => $fh,
			CURLOPT_TRANSFERTEXT => self::ASCII === $mode,
		);
		// A string range also preserves large offsets on platforms with 32-bit C longs.
		if ( $offset ) {
			$options[ CURLOPT_RANGE ] = $offset . '-';
		}
		if ( is_a( $backupplus, 'BackupPlus' ) ) {
			$last_recorded                 = $offset;
			$options[ CURLOPT_NOPROGRESS ] = false;
			$progress_option               = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
			$options[ $progress_option ]   = static function ( $handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $backupplus, $offset, &$last_recorded ) {
				$position = $offset + $downloaded;
				if ( $position - $last_recorded > 524288 ) {
					$last_recorded = $position;
					$backupplus->log( 'FTP fetch: file size is now: ' . sprintf( '%0.2f', $position / 1048576 ) . ' Mb' );
				}
				return 0;
			};
		}
		try {
			$result = $this->request( $remote_file_path, $options );
		} finally {
			fclose( $fh );
		}
		if ( is_a( $backupplus, 'BackupPlus' ) ) {
			$backupplus->log( false === $result ? 'FTP fetch: ' . $this->get_error() : 'FTP fetch: fetch complete' );
		}
		return false !== $result;
	}

	public function dir_list( $directory ) {
		$result = $this->request( $directory, array( CURLOPT_DIRLISTONLY => true ), true );
		if ( false === $result ) {
			return false;
		}
		$result = rtrim( $result, "\r\n" );
		return '' === $result ? array() : preg_split( '/\r?\n/', $result );
	}

	public function size( $file ) {
		if ( false === $this->request( $file, array( CURLOPT_NOBODY => true ) ) ) {
			return -1;
		}
		$info = defined( 'CURLINFO_CONTENT_LENGTH_DOWNLOAD_T' ) ? CURLINFO_CONTENT_LENGTH_DOWNLOAD_T : CURLINFO_CONTENT_LENGTH_DOWNLOAD;
		return (int) curl_getinfo( $this->conn_id, $info );
	}

	/** Run commands without a data transfer. All path arguments are absolute. */
	private function commands( $commands ) {
		return false !== $this->request(
			'',
			array(
				CURLOPT_QUOTE  => $commands,
				CURLOPT_NOBODY => true,
			),
			true
		);
	}

	public function delete( $remote_file_path ) {
		$path = $this->resolve_path( $remote_file_path );
		return false !== $path && $this->commands( array( 'DELE ' . $path ) );
	}

	public function make_dir( $directory ) {
		$path = $this->resolve_path( $directory );
		return false !== $path && $this->commands( array( 'MKD ' . $path ) );
	}

	public function remove_dir( $directory ) {
		$path = $this->resolve_path( $directory );
		return false !== $path && $this->commands( array( 'RMD ' . $path ) );
	}

	public function rename( $old_name, $new_name ) {
		$old_path = $this->resolve_path( $old_name );
		$new_path = $this->resolve_path( $new_name );
		return false !== $old_path && false !== $new_path && $this->commands( array( 'RNFR ' . $old_path, 'RNTO ' . $new_path ) );
	}

	public function chmod( $permissions, $remote_filename ) {
		if ( decoct( octdec( $permissions ) ) != $permissions ) {
			throw new Exception( '$permissions must be an octal number' );
		}
		$path = $this->resolve_path( $remote_filename );
		return false !== $path && $this->commands( array( 'SITE CHMOD ' . decoct( $permissions ) . ' ' . $path ) );
	}

	public function chdir( $directory ) {
		$path = $this->resolve_path( $directory );
		if ( false === $path || false === $this->request( $path, array( CURLOPT_NOBODY => true ), true ) ) {
			return false;
		}
		$this->working_directory = $path;
		return true;
	}

	public function cdup() {
		return $this->chdir( '..' );
	}

	public function current_dir() {
		return $this->working_directory;
	}

	public function __destruct() {
		// PHP 8+ releases CurlHandle objects automatically; older versions use resources.
		if ( is_resource( $this->conn_id ) ) {
			curl_close( $this->conn_id );
		}
	}
}
