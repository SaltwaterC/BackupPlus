<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>
<div id="backupplus_exclude_modal" title="BackupPlus - <?php esc_html_e( 'Exclude files/directories', 'backupplus' ); ?>">
	<input type="hidden" id="backupplus_exclude_modal_for" value=""/>
	<input type="hidden" id="backupplus_exclude_modal_path" value=""/>
	<div id="backupplus_exclude_modal_main">
		<p><?php esc_html_e( 'Select a way to exclude files or directories from the backup', 'backupplus' ); ?>:</p>
		<ol class="backupplus_exclude_actions_list">
			<li>
				<a href="#" class="backupplus-exclude-link" data-panel="file-dir"><?php esc_html_e( 'File/directory', 'backupplus' ); ?></a>
			</li>
			<li>
				<a href="#" class="backupplus-exclude-link" data-panel="extension"><?php esc_html_e( 'All files with this extension', 'backupplus' ); ?></a>
			</li>
			<li>
				<a href="#" class="backupplus-exclude-link" data-panel="begin-with"><?php esc_html_e( 'All files beginning with given characters', 'backupplus' ); ?></a>
			</li>
			<li>
				<a href="#" class="backupplus-exclude-link" data-panel="contain-clause"><?php esc_html_e( 'Files/Directories containing the given characters in their names', 'backupplus' ); ?></a>
			</li>
		</ol>
	</div>
	<?php $panel = 'file-dir'; ?>
	<div class="backupplus-exclude-panel backupplus-hidden" data-panel="<?php echo esc_attr( $panel ); ?>" style="display:none;">
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-heading.php', false, array( 'title' => __( 'File/directory', 'backupplus' ) ) );
		?>
		<div class="backupplus-add-dir-file-cont">
			<div id="backupplus_exclude_jstree_info_container" class="backupplus-jstree-info-container">
				<p>
					<span id="backupplus_exclude_jstree_path_text">
						<?php esc_html_e( 'Select a file/folder which you would like to exclude', 'backupplus' ); ?>
					</span>
				</p>
			</div>
			<div id="backupplus_exclude_files_jstree_container">
				<div id="backupplus_exclude_files_folders_jstree" class="backupplus_jstree"></div>
			</div>
			<?php
			$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-submit.php', false, array( 'panel' => $panel ) );
			?>
		</div>
	</div>
	
	<?php $panel = 'extension'; ?>
	<div class="backupplus-exclude-panel backupplus-hidden" data-panel="<?php echo esc_attr( $panel ); ?>" style="display:none;">
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-heading.php', false, array( 'title' => __( 'All files with this extension', 'backupplus' ) ) );
		?>
		<label for="backupplus_exclude_extension_field"><?php esc_html_e( 'All files with this extension', 'backupplus' ); ?>: </label>
		<input type="text" name="backupplus_exclude_extension_field" id="backupplus_exclude_extension_field" size="25" placeholder="<?php esc_html_e( 'Type an extension like zip', 'backupplus' ); ?>" />
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-submit.php', false, array( 'panel' => $panel ) );
		?>
	</div>
	
	<?php $panel = 'begin-with'; ?>
	<div class="backupplus-exclude-panel backupplus-hidden" data-panel="<?php echo esc_attr( $panel ); ?>" style="display:none;">
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-heading.php', false, array( 'title' => __( 'All files beginning with these characters', 'backupplus' ) ) );
		?>
		<label for="backupplus_exclude_prefix_field"><?php esc_html_e( 'All files beginning with these characters', 'backupplus' ); ?>: </label>
		<input type="text" name="backupplus_exclude_prefix_field" id="backupplus_exclude_prefix_field" size="25" placeholder="<?php esc_html_e( 'Type a file prefix', 'backupplus' ); ?>" />
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-submit.php', false, array( 'panel' => $panel ) );
		?>
	</div>

	<?php $panel = 'contain-clause'; ?>
	<div class="backupplus-exclude-panel backupplus-hidden" data-panel="<?php echo esc_attr( $panel ); ?>" style="display:none;">
		<?php
		$backupplus_admin->include_template( 'wp-admin/settings/exclude-settings-modal/exclude-panel-heading.php', false, array( 'title' => __( 'All files/directories containing the given characters in their names', 'backupplus' ) ) );
		?>
		<div id="backupplus_exclude_jstree_info_container" class="backupplus-jstree-info-container">
			<p>
				<span id="backupplus_exclude_jstree_path_text">
					<?php esc_html_e( 'Select the folder in which the files or sub-directories you would like to exclude are located', 'backupplus' ); ?>
				</span>
			</p>
		</div>
		<div id="backupplus_exclude_files_jstree_container">
				<div id="backupplus_exclude_files_folders_wildcards_jstree" class="backupplus_jstree"></div>
		</div>
		<label for="backupplus_exclude_prefix_field" class="contain-clause-sub-label"><?php esc_html_e( 'All files/directories containing ', 'backupplus' ); ?></label>
		<div class="clause-input-container">
			<input class="wildcards-input" type="text" size="25" placeholder="<?php esc_html_e( 'these characters', 'backupplus' ); ?>" />
			<select class="clause-options wildcards-input">
				<option value="beginning"><?php esc_html_e( 'at the beginning of their names', 'backupplus' ); ?></option>
				<option value="middle"><?php esc_html_e( 'anywhere in their names', 'backupplus' ); ?></option>
				<option value="end"><?php esc_html_e( 'at the end of their names', 'backupplus' ); ?></option>
			</select>
		</div>
		<?php
		$backupplus_admin->include_template(
			'wp-admin/settings/exclude-settings-modal/exclude-panel-submit.php',
			false,
			array(
				'panel'       => $panel,
				'text_button' => __( 'Add exclusion rule', 'backupplus' ),
			)
		);
		?>
	</div>
</div>
