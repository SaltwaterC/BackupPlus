<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div class="wrap" id="backupplus-wrap">

	<h1><img src="<?php echo esc_url( trailingslashit( BACKUPPLUS_URL ) . 'images/backupplus-logo.svg' ); ?>" width="240" height="56" alt="<?php echo esc_attr( $backupplus->plugin_title ); ?>"></h1>
	<div class="backupplus-top-menu">
		<span tabindex="0"><?php esc_html_e( 'Version', 'backupplus' ); ?>: <?php echo esc_html( $backupplus->version ); ?></span>
	</div>
