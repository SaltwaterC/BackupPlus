// The existing backups history, its refresh timer and the cron ping.

var backupplus_historytimer = 0;
var calculated_diskspace = 0;
var backupplus_historytimer_notbefore = 0;
var backupplus_history_lastchecksum = false;

function backupplus_historytimertoggle(forceon) {
	if (!backupplus_historytimer || forceon == 1) {
		backupplus_updatehistory(0, 0);
		backupplus_historytimer = setInterval(function() {
backupplus_updatehistory(0, 0);}, 30000);
		if (!calculated_diskspace) {
			backupplus_diskspace();
			calculated_diskspace = 1;
		}
	} else {
		clearTimeout(backupplus_historytimer);
		backupplus_historytimer = 0;
	}
}

/**
 * Update the HTML for the 'existing backups' table; optionally, after local/remote re-scanning.
 * Nothing is returned; any update necessary is performed directly on the DOM.
 *
 * @param {Integer} rescan	     - first, re-scan the local storage (0 or 1)
 * @param {Integer} remotescan   - first, re-scan the remote storage (you must also set rescan to 1 to use this)
 * @param {Integer} debug	     - if 1, then also request debugging information and log it to the console
 * @param {Integer} backup_count - the amount of backups we want to display
 */
function backupplus_updatehistory(rescan, remotescan, debug, backup_count) {

	if ('undefined' != typeof backupplus_restore_screen && backupplus_restore_screen) return;

	if ('undefined' === typeof debug) {
		debug = jQuery('#backupplus_debug_mode').is(':checked') ? 1 : 0;
	}

	var unixtime = Math.round(new Date().getTime() / 1000);
	
	if (1 == rescan || 1 == remotescan) {
		backupplus_historytimer_notbefore = unixtime + 30;
	} else {
		if (unixtime < backupplus_historytimer_notbefore && 'undefined' === typeof backup_count) {
			console.log("Update history skipped: "+unixtime.toString()+" < "+backupplus_historytimer_notbefore.toString());
			return;
		}
	}

	if ('undefined' === typeof backup_count) {
		backup_count = 0;
	}
	
	
	if (rescan == 1) {
		if (remotescan == 1) {
			backupplus_history_lastchecksum = false;
			jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').html('<p style="text-align:center;"><em>'+backupplus_i18n.rescanningremote+'</em></p>');
		} else {
			backupplus_history_lastchecksum = false;
			jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').html('<p style="text-align:center;"><em>'+backupplus_i18n.rescanning+'</em></p>');
		}
	}
	
	var what_op = remotescan ? 'remotescan' : (rescan ? 'rescan' : false);
	
	var data = {
		operation: what_op,
		debug: debug,
		backup_count: backup_count,
	}
	
	backupplus_send_command('rescan', data, function(resp) {
		if (resp.hasOwnProperty('logs_exist') && resp.logs_exist) {
			// Show the "most recently modified log" link, in case it was previously hidden (if there were no logs until now)
			jQuery('#backupplus_lastlogmessagerow .backupplus-log-link').show();
		}
		
		
		if (resp.hasOwnProperty('web_server_disk_space')) {
			if ('' == resp.web_server_disk_space) {
				console.log("BackupPlus: web_server_disk_space is empty");
				if (jQuery('#backupplus-navtab-backups-content .backupplus-server-disk-space').length) {
					jQuery('#backupplus-navtab-backups-content .backupplus-server-disk-space').slideUp('slow',  function() {
						jQuery(this).remove();
					});
				}
			} else {
				if (jQuery('#backupplus-navtab-backups-content .backupplus-server-disk-space').length) {
					jQuery('#backupplus-navtab-backups-content  .backupplus-server-disk-space').replaceWith(resp.web_server_disk_space);
				} else {
					jQuery('#backupplus-navtab-backups-content .backupplus-disk-space-actions').prepend(resp.web_server_disk_space);
				}
			}
		}

		update_backupnow_modal(resp);
		
		if (resp.hasOwnProperty('backupnow_file_entities')) {
			impossible_increment_entities = resp.backupnow_file_entities;
		}
		
		if (resp.n != null) { jQuery('#backupplus-existing-backups-heading').html(resp.n); }
		
		if (resp.t != null) {
			if (resp.cksum != null) {
				if (resp.cksum == backupplus_history_lastchecksum) {
					// Avoid unnecessarily refreshing the HTML if the data is the same. This helps avoid resetting the DOM (annoying when debugging), and keeps user row selections.
					return;
				}
				backupplus_history_lastchecksum = resp.cksum;
			}
			jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').html(resp.t);
			backupplus_backups_selection.checkSelectionStatus();
			if (resp.data) {
				console.log(resp.data);
			}
		}
	});
}

/**
 * This function will check if the passed in response contains content for the backup now modal that needs updating on page
 *
 * @param {array} response - an array that may contain backupnow_modal content that needs updating
 */
function update_backupnow_modal(response) {
	if (response.hasOwnProperty('modal_afterfileoptions')) {
		jQuery('.backupnow_modal_afterfileoptions').html(response.modal_afterfileoptions);
	}
}

/**
 * Exclude entities hidden input field update
 *
 * @param {string} include_entity_name - backup entity name
 */
function backupplus_exclude_entity_update(include_entity_name) {
	var exclude_entities = [];
	jQuery('#backupplus_include_'+include_entity_name+'_exclude_container .backupplus_exclude_entity_wrapper .backupplus_exclude_entity_field').each(function() {
		var data_val = jQuery(this).data('val').toString().trim();
		if ('' != data_val) {
			exclude_entities.push(data_val);
		}
	});
	jQuery('#backupplus_include_'+include_entity_name+'_exclude').val(exclude_entities.join(','));
}

/**
 * Check uniqueness of exclude rule in include_backup_file
 *
 * @param {string} exclude_rule - exclude rule
 * @param {string} include_backup_file - the backup file type on which the exclude_rule will be applied
 *
 * @return {boolean} true if exclude_rule is unique otherwise false
 */
function backupplus_is_unique_exclude_rule(exclude_rule, include_backup_file) {
	existing_exclude_rules_str = jQuery('#backupplus_include_'+include_backup_file+'_exclude').val();
	existing_exclude_rules = existing_exclude_rules_str.split(',');
	
	if (jQuery.inArray(exclude_rule, existing_exclude_rules) > -1) {
		alert(backupplus_i18n.duplicate_exclude_rule_error_msg)
		return false;
	} else {
		return true;
	}
}


function backupplus_check_same_times() {
	var file_interval = jQuery('#backupplus-navtab-settings-content .backupplus_interval').val();
	var db_interval = jQuery('#backupplus-navtab-settings-content .backupplus_interval_database').val();
	jQuery('#backupplus-navtab-settings-content .backupplus_files_timings').toggle('manual' != file_interval);
	// Database timing stays editable when its frequency matches the files schedule.
	jQuery('#backupplus-navtab-settings-content .backupplus_db_timings').toggle('manual' != db_interval);
	if ('function' === typeof window.backupplus_schedule_refresh) {
		window.backupplus_schedule_refresh();
	}
}

// Visit the site in the background every 3.5 minutes - ensures that backups can progress if you've got the UD settings page open
if ('undefined' !== typeof backupplus_siteurl) {
	setInterval(function() {
jQuery.get(backupplus_siteurl+'/wp-cron.php');}, 210000);
}
