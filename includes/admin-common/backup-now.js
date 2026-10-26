// Starting a backup from the Backup Now dialog.

/**
 * Function that sets up a ajax call to start a backup
 *
 * @param {Integer} backupnow_nodb            Indicate whether the database should be backed up: valid values are 0, 1
 * @param {Integer} backupnow_nofiles         Indicate whether any files should be backed up: valid values are 0, 1
 * @param {Integer} backupnow_nocloud         Indicate whether the backup should be uploaded to cloud storage: valid values are 0, 1
 * @param {String}  onlythesefileentities     A csv list of file entities to be backed up
 * @param {Array}   extradata                 any extra data to be added
 * @param {String}  label                     A optional label to be added to a backup
 * @param {String}  onlythesetableentities    A csv list of table entities to be backed up
 * @param {String}  only_these_cloud_services An array of remote sorage locations to be backed up to
 */
function backupplus_backupnow_go(backupnow_nodb, backupnow_nofiles, backupnow_nocloud, onlythesefileentities, extradata, label, onlythesetableentities, only_these_cloud_services) {

	var params = {
		backupnow_nodb: backupnow_nodb,
		backupnow_nofiles: backupnow_nofiles,
		backupnow_nocloud: backupnow_nocloud,
		backupnow_label: label,
		extradata: extradata
	};
	
	if ('' != onlythesefileentities) {
		params.onlythisfileentity = onlythesefileentities;
	}

	if (null != onlythesetableentities && '' != onlythesetableentities) {
		params.onlythesetableentities = onlythesetableentities;
	}

	if ('' != only_these_cloud_services) {
		params.only_these_cloud_services = only_these_cloud_services;
	}
	
	params.always_keep = (typeof extradata.always_keep !== 'undefined') ? extradata.always_keep : 0;
	delete extradata.always_keep;

	params.incremental = (typeof extradata.incremental !== 'undefined') ? extradata.incremental : 0;
	if (!params.incremental && window.backupplus_database_override) { params.database_selection = JSON.stringify(window.backupplus_database_override()); }
	delete extradata.incremental;

	params.db_anon_all = (typeof extradata.db_anon !== 'undefined' && typeof extradata.db_anon.all !== 'undefined') ? extradata.db_anon.all : 0;
	params.db_anon_non_staff = (typeof extradata.db_anon !== 'undefined' && typeof extradata.db_anon.non_staff !== 'undefined') ? extradata.db_anon.non_staff : 0;
	params.db_anon_wc_orders = (typeof extradata.db_anon !== 'undefined' && typeof extradata.db_anon.wc_orders !== 'undefined') ? extradata.db_anon.wc_orders : 0;
	if ('undefined' !== typeof extradata.db_anon) {
		delete extradata.db_anon.all;
		delete extradata.db_anon.non_staff;
		delete extradata.db_anon.wc_orders;
	}


	// Display Request start message
	if (!jQuery('.backupplus_requeststart').length) {
		var requeststart_el = jQuery('<div class="backupplus_requeststart /">').html('<span class="spinner"></span>'+backupplus_i18n.requeststart);
		requeststart_el.data('remove', false);
		setTimeout(
			function() {
				requeststart_el.data('remove', true);
			},
			3000
		);
		setTimeout(
			function() {
				requeststart_el.remove();
			},
			75000
		);
		jQuery('#backupplus_activejobsrow').before(requeststart_el);
	}
	
	backupplus_activejobslist_backupnownonce_only = 1;
	backupplus_send_command('backupnow', params, function(resp) {
		if (resp.hasOwnProperty('error')) {
			jQuery('.backupplus_requeststart').remove();
			alert(resp.error);
			return;
		}
		jQuery('#backupplus_backup_started').html(resp.m);
		if (resp.hasOwnProperty('nonce')) {
			// Can't return it from this context
			backupplus_backupnow_nonce = resp.nonce;
			console.log("BackupPlus: ID of started job: "+backupplus_backupnow_nonce);
		}
		setTimeout(function() {
			backupplus_activejobs_update(true);}, 500);
	});
}
