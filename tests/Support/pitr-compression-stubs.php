<?php
/** Optional compression executable selection, without a WordPress installation. */
function apply_filters( $name, $value ) {
	return 'backupplus_pitr_zstd_binary' === $name && array_key_exists( 'pitr_test_zstd', $GLOBALS ) ? $GLOBALS['pitr_test_zstd'] : $value;
}
