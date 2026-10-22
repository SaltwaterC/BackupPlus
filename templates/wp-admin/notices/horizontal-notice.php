<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<?php if ( ! empty( $button_meta ) && 'review' == $button_meta ) : ?>

	<div class="backupplus-ad-container updated">
	<div class="backupplus_notice_container backupplus_review_notice_container">
		<div class="backupplus_advert_content_left_extra">
			<img src="<?php echo esc_url( BACKUPPLUS_URL . '/images/' . $image ); ?>" width="100" alt="<?php esc_html_e( 'notice image', 'backupplus' ); ?>" />
		</div>
		<div class="backupplus_advert_content_right">
			<p>
				<?php echo wp_kses_post( $text ); ?>
			</p>
					
			<?php if ( ! empty( $button_link ) ) { ?>
				<div class="backupplus_advert_button_container">
					<a class="button button-primary" href="<?php echo esc_attr( apply_filters( 'backupplus_com_link', $button_link ) ); ?>" target="_blank" onclick="jQuery('.backupplus-ad-container').slideUp(); jQuery.post(ajaxurl, {action: 'backupplus_ajax', subaction: '<?php echo esc_js( $dismiss_time ); ?>', nonce: '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>', dismiss_forever: '1' });">
						<?php esc_html_e( 'Ok, you deserve it', 'backupplus' ); ?>
					</a>
					<div class="dashicons dashicons-calendar"></div>
					<a class="backupplus_notice_link" href="#" onclick="jQuery('.backupplus-ad-container').slideUp(); jQuery.post(ajaxurl, {action: 'backupplus_ajax', subaction: '<?php echo esc_js( $dismiss_time ); ?>', nonce: '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>', dismiss_forever: '0' });">
						<?php esc_html_e( 'Maybe later', 'backupplus' ); ?>
					</a>
					<div class="dashicons dashicons-no-alt"></div>
					<a class="backupplus_notice_link" href="#" onclick="jQuery('.backupplus-ad-container').slideUp(); jQuery.post(ajaxurl, {action: 'backupplus_ajax', subaction: '<?php echo esc_js( $dismiss_time ); ?>', nonce: '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>', dismiss_forever: '1' });"><?php esc_html_e( 'Never', 'backupplus' ); ?></a>
				</div>
			<?php } ?>
		</div>
	</div>
	<div class="clear"></div>
</div>

<?php else : ?>

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

				if ( isset( $button_meta ) && 'inline' == $button_meta ) {
					?>
				<br>
				<a href="<?php echo esc_attr( apply_filters( 'backupplus_com_link', $button_link ) ); ?>"><b><?php echo esc_html( $button_text ); ?></b></a>
					<?php
					echo wp_kses_post( $text2 );
				}

				if ( isset( $discount_code ) ) {
					echo ' <b>' . esc_html( $discount_code ) . '</b>';
				}

				if ( ! empty( $button_link ) && ( ( ! empty( $button_meta ) && 'inline' != $button_meta ) || ! empty( $button_text ) ) ) {
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

	<?php

endif;
