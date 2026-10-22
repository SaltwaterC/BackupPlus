<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

abstract class BackupPlus_Restorer_Skin_Main extends WP_Upgrader_Skin {

	// @codingStandardsIgnoreStart
	public function header() {}
	public function footer() {}
	public function bulk_header() {}
	public function bulk_footer() {}
	// @codingStandardsIgnoreEnd

	/**
	 * return error
	 *
	 * @param  string $error error message
	 * @return string
	 */
	public function error( $error ) {
		if ( ! $error ) {
			return;
		}
		global $backupplus;
		if ( is_wp_error( $error ) ) {
			$backupplus->log_wp_error( $error, true );
		} elseif ( is_string( $error ) ) {
			$backupplus->log( $error );
			$backupplus->log( $error, 'warning-restore' );
		}
	}

	protected function backupplus_feedback( $string ) {

		if ( isset( $this->upgrader->strings[ $string ] ) ) {
			$string = $this->upgrader->strings[ $string ];
		}

		if ( false !== strpos( $string, '%' ) ) {
			$args = func_get_args();
			$args = array_splice( $args, 1 );
			if ( $args ) {
				$args   = array_map( 'strip_tags', $args );
				$args   = array_map( 'esc_html', $args );
				$string = vsprintf( $string, $args );
			}
		}
		if ( empty( $string ) ) {
			return;
		}

		global $backupplus;
		$backupplus->log_e( $string );
	}
}

global $backupplus;
$wp_version = $backupplus->get_wordpress_version();

if ( version_compare( $wp_version, '5.3', '>=' ) ) {
	if ( ! class_exists( 'BackupPlus_Restorer_Skin' ) ) {
		backupplus_try_include_file( 'includes/backupplus-restorer-skin-compatibility.php', 'require_once' );
	}
} else {
	class BackupPlus_Restorer_Skin extends BackupPlus_Restorer_Skin_Main {

		public function feedback( $string ) {
			parent::backupplus_feedback( $string );
		}
	}
}
