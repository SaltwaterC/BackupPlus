<?php
/** Minimal standalone harness for the restore character set and collation checks. */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = null ) {
	return $text; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
class WP_Error {
	public $code;
	public function __construct( $code, $message ) {
		$this->code = $code; }
}
require dirname( __DIR__, 2 ) . '/includes/restorer/trait-database-restore.php';
class Restore_Charset_Checker {
	use BackupPlus_Restorer_Database_Restore;
	public $restore_options;
	public function __construct( $options ) {
		$this->restore_options = $options; }
	public function validate( $charsets, $collations ) {
		return $this->validate_charset_options( $charsets, $collations ); }
}
