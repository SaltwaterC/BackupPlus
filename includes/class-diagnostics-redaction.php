<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/**
 * Keeps secrets out of the Advanced Tools PHP information (BPS-20).
 *
 * The page shows phpinfo() and every defined constant. Before this, it also showed the environment and the PHP variables
 * ($_SERVER, $_ENV, $_COOKIE), and so DB_PASSWORD, the WordPress keys and salts, an environment-supplied
 * BACKUPPLUS_CREDENTIAL_KEYS, database passwords passed in the environment, and the request's cookies and Authorization header.
 *
 * Now phpinfo() is run without its Environment and PHP Variables sections, and the output is redacted three ways: rows and
 * constants whose name looks like a secret show *** instead of their value (sections that list the request environment, such
 * as mod_php's Apache Environment and HTTP Headers Information, are removed altogether); credentials in URLs and query strings,
 * such as a session.save_path of tcp://host:6379?auth=..., are masked; and the values of secret constants, environment
 * variables, server variables and ini directives are masked wherever else they appear.
 */
class BackupPlus_Diagnostics_Redaction {

	const MASK = '***';

	/**
	 * Names of constants, environment and server variables, and ini directives that hold secrets: passwords, keys, salts,
	 * tokens, credentials and authorization headers. The name is matched case-insensitively, with "." and "_" as separators,
	 * so that "Tokenizer Support", "Password hashing algorithms" and the working directory (PWD) are not taken for secrets.
	 */
	const SECRET_NAME_PATTERN = '/(?:PASS(?:WORD|WD|PHRASE)?S?(?:[._]|\z)|(?:\A|[._])PW(?:[._]|\z)|[._]PWD(?:[._]|\z)|SECRET|TOKENS?(?:[._]|\z)|SALTS?(?:[._]|\z)|PRIVKEY|PRIVATE(?:[._]|\z)|CREDENTIALS?(?:[._]|\z)|(?:\A|[._])(?:KEYS?|AUTH|AUTHORIZATION)(?:[._]|\z)|COOKIE\z|DSN\z|LICEN[CS]E[._]?KEY|[._]LICEN[CS]E\z)/i';

	/** Secret values shorter than this are only masked by name: masking short values wherever they appear would garble the page. */
	const MIN_VALUE_LENGTH = 8;

	/** phpinfo() sections that list the server or request environment. */
	const ENVIRONMENT_SECTIONS = array( 'Apache Environment', 'HTTP Headers Information', 'Environment', 'PHP Variables' );

	/**
	 * Whether a constant, variable or directive with this name holds a secret.
	 *
	 * @param String $name - the name
	 *
	 * @return Boolean
	 */
	public static function is_secret_name( $name ) {
		return is_string( $name ) && 1 === preg_match( self::SECRET_NAME_PATTERN, trim( $name ) );
	}

	/**
	 * The defined constants, sorted by name, as the text to display. Strings and arrays whose name looks like a secret, or
	 * that mention an API key, are masked; numbers and booleans are not secrets.
	 *
	 * @return Array - name => text
	 */
	public static function constants() {
		$constants = @get_defined_constants();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		ksort( $constants );
		$display = array();
		foreach ( $constants as $name => $value ) {
			// An array constant (a cache or service configuration) is shown with its secret entries masked
			$text = print_r( is_array( $value ) ? self::mask_entries( $value ) : $value, true );// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Displayed in the diagnostics.
			if ( ( is_string( $value ) || is_array( $value ) ) && ( self::is_secret_name( $name ) || false !== stripos( $text, 'api_key' ) ) ) {
				$text = self::MASK;
			}
			$display[ $name ] = $text;
		}
		return $display;
	}

