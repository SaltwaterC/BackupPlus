// Remote storage tabs, configuration links and connection tests.

function backupplus_remote_storage_tab_activation(the_method){
	jQuery('.backupplusmethod').not('.error').hide();
	jQuery('.remote-tab').data('active', false);
	jQuery('.remote-tab').removeClass('nav-tab-active');
	jQuery('.backupplusmethod.'+the_method).show();
	jQuery('.remote-tab-'+the_method).data('active', true);
	jQuery('.remote-tab-'+the_method).addClass('nav-tab-active');
}

/**
 * The row with the button that adds another destination of a type, kept after the last destination of that type.
 *
 * @param {string} method - The identifier for the remote storage
 * @return {string} The HTML row
 */
function backupplus_add_instance_row(method) {
	var escape = function(text) { return jQuery('<div>').text(text).html(); };
	return '<tr class="backupplusmethod ' + escape(method) + ' ' + escape(method) + '_add_instance_container"><th></th><td>' +
		'<a href="#" class="button backupplus_add_instance" data-method="' + escape(method) + '">' +
		escape(backupplus_i18n.add_instance.replace('%s', backupplus_i18n.remote_storage_methods[method])) + '</a></td></tr>';
}

/**
 * Scroll to a specific remote storage configuration by adding the #remote-storage-{method_name} anchor hash to the URL.
 */
function backupplus_scroll_to_remote_storage_config() {
	var url_hash_match = window.location.hash.match(/#remote-storage-([A-Za-z]+)/);
	if (url_hash_match && backupplus_i18n.remote_storage_methods[url_hash_match[1]]) {
		if (jQuery('.backupplus_servicecheckbox').hasClass('multi')) {
			backupplus_remote_storage_tab_activation(url_hash_match[1]);
		}
		
		document.getElementById('remote-storage-'+url_hash_match[1]).scrollIntoView();
	}
}

/**
 * Set up the remote storage configuration link to handle the click event. When it is clicked from the UDP settings page, we need to reopen the settings tab before scrolling to the remote storage configuration section.
 */
function backupplus_setup_remote_storage_config_link() {
	jQuery('.backupplus-remote-storage-link').on('click', function(e) {
		if ('settings' == backupplus_i18n.tab) {
			e.preventDefault();
			backupplus_open_main_tab('settings');
			window.location.href = jQuery(this).attr('href');
			backupplus_scroll_to_remote_storage_config();
		}
	});
}

/**
 * Set the email report's setting to a different interface when email storage is selected
 *
 * @param {boolean} value True to set the email report setting to another interface, false otherwise
 */
function set_email_report_storage_interface(value) {
	jQuery('#cb_not_email_storage_label').css('display', true === value ? 'none' : 'inline');
	jQuery('#cb_email_storage_label').css('display', true === value ? 'inline' : 'none');
	if (true === value) {
		jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').on('click', function(e) {
			return false;
		});
	} else {
		jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').prop("onclick", null).off("click");
	}
	if (!jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').is(':checked')) {
		jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').prop('checked', value);
	}
	jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').prop('disabled', value);

	var backupplus_email = jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon input#backupplus_email').val();

	jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon label.email_report input[type="hidden"]').remove();
	if (true === value) {
		jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon label.email_report input#backupplus_email').after('<input type="hidden" name="backupplus_email" value="'+backupplus_email+'">');
	}
}

/**
 * Check how many cron jobs are overdue, and display a message if it is several (as determined by the back-end)
 */
function backupplus_check_overduecrons() {
	backupplus_send_command('check_overdue_crons', null, function(response) {
		if (response && response.hasOwnProperty('m') && Array.isArray(response.m)) {
			for (var i in response.m) {
				jQuery('#backupplus-insert-admin-warning').append(response.m[i]);
			}
		}
	}, { alert_on_error: false });
}

