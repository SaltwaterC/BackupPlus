// Restore preparation, download status and size formatting.

async function backupplus_restorer_checkstage2(doalert) {
	if (window.backupplus_restore_preparing) return;
	// How many left?
	var stilldownloading = jQuery('#ud_downloadstatus2 .file').length;
	if (stilldownloading > 0) {
		if (doalert) { alert(backupplus_i18n.stilldownloading); }
		return;
	}
	// Allow pressing 'Restore' to proceed
	jQuery('.backupplus-restore--next-step').prop('disabled', true);
	jQuery('#backupplus-restore-modal-stage2a').html('<span class="dashicons dashicons-update rotate"></span> '+backupplus_i18n.preparing_backup_files);
	window.backupplus_restore_preparing = true;
	var prepared = false;
	try {
		prepared = await window.backupplus_prepare_restore();
	} catch (error) {
		jQuery('#backupplus-restore-modal-stage2a').text(backupplus_decryption.errors.TRANSFER_FAILED);
	} finally {
		window.backupplus_restore_preparing = false;
	}
	if (!prepared) {
		if (jQuery('.backupplus_restore_container').is(':visible')) {
			backupplus_restore_stage = 1;
			jQuery('#backupplus-restore-modal-stage1').show();
			jQuery('.backupplus-restore--stages li').removeClass('active').first().addClass('active');
			jQuery('.backupplus-restore--next-step').text(jQuery('.backupplus-restore--next-step').data('default-text'));
		}
		jQuery('.backupplus-restore--next-step').prop('disabled', false);
		return;
	}
	backupplus_send_command('restore_alldownloaded', {
		timestamp: jQuery('#backupplus_restore_timestamp').val(),
		restoreopts: jQuery('#backupplus_restore_form').serialize()
	}, function(resp, status, data) {
		var info = null;
		jQuery('#backupplus_restorer_restore_options').val('');
		jQuery('.backupplus-restore--next-step').prop('disabled', false);
		try {
			// var resp = ud_parse_json(data);
			if (null == resp) {
				jQuery('#backupplus-restore-modal-stage2a').html(backupplus_i18n.emptyresponse);
				return;
			}
			var report = resp.m;
			if (resp.w != '') {
				report = report + '<div class="udp-notice notice-warning"><p><span class="dashicons dashicons-warning"></span> <strong>' + backupplus_i18n.warnings +'</strong></p>' + resp.w + '</div>';
			}
			if (resp.e != '') {
				report = report + '<div class="udp-notice notice-error"><p><span class="dashicons dashicons-dismiss"></span> <strong>' + backupplus_i18n.errors+'</strong></p>' + resp.e + '</div>';
			} else {
				backupplus_restore_stage = 3;
			}
			if (resp.hasOwnProperty('i')) {
				// Store the information passed back from the backup scan
				try {
					info = ud_parse_json(resp.i);
					if (info.source_defaults) {
						Object.keys(info.source_defaults).forEach(function(id) { var input = jQuery('[name="backupplus_source_destinations['+id+']"]'); if (!input.val()) { input.val(info.source_defaults[id]); } });
					}
					if (info.age_ticket) {
						jQuery('#backupplus-age-ticket').val(info.age_ticket);
						jQuery('#backupplus-age-identity, #backupplus-age-key-passphrase').val('').prop('disabled', true);
					}
// if (info.hasOwnProperty('multisite') && info.multisite && info.hasOwnProperty('same_url') && info.same_url) {
					if (info.hasOwnProperty('addui')) {
						console.log("Further UI options are being displayed");
						var addui = info.addui;
						report += '<div id="backupplus_restoreoptions_ui">'+addui+'</div>';
						if (typeof JSON == 'object' && typeof JSON.stringify == 'function') {
							// If possible, remove from the stored info, to prevent passing back potentially large amounts of unwanted data
							delete info.addui;
							resp.i = JSON.stringify(info);
						}
					}
					if (info.hasOwnProperty('php_max_input_vars')) {
						php_max_input_vars = parseInt(info.php_max_input_vars);
					}
					if (info.hasOwnProperty('skipped_db_scan')) {
						skipped_db_scan = parseInt(info.skipped_db_scan);
					}
				} catch (err) {
					console.log(err);
					console.log(resp);
				}
				jQuery('#backupplus_restorer_backup_info').val(resp.i);
			} else {
				jQuery('#backupplus_restorer_backup_info').val();
			}
			jQuery('#backupplus-restore-modal-stage2a').html(report);
			jQuery('.backupplus-restore--next-step').text(backupplus_i18n.restore);
			if (jQuery('#backupplus-restore-modal-stage2a .backupplus_select2').length > 0) {
				jQuery('#backupplus-restore-modal-stage2a .backupplus_select2').select2();
			}
		} catch (err) {
			console.log(data);
			console.log(err);
			jQuery('#backupplus-restore-modal-stage2a').text(backupplus_i18n.jsonnotunderstood+' '+backupplus_i18n.errordata+": "+data).html();
		}
	}, { error_callback: function(response, status, error_code, resp) {
			if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
				console.error(resp.fatal_error_message);
				jQuery('#backupplus-restore-modal-stage2a').html('<p style="color: red;">'+resp.fatal_error_message+'</p>');
				alert(resp.fatal_error_message);
			} else {
				var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
				jQuery('#backupplus-restore-modal-stage2a').html('<p style="color: red;">'+error_message+'</p>');
				console.log(error_message);
				alert(error_message);
				console.log(response);
			}
		}
	});
}


