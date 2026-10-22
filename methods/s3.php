<?php
/**
 * BackupPlus Provider: Amazon S3
 * Provider ID: s3
 * Provider Class: BackupPlus_BackupModule_s3
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

require_once __DIR__ . '/s3/base.php';

class BackupPlus_BackupModule_s3 extends BackupPlus_BackupModule_s3_base {
}
