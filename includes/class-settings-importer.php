<?php
/**
 * Translate legacy settings exports into BackupPlus's current option schema.
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Settings_Importer {

	/**
	 * Parse current JSON exports and URL-encoded payloads from older releases.
	 *
	 * @param string $payload The export's settings field.
	 * @return array
	 */
	public static function parse_settings_payload( $payload ) {
		if ( ! is_string( $payload ) ) {
			return array();
		}

		$raw_settings     = $payload;
		$decoded_settings = json_decode( $raw_settings, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// WordPress adds slashes to request data. Retry only invalid JSON so
			// credentials containing literal backslashes are not altered.
			$raw_settings     = stripslashes( $payload );
			$decoded_settings = json_decode( $raw_settings, true );
		}
		if ( is_array( $decoded_settings ) ) {
			$posted_settings = $decoded_settings;
		} elseif ( is_string( $decoded_settings ) ) {
			parse_str( $decoded_settings, $posted_settings );
		} else {
			parse_str( $raw_settings, $posted_settings );
		}

		if ( isset( $posted_settings['data'] ) && is_array( $posted_settings['data'] ) && ( isset( $posted_settings['network_site_url'] ) || isset( $posted_settings['epoch_date'] ) || isset( $posted_settings['local_date'] ) ) ) {
			$posted_settings = $posted_settings['data'];
		}

		return is_array( $posted_settings ) ? $posted_settings : array();
	}

	/**
	 * Convert old option keys to this fork's schema, while reporting unsupported
	 * settings instead of silently discarding them.
	 *
	 * @param array      $settings          Parsed settings.
	 * @param array      $known_services    Storage service IDs understood here.
	 * @param array      $allowed_settings  Option names accepted by BackupPlus.
	 * @param array|null $available_services Storage services active in this build.
	 * @return array Normalized settings and skipped setting names.
	 */
	public static function normalize_legacy_settings( $settings, $known_services, $allowed_settings, $available_services = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! is_array( $available_services ) ) {
			$available_services = $known_services;
		}

		$renamed_options  = array(
			'updraft_autobackup_default' => 'backupplus_autobackup_default',
			'updraft_auto_updates'       => 'backupplus_auto_updates',
			'updraft_debug_mode'         => 'backupplus_debug_mode',
			'updraft_delete_local'       => 'backupplus_delete_local',
			'updraft_dir'                => 'backupplus_dir',
			'updraft_email'              => 'backupplus_email',
			'updraft_encryptionphrase'   => 'backupplus_encryptionphrase',
			'updraft_interval'           => 'backupplus_interval',
			'updraft_interval_database'  => 'backupplus_interval_database',
			'updraft_last_backup'        => 'backupplus_last_backup',
			'updraft_report_dbbackup'    => 'backupplus_report_dbbackup',
			'updraft_split_every'        => 'backupplus_split_every',
			'updraft_ssl_disableverify'  => 'backupplus_ssl_disableverify',
			'updraft_ssl_nossl'          => 'backupplus_ssl_nossl',
			'updraft_ssl_useservercerts' => 'backupplus_ssl_useservercerts',
			'updraft_startday_db'        => 'backupplus_startday_db',
			'updraft_startday_files'     => 'backupplus_startday_files',
			'updraft_starttime_db'       => 'backupplus_starttime_db',
			'updraft_starttime_files'    => 'backupplus_starttime_files',
		);
		$skipped_settings = array();

		foreach ( $renamed_options as $legacy_key => $current_key ) {
			if ( ! array_key_exists( $legacy_key, $settings ) ) {
				continue;
			}
			if ( ! array_key_exists( $current_key, $settings ) ) {
				$value = $settings[ $legacy_key ];
				// An empty legacy directory means the historical wp-content/updraft default.
				if ( 'updraft_dir' === $legacy_key && '' === $value ) {
					$value = 'updraft';
				}
				$settings[ $current_key ] = $value;
			}
			unset( $settings[ $legacy_key ] );
		}

		$removed_feature_keys = array(
			'updraft_updraftvault',
			'updraft_remotesend',
			'updraft_remotesites',
			'updraft_migrator_localkeys',
			'updraft_central_localkeys',
		);
		foreach ( $removed_feature_keys as $legacy_key ) {
			if ( ! array_key_exists( $legacy_key, $settings ) ) {
				continue;
			}
			$skipped_settings[] = $legacy_key;
			unset( $settings[ $legacy_key ] );
		}

		$allowed_setting_keys = array_flip( $allowed_settings );
		$legacy_service_keys  = array();
		foreach ( $known_services as $service ) {
			if ( is_string( $service ) && '' !== $service ) {
				$legacy_service_keys[ 'updraft_' . $service ] = true;
			}
		}

		// Keep later-added options importable when their BackupPlus key is allowed.
		foreach ( array_keys( $settings ) as $legacy_key ) {
			if ( ! is_string( $legacy_key ) || 0 !== strpos( $legacy_key, 'updraft_' ) || isset( $legacy_service_keys[ $legacy_key ] ) ) {
				continue;
			}
			$current_key = 'backupplus_' . substr( $legacy_key, strlen( 'updraft_' ) );
			if ( ! isset( $allowed_setting_keys[ $current_key ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $current_key, $settings ) ) {
				$settings[ $current_key ] = $settings[ $legacy_key ];
			}
			unset( $settings[ $legacy_key ] );
		}

		// Provider credentials remain useful even when a provider is optional or inactive.
		foreach ( $known_services as $service ) {
			if ( ! is_string( $service ) || '' === $service || 'email' === $service ) {
				continue;
			}
			$legacy_key  = 'updraft_' . $service;
			$current_key = 'backupplus_' . $service;
			if ( ! array_key_exists( $legacy_key, $settings ) ) {
				continue;
			}
			if ( ! array_key_exists( $current_key, $settings ) ) {
				$settings[ $current_key ] = $settings[ $legacy_key ];
			}
			unset( $settings[ $legacy_key ] );
		}

		// Values may contain the removed cloud destination even when the setting key is valid.
		$service_key = array_key_exists( 'backupplus_service', $settings ) ? 'backupplus_service' : ( array_key_exists( 'updraft_service', $settings ) ? 'updraft_service' : null );
		if ( null !== $service_key ) {
			$selected_services = is_array( $settings[ $service_key ] ) ? array_values( $settings[ $service_key ] ) : array( $settings[ $service_key ] );
			$valid_services    = array();
			foreach ( $selected_services as $service ) {
				if ( ! is_string( $service ) || '' === $service || 'none' === $service ) {
					continue;
				}
				if ( ! in_array( $service, $available_services, true ) ) {
					$skipped_settings[] = 'updraft_service:' . $service;
					continue;
				}
				$valid_services[] = $service;
			}

			$valid_services = array_values( array_unique( $valid_services ) );
			if ( count( $valid_services ) > 1 ) {
				$skipped_settings[] = 'updraft_service:multiple_destinations';
				$valid_services     = array_slice( $valid_services, -1 );
			}
			$settings['backupplus_service'] = empty( $valid_services ) ? 'none' : reset( $valid_services );
			if ( 'updraft_service' === $service_key ) {
				unset( $settings['updraft_service'] );
			}
		}

		$allowed_setting_keys = array_flip( $allowed_settings );
		$metadata_keys        = array( 'backupplus_version', 'updraftplus_version', 'updraft_version' );
		foreach ( array_keys( $settings ) as $key ) {
			if ( in_array( $key, $metadata_keys, true ) || isset( $allowed_setting_keys[ $key ] ) ) {
				continue;
			}
			$skipped_settings[] = $key;
			unset( $settings[ $key ] );
		}

		return array(
			'settings'         => $settings,
			'skipped_settings' => array_values( array_unique( $skipped_settings ) ),
		);
	}
}
