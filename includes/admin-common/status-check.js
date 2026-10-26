// Processing the status returned by polling and the heartbeat.

/**
 * Process a status check result
 *
 * @param {Object} resp				   - the response after being parsed
 * @param {String} response_raw		   - the raw response
 * @param {Object} original_parameters - the original parameters used to make the check
 *
 * @returns {void}
 */
function backupplus_process_status_check(resp, response_raw, original_parameters) {
	
	if (resp.hasOwnProperty('fatal_error')) {
		console.error(resp.fatal_error_message);
		if (true === backupplus_activejobs_list_fatal_error_alert) {
			backupplus_activejobs_list_fatal_error_alert = false;
			alert(this.alert_done + ' ' +resp.fatal_error_message);
		}
		return;
	}
	
	try {
		if (resp.hasOwnProperty('l')) {
			if (resp.l) {
				jQuery('#backupplus_lastlogmessagerow').show();
				jQuery('#backupplus_lastlogcontainer').html(resp.l);
			} else {
				jQuery('#backupplus_lastlogmessagerow').hide();
				jQuery('#backupplus_lastlogcontainer').html('('+backupplus_i18n.nothing_yet_logged+')');
			}
		}

		// hosting restrictions
		if (backupplus_i18n.hasOwnProperty('hosting_restriction') && backupplus_i18n.hosting_restriction instanceof Array) {
			backupplus_i18n.hosting_restriction.length = 0;
			if (resp.hasOwnProperty('hosting_restriction')) {
				if (resp.hosting_restriction && resp.hosting_restriction.includes('only_one_backup_per_month')) {
					backupplus_i18n.hosting_restriction.push('only_one_backup_per_month');
				}
				if (resp.hosting_restriction && resp.hosting_restriction.includes('only_one_incremental_per_day')) {
					backupplus_i18n.hosting_restriction.push('only_one_incremental_per_day');
				}
			}
		}

		if (!jQuery('#backupplus-wrap #backupplus-navtab-settings-content').is(':hidden')) {
			// auto-updates synchronised setting
			if (resp.hasOwnProperty('automatic_updates')) {
				jQuery('input[name="backupplus_auto_updates"]').prop('checked', resp.automatic_updates);
			}
		}
		
		var lastactivity = -1;

		// Requested start of backup text
		var requeststart_el = jQuery('.backupplus_requeststart');
		if (resp.j && requeststart_el.length && requeststart_el.data('remove')) {
			requeststart_el.remove();
		}

		// Parse response to add classes before inserting it, to avoid unwanted artifacts
		var $list_prepare = jQuery(resp.j);
		$list_prepare.find('.backupplus_jobtimings').each(function(ind, element) {
			var $el = jQuery(element);
			if ($el.data('jobid')) {
				var jobid = $el.data('jobid');
				var job_row = $el.closest('.backupplus_row');
				if (backupplus_aborted_jobs[jobid]) {
					job_row.hide();
				}
			}
		});

		jQuery('#backupplus_activejobsrow').html($list_prepare);

		jQuery('#backupplus_activejobs .backupplus_jobtimings').each(function(ind, element) {
			var $el = jQuery(element);
			// lastactivity, nextresumption, nextresumptionafter
			if ($el.data('lastactivity') && $el.data('jobid')) {
				var jobid = $el.data('jobid');
				var new_lastactivity = $el.data('lastactivity');
				if (lastactivity == -1 || new_lastactivity < lastactivity) { lastactivity = new_lastactivity; }
				var nextresumptionafter = $el.data('nextresumptionafter');
				var nextresumption = $el.data('nextresumption');

				// Milliseconds
				timenow = (new Date).getTime();
				if (new_lastactivity > 50 && nextresumption >0 && nextresumptionafter < -30 && timenow > backupplus_last_forced_when+100000 && (backupplus_last_forced_jobid != jobid || nextresumption != backupplus_last_forced_resumption)) {
					backupplus_last_forced_resumption = nextresumption;
					backupplus_last_forced_jobid = jobid;
					backupplus_last_forced_when = timenow;
					console.log('BackupPlus: force resumption: job_id='+jobid+', resumption='+nextresumption);
					backupplus_send_command('forcescheduledresumption', {
						resumption: nextresumption,
						job_id: jobid
					}, function(response) {
						console.log(response);
					}, { json_parse: false, alert_on_error: false });
				}
			}
		});
		
		timenow = (new Date).getTime();
		backupplus_activejobs_nextupdate = timenow + 180000;
		// More rapid updates needed if a) we are on the main console, or b) a downloader is open (which can only happen on the restore console)
		if ((backupplus_page_is_visible == 1 && 'backups' == backupplus_console_focussed_tab)) {
			if (lastactivity > -1) {
				if (lastactivity < 5) {
					backupplus_activejobs_nextupdate = timenow + 1750;
				} else {
					backupplus_activejobs_nextupdate = timenow + 5000;
				}
			} else if (lastlog_lastdata == response_raw) {
				backupplus_activejobs_nextupdate = timenow + 7500;
			} else {
				backupplus_activejobs_nextupdate = timenow + 1750;
			}
		}

		lastlog_lastdata = response_raw;
		
		if (resp.j != null && resp.j != '') {
			jQuery('#backupplus_activejobsrow').show();

			if (original_parameters.hasOwnProperty('thisjobonly') && !backupplus_inpage_hasbegun && jQuery('#backupplus-jobid-'+original_parameters.thisjobonly).length) {
				backupplus_inpage_hasbegun = 1;
				console.log('BackupPlus: the start of the requested backup job has been detected');
			} else if (!backupplus_inpage_hasbegun && backupplus_activejobslist_backupnownonce_only && jQuery('.backupplus_jobtimings.isautobackup').length) {
				autobackup_nonce = jQuery('.backupplus_jobtimings.isautobackup').first().data('jobid');
				if (autobackup_nonce) {
					backupplus_inpage_hasbegun = 1;
					backupplus_backupnow_nonce = autobackup_nonce;
					original_parameters.thisjobonly = autobackup_nonce;
					console.log('BackupPlus: the start of the requested backup job has been detected; id: '+autobackup_nonce);
				}
			}

			if (backupplus_inpage_hasbegun == 1 && jQuery('#backupplus-jobid-'+original_parameters.thisjobonly+'.backupplus_finished').length) {
				// Don't reset to 0 - this will cause the 'began' event to be detected again
				backupplus_inpage_hasbegun = 2;

				console.log('BackupPlus: the end of the requested backup job has been detected');
				if (backupplus_activejobs_update_timer) clearInterval(backupplus_activejobs_update_timer);
				if (typeof backupplus_inpage_success_callback !== 'undefined' && backupplus_inpage_success_callback != '') {
					// Move on to next page
					backupplus_inpage_success_callback.call(false);
				} else {
					jQuery('#backupplus-backupnow-inpage-modal').dialog('close');
				}
			}
			if ('' == lastlog_jobs) {
				setTimeout(function() {
					jQuery('#backupplus_backup_started').slideUp();}, 3500);
			}

			// detect manual backup
			if (original_parameters.hasOwnProperty('thisjobonly') && backupplus_backupnow_nonce && original_parameters.thisjobonly === backupplus_backupnow_nonce) {
				jQuery('.backupplus_requeststart').remove();

				var thisjob = jQuery('#backupplus-jobid-'+backupplus_backupnow_nonce);
				// detect manual backup end
				if (thisjob.is('.backupplus_finished')) {
					// reset current job vars
					backupplus_activejobslist_backupnownonce_only = 0;
					// Aborted jobs
					if (backupplus_aborted_jobs[backupplus_backupnow_nonce]) {
						// remove deleted job from deleted jobs list
						backupplus_aborted_jobs = backupplus_aborted_jobs.filter(function(val, index) {
							return val != backupplus_backupnow_nonce;
						});
					} else {
						backupplus_show_success_modal(backupplus_i18n.backup_complete);
					}
					backupplus_backupnow_nonce = '';
					// Force fetch active jobs
					backupplus_activejobs_update(true);

				}
			}
		} else {
			if (!jQuery('#backupplus_activejobsrow').is(':hidden')) {
				// Backup has now apparently finished - hide the row. If using this for detecting a finished job, be aware that it may never have shown in the first place - so you'll need more than this.
				if (typeof lastbackup_laststatus != 'undefined') { backupplus_showlastbackup(); }
				backupplus_updatehistory(0, 0);
				jQuery('#backupplus_activejobsrow').hide();
			}
		}
		lastlog_jobs = resp.j;
		
		// Download status
		if (resp.ds != null && resp.ds != '') {
			backupplus_downloader_status_update(resp.ds, response_raw);
		}

		if (resp.u != null && resp.u != '' && jQuery("#backupplus-poplog").dialog("isOpen")) {
			var log_append_array = resp.u;
			if (log_append_array.nonce == backupplus_poplog_log_nonce) {
				backupplus_poplog_log_pointer = log_append_array.pointer;
				if (log_append_array.log != null && log_append_array.log != '') {
					var oldscroll = jQuery('#backupplus-poplog').scrollTop();
					jQuery('#backupplus-poplog-content').append(log_append_array.log);
					if (backupplus_poplog_lastscroll == oldscroll || backupplus_poplog_lastscroll == -1) {
						jQuery('#backupplus-poplog').scrollTop(jQuery('#backupplus-poplog-content').prop("scrollHeight"));
						backupplus_poplog_lastscroll = jQuery('#backupplus-poplog').scrollTop();
					}
				}
			}
		}
	} catch (err) {
		console.log(backupplus_i18n.unexpectedresponse+' '+response_raw);
		console.log(err);
	}
}
