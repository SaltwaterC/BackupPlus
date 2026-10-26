// Restore dialog options and heartbeat parameters.

// For When character set and collate both are unsupported at restoration time and if user change anyone substitution dropdown from both, Other substitution select box value should be change respectively.
jQuery(function($) {
	jQuery('#backupplus-restore-modal').on('change', '#backupplus_restorer_charset', function(e) {
		if ($('#backupplus_restorer_charset').length && $('#backupplus_restorer_collate').length && $('#collate_change_on_charset_selection_data').length) {
			var backupplus_restorer_charset = $('#backupplus_restorer_charset').val();
			// For only show collate which are related to charset
			$('#backupplus_restorer_collate option').show();
			$('#backupplus_restorer_collate option[data-charset!='+backupplus_restorer_charset+']').hide();
			backupplus_send_command('collate_change_on_charset_selection', {
				collate_change_on_charset_selection_data: $('#collate_change_on_charset_selection_data').val(),
				backupplus_restorer_charset: backupplus_restorer_charset,
				backupplus_restorer_collate: $('#backupplus_restorer_collate').val(),
			}, function(response) {
				if (response.hasOwnProperty('is_action_required') && 1 == response.is_action_required && response.hasOwnProperty('similar_type_collate')) {
					$('#backupplus_restorer_collate').val(response.similar_type_collate);
				}
			});
		}
	});

	jQuery('#backupplus-restore-modal').on('click', '#backupplus_restore_tables_showmoreoptions', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_tables_options_container').toggle();
	});

	jQuery('#backupplus-restore-modal').on('click', '#backupplus_restore_plugins_showmoreoptions', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_plugins_options_container').toggle();
	});

	jQuery('#backupplus-restore-modal').on('click', '#backupplus_restore_themes_showmoreoptions', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_themes_options_container').toggle();
	});

	jQuery('#backupplus-restore-modal').on('click', '.backupplus-select-all-tables', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_tables_options:not(:disabled)').prop('checked', true);
	});

	jQuery('#backupplus-restore-modal').on('click', '.backupplus-deselect-all-tables', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_tables_options:not(:disabled)').prop('checked', false);
	});

	var last_checked = null;

	jQuery('#backupplus-restore-modal').on('click', '.backupplus_restore_tables_options', function(e) {
		if (!last_checked) {
			last_checked = this;
			return;
		}
	
		if (e.shiftKey) {
			var start = jQuery('.backupplus_restore_tables_options').index(this);
			var end = jQuery('.backupplus_restore_tables_options').index(last_checked);
	
			jQuery('.backupplus_restore_tables_options').slice(Math.min(start, end), Math.max(start, end)+1).prop('checked', last_checked.checked);
	
		}
	
		last_checked = this;
	});

	var heartbeat_last_parameters = {};
	
	$(document).on('heartbeat-send', function(event, heartbeat_data) {
		heartbeat_last_parameters = backupplus_poll_get_parameters();
		heartbeat_data.backupplus = heartbeat_last_parameters;
	});

	$(document).on('heartbeat-tick', function(event, heartbeat_data) {
		if (null === heartbeat_data || !heartbeat_data.hasOwnProperty('backupplus') || null == heartbeat_data.backupplus) return;
		var resp = heartbeat_data.backupplus;
		var response_raw = JSON.stringify(resp);
		// We do somewhat assume that there can't be overlapping heartbeat calls - they should be far enough apart to make that very unlikely (and even if it happened, it is unlikely to cause any trouble)
		backupplus_process_status_check(resp, response_raw, heartbeat_last_parameters);
		if (!heartbeat_data.backupplus.hasOwnProperty('time_now')) return;
		// Set the 'Time Now' status in the UI to the current time
		jQuery('body.settings_page_backupplus #backupplus-navtab-backups-content .backupplus_time_now_wrapper .backupplus_time_now').empty().html(heartbeat_data.backupplus.time_now);
	});
});
