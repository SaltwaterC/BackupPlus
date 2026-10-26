// Dialog setup: the message, PITR setup and delete backup sets modals.

jQuery(function($) {
	var bigbutton_width = 180;
	jQuery('.backupplus-bigbutton').each(function(x,y) {
		var bwid = jQuery(y).width();
		if (bwid > bigbutton_width) bigbutton_width = bwid;
	});
	if (bigbutton_width > 180) jQuery('.backupplus-bigbutton').width(bigbutton_width);

	if (jQuery('#backupplus-navtab-backups-content').length) {
		// setTimeout(function(){backupplus_showlastlog(true);}, 1200);
		setInterval(function() {
			backupplus_activejobs_update(false);}, 1250);
	}
	
	// Prevent profusion of notices
	setTimeout(function() {
		jQuery('#setting-error-settings_updated').slideUp();}, 5000);
	
	jQuery('#backupplus_restore_db').on('change', function() {
		if (jQuery('#backupplus_restore_db').is(':checked') && 1 == jQuery(this).data('encrypted')) {
			jQuery('#backupplus_restorer_dboptions').slideDown();
		} else {
			jQuery('#backupplus_restorer_dboptions').slideUp();
		}
	});

	backupplus_check_same_times();

	var backupplus_message_modal_buttons = {};
	backupplus_message_modal_buttons[backupplus_i18n.close] = function() {
		 jQuery(this).dialog("close");
	};
	jQuery("#backupplus-message-modal").dialog({
		autoOpen: false, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).dialog('option', 'width', 520);
			$(this).dialog('option', 'minHeight', 260);
			if ($(window).height() > 360 ) {
				$(this).dialog('option', 'height', 360);
			} else {
				$(this).dialog('option', 'height', $(window).height()-30);
			}
		},
		modal: true,
		buttons: backupplus_message_modal_buttons
	});
	
	var backupplus_delete_modal_buttons = {};
	var pitr_setup_busy = false;
	var pitr_setup_modal = jQuery('#backupplus-pitr-setup-modal').dialog({
		autoOpen: false, modal: true, closeOnEscape: true,
		width: 720, height: 'auto', minWidth: 0, minHeight: 0,
		useAnimation: false, resizeAccordingToViewport: false,
		open: function() { pitr_setup_size(); },
		close: function() { jQuery('#backupplus-pitr-setup-user,#backupplus-pitr-setup-password').val(''); }
	});
	function pitr_setup_size() {
		if (!pitr_setup_modal.dialog('isOpen')) return;
		pitr_setup_modal.dialog('option', {
			width: Math.min(720, Math.max(0, jQuery(window).width() - 32)),
			height: 'auto', maxHeight: Math.max(0, jQuery(window).height() - 40),
			position: {my: 'center', at: 'center', of: window}
		});
	}
	jQuery(window).on('resize.pitrSetup', pitr_setup_size);
	function pitr_setup_request(operation) {
		if (pitr_setup_busy) return;
		pitr_setup_busy = true;
		var status = jQuery('#backupplus-pitr-setup-status');
		var request = {operation:operation};
		// Check access tests typed credentials without saving them; with empty fields it tests the account capture uses.
		if (operation === 'connect' || (operation === 'status' && jQuery('#backupplus-pitr-setup-user').val() !== '')) {
			request.username = jQuery('#backupplus-pitr-setup-user').val();
			request.password = jQuery('#backupplus-pitr-setup-password').val();
		}
		pitr_setup_modal.find('button,input').prop('disabled',true);
		// Keep keyboard events inside the dialog when disabling the focused input.
		pitr_setup_modal.dialog('widget').trigger('focus');
		status.text(backupplus_i18n.pitr_setup_working);
		function finish(result) {
			result = result || {};
			delete request.password;
			delete request.username;
			pitr_setup_busy = false;
			pitr_setup_modal.find('button,input').prop('disabled',false);
			status.text(result.error || result.message || backupplus_i18n.emptyresponse);
			// A saved choice is reported beside the Backup account button; closing clears the typed login.
			// Failures and checks keep the dialog and its fields open for correction.
			if ((operation === 'connect' || operation === 'wordpress') && result.ready) {
				jQuery('#backupplus-pitr-setup-summary').text(result.message);
				pitr_setup_modal.dialog('close');
				return;
			}
			pitr_setup_size();
		}
		backupplus_send_command('pitr_setup',request,finish,{alert_on_error:false,error_callback:function(){finish({error:backupplus_i18n.pitr_setup_failed});}});
	}
	jQuery(document).on('click','#backupplus-pitr-setup-open',function(){pitr_setup_modal.dialog('open');pitr_setup_request('status');});
	jQuery('#backupplus-pitr-setup-wordpress').on('click',function(){pitr_setup_request('wordpress');});
	jQuery('#backupplus-pitr-setup-connect').on('click',function(){pitr_setup_request('connect');});
	jQuery('#backupplus-pitr-setup-check').on('click',function(){pitr_setup_request('status');});
	jQuery('#backupplus-pitr-setup-close').on('click',function(){jQuery('#backupplus-pitr-setup-user,#backupplus-pitr-setup-password').val('');pitr_setup_modal.dialog('close');});
	backupplus_delete_modal_buttons[backupplus_i18n.deletebutton] = function() {
		backupplus_remove_backup_sets(0, 0, 0, 0, [], false);
	};


	/**
	 * Perform a manual backup sets deletion
	 *
	 * @param {integer} deleted_counter        - The total number of local and remote files that have successfully been removed already. This and the next three parameters are used recursively so that the final total can be displayed to the user in a display message.
	 * @param {integer} backup_local           - The total number of local files that have been removed
	 * @param {integer} backup_remote          - The total number of remote files that have successfully been removed
	 * @param {integer} backup_sets            - The total number of backup sets that have been deleted
	 * @param {array}   processed_instance_ids - An array that contains a group of storage instance IDs that have been processed
	 * @param {boolean} is_continuation        - Whether or not the backup sets deletion is a continuation of an ongoing deletion
	 */
	function backupplus_remove_backup_sets(deleted_counter, backup_local, backup_remote, backup_sets, processed_instance_ids, is_continuation) {
		jQuery("#backupplus-delete-modal").dialog('close');
		var deleted_files_counter = deleted_counter;
		var local_deleted = backup_local;
		var remote_deleted = backup_remote;
		var sets_deleted = backup_sets;
		var error_log_prompt = '';
		
		var form_data = jQuery('#backupplus_delete_form').serializeArray();
		var data = {};

		$.each(form_data, function() {
			if (undefined !== data[this.name]) {
				if (!data[this.name].push) {
					data[this.name] = [data[this.name]];
				}
				data[this.name].push(this.value || '');
			} else {
				data[this.name] = this.value || '';
			}
		});

		if (data.delete_remote) {
			jQuery('#backupplus-delete-waitwarning').find('.backupplus-deleting-remote').show();
		} else {
			jQuery('#backupplus-delete-waitwarning').find('.backupplus-deleting-remote').hide();
		}
		
		jQuery('#backupplus-delete-waitwarning').slideDown().addClass('active');

		data.remote_delete_limit = backupplus_i18n.remote_delete_limit;
		data.processed_instance_ids = processed_instance_ids;
		data.is_continuation = is_continuation;
		
		delete data.action;
		delete data.subaction;
		delete data.nonce;
		
		backupplus_send_command('deleteset', data, function(resp) {
			if (!resp.hasOwnProperty('result') || resp.result == null) {
				jQuery('#backupplus-delete-waitwarning').slideUp();
				return;
			}
			if (resp.result == 'error') {
				jQuery('#backupplus-delete-waitwarning').slideUp();
				backupplus_history_lastchecksum = false;
				backupplus_updatehistory(0, 0);
				alert(backupplus_i18n.error+' '+resp.message);
			} else if (resp.result == 'continue') {
				deleted_files_counter = deleted_files_counter + resp.backup_local + resp.backup_remote;
				local_deleted = local_deleted + resp.backup_local;
				remote_deleted = remote_deleted + resp.backup_remote;
				sets_deleted = sets_deleted + resp.backup_sets;
				var deleted_timestamps = resp.deleted_timestamps.split(',');
				for (var i = 0; i < deleted_timestamps.length; i++) {
					var timestamp = deleted_timestamps[i];
					jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups_row_' + timestamp).slideUp().remove();
				}
				jQuery('#backupplus_delete_timestamp').val(resp.timestamps);
				jQuery('#backupplus-deleted-files-total').text(deleted_files_counter + ' ' + backupplus_i18n.remote_files_deleted);
				backupplus_remove_backup_sets(deleted_files_counter, local_deleted, remote_deleted, sets_deleted, resp.processed_instance_ids, true);
			} else if (resp.result == 'success') {

				setTimeout(function() {
					jQuery('#backupplus-deleted-files-total').text('');
					jQuery('#backupplus-delete-waitwarning').slideUp();
				}, 500);
				
				update_backupnow_modal(resp);

				if (resp.hasOwnProperty('backupnow_file_entities')) {
					impossible_increment_entities = resp.backupnow_file_entities;
				}

				if (resp.hasOwnProperty('count_backups')) {
					jQuery('#backupplus-existing-backups-heading').html(backupplus_i18n.existing_backups+' <span class="backupplus_existing_backups_count">'+resp.count_backups+'</span>');
				}
				var deleted_timestamps = resp.deleted_timestamps.split(',');
				for (var i = 0; i < deleted_timestamps.length; i++) {
					var timestamp = deleted_timestamps[i];
					jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups_row_' + timestamp).slideUp().remove();
				}

				backupplus_backups_selection.checkSelectionStatus();

				backupplus_history_lastchecksum = false;

				local_deleted = local_deleted + resp.backup_local;
				remote_deleted = remote_deleted + resp.backup_remote;
				sets_deleted = sets_deleted + resp.backup_sets;
				if ('' != resp.error_messages) {
					error_log_prompt = backupplus_i18n.delete_error_log_prompt;
				}
					
				setTimeout(function() {
					alert(resp.set_message + " " + sets_deleted + "\n" + resp.local_message + " " + local_deleted + "\n" + resp.remote_message + " " + remote_deleted + "\n\n" + resp.error_messages + "\n" + error_log_prompt);
				}, 900);
			}
		});
	};

	backupplus_delete_modal_buttons[backupplus_i18n.cancel] = function() {
 jQuery(this).dialog("close"); };
	jQuery("#backupplus-delete-modal").dialog({
		autoOpen: false, width: 560, height: 360, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).css('minHeight', 83);
		},
		modal: true,
		buttons: backupplus_delete_modal_buttons
	});
});
