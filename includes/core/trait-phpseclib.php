<?php

/** phpseclib loading and the requirements of the features that depend on it. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Phpseclib {

	/** Load the single bundled phpseclib 3 installation. */
	public function ensure_phpseclib() {
		if ( version_compare( PHP_VERSION, BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION, '<' ) ) {
			return new WP_Error( 'phpseclib_php_version', sprintf( __( 'The bundled cryptography library requires PHP %s or newer.', 'backupplus' ), BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION ) );
		}
		backupplus_try_include_file( 'vendor/autoload.php', 'require_once' );
		backupplus_try_include_file( 'includes/class-backupplus-crypto.php', 'require_once' );
		return true;
	}

	/**
	 * Identify whether the minimum system requirements for PHPSecLib are met
	 *
	 * @return Boolean True if the minimum system requirements are met, false otherwise
	 */
	public function phpseclib_requirements_met() {
		if ( version_compare( PHP_VERSION, BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION, '>=' ) ) {
			return true;
		}
		$active_phpseclib_related_features = $this->list_active_features_requiring_phpseclib();
		if ( ! empty( $active_phpseclib_related_features ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Log warnings regarding phpseclib requirements that are not met
	 */
	public function maybe_log_phpseclib_warnings() {
		global $backupplus;
		static $logged = false;
		$job_type      = $backupplus->jobdata_get( 'job_type' );
		if ( ( 'backup' === $job_type || 'restore' === $job_type ) && ! $logged ) {
			$backupplus->log( $this->get_phpseclib_warning_msg(), 'warning' );
			$logged = true; // prevent multiple warnings being logged
		}
	}

	/**
	 * Retrieve phpseclib warning message that will be shown at the front-end and/or included in the backup/restoration logs
	 *
	 * @return String The warning message (filterable)
	 */
	public function get_phpseclib_warning_msg() {
		$phpseclib_related_features        = array_merge( $this->backup_methods, array( 'dbencryption' => 'Backup Encryption' ) );
		$active_phpseclib_related_features = array_intersect_key( $phpseclib_related_features, array_flip( $this->list_active_features_requiring_phpseclib() ) );
		return sprintf(
			/* translators: %s: PHP version */
			__( 'Your site is running PHP version %s.', 'backupplus' ),
			PHP_VERSION
		) . ' ' .
		__( 'Some functionality you have enabled is deprecated on this PHP version, and future releases of BackupPlus will require a newer version of PHP to keep it working.', 'backupplus' ) . ' ' .
		sprintf(
			/* translators: %s: Affected feature list */
			__( 'This affects: %s.', 'backupplus' ),
			implode( ', ', $active_phpseclib_related_features )
		) . ' ' .
		sprintf(
			/* translators: %s: Minimum recommended PHP version */
			__( 'We recommend asking your web host about updating PHP to version %s or higher.', 'backupplus' ),
			BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION
		);
	}

	/**
	 * Get plugin features requiring phpseclib which are active
	 *
	 * @return Array an array of active features
	 */
	protected function list_active_features_requiring_phpseclib() {
		$active_features     = array();
		$encryption_config   = BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array() );
		$backupplus_services = $this->get_canonical_service_list();
		foreach ( $backupplus_services as $service ) {
			$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
			if ( ! is_wp_error( $provider ) && $provider->supports_feature( 'phpseclib' ) ) {
				$active_features[] = $service;
			}
		}
		if ( isset( $encryption_config['mode'] ) && 'age' === $encryption_config['mode'] ) {
			$active_features[] = 'dbencryption';
		}
		return $active_features;
	}
}
