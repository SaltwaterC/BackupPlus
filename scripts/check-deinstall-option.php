<?php
/**
 * BPS-13 in real WordPress: the network-wide deinstall option, which makes uninstall.php wipe all BackupPlus data, is stored
 * only for a user who manages BackupPlus and only as 'yes' or 'no', and the deinstall dialog is not set up for anyone else.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-deinstall-option.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function deinstall_option_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

require dirname( __DIR__ ) . '/backupplus.php';
$missing  = new stdClass();
$original = get_site_option( 'backupplus_deinstall_option', $missing );
$allow    = false;
$filter   = function () use ( &$allow ) {
	return $allow;
};
add_filter( 'backupplus_user_can_manage', $filter, 1000 );
try {
	delete_site_option( 'backupplus_deinstall_option' );
	$dialog = BackupPlus_Deactivation::get_instance();

	// A site administrator on a network: the dialog library would accept them, BackupPlus does not.
	deinstall_option_expect( ! BackupPlus_Deactivation::store_choice( 'yes' ), 'A user who cannot manage BackupPlus must not arm the wipe' );
	deinstall_option_expect( false === get_site_option( 'backupplus_deinstall_option', false ), 'The option must stay unset for such a user' );
	$ajax = 'wp_ajax_backupplus_deinstall_confirm';
	remove_all_actions( $ajax );
	$dialog->instantiate_deinstall_dialog_object();
	deinstall_option_expect( ! has_action( $ajax ), 'The dialog must not be set up for a user who cannot manage BackupPlus' );

	$allow = true;
	foreach ( array( 'wipe', 'YES', '', 'yes ', '1', array( 'yes' ), null ) as $value ) {
		deinstall_option_expect( ! BackupPlus_Deactivation::store_choice( $value ), 'Only yes and no may be stored: ' . wp_json_encode( $value ) );
	}
	deinstall_option_expect( false === get_site_option( 'backupplus_deinstall_option', false ), 'Refused values must not be stored' );
	deinstall_option_expect( BackupPlus_Deactivation::store_choice( 'yes' ) && 'yes' === get_site_option( 'backupplus_deinstall_option' ), 'A manager must be able to store yes' );
	deinstall_option_expect( BackupPlus_Deactivation::store_choice( 'no' ) && 'no' === get_site_option( 'backupplus_deinstall_option' ), 'A manager must be able to store no' );
	$dialog->instantiate_deinstall_dialog_object();
	deinstall_option_expect( has_action( $ajax ), 'The dialog must be set up for a user who manages BackupPlus' );

	// A value stored before the fix, by anyone, must not arm the wipe: uninstall.php wipes only on an explicit yes
	deinstall_option_expect( 1 === preg_match( '/^\s*if \( \'yes\' !== \$deinstall_option \) \{\s+return;/m', file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ) ), 'uninstall.php must keep the data unless the stored choice is exactly yes' );
} finally {
	remove_filter( 'backupplus_user_can_manage', $filter, 1000 );
	if ( $original === $missing ) {
		delete_site_option( 'backupplus_deinstall_option' );
	} else {
		update_site_option( 'backupplus_deinstall_option', $original );
	}
}
WP_CLI::success( 'The deinstall option is stored only by users who manage BackupPlus, and only as yes or no.' );
