// The upload to remote storage dialog and the existing backups table row actions.

jQuery(function($) {
	var backupplus_upload_modal_buttons = {};

	backupplus_upload_modal_buttons[backupplus_i18n.uploadbutton] = function () {
		var key = jQuery('#backupplus_upload_timestamp').val();
		var nonce = jQuery('#backupplus_upload_nonce').val();
		var services = '';
		var send_list = false;
		
		jQuery('.backupplus_remote_storage_destination').each(function (index) {
			if (jQuery(this).is(':checked')) { send_list = true; }
		});

		if (!send_list) {
			jQuery('#backupplus-upload-modal-error').html(backupplus_i18n.local_upload_error);
			return;
		} else {
			services = jQuery("input[name^='backupplus_remote_storage_destination_']").serializeArray();
		}

		jQuery(this).dialog("close");
		alert(backupplus_i18n.local_upload_started);
		backupplus_send_command('upload_local_backup', {
			use_nonce: nonce,
			use_timestamp: key,
			services: services
		});

	};

	backupplus_upload_modal_buttons[backupplus_i18n.cancel] = function () {
		jQuery(this).dialog("close");
	};

	jQuery("#backupplus-upload-modal").dialog({
		autoOpen: false, modal: true, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).parent().trigger('focus');
			$(this).dialog('option', 'width', 308);
			if (jQuery(window).height() > 460) {
				$(this).dialog('option', 'height', 318);
			} else if (jQuery(window).height() > 250 && jQuery(window).height() < 461) {
				$(this).dialog('option', 'height', 460);
			} else {
				$(this).dialog('option', 'height', jQuery(window).height() - 20);
			}
		},
		buttons: backupplus_upload_modal_buttons
	});

	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', 'button.backupplus-upload-link', function (e) {
		e.preventDefault();
		var nonce = jQuery(this).data('nonce').toString();
		var key = jQuery(this).data('key').toString();
		var services = jQuery(this).data('services').toString();
		if (nonce) {
			backupplus_upload(key, nonce, services);
		} else {
			console.log("BackupPlus: A upload link was clicked, but the Job ID could not be found");
		}
	});

	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', '.backupplus-load-more-backups', function (e) {
		e.preventDefault();
		var backup_count = parseInt(jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').length) + parseInt(backupplus_i18n.existing_backups_limit);
		backupplus_updatehistory(0, 0, 0, backup_count);
	});

	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', '.backupplus-load-all-backups', function (e) {
		e.preventDefault();
		backupplus_updatehistory(0, 0, 0, 9999999);
	});

	/**
	 * Opens the dialog box for confirmation of where to upload the backup
	 *
	 * @param {string}  key        - The UNIX timestamp of the backup
	 * @param {string}  nonce      - The backup job ID
	 * @param {string}  services   - A list of services that have not been uploaded to yet
	 */
	function backupplus_upload(key, nonce, services) {
		jQuery('#backupplus_upload_timestamp').val(key);
		jQuery('#backupplus_upload_nonce').val(nonce);
		var services_array = services.split(",");
		jQuery('.backupplus_remote_storage_destination').each(function (index) {
			var name = jQuery(this).val();
			if (jQuery.inArray(name, services_array) == -1) {
				jQuery(this).prop('checked', false);
				jQuery(this).prop('disabled', true);
				var label = $(this).prop("labels");
				jQuery(label).find('span').show();
			}
		});
		jQuery('#backupplus-upload-modal').dialog('open');
	}
	
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', '.backupplus-delete-link', function(e) {
		e.preventDefault();
		var hasremote = jQuery(this).data('hasremote');
		var nonce = jQuery(this).data('nonce').toString();
		var key = jQuery(this).data('key').toString();
		var chain = jQuery(this).data('chain');
		if (nonce) {
			backupplus_delete(key, nonce, hasremote, chain ? [chain] : [], jQuery(this).data('remotemissing') ? 1 : 0);
		} else {
			console.log("BackupPlus: A delete link was clicked, but the Job ID could not be found");
		}
	});
	
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', 'button.backupplus_chain_download_button', function(e) {
		e.preventDefault();
		backupplus_chain_downloads_open(jQuery(this).data('backup_timestamp'), jQuery(this).data('what'), jQuery(this).data('title'), jQuery(this).data('prettydate'));
	});

	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', 'button.backupplus_download_button', function(e) {
		e.preventDefault();
		var base = 'uddlstatus_';
		var backup_timestamp = jQuery(this).data('backup_timestamp');
		var what = jQuery(this).data('what');
		var whicharea = '.ud_downloadstatus';
		var set_contents = jQuery(this).data('set_contents');
		var prettydate = jQuery(this).data('prettydate');
		var async = true;
		backupplus_downloader(base, backup_timestamp, what, whicharea, set_contents, prettydate, async, jQuery(this).data('download_files'));
	});
});
