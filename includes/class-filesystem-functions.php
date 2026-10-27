<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access.' );
}

require_once __DIR__ . '/filesystem-functions/trait-disk-usage.php';
require_once __DIR__ . '/filesystem-functions/trait-files.php';
require_once __DIR__ . '/filesystem-functions/trait-unzip.php';

/**
 * Here live some stand-alone filesystem manipulation functions
 *
 * The methods live in traits under includes/filesystem-functions/ (disk usage, local file handling, unzip).
 */
require_once __DIR__ . '/class-tar.php';

class BackupPlus_Filesystem_Functions {
	use BackupPlus_Filesystem_Functions_Disk_Usage;
	use BackupPlus_Filesystem_Functions_Files;
	use BackupPlus_Filesystem_Functions_Unzip;
}
