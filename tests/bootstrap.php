<?php
/** PHPUnit bootstrap: the dev tools autoloader also provides the BackupPlus\Tests namespace. */

require dirname( __DIR__ ) . '/.cache/php-tools/vendor/autoload.php';

// The plugin calls WordPress's wrappers for these PHP functions. Host suites load plugin classes without WordPress, so
// every suite gets the same behaviour as WordPress 6.5+ (without its filters) from here.
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		return ! empty( $file ) && @unlink( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- As in WordPress.
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The stub of that function.
	}
}