	/**
	 * An array with every entry whose key looks like a secret masked, at any depth.
	 *
	 * @param Array $value - the array
	 *
	 * @return Array
	 */
	private static function mask_entries( $value ) {
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::is_secret_name( $key ) && ( is_string( $item ) || is_array( $item ) ) && array() !== $item && '' !== $item ) {
				$value[ $key ] = self::MASK;
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = self::mask_entries( $item );
			}
		}
		return $value;
	}

	/** The string values under secret keys of an array, at any depth, to mask wherever else they appear. */
	private static function entry_values( $value, &$values ) {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				self::entry_values( $item, $values );
			} elseif ( is_string( $key ) && is_string( $item ) && strlen( $item ) >= self::MIN_VALUE_LENGTH && self::is_secret_name( $key ) ) {
				$values[ $item ] = strlen( $item );
			}
		}
	}

	/**
	 * The secret values to mask wherever they appear: those of constants defined by the site (not PHP's own), environment
	 * variables, server variables and ini directives whose name looks like a secret.
	 *
	 * @return Array - the values, longest first
	 */
	public static function secret_values() {
		$sources   = array();
		$constants = @get_defined_constants( true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$sources[] = isset( $constants['user'] ) ? $constants['user'] : array();
		$sources[] = function_exists( 'getenv' ) ? (array) getenv() : array();
		$sources[] = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();
		$sources[] = isset( $_ENV ) && is_array( $_ENV ) ? $_ENV : array();
		$ini       = function_exists( 'ini_get_all' ) ? @ini_get_all( null, false ) : array();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$sources[] = is_array( $ini ) ? $ini : array();

		$values = array();
		foreach ( $sources as $source ) {
			foreach ( $source as $name => $value ) {
				if ( is_string( $name ) && is_string( $value ) && strlen( $value ) >= self::MIN_VALUE_LENGTH && self::is_secret_name( $name ) ) {
					$values[ $value ] = strlen( $value );
				} elseif ( is_array( $value ) ) {
					self::entry_values( $value, $values );
				}
			}
		}
		arsort( $values );
		return array_map( 'strval', array_keys( $values ) );
	}

	/**
	 * Redact the output of phpinfo(), in its HTML (web) or text (CLI) form.
	 *
	 * @param String $output        - the output
	 * @param Array  $secret_values - values to mask wherever they appear; see secret_values()
	 *
	 * @return String
	 */
	public static function phpinfo( $output, $secret_values = array() ) {
		if ( false !== strpos( $output, '<td class="e">' ) ) {
			$sections = implode( '|', array_map( 'preg_quote', self::ENVIRONMENT_SECTIONS ) );
			$output   = preg_replace( '#<h2>(?:<a [^>]*>)?(?:' . $sections . ')(?:</a>)?</h2>\s*<table>.*?</table>#si', '', $output );
			$output   = preg_replace_callback(
				'#(<tr><td class="e">)([^<]*)(</td>)((?:<td class="v">.*?</td>)+)(</tr>)#s',
				function ( $row ) {
					if ( ! self::is_secret_name( html_entity_decode( $row[2], ENT_QUOTES ) ) ) {
						return $row[0];
					}
					$cells = preg_replace( '#(<td class="v">).*?(</td>)#s', '${1}' . self::MASK . '${2}', $row[4] );
					return $row[1] . $row[2] . $row[3] . $cells . $row[5];
				},
				$output
			);
		} else {
			$output = preg_replace_callback(
				'/^([^\r\n=]+?) => .*$/m',
				function ( $row ) {
					return self::is_secret_name( $row[1] ) ? $row[1] . ' => ' . self::MASK : $row[0];
				},
				$output
			);
		}
		return self::mask_values( self::mask_url_credentials( $output ), $secret_values );
	}

	/**
	 * Mask passwords in URLs (scheme://user:password@host) and secret query parameters (?auth=..., &password=...).
	 *
	 * @param String $text - the text
	 *
	 * @return String
	 */
	public static function mask_url_credentials( $text ) {
		$text = preg_replace( '#(//[^/\s:@<>"\']+:)[^@\s/<>"\']+@#', '${1}' . self::MASK . '@', $text );
		return preg_replace( '#([?&;](?:amp;)?(?:auth|password|passwd|pass|pwd|secret|token|key)=)[^&\s<>"\';]+#i', '${1}' . self::MASK, $text );
	}

	/**
	 * Mask each value wherever it appears, raw or HTML-escaped.
	 *
	 * @param String $text   - the text
	 * @param Array  $values - the values
	 *
	 * @return String
	 */
	public static function mask_values( $text, $values ) {
		$search = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) < self::MIN_VALUE_LENGTH ) {
				continue;
			}
			foreach ( array( $value, htmlspecialchars( $value, ENT_QUOTES ), htmlspecialchars( $value, ENT_COMPAT ), htmlspecialchars( $value, ENT_NOQUOTES ) ) as $form ) {
				$search[ $form ] = strlen( $form );
			}
		}
		if ( ! $search ) {
			return $text;
		}
		// Longest first, so that a value that contains another is masked whole
		arsort( $search );
		$search = array_map( 'strval', array_keys( $search ) );
		return str_replace( $search, array_fill( 0, count( $search ), self::MASK ), $text );
	}
}
