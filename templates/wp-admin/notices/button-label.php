<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
if ( ! empty( $button_meta ) ) {
	if ( 'backupplus' == $button_meta ) {
		esc_html_e( 'Learn more', 'backupplus' );
	} elseif ( 'signup' == $button_meta ) {
		esc_html_e( 'Sign up', 'backupplus' );
	} elseif ( 'learnmore' == $button_meta ) {
		esc_html_e( 'Learn more', 'backupplus' );
	} elseif ( 'go_there' == $button_meta ) {
		esc_html_e( 'Go there', 'backupplus' );
	} else {
		esc_html_e( 'Read more', 'backupplus' );
	}
} elseif ( ! empty( $button_text ) ) {
	echo esc_html( $button_text );
}
