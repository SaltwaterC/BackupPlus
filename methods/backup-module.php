<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

require_once __DIR__ . '/../includes/backup-module/trait-options.php';
require_once __DIR__ . '/../includes/backup-module/trait-templates.php';
require_once __DIR__ . '/../includes/backup-module/trait-authentication.php';
require_once __DIR__ . '/../includes/backup-module/trait-runtime.php';

/**
 * Base class of every storage provider. Its methods live in traits under includes/backup-module/ (options, settings templates,
 * authentication, job data and logging); the overridable no-op hooks and the properties stay here.
 */
abstract class BackupPlus_BackupModule {
	use BackupPlus_BackupModule_Options;
	use BackupPlus_BackupModule_Templates;
	use BackupPlus_BackupModule_Authentication;
	use BackupPlus_BackupModule_Runtime;

	/** Whether this instance needs an OAuth authentication notice. */
	public function authentication_required( $settings ) {
		return false; }

	/** Provider notices, each containing a message and a WordPress notice class. */
	public function get_admin_notices( $settings ) {
		return array(); }

	/** Optional provider repair after a chunked download, before closing the file. */
	public function download_complete( $handle, $path ) {}

	/** Convert this provider's historical settings before generic import mapping. */
	public function normalize_imported_settings( $settings ) {
		return $settings; }

	/** @return Array Provider-specific values for the settings scripts. */
	public function get_admin_script_data() {
		return array();
	}

	private $_options;

	private $_instance_id;

	private $_storage;

	/**
	 * Indicates whether the connection test was successful.
	 *
	 * @var bool
	 */
	protected $is_connection_successful = false;

	/**
	 * Input and option field mappings with default values and supported contexts.
	 * N.B. This variable/property would normally be over-ridden by the child.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array();
}
