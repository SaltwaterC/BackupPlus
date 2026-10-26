<?php

/** Stand-in for Elementor's plugin class when Elementor is not loaded. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Plugin class that is used for Elementor's namespace aliasing or could also be used for a variety of purposes of Elementor need
 */
if ( ! class_exists( '\Elementor\Plugin' ) ) {
	class BackupPlus_Elementor_Plugin {
	}
}
