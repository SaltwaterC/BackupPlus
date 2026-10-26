// Gathering, saving, exporting and importing settings.

// Save/Export/Import settings via AJAX
jQuery(function($) {

	// When inclusion options for file entities in the settings tab, reflect that in the "Backup Now" dialog, to prevent unexpected surprises
	$('#backupplus-navtab-settings-content input.backupplus_include_entity').on('change', function(e) {
		var event_target = $(this).attr('id');
		var checked = $(this).is(':checked');
		var backup_target = '#backupnow_files_'+event_target;
		$(backup_target).prop('checked', checked);
	});

	$('#backupplus-settings-save').on('click', function(e) {
		e.preventDefault();
		$.blockUI({
			css: {
				width: '300px',
				border: 'none',
				'border-radius': '10px',
				left: 'calc(50% - 150px)',
				padding: '20px',
			},
			message: '<div style="margin: 8px; font-size:150%;" class="backupplus_saving_popup"><span class="dashicons dashicons-update" style="font-size:72px;width:72px;height:72px;padding-bottom:10px;color:#d54e21;"></span><br>'+backupplus_i18n.saving+'</div>'
		});
		
		var form_data = gather_backupplus_settings('string');
		// POST the settings back to the AJAX handler
		backupplus_send_command('savesettings', {
			settings: form_data,
			backupplus_version: backupplus_i18n.backupplus_version
		}, function(resp, status, response) {
			// Add page updates etc based on response
			backupplus_handle_page_updates(resp, response);
			
			$('#backupplus-wrap .fade').delay(6000).fadeOut(2000);
			if (window.backupplus_main_tour && !window.backupplus_main_tour.canceled) {
				window.backupplus_main_tour.show('settings_saved');
				check_cloud_authentication();
			} else {
				$('html, body').animate({
					scrollTop: $("#backupplus-wrap").offset().top
				}, 1000, function() {
				  check_cloud_authentication()
				});
			}
			$.unblockUI();
			$(document).trigger('wp-notice-added');
		}, { action: 'backupplus_savesettings', error_callback: function(response, status, error_code, resp) {
				$.unblockUI();
				if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
				console.error(resp.fatal_error_message);
				alert(resp.fatal_error_message);
				} else {
				var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
				console.log(error_message);
				alert(error_message);
				console.log(response);
				}
			}, nonce: backupplus_settings_nonce});
	});

	$('#backupplus-settings-export').on('click', function() {
		if (backupplus_settings_form_changed) {
			alert(backupplus_i18n.unsaved_settings_export);
		}
		export_settings();
	});

	$('#backupplus-settings-import').on('click', function() {
		$.blockUI({
			css: {
				width: '300px',
				border: 'none',
				'border-radius': '10px',
				left: 'calc(50% - 150px)',
				padding: '20px',
			},
			message: '<div style="margin: 8px; font-size:150%;" class="backupplus_saving_popup"><span class="dashicons dashicons-update" style="font-size:72px;width:72px;height:72px;padding-bottom:10px;color:#d54e21;"></span><br>'+backupplus_i18n.importing+'</div>'
		});
		var backupplus_import_file_input = document.getElementById('import_settings');
		if (backupplus_import_file_input.files.length == 0) {
			alert(backupplus_i18n.import_select_file);
			$.unblockUI();
			return;
		}
		var backupplus_import_file_file = backupplus_import_file_input.files[0];
		var backupplus_import_file_reader = new FileReader();
		backupplus_import_file_reader.onload = function() {
			import_settings(this.result);
		};
		backupplus_import_file_reader.readAsText(backupplus_import_file_file);
	});
	
	function export_settings() {
		var form_data = gather_backupplus_settings('object');
		// Connection settings and legacy secrets are never placed in export files.
		backupplus_i18n.credential_export_omissions.forEach(function(option) { delete form_data[option]; });
		
		var date_now = new Date();
		
		// The 'version' attribute indicates the last time the format changed - i.e. do not update this unless there is a format change
		form_data = JSON.stringify({
			version: '1.12.40',
			epoch_date: date_now.getTime(),
			local_date: date_now.toLocaleString(),
			network_site_url: backupplus_i18n.network_site_url,
			data: form_data
		});
		
		// Attach this data to an anchor on page
		var link = document.body.appendChild(document.createElement('a'));
		link.setAttribute('download', backupplus_i18n.export_settings_file_name);
		link.setAttribute('style', "display:none;");
		link.setAttribute('href', 'data:text/json' + ';charset=UTF-8,' + encodeURIComponent(form_data));
		link.click();
	}
	
	function import_settings(backupplus_file_result) {
		var parsed;
		try {
			parsed = ud_parse_json(backupplus_file_result);
		} catch (e) {
			$.unblockUI();
			jQuery('#import_settings').val('');
			console.log(backupplus_file_result);
			console.log(e);
			alert(backupplus_i18n.import_invalid_json_file);
			return;
		}
		if (window.confirm(backupplus_i18n.importing_data_from + ' ' + parsed['network_site_url'] + "\n" + backupplus_i18n.exported_on + ' ' + parsed['local_date'] + "\n" + backupplus_i18n.continue_import)) {
			// GET the settings back to the AJAX handler
			var stringified = JSON.stringify(parsed['data']);
			backupplus_send_command('importsettings', {
				settings: stringified,
				backupplus_version: backupplus_i18n.backupplus_version,
			}, function(decoded_response, status, response) {
				var resp = backupplus_handle_page_updates(decoded_response);
				if (!resp.hasOwnProperty('saved') || resp.saved) {
					if (resp.import_skipped_settings && resp.import_skipped_settings.length) {
						alert(backupplus_i18n.import_skipped_settings+'\n\n'+resp.import_skipped_settings.join('\n'));
					}
					// Prevent the user being told they have unsaved settings
					backupplus_settings_form_changed = false;
					// Add page updates etc based on response
					location.replace(backupplus_i18n.backupplus_settings_url);
				} else {
					$.unblockUI();
					if (resp.hasOwnProperty('error_message') && resp.error_message) {
						alert(resp.error_message);
					}
				}
			}, { action: 'backupplus_importsettings', nonce: backupplus_settings_nonce, error_callback: function(response, status, error_code, resp) {
					$.unblockUI();
					if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
					console.error(resp.fatal_error_message);
					alert(resp.fatal_error_message);
					} else {
					var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
					console.log(error_message);
					console.log(response);
					alert(error_message);
						
					}
				}
			 });
		} else {
			$.unblockUI();
		}
	}
	
	/**
	 * Retrieve the current settings from the DOM
	 *
	 * @param {string} output_format - the output format; valid values are 'string' or 'object'
	 *
	 * @returns String|Object
	 */
	function gather_backupplus_settings(output_format) {

		var form_data = '';
		var output_format = ('undefined' === typeof output_format) ? 'string' : output_format;
		
		if ('object' == output_format) {
			// Excluding the unnecessary 'action' input avoids triggering a very mis-conceived mod_security rule seen on one user's site
			form_data = $("#backupplus-navtab-settings-content form input[name!='action'][name!='option_page'][name!='_wpnonce'][name!='_wp_http_referer'], #backupplus-navtab-settings-content form textarea, #backupplus-navtab-settings-content form select, #backupplus-navtab-settings-content form input[type=checkbox]").serializeJSON({checkboxUncheckedValue: '0', useIntKeysAsArrayIndex: true});
		} else {
			// Excluding the unnecessary 'action' input avoids triggering a very mis-conceived mod_security rule seen on one user's site
			form_data = $("#backupplus-navtab-settings-content form input[name!='action'], #backupplus-navtab-settings-content form textarea, #backupplus-navtab-settings-content form select").serialize();
			
			// include unchecked checkboxes. user filter to only include unchecked boxes.
			$.each($('#backupplus-navtab-settings-content form input[type=checkbox]')
				.filter(function(idx) {
				return $(this).prop('checked') == false && !!$(this).attr('name');
				}),
				function(idx, el) {
					// attach matched element names to the form_data with chosen value.
					var empty_val = '0';
					form_data += '&' + $(el).attr('name') + '=' + empty_val;
				}
			);
		}

		return form_data;
	}
	
	/**
	 * Method to parse the response from the backend and update the page with the returned content or display error messages if failed
	 *
	 * @param {array}  resp     - the JSON-decoded response containing information to update the settings page with
	 * @param {string} response - the JSON-encoded response containing information to update the settings page with
	 *
	 * @return {object} - the decoded response (empty if decoding was not successful)
	 */
	function backupplus_handle_page_updates(resp, response) {
					
		try {
			var messages = resp.messages;
			// var debug = resp.changed.backupplus_debug_mode;
			
			// If backup dir is not writable, change the text, and grey out the 'Backup Now' button
			var backup_dir_writable = resp.backup_dir.writable;
			var backup_dir_message = resp.backup_dir.message;
			var backup_button_title = resp.backup_dir.button_title;
		} catch (e) {
			console.log(e);
			console.log(response);
			alert(backupplus_i18n.jsonnotunderstood);
			$.unblockUI();
			return {};
		}
		
		if (resp.hasOwnProperty('changed')) {
			console.log("BackupPlus: savesettings: some values were changed after being filtered");
			console.log(resp.changed);
			for (prop in resp.changed) {
				if ('object' === typeof resp.changed[prop]) {
					for (innerprop in resp.changed[prop]) {
						if (!$("[name='"+innerprop+"']").is(':checkbox')) {
							$("[name='"+prop+"["+innerprop+"]']").val(resp.changed[prop][innerprop]);
						}
					}
				} else {
					if (!$("[name='"+prop+"']").is(':checkbox')) {
						$("[name='"+prop+"']").val(resp.changed[prop]);
					}
				}
			}
		}
		
		$('#backupplus_writable_mess').html(backup_dir_message);
		
		if (false == backup_dir_writable) {
			$('#backupplus-backupnow-button').attr('disabled', 'disabled');
			$('#backupplus-backupnow-button').attr('title', backup_button_title);
			$('.backupdirrow').css('display', 'table-row');
		} else {
			$('#backupplus-backupnow-button').prop('disabled', false);
			$('#backupplus-backupnow-button').removeAttr('title');
			// $('.backupdirrow').hide();
		}

		if (resp.hasOwnProperty('backupplus_include_more_path')) {
			$('#backupnow_includefiles_moreoptions').html(resp.backupplus_include_more_path);
		}

		if (resp.hasOwnProperty('backup_now_message')) { $('#backupnow_remote_container').html(resp.backup_now_message); }
		
		// Move from 2 to 1
		$('.backupplusmessage').remove();
		
		$('#backupplus_backup_started').before(resp.messages);

		backupplus_setup_remote_storage_config_link();
		
		console.log(resp);
		// $('#backupplus-next-backup-inner').html(resp.scheduled);
		$('#backupplus-next-files-backup-inner').html(resp.files_scheduled);
		if (resp.hasOwnProperty('incremental_scheduled')) {
			$('#backupplus-next-incremental-backup-inner').html(resp.incremental_scheduled);
		}
		$('#backupplus-next-database-backup-inner').html(resp.database_scheduled);
		if (resp.hasOwnProperty('pitr_scheduled')) {
			$('#backupplus-next-pitr-backup-inner').html(resp.pitr_scheduled);
		}
		if (resp.hasOwnProperty('cleanup_scheduled')) {
			$('#backupplus-next-cleanup-inner').html(resp.cleanup_scheduled);
		}
		
		return resp;
		
	}

	/**
	 * This function has the workings for checking if any cloud storage needs authentication
	 * If so, these are amended to the HTML and the popup is shown to the users.
	 */
	function check_cloud_authentication(){
		var show_auth_modal = false;
		jQuery('#backupplus-authenticate-modal-innards').html('');
		
		jQuery("div[class*=backupplus_authenticate_] a.backupplus_authlink").each(function () {
			var pretext = jQuery(this).data('pretext');
			if ('undefined' === typeof pretext) pretext = '';
			jQuery("#backupplus-authenticate-modal-innards").append(pretext+'<p><a class="'+jQuery(this).attr('class')+'" href="'+jQuery(this).attr('href')+'">'+jQuery(this).html()+'</a></p>');
			show_auth_modal = true;
		  });
			

		if (show_auth_modal) {
			var backupplus_authenticate_modal_buttons = {};
			backupplus_authenticate_modal_buttons[backupplus_i18n.cancel] = function() {
 jQuery(this).dialog("close"); };

			jQuery('#backupplus-authenticate-modal').dialog({autoOpen: true,
				modal: true, resizable: false, draggable: false, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
				open: function(event, ui) {
					$(this).dialog('option', 'width', 860);
					$(this).dialog('option', 'height', 260);
				},
				buttons: backupplus_authenticate_modal_buttons}).dialog('open');
		}
	}

	$('.udp-replace-with-iframe--js').on('click', function(e) {
		e.preventDefault();
		var url = $(this).prop('href');
		var iframe = $('<iframe width="356" height="200" allowfullscreen webkitallowfullscreen mozallowfullscreen>').attr('src', url);
		iframe.insertAfter($(this));
		$(this).remove();
	});

});
