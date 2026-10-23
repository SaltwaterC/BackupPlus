<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
$exclude_id = $prefix . 'backupplus_include_' . $key . '_exclude';
?>
<div id="<?php echo esc_attr( $exclude_id . '_container' ); ?>" class="backupplus_exclude_container"<?php if ( ! $included ) { echo ' style="display:none;"'; } ?>>
	<?php if ( null !== $paths ) { ?>
		<label class="backupplus-exclude-label" for="<?php echo esc_attr( $prefix . 'backupplus_include_more_path' ); ?>"><?php esc_html_e( 'Additional file or directory paths', 'backupplus' ); ?>:</label>
		<p id="<?php echo esc_attr( $prefix . 'backupplus_include_more_path_help' ); ?>" class="description"><?php esc_html_e( 'Absolute local file or directory paths, one per line. Directories restore by merging their contents.', 'backupplus' ); ?></p>
		<textarea id="<?php echo esc_attr( $prefix . 'backupplus_include_more_path' ); ?>" name="backupplus_include_more_path" rows="5" class="large-text" aria-describedby="<?php echo esc_attr( $prefix . 'backupplus_include_more_path_help' ); ?>"><?php echo esc_textarea( implode( "\n", $paths ) ); ?></textarea>
	<?php } ?>
	<label class="backupplus-exclude-label" for="<?php echo esc_attr( $exclude_id ); ?>"><?php echo esc_html( __( 'Exclude these from', 'backupplus' ) . ' ' . $description ); ?>:</label>
	<span class="backupplus-fs-italic"><?php esc_html_e( '(the asterisk character matches zero or more characters)', 'backupplus' ); ?></span>
	<input type="hidden" id="<?php echo esc_attr( $exclude_id ); ?>" name="<?php echo esc_attr( 'backupplus_include_' . $key . '_exclude' ); ?>" value="<?php echo esc_attr( $include_exclude ); ?>">
	<?php
	$backupplus_admin->include_template(
		'wp-admin/settings/file-backup-exclude.php',
		false,
		array(
			'key'             => $key,
			'include_exclude' => $include_exclude,
			'path'            => $path,
		)
	);
	?>
</div>