function backupplus_remote_storage_tabs_setup() {
	
	var anychecked = 0;
	var set = jQuery('.backupplus_servicecheckbox:checked');
	
	jQuery(set).each(function(ind, obj) {
		var ser = jQuery(obj).val();
		
		jQuery('.error.backupplusmethod.'+ser).show();

		if (jQuery(obj).attr('id') != 'backupplus_servicecheckbox_none') {
			anychecked++;
		}
		
		jQuery('.remote-tab-'+ser).show();
		if (ind == jQuery(set).length-1) {
			backupplus_remote_storage_tab_activation(ser);
		}
	});
	
	if (anychecked > 0) {
		jQuery('.backupplusmethod.none').hide();
		jQuery('#remote_storage_tabs').show();
	} else {
		jQuery('#remote_storage_tabs').hide();
	}
	
	// To allow labelauty remote storage buttons to be used with keyboard
	jQuery(document).on('keyup', function(event) {
		if (32 === event.keyCode || 13 === event.keyCode) {
			if (jQuery(document.activeElement).is("input.labelauty + label")) {
				var for_box = jQuery(document.activeElement).attr("for");
				if (for_box) {
					jQuery("#"+for_box).trigger('change');
				}
			}
		}
	});
	
	jQuery('.backupplus_servicecheckbox').on('change', function() {
		var serv = jQuery(this).val();
		if (serv) {
			if (jQuery(this).is(':checked')) {
				anychecked++;
				jQuery('.error.backupplusmethod.'+serv).show();
				jQuery('.remote-tab-'+serv).fadeIn();
				backupplus_remote_storage_tab_activation(serv);
				if (jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon').length && 'email' === serv) set_email_report_storage_interface(true);
			} else {
				anychecked--;
				jQuery('.error.backupplusmethod.'+serv).hide();
				jQuery('.remote-tab-'+serv).hide();
				// Check if this was the active tab, if yes, switch to another
				if (jQuery('.remote-tab-'+serv).data('active') == true) {
					backupplus_remote_storage_tab_activation(jQuery('.remote-tab:visible').last().attr('name'));
				}
				if (jQuery('#backupplus-navtab-settings-content #backupplus_report_row_no_addon').length && 'email' === serv) set_email_report_storage_interface(false);
			}
		}

		if (anychecked <= 0) {
			jQuery('.backupplusmethod.none').fadeIn();
			jQuery('#remote_storage_tabs').hide();
		} else {
			jQuery('.backupplusmethod.none').hide();
			jQuery('#remote_storage_tabs').show();
		}
	});
	
	jQuery('.remote-tab').on('click', function(e) {
		e.preventDefault();
		backupplus_remote_storage_tab_activation(jQuery(this).attr('name'));
	});

	// Destinations are exclusive only when the checkboxes are not marked as multiple
	jQuery('.backupplus_servicecheckbox:not(.multi)').on('change', function() {
		set_email_report_storage_interface(false);
		var svalue = jQuery(this).attr('value');
		if (jQuery(this).is(':not(:checked)')) {
			jQuery('.backupplusmethod.'+svalue).hide();
			jQuery('.backupplusmethod.none').fadeIn();
		} else {
			jQuery('.backupplus_servicecheckbox').not(this).prop('checked', false);
			if ('email' === svalue) {
				set_email_report_storage_interface(true);
			}
		}
	});
	
	var servicecheckbox = jQuery('.backupplus_servicecheckbox');
	if (typeof servicecheckbox.labelauty === 'function') {
		servicecheckbox.labelauty();
	}
	
}

/**
 * Carries out a remote storage test
 *
 * @param {string}   method          - The identifier for the remote storage
 * @param {callback} result_callback - A callback function to be called with the result
 * @param {string}   instance_id     - The particular instance (if any) of the remote storage to be tested (for methods supporting multiple instances)
 */
function backupplus_remote_storage_test(method, result_callback, instance_id) {
	
	var $the_button;
	var settings_selector;
		
	if (instance_id) {
		$the_button = jQuery('#backupplus-'+method+'-test-'+instance_id);
		settings_selector = '.backupplusmethod.'+method+'-'+instance_id;
	} else {
		$the_button = jQuery('#backupplus-'+method+'-test');
		settings_selector = '.backupplusmethod.'+method;
	}
	
	var method_label = $the_button.data('method_label');
	
	$the_button.html(backupplus_i18n.testing_settings.replace('%s', method_label));
	
	var data = {
		method: method
	};
	
	// Add the other items to the data object. The expert mode settings are for the generic SSL options.
	jQuery('#backupplus-navtab-settings-content '+settings_selector+' input[data-backupplus_settings_test], #backupplus-navtab-settings-content .expertmode input[data-backupplus_settings_test]').each(function(index, item) {
		var item_key = jQuery(item).data('backupplus_settings_test');
		var input_type = jQuery(item).attr('type');
		if (!item_key) { return; }
		if (!input_type) {
			console.log("BackupPlus: settings test input item with no type found");
			console.log(item);
			// A default
			input_type = 'text';
		}
		var value = null;
		if ('checkbox' == input_type) {
			value = jQuery(item).is(':checked') ? 1 : 0;
		} else if ('text' == input_type || 'password' == input_type || 'hidden' == input_type || 'number' == input_type) {
			value = jQuery(item).val();
		} else {
			console.log("BackupPlus: settings test input item with unrecognised type ("+input_type+") found");
			console.log(item);
		}
		data[item_key] = value;
	});
	// Data from any text areas or select drop-downs
	jQuery('#backupplus-navtab-settings-content '+settings_selector+' textarea[data-backupplus_settings_test], #backupplus-navtab-settings-content '+settings_selector+' select[data-backupplus_settings_test]').each(function(index, item) {
		var item_key = jQuery(item).data('backupplus_settings_test');
		data[item_key] = jQuery(item).val();
	});

	backupplus_send_command('test_storage_settings', data, function(response, status) {
		$the_button.html(backupplus_i18n.test_settings.replace('%s', method_label));
		if ('undefined' !== typeof result_callback && false != result_callback) {
			result_callback = result_callback.call(this, response, status, data);
		}
		if ('undefined' !== typeof result_callback && false === result_callback) {
			response.output = response.output.replaceAll('&quot;', '"');
			alert(backupplus_i18n.settings_test_result.replace('%s', method_label)+' '+response.output);
			if (response.hasOwnProperty('data')) {
				console.log(response.data);
			}
		}
	}, { error_callback: function(response, status, error_code, resp) {
				$the_button.html(backupplus_i18n.test_settings.replace('%s', method_label));
				if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
			console.error(resp.fatal_error_message);
			alert(resp.fatal_error_message);
				} else {
			var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
			console.log(error_message);
			alert(error_message);
			console.log(response);
				}
			}
		});
}
