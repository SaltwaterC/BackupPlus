<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div class="wrap" id="backupplus-wrap">

	<h1><?php echo esc_html( $backupplus->plugin_title ); ?></h1>
	<div class="backupplus-top-menu">
		<span tabindex="0"><?php esc_html_e( 'Version', 'backupplus' ); ?>: <?php echo esc_html( $backupplus->version ); ?></span>
	</div>
