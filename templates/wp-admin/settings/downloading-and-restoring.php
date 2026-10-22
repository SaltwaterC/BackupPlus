<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// $options is passed in
$default_options = array(
	'include_uploader'                      => true,
	'include_opera_warning'                 => false,
	'will_immediately_calculate_disk_space' => true,
	'include_whitespace_warning'            => true,
	'include_header'                        => false,
);

foreach ( $default_options as $k => $v ) {
	if ( ! isset( $options[ $k ] ) ) {
		$options[ $k ] = $v;
	}
}

// $backup_history is passed in
if ( false === $backup_history ) {
	$backup_history = BackupPlus_Backup_History::get_history();
}

if ( ! empty( $options['include_header'] ) ) {
	echo '<h2>' . esc_html__( 'Existing backups', 'backupplus' ) . ' (' . count( $backup_history ) . ')</h2>';
}

?>
<div class="download-backups form-table">
	<?php if ( ! empty( $options['include_whitespace_warning'] ) ) { ?>
		<p class="ud-whitespace-warning backupplus-hidden" style="display:none;">
			<?php echo wp_kses_post( '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . __( 'Your WordPress installation has a problem with outputting extra whitespace.', 'backupplus' ) . ' ' . __( 'This can corrupt backups that you download from here. Check for output before or after PHP opening and closing tags in plugins and themes.', 'backupplus' ) ); ?>
		</p>
		<?php
	}
	$bom_warning = $backupplus_admin->get_bom_warning_text();
	if ( ! empty( $bom_warning ) ) {
		?>
		<p class="ud-bom-warning">
			<?php echo wp_kses_post( $bom_warning ); ?>
		</p>
		<?php
	}
	?>
	<ul class="backupplus-disk-space-actions">
		<?php
			echo wp_kses_post( BackupPlus_Filesystem_Functions::web_server_disk_space( $options['will_immediately_calculate_disk_space'] ) );
		?>

		<li class="backupplus-server-scan">
			<strong><?php esc_html_e( 'More tasks:', 'backupplus' ); ?></strong>
			<?php
			if ( ! empty( $options['include_uploader'] ) ) {
				?>
					<a class="backupplus_uploader_toggle" href="<?php echo esc_url( BackupPlus::get_current_clean_url() ); ?>"><?php esc_html_e( 'Upload backup files', 'backupplus' ); ?></a> |
				<?php
			}
			?>
			<a href="<?php echo esc_url( BackupPlus::get_current_clean_url() ); ?>" class="backupplus_rescan_local" title="<?php echo esc_attr( __( 'Press here to look inside your BackupPlus directory (in your web hosting space) for any new backup sets that you have uploaded.', 'backupplus' ) . ' ' . __( 'The location of this directory is set in the expert settings, in the Settings tab.', 'backupplus' ) ); ?>"><?php esc_html_e( 'Rescan local folder for new backup sets', 'backupplus' ); ?></a>
			| <a href="<?php echo esc_url( BackupPlus::get_current_clean_url() ); ?>" class="backupplus_rescan_remote" title="<?php esc_html_e( 'Press here to look inside your remote storage methods for any existing backup sets (from any site, if they are stored in the same folder).', 'backupplus' ); ?>"><?php esc_html_e( 'Rescan remote storage', 'backupplus' ); ?></a>
		</li>
		<?php if ( ! empty( $options['include_opera_warning'] ) ) { ?>
			<li class="backupplus-opera-warning"><strong><?php esc_html_e( 'Opera web browser', 'backupplus' ); ?>:</strong> <?php esc_html_e( 'If you are using this, then turn Turbo/Road mode off.', 'backupplus' ); ?></li>
		<?php } ?>

	</ul>

	<?php
	if ( ! empty( $options['include_uploader'] ) ) {
		?>
	
		<div id="backupplus-plupload-modal" style="display:none;" title="<?php echo esc_attr( __( 'BackupPlus - Upload backup files', 'backupplus' ) ); ?>">
		<p class="upload"><em><?php echo esc_html( __( 'Upload files into BackupPlus.', 'backupplus' ) . ' ' . __( 'Or, you can place them manually into your BackupPlus directory (usually wp-content/backupplus), e.g. via FTP, and then use the "rescan" link above.', 'backupplus' ) ); ?></em></p>
		<?php
		if ( version_compare( $backupplus->get_wordpress_version(), '3.3', '<' ) ) {
			/* translators: 1: Software name (WordPress), 2: Required version (3.3) */
			echo wp_kses_post( '<em>' . sprintf( __( 'This feature requires %1$s version %2$s or later', 'backupplus' ), 'WordPress', '3.3' ) . '</em>' );
		} else {
			?>
			<div id="plupload-upload-ui">
			<div id="drag-drop-area">
				<div class="drag-drop-inside">
				<p class="drag-drop-info"><?php esc_html_e( 'Drop backup files here', 'backupplus' ); ?></p>
				<p><?php echo esc_html_x( 'or', 'Uploader: Drop backup files here - or - Select Files' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?></p>
				<p class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e( 'Select Files' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core. ?>" class="button /"></p>
				</div>
			</div>
			<div id="filelist">
			</div>
			</div>
			<?php
		}
		?>
		</div>
		<?php } ?>

	<div class="ud_downloadstatus"></div>
	<div class="backupplus_existing_backups">
		<?php
		echo BackupPlus_Backup_History::existing_backup_table( $backup_history );// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aleady escaped, and wp_kses() cannot be used due to included onclick attribute.
		?>
	</div>

</div>
