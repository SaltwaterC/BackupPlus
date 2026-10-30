<?php
/** WordPress shims for MultisiteRestoreTest: a network whose main site is site 1. */

define( 'ABSPATH', __DIR__ . '/' );
function is_multisite() {
	return true; }
function is_main_site( $site_id = null ) {
	return 1 === (int) $site_id; }
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' ); }

/** A WP_Filesystem over a list of the directories that exist and their entries. */
final class Multisite_Restore_Test_Filesystem {
	public function __construct( public array $directories ) {}
	public function exists( $path ) {
		return isset( $this->directories[ $path ] ); }
	public function dirlist( $path ) {
		return isset( $this->directories[ $path ] ) ? array_fill_keys( $this->directories[ $path ], array() ) : false; }
}
