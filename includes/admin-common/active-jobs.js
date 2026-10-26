// Starting backups from the page and polling the list of active jobs.

/**
 * Run a backup with show modal with progress.
 *
 * @param {Function} success_callback   callback function after backup
 * @param {String}   onlythisfileentity csv list of file entities to be backed up
 * @param {Array}    extradata          any extra data to be added
 * @param {Integer}  backupnow_nodb      Indicate whether the database should be backed up. Valid values: 0, 1
 * @param {Integer}  backupnow_nofiles  Indicate whether any files should be backed up. Valid values: 0, 1
 * @param {Integer}  backupnow_nocloud  Indicate whether the backup should be uploaded to cloud storage. Valid values: 0, 1
 * @param {String}   label              An optional label to be added to a backup
 */
function backupplus_backupnow_inpage_go(success_callback, onlythisfileentity, extradata, backupnow_nodb, backupnow_nofiles, backupnow_nocloud, label) {
	
	backupnow_nodb = ('undefined' === typeof backupnow_nodb) ? 0 : backupnow_nodb;
	backupnow_nofiles = ('undefined' === typeof backupnow_nofiles) ? 0 : backupnow_nofiles;
	backupnow_nocloud = ('undefined' === typeof backupnow_nocloud) ? 0 : backupnow_nocloud;
	label = ('undefined' === typeof label) ? backupplus_i18n.automaticbackupbeforeupdate : label;
	
	// N.B. This function should never be called on the BackupPlus settings page - it is assumed we are elsewhere. So, it is safe to fake the console-focussing parameter.
	backupplus_console_focussed_tab = 'backups';
	backupplus_inpage_success_callback = success_callback;
	backupplus_activejobs_update_timer = setInterval(function () {
		backupplus_activejobs_update(false);
	}, 1250);
	var backupplus_inpage_modal_buttons = {};
	var inpage_modal_exists = jQuery('#backupplus-backupnow-inpage-modal').length;
	if (inpage_modal_exists) {
		jQuery('#backupplus-backupnow-inpage-modal').dialog('option', 'buttons', backupplus_inpage_modal_buttons);
	}
	jQuery('#backupplus_inpage_prebackup').hide();
	if (inpage_modal_exists) {
		jQuery('#backupplus-backupnow-inpage-modal').dialog('open');
	}
	jQuery('#backupplus_inpage_backup').show();
	backupplus_activejobslist_backupnownonce_only = 1;
	backupplus_inpage_hasbegun = 0;
	backupplus_backupnow_go(backupnow_nodb, backupnow_nofiles, backupnow_nocloud, onlythisfileentity, extradata, label, '');
}

function backupplus_get_downloaders() {
	var downloaders = '';
	jQuery('.ud_downloadstatus .backupplus_downloader, #ud_downloadstatus2 .backupplus_downloader, #ud_downloadstatus3 .backupplus_downloader').each(function(x,y) {
		var dat = jQuery(y).data('downloaderfor');
		if (typeof dat == 'object') {
			if (downloaders != '') { downloaders = downloaders + ':'; }
			downloaders = downloaders + dat.base + ',' + dat.nonce + ',' + dat.what + ',' + dat.index;
		}
	});
	return downloaders;
}

function backupplus_poll_get_parameters() {
	
	var gdata = {
		downloaders: backupplus_get_downloaders()
	}
	
	try {
		if (jQuery('#backupplus-poplog').dialog('isOpen')) {
			gdata.log_fetch = 1;
			gdata.log_nonce = backupplus_poplog_log_nonce;
			gdata.log_pointer = backupplus_poplog_log_pointer
		}
	} catch (err) {
		console.log(err);
	}
	
	if (backupplus_activejobslist_backupnownonce_only && typeof backupplus_backupnow_nonce !== 'undefined' && '' != backupplus_backupnow_nonce) {
		gdata.thisjobonly = backupplus_backupnow_nonce;
	}

	if (0 !== jQuery('#backupplus_ajax_restore_job_id').length) gdata.backupplus_credentialtest_nonce = backupplus_credentialtest_nonce;
	
	return gdata;
}

