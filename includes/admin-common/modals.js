// Job deletion, disk space and the iframe and HTML modals.

function backupplus_activejobs_delete(jobid) {
	backupplus_aborted_jobs[jobid] = 1;
	jQuery('#backupplus-jobid-'+jobid).closest('.backupplus_row').addClass('deleting');
	backupplus_send_command('activejobs_delete', jobid, function(resp) {
		var job_row = jQuery('#backupplus-jobid-'+jobid).closest('.backupplus_row');
		job_row.addClass('deleting');

		if (resp.ok == 'Y') {
			jQuery('#backupplus-jobid-'+jobid).html(resp.m);
			job_row.remove();

			// inpage backup - Close modal if canceling backup
			if (jQuery('#backupplus-backupnow-inpage-modal').dialog('isOpen')) jQuery('#backupplus-backupnow-inpage-modal').dialog('close');

			backupplus_show_success_modal({
				message: backupplus_i18n.backup_aborted,
				icon: 'no-alt',
				classes: 'warning'
			});
		} else if ('N' == resp.ok) {
			job_row.removeClass('deleting');
			alert(resp.m);
		} else {
			job_row.removeClass('deleting');
			alert(backupplus_i18n.unexpectedresponse);
			console.log(resp);
		}
	});
}

function backupplus_diskspace_entity(key) {
	jQuery('#backupplus_diskspaceused_'+key).html('<em>'+backupplus_i18n.calculating+'</em>');
	backupplus_send_command('get_fragment', { fragment: 'disk_usage', data: key }, function(response) {
		jQuery('#backupplus_diskspaceused_'+key).html(response.output);
	}, { type: 'GET' });
}

/**
 * Open a modal with content fetched from an iframe
 *
 * @param {String} getwhat - the subaction parameter to pass to UD's AJAX handler
 * @param {String} title   - the title for the modal
 */
function backupplus_iframe_modal(getwhat, title) {
	var width = 780;
	var height = 500;
	jQuery('#backupplus-iframe-modal-innards').html('<iframe width="100%" height="430px" src="'+ajaxurl+'?action=backupplus_ajax&subaction='+getwhat+'&nonce='+backupplus_credentialtest_nonce+'"></iframe>');
	jQuery('#backupplus-iframe-modal').dialog({
		title: title, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			jQuery(this).dialog('option', 'width', width),
			jQuery(this).dialog('option', 'minHeight', 260);
			if (jQuery(window).height() > height) {
				jQuery(this).dialog('option', 'height', height);
			} else {
				jQuery(this).dialog('option', 'height', jQuery(window).height()-30);
			}
		}
	}).dialog('open');
}

function backupplus_html_modal(showwhat, title, width, height) {
	jQuery('#backupplus-iframe-modal-innards').html(showwhat);
	var backupplus_html_modal_buttons = {};
	if (width < 450) {
		backupplus_html_modal_buttons[backupplus_i18n.close] = function() {
 jQuery(this).dialog("close"); };
	}
	jQuery('#backupplus-iframe-modal').dialog({
		title: title, buttons: backupplus_html_modal_buttons, resizeOnWindowResize: true, scrollWithViewport: true, resizeAccordingToViewport: true, useContentSize: false,
		open: function(event, ui) {
			jQuery(this).dialog('option', 'width', width),
			jQuery(this).dialog('option', 'minHeight', 260);
			if (jQuery(window).height() > height) {
				jQuery(this).dialog('option', 'height', height);
			} else {
				jQuery(this).dialog('option', 'height', jQuery(window).height()-30);
			}
		}
	}).dialog('open');
}

function backupplus_diskspace() {
	jQuery('#backupplus-navtab-backups-content .backupplus_diskspaceused').html('<em>'+backupplus_i18n.calculating+'</em>');
	backupplus_send_command('get_fragment', { fragment: 'disk_usage', data: 'backupplus' }, function(response) {
		jQuery('#backupplus-navtab-backups-content .backupplus_diskspaceused').html(response.output);
	}, { type: 'GET' });
}
var lastlog_lastmessage = "";
