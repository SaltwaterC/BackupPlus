<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** One policy for provider secrets in options, snapshots and settings transfers. */
class BackupPlus_Credential_Storage {
	/**
	 * Shown in a form instead of a saved secret, which never reaches the browser (BPS-20). Submitting it back
	 * keeps the saved value; the placeholder itself is never saved.
	 */
	const PLACEHOLDER = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";

	private static $options;

	/** Providers can extend this list without implementing cryptography or key management. */
	public static function secret_fields( $option ) {
		return apply_filters(
			'backupplus_credential_secret_fields',
			array_merge(
				// An ntfy topic is the only access control of a public server, so it is as sensitive as the token.
				'backupplus_ntfy' === $option ? array( 'topic' ) : array(),
				array(
					'pass',
					'password',
					'key',
					'key_passphrase',
					'private_key',
					'privatekey',
					'private_key_passphrase',
					'secret',
					'secretkey',
					'secret_key',
					'client_secret',
					'clientsecret',
					'apikey',
					'api_key',
					'accountkey',
					'account_key',
					'applicationkey',
					'token',
					'access_token',
					'refresh_token',
					'tmp_access_token',
					'sessiontoken',
					'session_token',
					'application_key',
					'application_key_id',
					'credentials',
					'service_account',
					'service_account_key',
					'url',
				)
			),
			$option
		);
	}

	public static function option_names() {
		return array_merge(
			array_map(
				function ( $id ) {
					return 'backupplus_' . $id;
				},
				array_keys( BackupPlus_Storage_Provider_Factory::get_methods() )
			),
			array( 'backupplus_encryptionphrase', 'backupplus_ntfy', 'backupplus_extradbs', 'backupplus_pitr_capture', 'backupplus_pitr_recovery', 'backupplus_ftp_pass', 'backupplus_googledrive_secret', 'backupplus_googledrive_token' )
		);
	}

	public static function register() {
		self::$options = array_fill_keys( self::option_names(), true );
		foreach ( array_keys( self::$options ) as $option ) {
			// WordPress may sanitize twice on first save. Decode before provider
			// sanitizers, then encrypt their final result before it can reach the DB.
			add_filter( 'sanitize_option_' . $option, array( __CLASS__, 'incoming' ), -1000, 2 );
			add_filter( 'sanitize_option_' . $option, array( __CLASS__, 'saving' ), PHP_INT_MAX, 2 );
		}
	}

	public static function handles( $option ) {
		if ( null === self::$options ) {
			self::$options = array_fill_keys( self::option_names(), true );
		}
		return isset( self::$options[ $option ] );
	}

	private static function context( $option, $path ) {
		return 'option:' . get_current_blog_id() . ':' . $option . ':' . serialize( $path );
	}

	private static function cipher( $context, $config = null ) {
		return null === $config ? BackupPlus_Credential_Encrypter::from_config( $context ) : new BackupPlus_Credential_Encrypter( $config['keys'], $config['active'], $context );
	}

	private static function envelope( $value ) {
		return is_string( $value ) && 0 === strpos( $value, 'bpcred:' );
	}

