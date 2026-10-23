<?php
/** Request-scoped integration isolation, installed only by check-pre-update-backups.php. */
if ( ! defined( 'BACKUPPLUS_PRE_UPDATE_TEST_TOKEN' ) || ! isset( $_SERVER['HTTP_X_BACKUPPLUS_PRE_UPDATE_TEST'] ) || ! hash_equals( BACKUPPLUS_PRE_UPDATE_TEST_TOKEN, $_SERVER['HTTP_X_BACKUPPLUS_PRE_UPDATE_TEST'] ) ) {
	return;
}
$prefix = 'backupplus_pre_update_test_' . BACKUPPLUS_PRE_UPDATE_TEST_TOKEN;
foreach ( array(
	'backupplus_pre_update_batch' => '_batch',
	'backupplus_backup_history'   => '_history',
	'cron'                        => '_cron',
) as $name => $suffix ) {
	add_filter(
		'pre_option_' . $name,
		function () use ( $prefix, $suffix ) {
			return get_option( $prefix . $suffix, array() );
		},
		100
	);
	add_filter(
		'pre_update_option_' . $name,
		function ( $value, $old ) use ( $prefix, $suffix ) {
			update_option( $prefix . $suffix, $value, false );
			return $old;
		},
		100,
		2
	);
}
add_filter( 'pre_option_backupplus_autobackup_default', '__return_true', 100 );
add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		$update_response = null;
		if ( is_string( $url ) && false !== strpos( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
			$update_response = array(
				'plugins'      => array(),
				'translations' => array(),
				'no_update'    => array(),
			);
		} elseif ( is_string( $url ) && false !== strpos( $url, 'api.wordpress.org/themes/update-check/' ) ) {
			$update_response = array(
				'themes'       => array(),
				'translations' => array(),
				'no_update'    => array(),
			);
		} elseif ( is_string( $url ) && false !== strpos( $url, 'api.wordpress.org/core/version-check/' ) ) {
			$update_response = array( 'offers' => array() );
		}
		if ( null === $update_response ) {
			$host = is_string( $url ) ? parse_url( $url, PHP_URL_HOST ) : null;
			if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				return $response;
			}
			return new WP_Error( 'test_offline', 'External requests disabled in this test.' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $update_response ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	100,
	3
);
add_filter(
	'pre_transient_doing_cron',
	function () {
		return microtime( true );
	}
);
add_filter(
	'pre_site_transient_update_plugins',
	function () {
		return (object) array(
			'response' => array(
				'sample/sample.php' => (object) array(
					'plugin'      => 'sample/sample.php',
					'slug'        => 'sample',
					'new_version' => '2.0',
					'package'     => 'https://example.invalid/plugin-2.zip',
				),
			),
		);
	}
);
add_filter(
	'pre_site_transient_update_themes',
	function () {
		return (object) array(
			'response' => array(
				'sample' => array(
					'theme'       => 'sample',
					'new_version' => '2.0',
					'package'     => 'https://example.invalid/theme-2.zip',
				),
			),
		);
	}
);
add_filter(
	'pre_site_transient_update_core',
	function () {
		return (object) array(
			'updates' => array(
				(object) array(
					'response'      => 'upgrade',
					'current'       => '99.0',
					'version'       => '99.0',
					'locale'        => 'en_US',
					'php_version'   => '7.2',
					'mysql_version' => '5.0',
					'packages'      => (object) array( 'full' => 'https://example.invalid/core.zip' ),
				),
			),
		);
	}
);
