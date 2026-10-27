<?php
/** Minimal standalone harness for the real restore dump preflight. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	public function __construct( $code, $message ) {}
}
class BackupPlus_Archive_Compression {
	public static function open_read( $path ) {
		return gzopen( $path, 'rb' ); }
}
class BackupPlus_Backup_Sources {
	public static function custom( $id ) {
		return false; }
}
require dirname( __DIR__, 2 ) . '/includes/restorer/trait-database-restore.php';
require dirname( __DIR__, 2 ) . '/includes/class-source-restore.php';
require dirname( __DIR__, 2 ) . '/includes/class-pitr-restore.php';
class Selected_Table_Reader {
	use BackupPlus_Restorer_Database_Restore;
	private $tables_to_restore;
	private $include_unspecified_tables = false;
	public function __construct( $tables ) {
		$this->tables_to_restore = $tables; }
	public function validate( $path, $plain ) {
		return $this->validate_selected_dump_tables( $path, $plain, false ); }
}