	/** Transform whole secret values, including structured OAuth responses, preserving their types. */
	private static function secret( $value, $mode, $context, $config = null ) {
		if ( '' === $value || null === $value || false === $value || array() === $value ) {
			return $value;
		}
		if ( 'redact' === $mode ) {
			return '';
		}
		if ( 'mask' === $mode ) {
			return self::PLACEHOLDER;
		}
		$encrypted = self::envelope( $value );
		if ( $encrypted ) {
			if ( 'retire' === $mode ) {
				// Key retirement re-encrypts only what an older key wrote, so unchanged envelopes keep the incremental fingerprint.
				$current = null === $config ? BackupPlus_Credential_Key_Store::configuration() : $config;
				if ( 0 === strpos( $value, BackupPlus_Credential_Encrypter::PREFIX . $current['active'] . ':' ) ) {
					return $value;
				}
			}
			if ( 'verify' === $mode ) {
				$current = null === $config ? BackupPlus_Credential_Key_Store::configuration() : $config;
				if ( 0 !== strpos( $value, BackupPlus_Credential_Encrypter::PREFIX . $current['active'] . ':' ) ) {
					throw new RuntimeException( 'Credentials were not saved with the active encryption key. Please retry.' );
				}
			}
			$plain = self::cipher( $context, $config )->decrypt( $value );
			if ( false === $plain ) {
				throw new RuntimeException( 'Stored credentials could not be authenticated. Restore the recovery key or reconnect this storage destination.' );
			}
			$decoded = BackupPlus::unserialize( $plain, array( 'stdClass' ) );
			if ( serialize( $decoded ) !== $plain ) {
				throw new RuntimeException( 'The stored credential format is invalid.' );
			}
			if ( 'encrypt' !== $mode && 'rotate' !== $mode && 'retire' !== $mode ) {
				return $decoded;
			}
			if ( 'encrypt' === $mode ) {
				return $value;
			}
			$value = $decoded;
		} elseif ( 'decrypt' === $mode || 'verify' === $mode || 'rotate' === $mode || 'retire' === $mode ) {
			throw new RuntimeException( 'Stored plaintext credentials require the authorized credential upgrade in Settings.' );
		} elseif ( 'incoming' === $mode ) {
			return $value;
		}
		return self::cipher( $context, $config )->encrypt( serialize( $value ) );
	}

