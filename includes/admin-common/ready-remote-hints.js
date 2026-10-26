// Provider folder hints and the always-keep toggle for a backup.

// Remote storage settings.
jQuery(function($) {

	$('#remote-storage-holder').on('keyup', '.backupplus_onedrive_folder_input', function(e) {
		var folder = $(this).val();
		var td_container = $(this).closest('td')
		if (0 == folder.indexOf('https:') || 0 == folder.indexOf('http:')) {
			if (!td_container.find('.onedrive_folder_error').length) {
				td_container.append('<div class="onedrive_folder_error">'+backupplus_i18n.onedrive_folder_url_warning+'</div>');
			}
		} else {
			td_container.find('.onedrive_folder_error').slideUp('slow', function() {
				td_container.find('.onedrive_folder_error').remove();
			});
		}
	});
	
	// Mark a backup as always keep (do not delete)
	$('#backupplus-iframe-modal').on('change', '#always_keep_this_backup', function() {
		var backup_key = $(this).data('backup_key');
		var params = {
			backup_key: backup_key,
			always_keep: $(this).is(':checked') ? 1 : 0,
		};
		backupplus_send_command('always_keep_this_backup', params, function(resp) {
			if (resp.hasOwnProperty('rawbackup')) {
				jQuery('#backupplus-iframe-modal').dialog('close');
				jQuery('.backupplus_existing_backups_row_'+backup_key+' .backupplus_existingbackup_date').data('rawbackup', resp.rawbackup);
				backupplus_html_modal(jQuery('.backupplus_existing_backups_row_'+backup_key+' .backupplus_existingbackup_date').data('rawbackup'), backupplus_i18n.raw, 780, 500);
			}
		});
	});


}); // End ready Vault
