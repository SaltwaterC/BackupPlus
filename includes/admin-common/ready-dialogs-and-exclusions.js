// Backup now, log and iframe dialogs, the settings page exclusion fields and the exclude modal.

jQuery(function($) {
	var original_restore_main_activity_width = $('.backupplus_restore_main--activity').width();
	jQuery('#activity-full-log').on('click', function() {
		var activity_log_max_width = $('.backupplus_restore_main').css('max-width') == '1460px' ? '860px' : '1460px';
		var restore_main_activity_width = $('.backupplus_restore_main--activity').width() == original_restore_main_activity_width ? '100%' : original_restore_main_activity_width + 'px';
		var activity_log_max_height = $('.backupplus_restore_main--activity').css('min-height') == '600px' ? '0px' : '600px';
		var activity_log_icon_title = $('#activity-full-log').attr('title') == backupplus_i18n.restoreactivitylogscreenexit ? backupplus_i18n.restoreactivitylogfullscreen : backupplus_i18n.restoreactivitylogscreenexit;
		$('#activity-full-log').toggleClass('dashicons-fullscreen-exit-alt');
		$('#activity-full-log').attr('title', activity_log_icon_title);
		$('.backupplus_restore_main--components').toggle('fast');
		$('.backupplus_restore_main--header').toggle('fast');
		$('.backupplus_restore_main--activity').animate({minHeight: activity_log_max_height, width: restore_main_activity_width});
		$('.backupplus_restore_main').animate({maxWidth: activity_log_max_width});
	});
	
	jQuery("#backupplus-iframe-modal").dialog({
		autoOpen: false, height: 500, width: 780, modal: true
	});

	jQuery("#backupplus-backupnow-inpage-modal").dialog({
		autoOpen: false, modal: true, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).dialog('option', 'width', 580);
			$(this).dialog('option', 'minHeight', 261);
			$(this).dialog('option', 'height', 380);
		},
	});
	
	var backupnow_modal_buttons = {};
	jQuery('#backupnow_includedb, #backupnow_includefiles').on('change', backupplus_backupnow_update_selection);
	backupnow_modal_buttons[backupplus_i18n.backupnow] = function() {

		var backupnow_nodb = jQuery('#backupnow_includedb').is(':checked') ? 0 : 1;
		var backupnow_nofiles = jQuery('#backupnow_includefiles').is(':checked') ? 0 : 1;
		var backupnow_nocloud = jQuery('#backupnow_includecloud').is(':checked') ? 0 : 1;
		var db_anon_all = jQuery('#backupnow_db_anon_all').is(':checked') ? 1 : 0;
		var db_anon_non_staff = jQuery('#backupnow_db_anon_non_staff').is(':checked') ? 1 : 0;
		var db_anon_wc_orders = jQuery('#backupnow_db_anon_wc_order_data').is(':checked') ? 1 : 0;
		var pitr_capture = backupplus_backupnow_pitr_selected();
		var onlythesetableentities = pitr_capture ? null : backupnow_whichtables_checked('');
		if (!pitr_capture && window.backupplus_database_override && !backupplus_database_override() && !backupnow_nodb) { alert(backupplus_i18n.notableschosen); return; }
		var always_keep = jQuery('#always_keep').is(':checked') ? 1 : 0;
		var incremental = ('incremental' == jQuery('#backupplus-backupnow-modal').data('backup-type')) ? 1 : 0;

		if (backupplus_i18n.hosting_restriction.includes('only_one_backup_per_month') && !incremental) {
			alert(backupplus_i18n.hosting_restriction_one_backup_permonth);
			return;
		}

		if (backupplus_i18n.hosting_restriction.includes('only_one_incremental_per_day') && incremental) {
			alert(backupplus_i18n.hosting_restriction_one_incremental_perday);
			return;
		}

		if ('' == onlythesetableentities && 0 == backupnow_nodb) {
			alert(backupplus_i18n.notableschosen);
			jQuery('#backupnow_database_moreoptions').show();
			return;
		}

		if (typeof onlythesetableentities === 'boolean') {
			onlythesetableentities = null;
		}

		var onlythesefileentities = backupnow_whichfiles_checked('');

		if ('' == onlythesefileentities && 0 == backupnow_nofiles) {
			alert(backupplus_i18n.nofileschosen);
			jQuery('#backupnow_includefiles_moreoptions').show();
			return;
		}

		var only_these_cloud_services = jQuery("input[name^='backupplus_include_remote_service_']").serializeArray();

		if ('' == only_these_cloud_services && 0 == backupnow_nocloud && !(pitr_capture && backupnow_nofiles)) {
			alert(backupplus_i18n.nocloudserviceschosen);
			jQuery('#backupnow_includecloud_moreoptions').show();
			return;
		}

		if (typeof only_these_cloud_services === 'boolean') {
			only_these_cloud_services = null;
		}
		
		if (backupnow_nodb && backupnow_nofiles) {
			alert(backupplus_i18n.excludedeverything);
			return;
		}
		
		jQuery(this).dialog("close");

		setTimeout(function() {
			jQuery('#backupplus_lastlogmessagerow').fadeOut('slow', function() {
				jQuery(this).fadeIn('slow');
			});
		}, 1700);
	
		backupplus_backupnow_go(backupnow_nodb, backupnow_nofiles, backupnow_nocloud, onlythesefileentities, {always_keep: always_keep, incremental: incremental, db_anon: { all: db_anon_all, non_staff: db_anon_non_staff, wc_orders: db_anon_wc_orders }}, jQuery('#backupnow_label').val(), onlythesetableentities, only_these_cloud_services);
	};
	backupnow_modal_buttons[backupplus_i18n.cancel] = function() {
	jQuery(this).dialog("close"); };

	jQuery("#backupplus-backupnow-modal").dialog({
		autoOpen: false, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).dialog('option', 'width', 610);
			$(this).dialog('option', 'minHeight', 300);
			$(this).dialog('option', 'height', 472);
		},
		modal: true,
		buttons: backupnow_modal_buttons,
		create: function () {
			$(this).closest(".ui-dialog")
				.find(".ui-dialog-buttonpane .ui-button").first() // the first button
				.addClass("js-tour-backup-now-button");
		}
	});

	jQuery("#backupplus-poplog").dialog({
		autoOpen: false, modal: true, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			$(this).dialog('option', 'width', 860);
			$(this).dialog('option', 'minHeight', 260);
			if ($(window).height() > 600) {
				$(this).dialog('option', 'height', 600);
			} else {
				$(this).dialog('option', 'height', $(window).height()-50);
			}
		},
	});
	
	jQuery('#backupplus-navtab-settings-content .enableexpertmode').on('click', function() {
		jQuery('#backupplus-navtab-settings-content .expertmode').fadeIn();
		jQuery('#backupplus-navtab-settings-content .enableexpertmode').off('click');
		return false;
	});
	
	jQuery('#backupplus-navtab-settings-content .backupdirrow').on('click', 'a.backupplus_backup_dir_reset', function() {
		jQuery('#backupplus_dir').val(''); return false;
	});

	function setup_file_entity_exclude_field(field, instant) {
		if (jQuery('#backupplus-navtab-settings-content #backupplus_include_'+field).is(':checked')) {
			if (instant) {
				jQuery('#backupplus-navtab-settings-content #backupplus_include_'+field+'_exclude_container').show();
			} else {
				jQuery('#backupplus-navtab-settings-content #backupplus_include_'+field+'_exclude_container').slideDown();
			}
		} else {
			if (instant) {
				jQuery('#backupplus-navtab-settings-content #backupplus_include_'+field+'_exclude').hide();
			} else {
				jQuery('#backupplus-navtab-settings-content #backupplus_include_'+field+'_exclude_container').slideUp();
			}
		}
	}
	
	jQuery('#backupplus-navtab-settings-content .backupplus_include_entity').on('click', function() {
		var has_exclude_field = jQuery(this).data('toggle_exclude_field');
		if (has_exclude_field) {
			setup_file_entity_exclude_field(has_exclude_field, false);
		}
	});
	
	jQuery('.backupplus_exclude_entity_container').on('click', '.backupplus_exclude_entity_delete', function(event) {
		event.preventDefault();
		if (!confirm(backupplus_i18n.exclude_rule_remove_conformation_msg))  return;
		
		var include_entity_name = jQuery(this).data('include-backup-file');
		jQuery.when(
			jQuery(this).closest('.backupplus_exclude_entity_wrapper').remove()
		).then(
			backupplus_exclude_entity_update(include_entity_name)
		);
	});
	
	jQuery('.backupplus_exclude_entity_container').on('click', '.backupplus_exclude_entity_edit', function(event) {
		event.preventDefault();
		var wrapper = jQuery(this).hide().closest('.backupplus_exclude_entity_wrapper');
		var input = wrapper.find('input');
		input.prop('readonly', false).trigger('focus');

		// place caret at the end of the text
		var input_val = input.val();
		input.val('');
		input.val(input_val);

		wrapper.find('.backupplus_exclude_entity_update').addClass('is-active').show();
	});
	
	jQuery('.backupplus_exclude_entity_container').on('click', '.backupplus_exclude_entity_update', function(event) {
		event.preventDefault();
		var wrapper = jQuery(this).closest('.backupplus_exclude_entity_wrapper');
		var include_backup_file = jQuery(this).data('include-backup-file')
		var exclude_item_val = wrapper.find('input').val().trim();
		
		var should_be_updated = false;
		if (exclude_item_val == wrapper.find('input').data('val')) {
			should_be_updated = true;
		} else if (backupplus_is_unique_exclude_rule(exclude_item_val, include_backup_file)) {
			should_be_updated = true;
		}
		
		if (should_be_updated) {
			jQuery(this).hide().removeClass('is-active');
			jQuery.when(
				wrapper.find('input').prop('readonly', 'readonly').data('val', exclude_item_val)
			).then(function() {
				wrapper.find('.backupplus_exclude_entity_edit').show();
				backupplus_exclude_entity_update(include_backup_file);
			});
		}
	});
	
	jQuery('#backupplus_exclude_modal').dialog({
		autoOpen: false, modal: true, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event,ui) {
			$(this).parent().trigger('focus');
			$(this).dialog('option', 'width', 520);
			$(this).dialog('option', 'minHeight', 260);
			if ($(window).height() > 579) {
				$(this).css('height', 'auto');
			} else if ($(window).height() < 580 && $(window).height() > 410) {
				$(this).dialog('option', 'height', 410);
				$(this).css('height', 'auto');
			} else {
				$(this).dialog('option', 'height', $(window).height()-20);
			}
		}
	});
	
	jQuery('#backupplus_include_others_exclude_container, #backupplus_include_uploads_exclude_container, .backupplus_exclude_container').on('click', 'a.backupplus_add_exclude_item', function(event) {
		event.preventDefault();
		var backup_entity = jQuery(this).data('include-backup-file');
		jQuery('#backupplus_exclude_modal_for').val(backup_entity);
		jQuery('#backupplus_exclude_modal_path').val(jQuery(this).data('path'));
		if ('uploads' == backup_entity) {
			jQuery('#backupplus-exclude-file-dir-prefix').html(jQuery('#backupplus-exclude-upload-base-dir').val());
		}
		jQuery('.backupplus-exclude-modal-reset').trigger('click');
		jQuery('#backupplus_exclude_modal').dialog('open');
	});
	
	jQuery('.backupplus-exclude-link').on('click', function(event) {
		event.preventDefault();
		var panel = jQuery(this).data('panel');
		if ('file-dir' == panel) {
			jQuery('#backupplus_exclude_files_folders_jstree').jstree({
				"core": {
					"multiple": false,
					"data": function (nodeid, callback) {
						backupplus_send_command('get_jstree_directory_nodes', {entity: 'filebrowser', node:nodeid, path: jQuery('#backupplus_exclude_modal_path').val(), findex: 0, skip_root_node: true}, function(response) {
						if (response.hasOwnProperty('error')) {
									alert(response.error);
													   } else {
		 callback.call(this, response.nodes);
													   }
						}, { error_callback: function(response, status, error_code, resp) {
								if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
								console.error(resp.fatal_error_message);
								jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+resp.fatal_error_message+'</p>');
								alert(resp.fatal_error_message);
									} else {
								var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
								jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+error_message+'</p>');
								console.log(error_message);
								alert(error_message);
								console.log(response);
									}
								}
						});
					},
					"error": function(error) {
						alert(error);
						console.log(error);
					},
				},
				"search": {
					"show_only_matches": true
				},
				"plugins": ["sort"],
			});
		} else if ('contain-clause' == panel) {
			jQuery('#backupplus_exclude_files_folders_wildcards_jstree').jstree({
				"core": {
					"multiple": false,
					"data": function (nodeid, callback) {
						backupplus_send_command('get_jstree_directory_nodes', {entity: 'filebrowser', directories_only: 1, node:nodeid, path: jQuery('#backupplus_exclude_modal_path').val(), findex: 0, skip_root_node: 0}, function(response) {
						if (response.hasOwnProperty('error')) {
									alert(response.error);
													   } else {
		 callback.call(this, response.nodes);
													   }
						}, { error_callback: function(response, status, error_code, resp) {
								if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
								console.error(resp.fatal_error_message);
								jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+resp.fatal_error_message+'</p>');
								alert(resp.fatal_error_message);
									} else {
								var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
								jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+error_message+'</p>');
								console.log(error_message);
								alert(error_message);
								console.log(response);
									}
								}
						});
					},
					"error": function(error) {
						alert(error);
						console.log(error);
					},
				},
				"search": {
					"show_only_matches": true
				},
				"plugins": ["sort"],
			});
		}
		jQuery('#backupplus_exclude_modal_main').slideUp();
		jQuery('.backupplus-exclude-panel').hide();
		jQuery('.backupplus-exclude-panel[data-panel='+panel+']').slideDown();
	});
	
	jQuery('.backupplus-exclude-modal-reset').on('click', function(event) {
		event.preventDefault();
		jQuery('#backupplus_exclude_files_folders_jstree').jstree("destroy");
		jQuery('#backupplus_exclude_files_folders_wildcards_jstree').jstree("destroy");
		jQuery('#backupplus_exclude_extension_field').val('');
		jQuery('#backupplus_exclude_prefix_field').val('');
		jQuery('.backupplus-exclude-panel').slideUp();
		jQuery('#backupplus_exclude_modal_main').slideDown();
	});
	
	jQuery('.backupplus-exclude-submit').on('click', function() {
		var panel = jQuery(this).data('panel');
		var exclude_item_val = '';
		switch (panel) {
			case 'file-dir':
			var exclude_jstree_selected = jQuery("#backupplus_exclude_files_folders_jstree").jstree("get_selected");
			if (0 == exclude_jstree_selected.length) {
					alert(backupplus_i18n.exclude_select_file_or_folder_msg);
					return;
				}
			var selected_file_or_folder = exclude_jstree_selected[0];
			var prefix_path = jQuery('#backupplus_exclude_modal_path').val();
			if (selected_file_or_folder.substr(0, prefix_path.length) == prefix_path) {
					selected_file_or_folder = selected_file_or_folder.substr(prefix_path.length, selected_file_or_folder.length);
				}
			if ('/' == selected_file_or_folder.charAt(0))  selected_file_or_folder = selected_file_or_folder.substr(1);
			if ('/' == selected_file_or_folder.charAt(selected_file_or_folder.length - 1))  selected_file_or_folder = selected_file_or_folder.substr(0, selected_file_or_folder.length - 1);
			exclude_item_val = selected_file_or_folder;
				break;
				
			case 'extension':
			var exclude_extension = jQuery('#backupplus_exclude_extension_field').val();
			if ('' == exclude_extension) {
					alert(backupplus_i18n.exclude_type_ext_msg);
					return;
				}
			if (!exclude_extension.match(/^[0-9a-zA-Z]+$/)) {
					alert(backupplus_i18n.exclude_ext_error_msg);
					return;
				}
			exclude_item_val = 'ext:'+exclude_extension;
				break;
				
			case 'begin-with':
			var prefix = jQuery('#backupplus_exclude_prefix_field').val();
			if ('' == prefix) {
					alert(backupplus_i18n.exclude_type_prefix_msg);
					return;
				}
			if (!prefix.match(/^\s*[a-z-_\d,\s]+\s*$/i)) {
					alert(backupplus_i18n.exclude_prefix_error_msg);
					return;
				}
			exclude_item_val = 'prefix:'+prefix;
				break;
				
			case 'contain-clause':
				var exclude_jstree_selected = jQuery("#backupplus_exclude_files_folders_wildcards_jstree").jstree("get_selected");
				if (0 == exclude_jstree_selected.length) {
					alert(backupplus_i18n.exclude_select_folder_wildcards_msg);
					return;
				}
				var clause_val = jQuery(this).parents('div.backupplus-exclude-panel').find('div.clause-input-container input').val();
				jQuery(this).parents('div.backupplus-exclude-panel').find('div.clause-input-container input').val('');
				var clause_type = jQuery(this).parents('div.backupplus-exclude-panel').find('div.clause-input-container select').val();
				if ('' == clause_val) {
					alert(backupplus_i18n.exclude_contain_error_msg);
					return;
				}
				jQuery(this).parents('div.backupplus-exclude-panel').find('div.clause-input-container select option').eq(0).prop('selected', true);
				var selected_file_or_folder = exclude_jstree_selected[0];
				var prefix_path = jQuery('#backupplus_exclude_modal_path').val();
				if (selected_file_or_folder.substr(0, prefix_path.length) == prefix_path) {
						selected_file_or_folder = selected_file_or_folder.substr(prefix_path.length, selected_file_or_folder.length);
				}
				if ('/' == selected_file_or_folder.charAt(0))  selected_file_or_folder = selected_file_or_folder.substr(1);
				if ('/' == selected_file_or_folder.charAt(selected_file_or_folder.length - 1))  selected_file_or_folder = selected_file_or_folder.substr(0, selected_file_or_folder.length - 1);
				exclude_item_val = selected_file_or_folder;
				if ('' !== exclude_item_val) exclude_item_val += '/';
				clause_val = clause_val.replace(/\*/g, '\\*');
				if ('beginning' === clause_type) {
					exclude_item_val += clause_val + '*';
				} else if ('middle' === clause_type) {
					exclude_item_val += '*' + clause_val + '*';
				} else if ('end' === clause_type) {
					exclude_item_val += '*' + clause_val;
				}
				break;
		
			default:
				return;
		}
		
		var include_backup_file = jQuery('#backupplus_exclude_modal_for').val();
		if (!backupplus_is_unique_exclude_rule(exclude_item_val, include_backup_file))  return;
		
		var exclude_entity_html = '<div class="backupplus_exclude_entity_wrapper"><input type="text" class="backupplus_exclude_entity_field backupplus_include_' + include_backup_file + '_exclude_entity" name="backupplus_include_' + include_backup_file + '_exclude_entity[]" value="' + exclude_item_val + '" data-val="' + exclude_item_val + '" data-include-backup-file="' + include_backup_file + '" readonly="readonly"><a href="#" class="backupplus_exclude_entity_edit dashicons dashicons-edit" data-include-backup-file="' + include_backup_file + '"></a><a href="#" class="backupplus_exclude_entity_update dashicons dashicons-yes" data-include-backup-file="' + include_backup_file + '" style="display: none;"></a><a href="#" class="backupplus_exclude_entity_delete dashicons dashicons-no" data-include-backup-file="' + include_backup_file + '"></a></div>';
		jQuery('.backupplus_exclude_entity_container[data-include-backup-file="' + include_backup_file + '"]').append(exclude_entity_html);
		backupplus_exclude_entity_update(include_backup_file);
		jQuery('#backupplus_exclude_modal').dialog('close');
	});
});
