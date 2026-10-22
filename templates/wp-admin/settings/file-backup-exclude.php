<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>
<div class="backupplus_exclude_entity_container" data-include-backup-file="<?php echo esc_attr( $key ); ?>">
	<?php
	if ( ! empty( $include_exclude ) ) {
		$include_exclude_entities = explode( ',', $include_exclude );
		foreach ( $include_exclude_entities as $include_exclude_entity ) {
			?>
			<div class="backupplus_exclude_entity_wrapper">
				<input type="text" class="backupplus_exclude_entity_field" name="<?php echo esc_attr( 'backupplus_include_' . $key . '_exclude_entity[]' ); ?>" value="<?php echo esc_attr( $include_exclude_entity ); ?>" data-val="<?php echo esc_attr( $include_exclude_entity ); ?>" data-include-backup-file="<?php echo esc_attr( $key ); ?>" readonly="readonly"/><a href="#" class="backupplus_exclude_entity_edit dashicons dashicons-edit" data-include-backup-file="<?php echo esc_attr( $key ); ?>" title="<?php esc_attr_e( 'Edit', 'backupplus' ); ?>"></a><a href="#" class="backupplus_exclude_entity_update dashicons dashicons-yes" data-include-backup-file="<?php echo esc_attr( $key ); ?>" style="display: none;" title="<?php esc_attr_e( 'Confirm change', 'backupplus' ); ?>"></a><a href="#" class="backupplus_exclude_entity_delete dashicons dashicons-no" data-include-backup-file="<?php echo esc_attr( $key ); ?>" title="<?php esc_attr_e( 'Delete', 'backupplus' ); ?>"></a>
			</div>
			<?php
		}
	}
	?>
</div>
<?php
/* translators: %s: Backup entity*/
$exclusion_label = sprintf( __( 'Add an exclusion rule for %s', 'backupplus' ), $key );
?>
<a href="#" class="backupplus_add_exclude_item backupplus_icon_link" data-include-backup-file="<?php echo esc_attr( $key ); ?>" data-path="<?php echo esc_attr( $path ); ?>" aria-label="<?php echo esc_attr( $exclusion_label ); ?>"><span class="dashicons dashicons-plus"></span><?php echo esc_html__( 'Add an exclusion rule', 'backupplus' ); ?></a>
