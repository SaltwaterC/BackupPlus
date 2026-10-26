// Shared page state, the floating save button and page visibility tracking.

var impossible_increment_entities;
var backupplus_restore_stage = 1;
var lastlog_lastmessage = "";
var lastlog_lastdata = "";
var lastlog_jobs = "";
// var lastlog_sdata = { action: 'backupplus_ajax', subaction: 'lastlog' };
var backupplus_activejobs_nextupdate = (new Date).getTime() + 1000;
// Bits: main tab displayed (1); restore dialog open (uses downloader) (2); tab not visible (4)
var backupplus_page_is_visible = 1;
var backupplus_console_focussed_tab = backupplus_i18n.tab;
var php_max_input_vars = 0;
var skipped_db_scan = 0;

var backupplus_settings_form_changed = false;
var save_button_added = false;

function load_save_button() {
	if (backupplus_settings_form_changed && !save_button_added) {
		save_button_added = true;
		jQuery('#backupplus-navtab-settings-content').prepend('<input style="position:fixed;top:46px; right:20px;z-index: 999999;" type="button" class="button-primary" id="backupplus-floating-settings-save" value="'+backupplus_i18n.save_changes+'">');
		
		// The click event for the save button will be registered every time the button is created. We need to use the 'one' method instead of the 'on' method to make sure the click event is executed only once.
		jQuery("#backupplus-navtab-settings-content").one('click', '#backupplus-floating-settings-save', function() {
			jQuery("#backupplus-settings-save").trigger('click');
			jQuery("#backupplus-floating-settings-save").remove();
			save_button_added = false;
		});
		
		jQuery("#backupplus-settings-save").one('click', function() {
			jQuery("#backupplus-floating-settings-save").remove();
			save_button_added = false;
		});

	}
}
window.onbeforeunload = function(e) {
	if (backupplus_settings_form_changed) return backupplus_i18n.unsavedsettings;
}

/**
 * N.B. This function works on both the UD settings page and elsewhere
 *
 * @param {boolean} firstload Check if this is first load
 */
function backupplus_check_page_visibility(firstload) {
	if ('hidden' == document["visibilityState"]) {
		backupplus_page_is_visible = 0;
	} else {
		backupplus_page_is_visible = 1;
		if (1 !== firstload) {
			if (jQuery('#backupplus-navtab-backups-content').length) {
				backupplus_activejobs_update(true);
			}
		}
	};
}

// See http://caniuse.com/#feat=pagevisibility for compatibility (we don't bother with prefixes)
if (typeof document.hidden !== "undefined") {
	document.addEventListener('visibilitychange', function() {
backupplus_check_page_visibility(0);}, false);
}

backupplus_check_page_visibility(1);

var backupplus_poplog_log_nonce;
var backupplus_poplog_log_pointer = 0;
var backupplus_poplog_lastscroll = -1;
var backupplus_last_forced_jobid = -1;
var backupplus_last_forced_resumption = -1;
var backupplus_last_forced_when = -1;

var backupplus_backupnow_nonce = '';
var backupplus_activejobslist_backupnownonce_only = 0;
var backupplus_inpage_hasbegun = 0;
var backupplus_activejobs_update_timer;
var backupplus_aborted_jobs = [];