var backupplus_activejobs_list_fatal_error_alert = true;
function backupplus_activejobs_update(force) {
	
	var $ = jQuery;
	
	var timenow = (new Date).getTime();
	if (false == force && timenow < backupplus_activejobs_nextupdate) { return; }
	backupplus_activejobs_nextupdate = timenow + 5500;
	
	var gdata = backupplus_poll_get_parameters();

	backupplus_send_command('activejobs_list', gdata, function(resp, status, response_raw) {
		backupplus_process_status_check(resp, response_raw, gdata);
	}, {
		type: 'GET',
		error_callback: function(response, status, error_code, resp) {
			if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
				console.error(resp.fatal_error_message);
				if (true === backupplus_activejobs_list_fatal_error_alert) {
					backupplus_activejobs_list_fatal_error_alert = false;
					alert(this.alert_done + ' ' +resp.fatal_error_message);
				}
			} else {
				var msg = (status == error_code) ? error_code : error_code+" ("+status+")";
				console.error(msg);
				console.log(response);
			}
			return false;
		}
	});
}

/**
 * Shows a modal on success
 *
 * @param {string|obj} args The message to display or an object of parameters
 */
function backupplus_show_success_modal(args) {
	if ('string' == typeof args) {
		args = {
			message: args
		};
	}
	var data = jQuery.extend(
		{
			icon: 'yes',
			close: backupplus_i18n.close,
			message: '',
			classes: 'success'
		},
		args
	);
	jQuery.blockUI({
		css: {
			width: '300px',
			border: 'none',
			'border-radius': '10px',
			left: 'calc(50% - 150px)'
		},
		message: '<div class="backupplus_success_popup '+data.classes+'"><span class="dashicons udp-dashicons-'+data.icon+'"></span><div class="backupplus_success_popup--message">'+data.message+'</div><button class="udp-button backupplus-close-overlay"><span class="udp-dashicons udp-dashicons-no-alt"></span>'+data.close+'</button></div>'
	});
	// close success popup
	setTimeout(jQuery.unblockUI, 5000);
	jQuery('.blockUI .backupplus-close-overlay').on('click', function() {
		jQuery.unblockUI();
	})
}

/**
 * Opens a dialog window showing the requested (or latest) log file, plus an option to download it
 *
 * @param {string} backup_nonce - the nonce of the log to display, or empty for the latest one
 */
function backupplus_popuplog(backup_nonce) {
		
		var loading_message = backupplus_i18n.loading_log_file;
		
		if (backup_nonce) { loading_message += ' (log.'+backup_nonce+'.txt)'; }
	
		jQuery('#backupplus-poplog').dialog("option", "title", loading_message);
		jQuery('#backupplus-poplog-content').html('<em>'+loading_message+' ...</em> ');
		jQuery('#backupplus-poplog').dialog("open");
		
		backupplus_send_command('get_log', backup_nonce, function(resp) {

			backupplus_poplog_log_pointer = resp.pointer;
			backupplus_poplog_log_nonce = resp.nonce;
			
			var download_url = '?page=backupplus&action=downloadlog&force_download=1&backupplus_backup_nonce='+resp.nonce;
			
			jQuery('#backupplus-poplog-content').html(resp.log);
			
			var log_popup_buttons = {};
			log_popup_buttons[backupplus_i18n.downloadlogfile] = function() {
 window.location.href = download_url; };
			log_popup_buttons[backupplus_i18n.close] = function() {
 jQuery(this).dialog("close"); };
			
			// Set the dialog buttons: Download log, Close log
			jQuery('#backupplus-poplog').dialog("option", "buttons", log_popup_buttons);
			jQuery('#backupplus-poplog').dialog("option", "title", 'log.'+resp.nonce+'.txt');
			
			backupplus_poplog_lastscroll = -1;
			
		}, { type: 'GET', timeout: 60000, error_callback: function(response, status, error_code, resp) {
				if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
				console.error(resp.fatal_error_message);
				jQuery('#backupplus-poplog-content').append(resp.fatal_error_message);
				} else {
				var msg = (status == error_code) ? error_code : error_code+" ("+status+")";
				jQuery('#backupplus-poplog-content').append(msg);
				console.log(response);
				}
			}
		});
}

function backupplus_showlastbackup() {
	
	backupplus_send_command('get_fragment', 'last_backup_html', function(resp) {
		
		response = resp.output;
		
		if (lastbackup_laststatus == response) {
			setTimeout(function() {
 backupplus_showlastbackup(); }, 7000);
		} else {
			jQuery('#backupplus_last_backup').html(response);
		}
		lastbackup_laststatus = response;
		
	}, { type: 'GET' });
	
}
