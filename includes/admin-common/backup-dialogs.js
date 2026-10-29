// Selection helpers and openers for the Backup Now, restore and delete dialogs.

function backupnow_whichfiles_checked(onlythesefileentities){
	var entity_prefix = 'backupplus_include_';
	jQuery('#backupnow_includefiles_moreoptions input[type="checkbox"]').each(function(index) {
		if (!jQuery(this).is(':checked')) { return; }
		var name = jQuery(this).attr('name');
		if ('string' !== typeof name || name.indexOf(entity_prefix) !== 0) { return; }
		var entity = name.substring(entity_prefix.length);
		if (onlythesefileentities != '') { onlythesefileentities += ','; }
		onlythesefileentities += entity;
	});
// console.log(onlythesefileentities);
	return onlythesefileentities;
}

/**
 * A method to get all the selected table values from the backup now modal
 *
 * @param {string} onlythesetableentities an empty string to append values to
 *
 * @return {string} a string that contains the values of all selected table entities and the database the belong to
 */
function backupnow_whichtables_checked(onlythesetableentities){
	var send_list = false;
	jQuery('#backupnow_database_moreoptions .backupplus_db_entity').each(function(index) {
		if (!jQuery(this).is(':checked')) { send_list = true; return; }
		if (jQuery(this).is(':checked') && jQuery(this).data('non_wp_table')) { send_list = true; return; }
	});

	onlythesetableentities = jQuery("input[name^='backupplus_include_tables_']").serializeArray();

	if (send_list) {
		return onlythesetableentities;
	} else {
		return true;
	}
}

function backupplus_deleteallselected() {
	var howmany = 0;
	var remote_exists = 0;
	var key_all = '';
	var nonce_all = '';
	var remote_all = 0;
	var remote_missing = 0;
	var chains = [];
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row.backuprowselected').each(function(index) {
		howmany++;
		var nonce = jQuery(this).data('nonce');
		if (nonce_all) { nonce_all += ','; }
		nonce_all += nonce;
		var key = jQuery(this).data('key');
		if (key_all) { key_all += ','; }
		key_all += key;
		var has_remote = jQuery(this).find('.backupplus-remove').data('hasremote');
		if (has_remote) remote_all++;
		if (jQuery(this).find('.backupplus-remove').data('remotemissing')) remote_missing++;
		var chain = jQuery(this).find('.backupplus-delete-link').data('chain');
		if (chain) chains.push(chain);
	});
	backupplus_delete(key_all, nonce_all, remote_all, chains, remote_missing);
}

/**
 * Open main tab which is given as argument
 *
 * @param {string} active_tab_key A tab key which you would like to open
 */
function backupplus_open_main_tab(active_tab_key) {
	backupplus_i18n.main_tabs_keys.forEach(function(tab_key) {
		if (active_tab_key == tab_key) {
			jQuery('#backupplus-navtab-' + tab_key + '-content').show();
			jQuery('#backupplus-navtab-' + tab_key).addClass('nav-tab-active');
		} else {
			jQuery('#backupplus-navtab-' + tab_key + '-content').hide();
			jQuery('#backupplus-navtab-' + tab_key).removeClass('nav-tab-active');
		}
		backupplus_console_focussed_tab = active_tab_key;
	});
}

/**
 * Open an existing backups tab
 *
 * @param {Boolean} toggly Whether switch on backupplus_historytimer or not
 */
function backupplus_openrestorepanel(toggly) {
	// jQuery('.download-backups').slideDown(); backupplus_historytimertoggle(1); jQuery('html,body').animate({scrollTop: jQuery('#backupplus_lastlogcontainer').offset().top},'slow');
	backupplus_historytimertoggle(toggly);
	backupplus_open_main_tab('backups');
}

function backupplus_delete_old_dirs() {
	return true;
}

function backupplus_initiate_restore(whichset) {
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups .restore-button button[data-backup_timestamp="'+whichset+'"]').trigger('click');
}