function backupplus_downloader_status(base, nonce, what, findex) {
	// Short-circuit. See previous versions for the old code.
	return;
}

function backupplus_downloader_status_update(download_status, response_raw) {
	
	var cancel_repeat = 0;

	jQuery(download_status).each(function (x, dstatus) {
		if (dstatus.base == '') return;
		
		var stid = dstatus.base + dstatus.timestamp + '_' + dstatus.what + '_' + dstatus.findex;
		var stid_selector = '.' + stid;
		jQuery(stid_selector).data('download-ready', dstatus.e == null && dstatus.p >= 100 && !!dstatus.f);
		
		if (dstatus.e != null) {
			jQuery(stid_selector + ' .raw').html('<strong>' + backupplus_i18n.error + '</strong> ' + dstatus.e);
			console.log(dstatus);
		} else if (dstatus.p != null) {
			jQuery(stid_selector + '_st .dlfileprogress').width(dstatus.p + '%');
			// jQuery(stid_selector+'_st .dlsofar').html(Math.round(dstatus.s/1024));
			// jQuery(stid_selector+'_st .dlsize').html(Math.round(dstatus.t/1024));

			// Is a restart appropriate?
			// dstatus.a, if set, indicates that a) the download is incomplete and b) the value is the number of seconds since the file was last modified...
			if (dstatus.a != null && dstatus.a > 0) {
				var timenow = (new Date).getTime();
				var lasttimebegan = jQuery(stid_selector).data('lasttimebegan');
				// Remember that this is in milliseconds
				var sincelastrestart = timenow - lasttimebegan;
				if (dstatus.a > 90 && sincelastrestart > 60000) {
					console.log(dstatus.timestamp + " " + dstatus.what + " " + dstatus.findex + ": restarting download: file_age=" + dstatus.a + ", sincelastrestart_ms=" + sincelastrestart);
					jQuery(stid_selector).data('lasttimebegan', (new Date).getTime());

					var $original_button = jQuery('#backupplus-navtab-backups-content .uddownloadform_' + dstatus.what + '_' + dstatus.timestamp + '_0');

					var data = {
						type: dstatus.what,
						timestamp: dstatus.timestamp,
						findex: dstatus.findex
					};

					var options = {
						action: 'backupplus_download_backup',
						nonce_key: '_wpnonce',
						nonce: $original_button.data('wp_nonce').toString(),
						timeout: 10000
					};

					backupplus_send_command('backupplus_download_backup', data, null, options);

					jQuery(stid_selector).data('lasttimebegan', (new Date).getTime());
				}
			}

			if (dstatus.m != null) {
				if (dstatus.p >= 100 && 'udrestoredlstatus_' == dstatus.base) {
					jQuery(stid_selector + ' .raw').html(dstatus.m);
					jQuery(stid_selector).fadeOut('slow', function () {
						remove_backupplus_downloader(this, dstatus.what);
						backupplus_restorer_checkstage2(0);
					});
				} else if (dstatus.p < 100 || dstatus.base != 'uddlstatus_') {
					jQuery(stid_selector + ' .raw').html(dstatus.m);
				} else {
					var file_ready_actions = backupplus_i18n.fileready + ' ' + '(size: ' + convert_numeric_size_to_text(dstatus.s) + '). ' + backupplus_i18n.actions + ':\
				<button class="button" type="button" onclick="backupplus_downloadstage2(\''+ dstatus.timestamp + '\', \'' + dstatus.what + '\', \'' + dstatus.findex + '\')\">' + backupplus_i18n.downloadtocomputer + '</button> \
				<button class="button" id="uddownloaddelete_'+ dstatus.timestamp + '_' + dstatus.what + '" type="button" onclick="backupplus_deletefromserver(\'' + dstatus.timestamp + '\', \'' + dstatus.what + '\', \'' + dstatus.findex + '\')\">' + backupplus_i18n.deletefromserver + '</button>';

					if (dstatus.hasOwnProperty('can_show_contents') && dstatus.can_show_contents) {
						file_ready_actions += ' <button class="button" type="button" onclick="backupplus_show_contents(\'' + dstatus.timestamp + '\', \'' + dstatus.what + '\', \'' + dstatus.findex + '\')\">' + backupplus_i18n.browse_contents + '</button>';
					}
					jQuery(stid_selector + ' .raw').html(file_ready_actions);
					jQuery(stid_selector + '_st').remove();
				}
			}
			// dlstatus_lastlog = response_raw;
		} else if (dstatus.m != null) {
			jQuery(stid_selector + ' .raw').html(dstatus.m);
		} else {
			jQuery(stid_selector + ' .raw').html(backupplus_i18n.jsonnotunderstood + ' (' + response_raw + ')');
			cancel_repeat = 1;
		}
	});
	jQuery('.backupplus_downloader_container').each(function() { backupplus_download_controls_update(jQuery(this)); });

	return cancel_repeat;
}

/**
 * Converts a number of bytes into a suitable textual unit value
 *
 * @param {long} bytes
 * @returns string
 */
function convert_numeric_size_to_text(bytes) {
	if (1024 > bytes) {
		return bytes + " b";
	} else if (1024 * 1024 >bytes) {
		return (bytes / 1024).toFixed(2) + " KB";
	} else if (1024 * 1024 * 1024 > bytes ) {
		return (bytes / (1024 * 1024)).toFixed(2) + " MB";
	} else {
		return (bytes / (1024 * 1024 * 1024)).toFixed(2) + " GB";
	}
}
