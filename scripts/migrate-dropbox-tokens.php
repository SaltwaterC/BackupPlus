<?php
/** Optional maintenance shortcut; installation and upgrades require no CLI. */
if (!defined('WP_CLI') || !WP_CLI || !defined('BACKUPPLUS_DIR')) {
    fwrite(STDERR, "Run using WP-CLI with BackupPlus active.\n");
    exit(1);
}
try {
    $mode = isset($args[0]) ? $args[0] : '';
    if ('migrate-legacy' === $mode) {
        $count = BackupPlus_Dropbox_Token_Migration::upgrade();
    } elseif ('rotate' === $mode) {
        $count = BackupPlus_Dropbox_Token_Migration::rotate();
    } else {
        throw new RuntimeException('Pass migrate-legacy to finish the one-time upgrade, or rotate to re-encrypt saved credentials with the active key.');
    }
    WP_CLI::success('Migrated and verified '.$count.' Dropbox credential(s).');
} catch (Exception $e) {
    WP_CLI::error($e->getMessage());
}
