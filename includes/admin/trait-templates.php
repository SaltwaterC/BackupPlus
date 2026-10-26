<?php

/** Template directory lookup and rendering for the admin pages. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Templates {

	/**
	 * Get the path to the UI templates directory
	 *
	 * @return String - a filesystem directory path
	 */
	public function get_templates_dir() {
		return apply_filters( 'backupplus_templates_dir', BackupPlus_Manipulation_Functions::wp_normalize_path( BACKUPPLUS_DIR . '/templates' ) );
	}

	/**
	 * Initialises self::$template_directories
	 */
	private function register_template_directories() {

		$template_directories = array();

		$templates_dir = $this->get_templates_dir();

		if ( $dh = opendir( $templates_dir ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( '.' == $file || '..' == $file ) {
					continue;
				}
				if ( is_dir( $templates_dir . '/' . $file ) ) {
					$template_directories[ $file ] = $templates_dir . '/' . $file;
				}
			}
			closedir( $dh );
		}

		// This is the optimal hook for most extensions to hook into
		$this->template_directories = apply_filters( 'backupplus_template_directories', $template_directories );
	}

	/**
	 * Output, or return, the results of running a template (from the 'templates' directory, unless a filter over-rides it). Templates are run with $backupplus, $backupplus_admin and $wpdb set.
	 *
	 * @param String  $path                   - path to the template
	 * @param Boolean $return_instead_of_echo - by default, the template is echo-ed; set this to instead return it
	 * @param Array   $extract_these          - variables to inject into the template's run context
	 *
	 * @return Void|String
	 */
	public function include_template( $path, $return_instead_of_echo = false, $extract_these = array() ) {
		if ( $return_instead_of_echo ) {
			ob_start();
		}

		if ( preg_match( '#^([^/]+)/(.*)$#', $path, $matches ) ) {
			$prefix = $matches[1];
			$suffix = $matches[2];
			if ( isset( $this->template_directories[ $prefix ] ) ) {
				$template_file = $this->template_directories[ $prefix ] . '/' . $suffix;
			}
		}

		if ( ! isset( $template_file ) ) {
			$template_file = BACKUPPLUS_DIR . '/templates/' . $path;
		}

		$template_file = apply_filters( 'backupplus_template', $template_file, $path );

		do_action( 'backupplus_before_template', $path, $template_file, $return_instead_of_echo, $extract_these );

		if ( ! file_exists( $template_file ) ) {
			BackupPlus_Manipulation_Functions::error_log( "BackupPlus: template not found: $template_file" );
			echo esc_html( __( 'Error:', 'backupplus' ) . ' ' . __( 'template not found', 'backupplus' ) . ' (' . $path . ')' );
		} else {
			extract( $extract_these );
			global $backupplus, $wpdb;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Passed though to template file.
			$backupplus_admin            = $this;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Passed though to template file.
			$GLOBALS['backupplus_admin'] = $backupplus_admin;
			include $template_file;
		}

		do_action( 'backupplus_after_template', $path, $template_file, $return_instead_of_echo, $extract_these );

		if ( $return_instead_of_echo ) {
			return ob_get_clean();
		}
	}
}
