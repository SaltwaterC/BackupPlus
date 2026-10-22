<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access.' );
}

require_once __DIR__ . '/class-archive-io.php';
require_once __DIR__ . '/class-age-crypto.php';

class BackupPlus_Encryption {

	/**
	 * Read age archives and original UpdraftPlus CBC passphrase backups.
	 * Plaintext is published only after the whole archive has been checked.
	 *
	 * @return Boolean|Array Path and basename, or false on failure.
	 */
	public static function decrypt( $fullpath, $key, $to_temporary_file = false, $key_passphrase = '' ) {
		global $backupplus;
		if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
			return false;
		}
		$input     = false;
		$output    = false;
		$temporary = false;
		$success   = false;
		try {
			$input = fopen( $fullpath, 'rb' );
			if ( false === $input ) {
				return false;
			}
			list($temporary, $output) = self::temporary_file( dirname( $fullpath ), 'decrypt' );
			self::decrypt_stream( $input, $output, $key, $key_passphrase );
			if ( ! fflush( $output ) ) {
				return false;
			}
			fclose( $output );
			$output   = false;
			$new_path = preg_replace( '/\.crypt$/i', '', $fullpath, 1 );
			if ( ! $to_temporary_file ) {
				if ( $new_path === $fullpath || ! rename( $temporary, $new_path ) ) {
					return false;
				}
			}
			$success = true;
			return array(
				'fullpath' => $to_temporary_file ? $temporary : $new_path,
				'basename' => basename( $new_path ),
			);
		} catch ( \Exception $e ) {
			// Exception messages and traces can contain sensitive arguments.
			$backupplus->log( 'Database archive decryption failed.', 'error' );
			return false;
		} catch ( \Throwable $e ) {
			$backupplus->log( 'Database archive decryption failed.', 'error' );
			return false;
		} finally {
			if ( is_resource( $input ) ) {
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				fclose( $output );
			}
			if ( ! $success && false !== $temporary ) {
				unlink( $temporary );
			}
		}
	}

	/**
	 * Shared with the standalone decryptor. phpseclib must already be loaded.
	 * Output must remain private until this returns successfully; discard it on
	 * any exception. This method neither closes nor publishes the supplied handles.
	 */
	public static function decrypt_stream( $input, $output, $key, $key_passphrase = '' ) {
		$prefix = BackupPlus_Archive_IO::read_up_to( $input, strlen( BackupPlus_Age_Crypto::MAGIC ) );
		if ( 0 !== fseek( $input, 0 ) ) {
			throw new \RuntimeException( 'Archive seek failed.' );
		}
		if ( 0 === strpos( $prefix, 'age-encryption.org/' ) ) {
			BackupPlus_Age_Crypto::decrypt( $input, $output, $key, $key_passphrase );
		} else {
			self::decrypt_legacy( $input, $output, $key );
		}
	}

	/**
	 * New writes use age public recipients only. Interrupted attempts restart
	 * with fresh randomness. Passphrase formats are supported only for reading.
	 *
	 * @return String|Boolean Encrypted path, or false on failure.
	 */
	public static function encrypt( $fullpath, $key, $format = 'age' ) {
		global $backupplus;
		if ( 'age' !== $format || ! is_array( $key ) ) {
			return false;
		}
		if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
			return false;
		}
		$input     = false;
		$output    = false;
		$temporary = false;
		$success   = false;
		try {
			$input = fopen( $fullpath, 'rb' );
			if ( false === $input ) {
				return false;
			}
			list($temporary, $output) = self::temporary_file( dirname( $fullpath ), 'encrypt' );
			BackupPlus_Age_Crypto::encrypt( $input, $output, $key );
			if ( ! fflush( $output ) ) {
				return false;
			}
			fclose( $output );
			$output = false;
			if ( ! rename( $temporary, $fullpath . '.crypt' ) ) {
				return false;
			}
			$success = true;
			return $fullpath . '.crypt';
		} catch ( \Exception $e ) {
			$backupplus->log( 'Database archive encryption failed.', 'error' );
			return false;
		} catch ( \Throwable $e ) {
			$backupplus->log( 'Database archive encryption failed.', 'error' );
			return false;
		} finally {
			if ( is_resource( $input ) ) {
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				fclose( $output );
			}
			if ( ! $success && false !== $temporary ) {
				unlink( $temporary );
			}
		}
	}

	/** Unique, owner-only staging files, recognized by the temporary file reaper. */
	private static function temporary_file( $directory, $prefix ) {
		$path = $directory . '/' . $prefix . '_' . bin2hex( \phpseclib3\Crypt\Random::string( 16 ) ) . '.tmp';
		$mask = umask( 0077 );
		try {
			$handle = fopen( $path, 'x+b' );
		} finally {
			umask( $mask );
		}
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not create archive staging file.' );
		}
		// Set the mode before writing, including on hosts with inherited ACLs.
		if ( ! chmod( $path, 0600 ) ) {
			fclose( $handle );
			unlink( $path );
			throw new \RuntimeException( 'Could not protect archive staging file.' );
		}
		return array( $path, $handle );
	}

	/**
	 * Read-only compatibility for the historical zero-IV CBC format. These
	 * archives have no authentication. Require a recognizable database/compressed
	 * file signature so a damaged modern header cannot return arbitrary garbage.
	 */
	private static function decrypt_legacy( $input, $output, $key ) {
		$stat = fstat( $input );
		if ( false === $stat || $stat['size'] < 16 || 0 !== $stat['size'] % 16 ) {
			throw new \RuntimeException( 'Invalid legacy archive length.' );
		}
		$cipher = BackupPlus_Crypto::rijndael( $key );
		$cipher->disablePadding();
		$cipher->enableContinuousBuffer();
		if ( defined( 'BACKUPPLUS_DECRYPTION_ENGINE' ) ) {
			$cipher->setPreferredEngine( 'internal' === BACKUPPLUS_DECRYPTION_ENGINE ? 'PHP' : BACKUPPLUS_DECRYPTION_ENGINE );
		}
		$remaining = $stat['size'];
		$first     = true;
		while ( $remaining > 0 ) {
			$data = BackupPlus_Archive_IO::read_up_to( $input, min( 2097152, $remaining ) );
			if ( '' === $data || strlen( $data ) % 16 ) {
				throw new \RuntimeException( 'Truncated legacy archive.' );
			}
			$plain = $cipher->decrypt( $data );
			if ( $first ) {
				$compressed = "\x1f\x8b" === substr( $plain, 0, 2 ) || in_array( substr( $plain, 0, 4 ), array( "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ), true );
				$sql        = preg_match( '/\A(?:#[ \t]+[^\r\n]{12,}\r?\n|-- [^\r\n]{12,}\r?\n|\/\*[! *][^\r\n]{12,})/', $plain );
				if ( ! $compressed && ! $sql ) {
					throw new \RuntimeException( 'Unrecognized legacy database archive.' );
				}
				$first = false;
			}
			$remaining -= strlen( $data );
			if ( 0 === $remaining ) {
				// The old writer padded only partial blocks, never aligned ones.
				$padding = ord( substr( $plain, -1 ) );
				if ( $padding > 0 && $padding < 16 && substr( $plain, -$padding ) === str_repeat( chr( $padding ), $padding ) ) {
					$plain = substr( $plain, 0, -$padding );
				}
			}
			BackupPlus_Archive_IO::write_all( $output, $plain );
		}
	}

	/**
	 * This function spools the decrypted contents of a file to the browser
	 *
	 * @param  String $fullpath   This is the full path to the encrypted file
	 * @param  String $encryption This is the key used to decrypt the file
	 *
	 * @uses header()
	 */
	public static function spool_crypted_file( $fullpath, $encryption, $key_passphrase = '' ) {

		global $backupplus;

		if ( '' == $encryption ) {
			$encryption = BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' );
		}

		if ( '' == $encryption ) {
			http_response_code( 400 );
			header( 'Content-type: text/plain' );
			echo esc_html( __( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The database file is encrypted, but you have no encryption key entered.', 'backupplus' ) );
			$backupplus->log( 'Decryption of database failed: the database file is encrypted, but you have no encryption key entered.', 'error' );
		} else {

			// now decrypt the file and return array
			$decrypted_file = self::decrypt( $fullpath, $encryption, true, $key_passphrase );

			// check to ensure there is a response back
			if ( is_array( $decrypted_file ) ) {
				header( 'Content-type: ' . ( preg_match( '/\.zip$/i', $decrypted_file['basename'] ) ? 'application/zip' : 'application/x-gzip' ) );
				header( 'Content-Disposition: attachment; filename="' . $decrypted_file['basename'] . '";' );
				header( 'Content-Length: ' . filesize( $decrypted_file['fullpath'] ) );
				readfile( $decrypted_file['fullpath'] );

				// need to remove the file as this is no longer needed on the local server
				unlink( $decrypted_file['fullpath'] );
			} else {
				http_response_code( 400 );
				header( 'Content-type: text/plain' );
				echo esc_html( __( 'Decryption failed. Check the key or private key passphrase and ensure the archive is complete.', 'backupplus' ) );

			}
		}
	}

	/**
	 * Indicate whether an indicated backup file is encrypted or not, as indicated by the suffix
	 *
	 * @param String $file - the filename
	 *
	 * @return Boolean
	 */
	public static function is_file_encrypted( $file ) {
		return preg_match( '/\.crypt$/i', $file );
	}
}
