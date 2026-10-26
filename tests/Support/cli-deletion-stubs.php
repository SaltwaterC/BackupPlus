<?php
/** History, storage and update shims for the strict deletion tests. Loaded once per process by CliDeletionTest, after cli-stubs.php. */

class BackupPlus_Backup_History {

	public static $history = array();

	public static function get_history( $timestamp = false ) {
		return $timestamp ? ( isset( self::$history[ $timestamp ] ) ? self::$history[ $timestamp ] : array() ) : self::$history;
	}

	public static function save_history( $history ) {
		self::$history = $history;
	}

}

class BackupPlus_Pre_Update_Backups {

	public static $batch = array();

	public static function instance() {
		return new self();
	}

	public function batch() {
		return self::$batch;
	}

}

class CLI_Delete_Provider {

	public $fail = true;

	public $calls = 0;

	public function supports_feature() {
		return false;
	}

	public function delete( $file ) {
		++$this->calls;
		return ! $this->fail;
	}

}

class BackupPlus_Storage_Methods_Interface {

	public static $provider;

	public static function get_storage_objects_and_ids( $services ) {
		return array( 'fixture' => array( 'object' => self::$provider ) );
	}

}

require BACKUPPLUS_DIR . '/includes/class-backup-deletion.php';