	private static function walk( $value, $option, $mode, $path = array(), $config = null, $previous = null ) {
		if ( in_array( $option, array( 'backupplus_encryptionphrase', 'backupplus_ftp_pass', 'backupplus_googledrive_secret', 'backupplus_googledrive_token' ), true ) ) {
			return self::keep( $value, $mode, self::context( $option, $path ), $config, $previous );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$fields = self::secret_fields( $option );
		// A saved secret goes back only to the server it was saved for: with an endpoint changed, the placeholder means no value
		$bound = 'unmask' === $mode && self::endpoint_changed( $value, $previous, $option );
		foreach ( $value as $name => $item ) {
			$child = array_merge( $path, array( $name ) );
			if ( in_array( $name, $fields, true ) ) {
				$value[ $name ] = self::keep( $item, $mode, self::context( $option, $child ), $config, ! $bound && is_array( $previous ) && isset( $previous[ $name ] ) ? $previous[ $name ] : null );
			} else {
				$value[ $name ] = self::walk( $item, $option, $mode, $child, $config, is_array( $previous ) && isset( $previous[ $name ] ) ? $previous[ $name ] : null );
			}
		}
		return $value;
	}

	/**
	 * The fields that decide where a secret is sent, or how it is protected on the way. Script in the admin page could otherwise
	 * submit the placeholder with its own server and receive the saved secret in a connection test or a later backup (BPS-20).
	 *
	 * @param String $option The option name
	 *
	 * @return Array
	 */
	public static function endpoint_fields( $option ) {
		return apply_filters(
			'backupplus_credential_endpoint_fields',
			array( 'host', 'port', 'server', 'endpoint', 'authurl', 'account', 'protocol', 'host_key', 'fingerprint', 'encryption', 'scheme', 'disableverify', 'useservercerts' ),
			$option
		);
	}

	private static function endpoint_changed( $value, $previous, $option ) {
		if ( ! is_array( $value ) || ! is_array( $previous ) ) {
			return false;
		}
		$normal = function ( $item ) {
			// An unticked box is not submitted, and an untouched one may be saved as 0
			return ( null === $item || false === $item || '' === $item || '0' === $item || 0 === $item ) ? '' : ( is_scalar( $item ) ? (string) $item : serialize( $item ) );
		};
		foreach ( self::endpoint_fields( $option ) as $field ) {
			// A field that is not submitted falls back to the provider's default, which is not a server of the submitter's choosing
			if ( ! array_key_exists( $field, $value ) ) {
				continue;
			}
			if ( $normal( isset( $value[ $field ] ) ? $value[ $field ] : null ) !== $normal( isset( $previous[ $field ] ) ? $previous[ $field ] : null ) ) {
				return true;
			}
		}
		return false;
	}

	/** Saving unchanged settings must not rewrite their ciphertext: the incremental chain fingerprint hashes these envelopes (BPS-10). */
	private static function keep( $value, $mode, $context, $config, $previous ) {
		if ( 'unmask' === $mode ) {
			// $previous is the decrypted value the form was shown. With nothing behind it, the placeholder stands for no value.
			return self::PLACEHOLDER === $value ? ( null === $previous ? '' : $previous ) : $value;
		}
		if ( 'encrypt' !== $mode || null === $previous ) {
			return self::secret( $value, $mode, $context, $config );
		}
		try {
			return self::job_secret( $value, $mode, $context, $config, $previous );
		} catch ( Exception $e ) {
			return self::secret( $value, $mode, $context, $config );
		}
	}

	public static function incoming( $value, $option ) {
		return self::walk( self::unmask( $value, $option ), $option, 'incoming' );
	}

	/**
	 * A value for a page or debug output: every non-empty secret field is replaced by the placeholder.
	 *
	 * @param Mixed  $value  The option value, decrypted or as saved
	 * @param String $option The option name, which selects the secret fields
	 *
	 * @return Mixed
	 */
	public static function mask( $value, $option ) {
		return self::walk( $value, $option, 'mask' );
	}

	/**
	 * Put the saved secret back wherever a submitted value returns the placeholder it was shown. The saved value is the one
	 * at the same path, which includes the instance ID; a placeholder with nothing saved behind it becomes empty. Unchanged
	 * secrets then keep their envelopes when saved (see keep()).
	 *
	 * @param Mixed  $value  The submitted option value
	 * @param String $option The option name
	 *
	 * @return Mixed
	 */
	public static function unmask( $value, $option ) {
		if ( ! self::has_placeholder( $value ) ) {
			return $value;
		}
		$saved = class_exists( 'BackupPlus_Options' ) ? BackupPlus_Options::get_stored_backupplus_option( $option, null ) : get_option( $option, null );
		try {
			$shown = self::shown( self::walk( $saved, $option, 'incoming' ), $option );
		} catch ( Exception $e ) {
			// Unreadable credentials were never shown as placeholders. saving() refuses to replace them with empty values.
			$shown = null;
		}
		return self::walk( $value, $option, 'unmask', array(), null, $shown );
	}

	/**
	 * Unmask part of an option that was submitted on its own, such as the settings of one instance for a connection test.
	 *
	 * @param Array  $value  The submitted settings
	 * @param String $option The option name
	 * @param Array  $path   Where the settings are in the option, e.g. array( 'settings', $instance_id )
	 *
	 * @return Array
	 */
	public static function unmask_at( $value, $option, $path ) {
		$wrapped = $value;
		foreach ( array_reverse( $path ) as $key ) {
			$wrapped = array( $key => $wrapped );
		}
		$wrapped = self::unmask( $wrapped, $option );
		foreach ( $path as $key ) {
			$wrapped = $wrapped[ $key ];
		}
		return $wrapped;
	}

	private static function has_placeholder( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_placeholder( $item ) ) {
					return true;
				}
			}
			return false;
		}
		return self::PLACEHOLDER === $value;
	}

	/** The saved option as the settings page showed it, where a page derives fields (a legacy WebDAV URL, the external database list). */
	private static function shown( $value, $option ) {
		if ( 'backupplus_extradbs' === $option && class_exists( 'BackupPlus_Backup_Sources' ) ) {
			$value = BackupPlus_Backup_Sources::connections( is_array( $value ) ? $value : array() );
		} elseif ( is_array( $value ) && isset( $value['settings'] ) && is_array( $value['settings'] ) && 0 === strpos( $option, 'backupplus_' ) && class_exists( 'BackupPlus_Storage_Methods_Interface' ) ) {
			$value['settings'] = BackupPlus_Storage_Methods_Interface::template_instance_settings( substr( $option, strlen( 'backupplus_' ) ), $value['settings'] );
		}
		return apply_filters( 'backupplus_credential_placeholder_source', $value, $option );
	}

	/**
	 * Job data for display, with every credential the job API protects masked and the rest unchanged. Option snapshots keep
	 * their ordinary settings, which the raw backup information is there to show.
	 *
	 * @param Mixed $data Decrypted job data
	 *
	 * @return Mixed
	 */
	public static function mask_job( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$cache = isset( $data['option_cache'] ) && is_array( $data['option_cache'] ) ? $data['option_cache'] : null;
		$data  = self::job( $data, '', 'mask' );
		if ( null !== $cache ) {
			foreach ( $cache as $name => $value ) {
				$cache[ $name ] = self::handles( $name ) ? self::mask( $value, $name ) : $value;
			}
			$data['option_cache'] = $cache;
		}
		return $data;
	}

	public static function saving( $value, $option ) {
		$old = get_option( $option, null );
		try {
			self::incoming( $old, $option );
		} catch ( Exception $e ) {
			// Redacted fields on the Settings page must not silently erase
			// unreadable saved credentials when an unrelated setting is saved.
			if ( self::walk( $value, $option, 'redact' ) === $value ) {
				throw new RuntimeException( 'Saved credentials are unavailable. Restore the recovery key, delete this destination, or enter replacement credentials before saving.' );
			}
		}
		return self::walk( $value, $option, 'encrypt', array(), null, $old );
	}

	public static function reading( $value, $option, $strict = false ) {
		if ( ! self::handles( $option ) ) {
			return $value;
		}
		try {
			return self::walk( $value, $option, 'decrypt' );
		} catch ( Exception $e ) {
			if ( $strict ) {
				throw $e;
			}
			// Keep Settings usable for reconnect/recovery, without handing ciphertext
			// or unauthenticated plaintext to providers. Never modify the saved value.
			update_option( 'backupplus_credential_setup_error', $e->getMessage(), false );
			return self::walk( $value, $option, 'redact' );
		}
	}

	/** A failed restore must be recoverable even while its WordPress options table is absent. */
	public static function recovery_reference( $option, $field ) {
		$raw = get_option( $option, array() );
		if ( ! isset( $raw[ $field ] ) || ! self::envelope( $raw[ $field ] ) ) { throw new RuntimeException( 'The saved recovery credential is not encrypted.' ); }
		return array(
			'ciphertext'    => $raw[ $field ],
			'context'       => self::context( $option, array( $field ) ),
			'key_directory' => BackupPlus_Credential_Key_Store::directory(),
		);
	}

	/** Reuse an authenticated current-key envelope when the credential value did not change. */
	private static function job_secret( $value, $mode, $context, $config, $previous ) {
		if ( 'encrypt' === $mode && self::envelope( $previous ) && ! self::envelope( $value ) ) {
			$current = null === $config ? BackupPlus_Credential_Key_Store::configuration() : $config;
			if ( 0 === strpos( $previous, BackupPlus_Credential_Encrypter::PREFIX . $current['active'] . ':' ) ) {
				$plain = self::secret( $previous, 'decrypt', $context, $current );
				if ( $plain === $value || serialize( $plain ) === serialize( $value ) ) { return $previous; }
			}
		}
		return self::secret( $value, $mode, $context, $config );
	}

	/** Jobs keep ordinary progress metadata readable; credentials use job-bound envelopes. */
	public static function job( $data, $job_id, $mode, $config = null, $previous = array() ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( array( 'option_cache', 'filesystem_credentials', 'backupplus_encryptionphrase' ) as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}
			$data[ $field ] = self::job_secret( $data[ $field ], $mode, 'job:' . $job_id . ':' . $field, $config, isset( $previous[ $field ] ) ? $previous[ $field ] : null );
		}
		// External database connection data is also copied outside option_cache.
		if ( ! empty( $data['backup_database'] ) && is_array( $data['backup_database'] ) ) {
			foreach ( $data['backup_database'] as $id => $database ) {
				foreach ( array( 'pass', 'password' ) as $field ) {
					if ( ! isset( $database['dbinfo'][ $field ] ) ) {
						continue;
					}
					$data['backup_database'][ $id ]['dbinfo'][ $field ] = self::job_secret( $database['dbinfo'][ $field ], $mode, 'job:' . $job_id . ':database:' . serialize( array( $id, $field ) ), $config, isset( $previous['backup_database'][ $id ]['dbinfo'][ $field ] ) ? $previous['backup_database'][ $id ]['dbinfo'][ $field ] : null );
				}
			}
		}
		foreach ( array( '', 'restore_options', 'backupplus_restorer_restore_options' ) as $container ) {
			$targets = '' === $container ? ( isset( $data['backupplus_external_targets'] ) ? $data['backupplus_external_targets'] : array() ) : ( isset( $data[ $container ]['backupplus_external_targets'] ) ? $data[ $container ]['backupplus_external_targets'] : array() );
			foreach ( $targets as $id => &$target ) {
				foreach ( array( 'pass', 'password' ) as $field ) {
					if ( isset( $target['connection'][ $field ] ) ) {
						$target['connection'][ $field ] = self::job_secret( $target['connection'][ $field ], $mode, 'job:' . $job_id . ':restore-target:' . $container . ':' . $id . ':' . $field, $config, '' === $container ? ( isset( $previous['backupplus_external_targets'][ $id ]['connection'][ $field ] ) ? $previous['backupplus_external_targets'][ $id ]['connection'][ $field ] : null ) : ( isset( $previous[ $container ]['backupplus_external_targets'][ $id ]['connection'][ $field ] ) ? $previous[ $container ]['backupplus_external_targets'][ $id ]['connection'][ $field ] : null ) );
					}
				}
			}
			unset( $target );
			if ( $targets ) {
				if ( '' === $container ) { $data['backupplus_external_targets'] = $targets; }
				else { $data[ $container ]['backupplus_external_targets'] = $targets; }
			}
		}
		// Both old and current restore containers may carry a legacy phrase.
		foreach ( array( 'restore_options', 'backupplus_restorer_restore_options' ) as $container ) {
			if ( isset( $data[ $container ]['backupplus_encryptionphrase'] ) ) {
				$data[ $container ]['backupplus_encryptionphrase'] = self::job_secret( $data[ $container ]['backupplus_encryptionphrase'], $mode, 'job:' . $job_id . ':' . $container . ':phrase', $config, isset( $previous[ $container ]['backupplus_encryptionphrase'] ) ? $previous[ $container ]['backupplus_encryptionphrase'] : null );
			}
		}
		return $data;
	}

	/** Migration/rotation prepares all option replacements before publishing any. */
	public static function rewrite( $allow_plaintext = false, $retire = false ) {
		$mode     = $allow_plaintext ? 'encrypt' : ( $retire ? 'retire' : 'rotate' );
		$prepared = array();
		foreach ( self::option_names() as $option ) {
			$raw = get_option( $option, null );
			if ( null === $raw ) {
				continue;
			}
			$prepared[ $option ] = array( $raw, self::walk( $raw, $option, $mode ) );
		}
		foreach ( $prepared as $option => $pair ) {
			if ( $pair[0] === $pair[1] ) {
				continue;
			}
			if ( get_option( $option, null ) !== $pair[0] ) {
				throw new RuntimeException( 'Storage settings changed during credential maintenance. Please retry.' );
			}
			update_option( $option, $pair[1] );
			if ( self::walk( get_option( $option ), $option, 'verify' ) !== self::walk( $pair[1], $option, 'decrypt' ) ) {
				throw new RuntimeException( 'Encrypted credentials could not be saved. Previous keys have been retained.' );
			}
		}
		self::rewrite_jobs( $mode );
	}

	private static function rewrite_jobs( $mode ) {
		foreach ( self::job_names() as $name ) {
			$raw     = get_site_option( $name );
			$id      = substr( $name, strlen( 'backupplus_jobdata_' ) );
			$updated = self::job( $raw, $id, $mode );
			// Providers that keep their own envelopes inside the snapshot (Dropbox) re-encrypt them as well
			if ( in_array( $mode, array( 'rotate', 'retire' ), true ) && is_array( $raw ) && isset( $raw['option_cache'] ) ) {
				$cache    = self::job( array( 'option_cache' => $raw['option_cache'] ), $id, 'decrypt' );
				$snapshot = apply_filters( 'backupplus_credentials_job_snapshot', $cache['option_cache'], $mode );
				if ( $snapshot !== $cache['option_cache'] ) {
					$encrypted               = self::job( array( 'option_cache' => $snapshot ), $id, 'encrypt' );
					$updated['option_cache'] = $encrypted['option_cache'];
				}
			}
			if ( $updated === $raw ) {
				continue;
			}
			if ( get_site_option( $name ) !== $raw ) {
				throw new RuntimeException( 'A backup job changed during credential maintenance. Please retry.' );
			}
			update_site_option( $name, $updated );
			if ( get_site_option( $name ) !== $updated ) {
				throw new RuntimeException( 'Encrypted job credentials could not be saved. Please retry.' );
			}
		}
	}

	private static function job_names() {
		global $wpdb;
		$pattern = $wpdb->esc_like( 'backupplus_jobdata_' ) . '%';
		$names   = is_multisite()
			? BackupPlus_SQL::wpdb()->column( 'SELECT meta_key FROM %i WHERE meta_key LIKE ? AND site_id = ?', array( $wpdb->sitemeta ), array( $pattern, (int) get_current_network_id() ) )
			: BackupPlus_SQL::wpdb()->column( 'SELECT option_name FROM %i WHERE option_name LIKE ?', array( $wpdb->options ), array( $pattern ) );
		// Like $wpdb->get_col(), a failed lookup finds no jobs.
		return array_unique( false === $names ? array() : $names );
	}

	/**
	 * Where the database still holds an envelope written with one of these keys: any option of any site, in every network
	 * that shares the key file, and any network option. This reads the stored text only, never the key store, so key
	 * retirement can run it while it holds the key store lock. It also finds copies outside the credential options.
	 *
	 * @param String[] $key_ids Key IDs
	 *
	 * @return String[] One description per place, such as "option backupplus_s3 (site 1)"
	 */
	public static function key_references( $key_ids ) {
		global $wpdb;
		$found = array();
		$sites = is_multisite() ? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) : array( get_current_blog_id() );
		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( $site );
			}
			try {
				foreach ( $key_ids as $id ) {
					$names = BackupPlus_SQL::wpdb()->column( 'SELECT option_name FROM %i WHERE option_value LIKE ?', array( $wpdb->options ), array( '%' . $wpdb->esc_like( BackupPlus_Credential_Encrypter::PREFIX . $id . ':' ) . '%' ) );
					if ( false === $names ) {
						throw new RuntimeException( 'Stored credentials could not be checked for old keys.' );
					}
					foreach ( $names as $name ) {
						$found[] = 'option ' . $name . ' (site ' . (int) $site . ')';
					}
				}
			} finally {
				if ( is_multisite() ) {
					restore_current_blog();
				}
			}
		}
		if ( is_multisite() ) {
			foreach ( $key_ids as $id ) {
				$names = BackupPlus_SQL::wpdb()->column( 'SELECT meta_key FROM %i WHERE meta_value LIKE ?', array( $wpdb->sitemeta ), array( '%' . $wpdb->esc_like( BackupPlus_Credential_Encrypter::PREFIX . $id . ':' ) . '%' ) );
				if ( false === $names ) {
					throw new RuntimeException( 'Stored credentials could not be checked for old keys.' );
				}
				foreach ( $names as $name ) {
					$found[] = 'network option ' . $name;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	public static function has_ciphertext() {
		$sites = is_multisite() ? get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		) : array( get_current_blog_id() );
		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( $site );
			}
			try {
				foreach ( self::option_names() as $option ) {
					if ( false !== strpos( serialize( get_option( $option ) ), 'bpcred:' ) ) {
						return true;
					}
				}
			} finally {
				if ( is_multisite() ) {
					restore_current_blog();
				}
			}
		}
		foreach ( self::job_names() as $name ) {
			if ( false !== strpos( serialize( get_site_option( $name ) ), 'bpcred:' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function upgrade( $restored = false ) {
		if ( ! $restored && BackupPlus_Credential_Key_Store::upgrade_complete( 'credentials' ) ) {
			return;
		}
		self::rewrite( true );
		BackupPlus_Credential_Key_Store::mark_upgraded( 'credentials' );
	}

	public static function verify( $config ) {
		foreach ( self::option_names() as $option ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				self::walk( $value, $option, 'incoming', array(), $config );
			}
		}
		foreach ( self::job_names() as $name ) {
			self::job( get_site_option( $name ), substr( $name, strlen( 'backupplus_jobdata_' ) ), 'incoming', $config );
		}
	}

	/** Exports omit every provider option and legacy phrase, including unsaved hidden OAuth data. */
	public static function export_omitted_options() {
		return self::option_names();
	}
}
