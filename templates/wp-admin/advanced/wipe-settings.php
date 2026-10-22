<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>
<div class="advanced_tools wipe_settings">
	<h3><?php esc_html_e( 'Wipe settings', 'backupplus' ); ?></h3>
	<p><?php echo esc_html( __( 'This button will delete all BackupPlus settings and progress information for in-progress backups (but not any of your existing backups from your cloud storage).', 'backupplus' ) . ' ' . __( 'You will then need to enter all your settings again.', 'backupplus' ) . ' ' . __( 'You can also do this before deactivating/deinstalling BackupPlus if you wish.', 'backupplus' ) ); ?></p>
	<form method="post" action="
	<?php
	echo esc_url(
		add_query_arg(
			array(
				'error'                      => false,
				'backupplus_restore_success' => false,
				'action'                     => false,
				'page'                       => 'backupplus',
			)
		)
	);
	?>
	">
		<input type="hidden" name="action" value="backupplus_wipesettings" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'backupplus-wipe-setting-nonce' ) ); ?>">
		<input type="submit" class="button-primary backupplus_wipe_settings" value="<?php esc_attr_e( 'Wipe settings', 'backupplus' ); ?>" onclick="var backupplus_wipe_settings = confirm('<?php echo esc_js( __( 'This will delete all your BackupPlus settings - are you sure you want to do this?', 'backupplus' ) ); ?>'); if (backupplus_wipe_settings) { backupplus_settings_form_changed = false; }; return backupplus_wipe_settings;">
	</form>
</div>