function backupplus_restore_setoptions(entities) {
	var howmany = 0;
	jQuery('input[name="backupplus_restore[]"]').each(function(x,y) {
		var entity = jQuery(y).val();
		var epat = '\/'+entity+'=([0-9,]+)';
		var eregex = new RegExp(epat);
		var ematch = entities.match(eregex);
		if (ematch) {
			jQuery(y).prop('disabled', false).data('howmany', ematch[1]).parent().show();
			howmany++;
			if ('db' == entity) { howmany += 4.5;}
			if (jQuery(y).is(':checked')) {
				// This element may or may not exist. The purpose of explicitly calling show() is that Firefox, when reloading (including via forwards/backwards navigation) will remember checkbox states, but not which DOM elements were showing/hidden - which can result in some being hidden when they should be shown, and the user not seeing the options that are/are not checked.
				jQuery('#backupplus_restorer_'+entity+'options').show();
			}
		} else {
			jQuery(y).attr('disabled','disabled').parent().hide();
		}
	});
	var cryptmatch = entities.match(/dbcrypted=1/);
	if (cryptmatch) {
		jQuery('#backupplus_restore_db').data('encrypted', 1);
		jQuery('.backupplus_restore_crypteddb').show();
	} else {
		jQuery('#backupplus_restore_db').data('encrypted', 0);
		jQuery('.backupplus_restore_crypteddb').hide();
	}
	jQuery('#backupplus_restore_db').trigger('change');
	jQuery('.backupplus-restore-encryption').toggle(/\/crypted=1\//.test(entities));
	var dmatch = entities.match(/meta_foreign=([12])/);
	if (dmatch) {
		jQuery('#backupplus_restore_meta_foreign').val(dmatch[1]);
	} else {
		jQuery('#backupplus_restore_meta_foreign').val('0');
	}
}

/**
 * Open the 'Backup Now' dialog box
 *
 * @param {string} type - the backup type; either "new" or "incremental"
 */
function backupplus_backup_dialog_open(type) {
	
	type = ('undefined' === typeof type) ? 'new' : type;

	if (0 == jQuery('#backupplus_incremental_backup_link').data('incremental') && 'incremental' == type) {
		jQuery('#backupplus-backupnow-modal .incremental-free-only').show();
		type = 'new';
	} else {
		jQuery('#backupplus-backupnow-modal .incremental-backups-only, #backupplus-backupnow-modal .incremental-free-only').hide();
	}
	
	jQuery('#backupnow_includefiles_moreoptions').hide();
	if (!backupplus_settings_form_changed || window.confirm(backupplus_i18n.unsavedsettingsbackup)) {
		jQuery('#backupnow_label').val('');
		if ('incremental' == type) {
			update_file_entities_checkboxes(true, impossible_increment_entities);
			jQuery('#backupnow_includedb').prop('checked', 1 == jQuery('#backupnow_database_container').data('pitr-enabled'));
			jQuery('#backupnow_includefiles').prop('checked', true);
			jQuery('#backupnow_includefiles_label').text(backupplus_i18n.files_incremental_backup);
			jQuery('#backupnow_includedb_label').text(backupplus_i18n.database_incremental_backup);
			jQuery('#backupnow_database_moreoptions').hide();
			jQuery('#backupplus-backupnow-modal .new-backups-only').hide();
			jQuery('#backupplus-backupnow-modal .incremental-backups-only').show();
		} else {
			update_file_entities_checkboxes(false, impossible_increment_entities);
			jQuery('#backupnow_includedb').prop('checked', true);
			jQuery('#backupnow_includefiles_label').text(backupplus_i18n.files_new_backup);
			jQuery('#backupnow_includedb_label').text(backupplus_i18n.database_new_backup);
			jQuery('#backupplus-backupnow-modal .new-backups-only').show();
			jQuery('#backupplus-backupnow-modal .incremental-backups-only').hide();
		}
		jQuery('#backupplus-backupnow-modal').data('backup-type', type);
		var pitr_enabled = 1 == jQuery('#backupnow_database_container').data('pitr-enabled');
		jQuery('#backupnow_database_container').toggle('incremental' != type || pitr_enabled);
		jQuery('#backupnow_pitr_disabled').toggle('incremental' == type && !pitr_enabled);
		backupplus_backupnow_update_selection();
		jQuery('#backupplus-backupnow-modal').dialog('open');
	}
}

function backupplus_backupnow_pitr_selected() {
	return 'incremental' == jQuery('#backupplus-backupnow-modal').data('backup-type') && jQuery('#backupnow_includedb').is(':checked');
}

function backupplus_backupnow_update_selection() {
	// PITR destinations belong to the saved chain; these temporary controls apply to files.
	jQuery('#backupnow_remote_container').toggle(!backupplus_backupnow_pitr_selected() || jQuery('#backupnow_includefiles').is(':checked'));
	jQuery('label[for="backupnow_includecloud"]').text(backupplus_backupnow_pitr_selected() ? backupplus_i18n.send_files_remote : backupplus_i18n.send_backup_remote);
}
/**
 * Open the 'Backup Now' dialog box
 *
 * @param {string} type - the backup type; either "new" or "incremental"
 */
/**
 * This function will enable and disable the file entity options depending on what entities increments can be added to and if this is a new backup or not.
 *
 * @param {boolean} incremental - a boolean to indicate if this is an incremental backup or not
 * @param {array}   entities    - an array of entities to disable
 */
function update_file_entities_checkboxes(incremental, entities) {
	if (incremental) {
		jQuery(entities).each(function (index, entity) {
			jQuery('#backupnow_files_backupplus_include_' + entity).prop('checked', false);
			jQuery('#backupnow_files_backupplus_include_' + entity).prop('disabled', true);
		});
	} else {
		jQuery('#backupnow_includefiles_moreoptions input[type="checkbox"]').each(function (index) {
			var name = jQuery(this).attr('name');
			var entity_prefix = 'backupplus_include_';
			if ('string' !== typeof name || name.indexOf(entity_prefix) !== 0) { return; }
			var entity = name.substring(entity_prefix.length);
			if (typeof jQuery('#backupnow_files_backupplus_include_' + entity).data('force_disabled') == 'undefined') {
				jQuery('#backupnow_files_backupplus_include_' + entity).prop('disabled', false);
				if (jQuery('#backupplus_include_' + entity).is(':checked')) {
					jQuery('#backupnow_files_backupplus_include_' + entity).prop('checked', true);
				}
			} else {
				jQuery('#backupnow_files_backupplus_include_' + entity).prop('disabled', true);
				jQuery('#backupnow_files_backupplus_include_' + entity).prop('checked', false);
			}
		});
	}
}

var onlythesefileentities = backupnow_whichfiles_checked('');
if ('' == onlythesefileentities) {
	jQuery("#backupnow_includefiles_moreoptions").show();
} else {
	jQuery("#backupnow_includefiles_moreoptions").hide();
}
