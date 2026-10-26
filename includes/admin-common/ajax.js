// Sending AJAX commands to the BackupPlus endpoint and the delete backups dialog.

/**
 * Send an action over AJAX. A wrapper around jQuery.ajax. In future, all consumers can be reviewed to simplify some of the options, where there is historical cruft.
 * N.B. backupplus_iframe_modal() below uses the AJAX URL for the iframe's src attribute
 *
 * @param {string}   action   - the action to send
 * @param {*}        data     - data to send
 * @param {Function} callback - if specified, will be called with the results
 * @param {object}   options  -further options. Relevant properties include:
 * - [json_parse=true] - whether to JSON parse the results
 * - [alert_on_error=true] - whether to show an alert box if there was a problem (otherwise, suppress it)
 * - [action='backupplus_ajax'] - what to send as the action parameter on the AJAX request (N.B. action parameter to this function goes as the 'subaction' parameter on the AJAX request)
 * - [nonce=backupplus_credentialtest_nonce] - the nonce value to send.
 * - [nonce_key='nonce'] - the key value for the nonce field
 * - [timeout=null] - set a timeout after this number of seconds (or if null, none is set)
 * - [async=true] - control whether the request is asynchronous (almost always wanted) or blocking (would need to have a specific reason)
 * - [type='POST'] - GET or POST
 */
function backupplus_send_command(action, data, callback, options) {
	
	default_options = {
		json_parse: true,
		alert_on_error: true,
		action: 'backupplus_ajax',
		nonce: backupplus_credentialtest_nonce,
		nonce_key: 'nonce',
		timeout: null,
		async: true,
		type: 'POST'
	}
	
	if ('undefined' === typeof options) options = {};

	for (var opt in default_options) {
		if (!options.hasOwnProperty(opt)) { options[opt] = default_options[opt]; }
	}
	
	var ajax_data = {
		action: options.action,
		subaction: action,
	};
	
	ajax_data[options.nonce_key] = options.nonce;
	
	// TODO: Once all calls are routed through here, change the listener in admin.php to always take the data from the 'data' attribute, instead of in the naked $_POST/$_GET
	if (typeof data == 'object') {
		for (var attrname in data) { ajax_data[attrname] = data[attrname]; }
	} else {
		ajax_data.action_data = data;
	}
	
	var ajax_opts = {
		type: options.type,
		url: ajaxurl,
		data: ajax_data,
		success: function(response, status) {
			if (options.json_parse) {
				try {
					var resp = ud_parse_json(response);
				} catch (e) {
					if ('function' == typeof options.error_callback) {
						return options.error_callback(response, e, 502, resp);
					} else {
						console.log(e);
						console.log(response);
						if (options.alert_on_error) {
							if ('string' === typeof response && response.match(/security\scheck\s?/i)) response += ' (' + backupplus_i18n.expired_tokens + ' ' + backupplus_i18n.reload_page + ')';
							alert(backupplus_i18n.unexpectedresponse+' '+response);
						}
						return;
					}
				}
				if (resp.hasOwnProperty('fatal_error')) {
					if ('function' == typeof options.error_callback) {
						// 500 is internal server error code
						return options.error_callback(response, status, 500, resp);
					} else {
						console.error(resp.fatal_error_message);
						if (options.alert_on_error) { alert(resp.fatal_error_message); }
						return false;
					}
				}
				if ('function' == typeof callback) callback(resp, status, response);
			} else {
				if ('function' == typeof callback) callback(response, status);
			}
		},
		error: function(response, status, error_code) {
			if ('function' == typeof options.error_callback) {
				options.error_callback(response, status, error_code);
			} else {
				console.log("backupplus_send_command: error: "+status+" ("+error_code+")");
				console.log(response);
			}
		},
		dataType: 'text',
		async: options.async
	};
	
	if (null != options.timeout) { ajax_opts.timeout = options.timeout; }
	
	jQuery.ajax(ajax_opts);
	
}

/**
 * Opens the dialog box for confirmation of whether to delete a backup, plus options if relevant
 *
 * @param {string}  key        - The UNIX timestamp of the backup
 * @param {string}  nonce      - The backup job ID
 * @param {boolean} showremote - Whether or not to show the "also delete from remote storage?" checkbox
 * @param {Array}   chains     - Descriptions of the selected sets which are a full backup with incremental backups
 */
function backupplus_delete(key, nonce, showremote, chains) {
	var chain_warning = jQuery('#backupplus_delete_chain_warning');
	var chain_list = chain_warning.find('ul').empty();
	chains = chains || [];
	jQuery.each(chains, function(index, line) {
		chain_list.append(jQuery('<li>').text(line));
	});
	chain_warning.toggle(chains.length > 0);
	jQuery('#backupplus_delete_timestamp').val(key);
	jQuery('#backupplus_delete_nonce').val(nonce);
	if (showremote) {
		jQuery('#backupplus-delete-remote-section, #backupplus_delete_remote').prop('disabled', false).show();
	} else {
		jQuery('#backupplus-delete-remote-section, #backupplus_delete_remote').hide().attr('disabled','disabled');
	}
	if (key.indexOf(',') > -1) {
		jQuery('#backupplus_delete_question_singular').hide();
		jQuery('#backupplus_delete_question_plural').show();
	} else {
		jQuery('#backupplus_delete_question_plural').hide();
		jQuery('#backupplus_delete_question_singular').show();
	}
	jQuery('#backupplus-delete-modal').dialog('open');
}
