<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<div class="backupplus-ad-container updated">
	<div class="backupplus_notice_container">
		<div class="backupplus_advert_content_left">
			<img src="<?php echo esc_url( BACKUPPLUS_URL . '/images/' . $image ); ?>" width="60" height="60" alt="<?php esc_attr_e( 'notice image', 'backupplus' ); ?>" />
		</div>
		<div class="backupplus_advert_content_right">
			<h3 class="backupplus_advert_heading">
				<?php
				if ( ! empty( $prefix ) ) {
					echo esc_html( $prefix ) . ' ';
				}
					echo esc_html( $title );
				?>
				<div class="backupplus-advert-dismiss">
				<?php if ( ! empty( $dismiss_time ) ) { ?>
					<a href="#" onclick="jQuery('.backupplus-ad-container').slideUp(); jQuery.post(ajaxurl, {action: 'backupplus_ajax', subaction: '<?php echo esc_js( $dismiss_time ); ?>', nonce: '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>' });"><?php esc_html_e( 'Dismiss', 'backupplus' ); ?></a>
				<?php } else { ?>
					<a href="#" onclick="jQuery('.backupplus-ad-container').slideUp();"><?php esc_html_e( 'Dismiss', 'backupplus' ); ?></a>
				<?php } ?>
				</div>
			</h3>
			<p>
				<?php
					echo wp_kses_post( $text );

				if ( isset( $discount_code ) ) {
					echo ' <b>' . esc_html( $discount_code ) . '</b>';
				}

				if ( ! empty( $button_link ) && ( ! empty( $button_meta ) || ! empty( $button_text ) ) ) {
					?>
				<a class="backupplus_notice_link" href="<?php echo esc_attr( apply_filters( 'backupplus_com_link', $button_link ) ); ?>">
					<?php
					global $backupplus_admin;
					$backupplus_admin->include_template(
						'wp-admin/notices/button-label.php',
						false,
						array(
							'button_meta' => isset( $button_meta ) ? $button_meta : '',
							'button_text' => isset( $button_text ) ? $button_text : '',
						)
					);
					?>
					<?php
				}
				?>
			</p>
		</div>
	</div>
	<div class="clear"></div>
</div>
