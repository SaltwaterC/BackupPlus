// Event handlers for the settings page, remote storage instances and the backups tab.

jQuery(function($) {
	
	// Advanced settings new menu button listeners
	$('.expertmode .advanced_settings_container .advanced_tools_button').on('click', function() {
		advanced_tool_hide($(this).attr("id"));
	});
	
	function advanced_tool_hide(show_tool) {
		
		$('.expertmode .advanced_settings_container .advanced_tools:not(".'+show_tool+'")').hide();
		$('.expertmode .advanced_settings_container .advanced_tools.'+show_tool).fadeIn('slow');
		
		$('.expertmode .advanced_settings_container .advanced_tools_button:not(#'+show_tool+')').removeClass('active');
		$('.expertmode .advanced_settings_container .advanced_tools_button#'+show_tool).addClass('active');
		
	}
	// https://github.com/select2/select2/issues/1246#issuecomment-71710835
	if (jQuery.ui && jQuery.ui.dialog && jQuery.ui.dialog.prototype._allowInteraction) {
		var ui_dialog_interaction = jQuery.ui.dialog.prototype._allowInteraction;
		jQuery.ui.dialog.prototype._allowInteraction = function(e) {
			if (jQuery(e.target).closest('.select2-dropdown').length) return true;
					   return ui_dialog_interaction.apply(this, arguments);
		};
	}

	// Check SFTP and FTP host as user edits
	$('#backupplus-navtab-settings-content #remote-storage-holder').on('input', '.backupplus_sftp_host_settings, .backupplus_ftp_host_settings', function() {
		var notice_selector = $(this).closest('tr').find('[class^="backupplus_"][class$="_host_error"]');

		notice_selector.hide();
		if ($(this).hasClass('backupplus_ftp_host_settings')) {
			var ftp_address = $(this).val().trim().match(/^ftp(es|s)?:\/\/(.*)$/i);
			if (ftp_address) {
				if (ftp_address[1]) {
					$(document.getElementById(this.id.replace('_host_', '_encryption_'))).val('tls').trigger('change');
				}
				$(this).val(ftp_address[2].replace(/\/+$/, ''));
			}
		}
		if (/\/|^https?:/.test($(this).val())) {
			$(this).val($(this).val().replace(/^https?:|\/+/g, ''));
			notice_selector.show();
		}
	});
	
	// Update WebDAV URL as user edits
	$('#backupplus-navtab-settings-content #remote-storage-holder').on('change keyup paste', '.backupplus_webdav_settings', function() {
		var attr_id = $(this).attr('id');
		if ('string' !== typeof attr_id) return;
		var parsed_attr_id = attr_id.match(/backupplus_webdav_(.+)_(s-[^_]+)$/i);
		if (!parsed_attr_id || !parsed_attr_id[1] || !parsed_attr_id[2]) return;
		var instance_id = parsed_attr_id[2],
			webdav_input = parsed_attr_id[1].toLowerCase(),
			masked_password = '';
		
		if ('host' === webdav_input) {
			$('.webdav-'+instance_id+' .backupplus_webdav_host_error').hide();
			if ($(this).val().indexOf("@") >= 0) {
				$(this).val($(this).val().replaceAll('@', encodeURIComponent('@')));
			} else if ($(this).val().indexOf("/") >= 0) {
				$(this).val($(this).val().replaceAll('/', ''));
				$('.webdav-'+instance_id+' .backupplus_webdav_host_error').show();
			}
		} else if ('path' === webdav_input && 0 == $(this).val().indexOf('/')) {
			$(this).val($(this).val().replace(/^[\/]+/, ''));
		}

		var	webdav = $('#backupplus_webdav_webdav_' + instance_id).val(),
			host = $('#backupplus_webdav_host_' + instance_id).val() || '',
			user = $('#backupplus_webdav_user_' + instance_id).val() || '',
			password = $('#backupplus_webdav_pass_' + instance_id).val() || '',
			port = $('#backupplus_webdav_port_' + instance_id).val() || '',
			path = $('#backupplus_webdav_path_' + instance_id).val() || '';

		if (password) {
			masked_password = password.replace(/./gi,'*');
		}
		if (user && password) {
			user += ':';
		}
		if ((user || password) && host) {
			masked_password += '@';
			password += '@';
		}
		if (host && port) {
			host += ':';
		}
		if (path) {
			path = '/' + path;
		}

		$('#backupplus_webdav_url_' + instance_id).val(webdav + user + password + host + port + path);
		$('#backupplus_webdav_masked_url_' + instance_id).val(webdav + user + masked_password + host + port + path);
	});
	
	$('div.ud-phpseclib-notice').on('click', 'button.notice-dismiss', function (event) {
		event.stopImmediatePropagation();
		backupplus_send_command('dismiss_phpseclib_notice', null, function(resp, status, response) {
			if (!resp.hasOwnProperty('success') || 1 !== resp.success) {
				console.log(resp);
				alert(backupplus_i18n.unexpectedresponse+' '+response);
			}
		});
	});

	// Delete button
	$('#backupplus-navtab-backups-content').on('click', '.js--delete-selected-backups', function(e) {
		e.preventDefault();
		backupplus_deleteallselected();
	});

	/**
	 * Lock or unlock backups so that retention skips (or stops skipping) them, then refresh the lock icons in place
	 *
	 * @param {Array}   keys   - backup timestamps
	 * @param {Boolean} locked - true to lock, false to unlock
	 * @param {jQuery}  $busy  - controls to disable until the request completes
	 */
	function backupplus_set_backups_locked(keys, locked, $busy) {
		$busy.prop('disabled', true).addClass('disabled');
		backupplus_send_command('always_keep_backups', {backup_keys: keys.join(','), always_keep: locked ? 1 : 0}, function(resp) {
			$busy.prop('disabled', false).removeClass('disabled');
			if (!resp.hasOwnProperty('locked')) {
				alert(resp.hasOwnProperty('error_message') ? resp.error_message : backupplus_i18n.unexpectedresponse+' '+JSON.stringify(resp));
				return;
			}
			$.each(resp.locked, function(key, is_locked) {
				$('.backupplus_existing_backups_row_'+key+' .backupplus-lock-toggle')
					.attr('data-locked', is_locked ? '1' : '0')
					.attr('title', is_locked ? backupplus_i18n.backup_locked_title : backupplus_i18n.backup_unlocked_title)
					.find('.dashicons').toggleClass('dashicons-lock', is_locked).toggleClass('dashicons-unlock', !is_locked);
			});
			var failures = $.map(resp.errors, function(message, key) { return key+': '+message; });
			if (failures.length) { alert(backupplus_i18n.backup_lock_failed+"\n"+failures.join("\n")); }
		});
	}

	$('#backupplus-navtab-backups-content').on('click', '.backupplus-lock-toggle', function(e) {
		e.preventDefault();
		e.stopPropagation();
		backupplus_set_backups_locked([$(this).data('key')], '1' !== String($(this).attr('data-locked')), $(this));
	});

	$('#backupplus-navtab-backups-content').on('click', '.js--lock-selected-backups, .js--unlock-selected-backups', function(e) {
		e.preventDefault();
		var keys = $('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row.backuprowselected').map(function() { return $(this).data('key'); }).get();
		if (keys.length) { backupplus_set_backups_locked(keys, $(this).hasClass('js--lock-selected-backups'), $(this)); }
	});

	$('#backupplus-navtab-backups-content').on('click', '.backupplus_existing_backups .backup-select input', function(e) {
		// e.preventDefault();
		backupplus_backups_selection.toggle($(this).closest('.backupplus_existing_backups_row'));
	});

	$('#backupplus-navtab-backups-content').on('click', '#cb-select-all', function(e) {
		if ($(this).is(':checked')) {
			backupplus_backups_selection.selectAll();
		} else {
			backupplus_backups_selection.deselectAll();
		}
	});

	$('#backupplus-wrap').on('click', '[id^=backupplus_manual_authorisation_submit_]', function(e) {
		e.preventDefault();
		var method = $(this).data('method');
		var auth_data = $('#backupplus_manual_authentication_data_'+method).val();
		$('#backupplus_manual_authentication_error_'+method).text();
		$('#backupplus-wrap #backupplus_manual_authorisation_template_'+method+' .backupplus_spinner.spinner').addClass('visible');
		$('#backupplus_manual_authorisation_submit_'+method).prop('disabled', true);
		manual_remote_storage_auth(method, auth_data);
	});

	/**
	 * This method will send the ajax request to manually authenticate the remote storage method and then update the page with the response
	 *
	 * @param {string} method    - the remote storage method
	 * @param {string} auth_data - the auth data as a base64 json encoded string
	 */
	function manual_remote_storage_auth(method, auth_data) {
		backupplus_send_command('manual_remote_storage_authentication', {method: method, auth_data: auth_data}, function(response) {
			$('#backupplus-wrap #backupplus_manual_authorisation_template_'+method+' .backupplus_spinner.spinner').removeClass('visible');
			if (response.hasOwnProperty('result') && 'success' === response.result) {
				$('#backupplus-wrap .backupplus-top-menu').before(response.data);
				$('#backupplus-wrap #backupplus_manual_authorisation_template_'+method).parent().remove();
				$('#backupplus-wrap .backupplus_authenticate_'+method).remove();
			} else if (response.hasOwnProperty('result') && 'error' === response.result) {
				$('#backupplus_manual_authentication_error_'+method).text(response.data);
				$('#backupplus_manual_authorisation_submit_'+method).prop('disabled', false);
			}
		});
	}
	
	
	$('#backupplus-navtab-backups-content').on('click', '.js--select-all-backups', function(e) {
		backupplus_backups_selection.selectAll();
	});
	
	$('#backupplus-navtab-backups-content').on('click', '.js--deselect-all-backups', function(e) {
		backupplus_backups_selection.deselectAll();
	});
	
	$('#backupplus-navtab-backups-content').on('click', '.backupplus_existing_backups .backupplus_existing_backups_row', function(e) {
		if (!e.ctrlKey && !e.metaKey) return;
		if (e.shiftKey) {
			// it's multiple range selection, it requires the user to hold shift+ctrl buttons during the range selection, the initial and the new starting index is saved in firstMultipleSelectionIndex variable
			if ("undefined" == typeof backupplus_backups_selection.firstMultipleSelectionIndex) {
				// if all the above conditions are fulfilled then we need to set up the keyup event handler only for range selection operation. By doing it, we also ignore the Apple Command (metaKey) keycode checking which varies among the browser https://unixpapa.com/js/key.html
				$(document).on('keyup.MultipleSelection', function(e) {
					// multiple range selection operation requires the user to hold ctrl/cmd + shift buttons all the time during the selections, the range selection operation will be canceled if the user releases one of the held buttons (shitf or ctrl/cmd) and if that happens the highlight mode will stop working
					backupplus_backups_selection.unregister_highlight_mode();
					// once this event handler has been triggered and the highlight mode has been turned off, this event handler needs to be removed by using its namespace .MultipleSelection
					$(document).off('.MultipleSelection');
				});
				backupplus_backups_selection.select(this);
				$(this).addClass('range-selection-start');
				backupplus_backups_selection.register_highlight_mode();
			} else {
				backupplus_backups_selection.selectAllInBetween(this);
				jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').removeClass('range-selection');
			}
			// set the new starting index to the ending range index
			backupplus_backups_selection.firstMultipleSelectionIndex = this.rowIndex - 1;
		} else {
			backupplus_backups_selection.toggle(this);
		}
	});

	backupplus_backups_selection.checkSelectionStatus();
	
	
	$('#backupplus-navtab-settings-content #remote-storage-holder').on('click', '.backupplusmethod a.backupplus_add_instance', function(e) {
		e.preventDefault();

		backupplus_settings_form_changed = true;
		load_save_button();
		
		var method = $(this).data('method');
		add_new_instance(method);
	});

	$('#backupplus-navtab-settings-content #remote-storage-holder').on('click', '.backupplusmethod a.backupplus_delete_instance', function(e) {
		e.preventDefault();

		backupplus_settings_form_changed = true;
		load_save_button();

		var method = $(this).data('method');
		var instance_id = $(this).data('instance_id');

		if (1 === $('.' + method + '_backupplus_remote_storage_border').length) {
			add_new_instance(method);
		}

		$('.' + method + '-' + instance_id).hide('slow', function() {
			$(this).remove();
		});
	});

	$('#backupplus-navtab-settings-content #remote-storage-holder').on('click', '.backupplusmethod .backupplus_edit_label_instance', function(e) {
		$(this).find('span').hide();
		$(this).attr('contentEditable', true).trigger('focus');
	});
	
	$('#backupplus-navtab-settings-content #remote-storage-holder').on('keyup', '.backupplusmethod .backupplus_edit_label_instance', function(e) {
		var method = jQuery(this).data('method');
		var instance_id = jQuery(this).data('instance_id');
		var content = jQuery(this).text();

		$('#backupplus_' + method + '_instance_label_' + instance_id).val(content);
	});

	$('#backupplus-navtab-settings-content #remote-storage-holder').on('blur', '.backupplusmethod .backupplus_edit_label_instance', function(e) {
		$(this).attr('contentEditable', false);
		$(this).find('span').show();
	});

	$('#backupplus-navtab-settings-content #remote-storage-holder').on('keypress', '.backupplusmethod .backupplus_edit_label_instance', function(e) {
		if (13 === e.which) {
			$(this).attr('contentEditable', false);
			$(this).find('span').show();
			$(this).trigger('blur');
		}
	});

	/**
	 * This method will get the default options and compile a template with them
	 *
	 * @param {string} method - the remote storage name
	 */
	function add_new_instance(method) {
		var template = Handlebars.compile(backupplus_i18n.remote_storage_templates[method]);
		var context = {}; // Initiate a reference by assigning an empty object to a variable (in this case the context variable) so that it can be used as a target of merging one or more other objects. Unlike basic values (boolean, string, integer, etc.), in Javascript objects and arrays are passed by reference
		// copy what are in the template properties to the context overwriting the same object properties, and then copy what are in the default instance settings to the context overwriting all the same properties from the previous merging operation (if any). The context properties are overwritten by other objects that have the same properties later in the parameters order
		Object.assign(context, backupplus_i18n.remote_storage_options[method]['template_properties'], backupplus_i18n.remote_storage_options[method]['default']);
		var method_name = backupplus_i18n.remote_storage_methods[method];
		context['instance_id'] = 's-' + generate_instance_id(32);
		context['instance_enabled'] = 1;
		context['instance_label'] = method_name + ' (' + (jQuery('.' + method + '_backupplus_remote_storage_border').length + 1) + ')';
		context['instance_conditional_logic'] = {
			type: '', // always by default
			rules: [],
			day_of_the_week_options: backupplus_i18n.conditional_logic.day_of_the_week_options,
			logic_options: backupplus_i18n.conditional_logic.logic_options,
			operand_options: backupplus_i18n.conditional_logic.operand_options,
			operator_options: backupplus_i18n.conditional_logic.operator_options,
		};
		var html = template(context);
		jQuery(html).hide().insertAfter(jQuery('.' + method + '_add_instance_container').first()).show('slow', function() {
			initialize_remote_storage_select2_elements(this);
		});
	}

	/**
	 * This method will return a random instance id string
	 *
	 * @param {integer} length - the length of the string to be generated
	 *
	 * @return string         - the instance id
	 */
	function generate_instance_id(length) {
		var uuid = '';
		var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

		for (var i = 0; i < length; i++) {
			uuid += characters.charAt(Math.floor(Math.random() * characters.length));
		}

		return uuid;
	}

	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on("change", "input[class='backupplus_instance_toggle']", function () {
		backupplus_settings_form_changed = true;
		load_save_button();
		if (jQuery(this).is(':checked')) {
			jQuery(this).siblings('label').html(backupplus_i18n.instance_enabled);
		} else {
			jQuery(this).siblings('label').html(backupplus_i18n.instance_disabled);
		}
	});

	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on("change", "select[class='logic_type']", function () {
		backupplus_settings_form_changed = true;
		load_save_button();
		if ('' !== this.value) {
			jQuery('div.logic', jQuery(this).parents('tr.backupplusmethod')).show();
			jQuery(this).parents('tr.backupplusmethod').find('div.logic ul.rules > li').each(function() {
				jQuery(this).find('select').each(function() {
					jQuery(this).prop('disabled', false);
				})
			});
		} else {
			jQuery(this).parents('tr.backupplusmethod').find('div.logic ul.rules > li').each(function() {
				jQuery(this).find('select').each(function() {
					jQuery(this).prop('disabled', true);
				})
			});
			jQuery(this).parents('tr.backupplusmethod').find('div.logic').hide();
		}
	});

	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on("change", "select[class='conditional_logic_operand']", function () {
		backupplus_settings_form_changed = true;
		load_save_button();
		jQuery(this).parent().find('select:nth(2)').empty();
		if ('day_of_the_week' === jQuery(this).val()) {
			for (var i=0; i<backupplus_i18n.conditional_logic.day_of_the_week_options.length; i++) {
				jQuery(this).parent().find('select:nth(2)').append(jQuery('<option value="'+backupplus_i18n.conditional_logic.day_of_the_week_options[i].index+'"></option>').text(backupplus_i18n.conditional_logic.day_of_the_week_options[i].value));
			}
		} else if ('day_of_the_month' === jQuery(this).val()) {
			for (var i=1; i<=31; i++) {
				jQuery(this).parent().find('select:nth(2)').append(jQuery('<option value="'+i+'"></option>').text(i));
			}
		}
	});

	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on("click", "div.conditional_remote_backup ul.rules li span", function () {
		backupplus_settings_form_changed = true;
		load_save_button();
		var $ul = jQuery(this).parents('ul.rules');
		if (jQuery(this).hasClass('remove-rule')) {
			jQuery(this).parent().slideUp(function() {
				jQuery(this).remove();
				if (jQuery($ul).find('> li').length < 2) {
					jQuery('li:nth(0) span.remove-rule', $ul).remove();
				}
			});
		}
	});

	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on("click", "div.conditional_remote_backup input.add-new-rule", function () {
		var $ul = jQuery(this).parent().find('ul.rules');
		if (jQuery($ul).find('> li').length < 2) {
			jQuery($ul).find('li:nth(0)').append('<span class="remove-rule"><svg viewbox="0 0 25 25"><line x1="6.5" y1="18.5" x2="18.5" y2="6.5" fill="none" stroke="#FF6347" stroke-width="3" vector-effect="non-scaling-stroke" ></line><line y1="6.5" x1="6.5" y2="18.5" x2="18.5" fill="none" stroke="#FF6347" stroke-width="3" vector-effect="non-scaling-stroke" ></line></svg></span>');
		}
		$cloned_item = jQuery($ul).find('> li').last().clone();
		jQuery($cloned_item).find('> select').each(function() {
			jQuery(this).prop('name', jQuery(this).prop('name').replace(/\[instance_conditional_logic\]\[rules\]\[[0-9]+\]/gi, '[instance_conditional_logic][rules]['+jQuery($ul).data('rules')+']'));
		});
		jQuery($ul).append($cloned_item);
		jQuery($ul).data('rules', parseInt(jQuery($ul).data('rules')) + 1);
		jQuery($cloned_item).find('select[name*="[operand]"]').trigger('change');
	});
	
	jQuery('#backupplus-navtab-settings-content #remote-storage-holder').on('click', '.backupplusmethod button.backupplus-test-button', function() {

		var method = jQuery(this).data('method');
		var instance_id = jQuery(this).data('instance_id');
		backupplus_remote_storage_test(method, function(response, status, data) {
			if ('sftp' != method) { return false; }
			
			if (data.hasOwnProperty('scp') && data.scp) {
				alert(backupplus_i18n.settings_test_result.replace('%s', 'SCP')+' '+response.output);
			} else {
				alert(backupplus_i18n.settings_test_result.replace('%s', 'SFTP')+' '+response.output);
			}
			
			if (response.hasOwnProperty('data') && response.data) {
				if (response.data.hasOwnProperty('valid_md5_fingerprint') && response.data.valid_md5_fingerprint) {
					$('#backupplus_sftp_fingerprint_'+instance_id).val(response.data.valid_md5_fingerprint);
				}
			}
			
			return true;
			
		}, instance_id);
	});
	
	$('#backupplus-navtab-settings-content select.backupplus_interval, #backupplus-navtab-settings-content select.backupplus_interval_database').on('change', function() {
		backupplus_check_same_times();
	});
	
	$('#backupnow_includefiles_showmoreoptions').on('click', function(e) {
		e.preventDefault();
		$('#backupnow_includefiles_moreoptions').toggle();
	});

	$('#backupnow_database_showmoreoptions').on('click', function(e) {
		e.preventDefault();
		$('#backupnow_database_moreoptions').toggle();
	});


	$('#backupnow_db_anon_all').on('click', function(e) {
		if ($('#backupnow_db_anon_non_staff').prop('checked')) $('#backupnow_db_anon_non_staff').prop("checked", false);
	});

	$('#backupnow_db_anon_non_staff').on('click', function(e) {
		if ($('#backupnow_db_anon_all').prop('checked')) $('#backupnow_db_anon_all').prop("checked", false);
	});


	$('#backupplus-backupnow-modal').on('click', '#backupnow_includecloud_showmoreoptions', function(e) {
		e.preventDefault();
		$('#backupnow_includecloud_moreoptions').toggle();
	});
	
	$('#backupplus-navtab-backups-content').on('click', 'a.backupplus_diskspaceused_update',function(e) {
		e.preventDefault();
		backupplus_diskspace();
	});
	
	// For Advanced Tools > Site information > Web-server disk space in use by BackupPlus
	$('.advanced_settings_content a.backupplus_diskspaceused_update').on('click', function(e) {
		e.preventDefault();
		jQuery('.advanced_settings_content .backupplus_diskspaceused').html('<em>'+backupplus_i18n.calculating+'</em>');
		backupplus_send_command('get_fragment', { fragment: 'disk_usage', data: 'backupplus' }, function(response) {
			jQuery('.advanced_settings_content .backupplus_diskspaceused').html(response.output);
		}, { type: 'GET' });
	});
	
	$('#backupplus-navtab-backups-content a.backupplus_uploader_toggle').on('click', function(e) {
		e.preventDefault();
		$('#backupplus-plupload-modal').slideToggle();
	});
	
	$('#backupplus-navtab-backups-content a.backupplus_rescan_local').on('click', function(e) {
		e.preventDefault();
		backupplus_updatehistory(1, 0);
	});
	
	$('#backupplus-navtab-backups-content a.backupplus_rescan_remote').on('click', function(e) {
		e.preventDefault();
		if (!confirm(backupplus_i18n.remote_scan_warning)) return;
		backupplus_updatehistory(1, 1);
	});
	
	$('#backupplus-remote-rescan-debug').on('click', function(e) {
		e.preventDefault();
		backupplus_updatehistory(1, 1, 1);
	});
	
	jQuery('#backupplus_reset_sid').on('click', function(e) {
		e.preventDefault();
		backupplus_send_command('reset_site_id', null, function(response) {
			jQuery('#backupplus_show_sid').html(response);
		}, { json_parse: false });
	});
	
	jQuery("#backupplus-navtab-settings-content").on('input', "form input:not('.udignorechange'), form textarea:not('.udignorechange')", function(e) {
		backupplus_settings_form_changed = true;
		load_save_button();
	});

	jQuery("#backupplus-navtab-settings-content").on('change', "form select", function(e) {
		backupplus_settings_form_changed = true;
		load_save_button();
	});

	jQuery("#backupplus-navtab-settings-content").on('click', "form input[type='submit']", function (e) {
		backupplus_settings_form_changed = false;
	});
});
