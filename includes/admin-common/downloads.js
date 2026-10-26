// Downloading backup archives and browsing their contents.

function backupplus_deletefromserver(timestamp, type, findex) {
	if (!findex) findex=0;
	var pdata = {
		stage: 'delete',
		timestamp: timestamp,
		type: type,
		findex: findex
	};
	backupplus_send_command('backupplus_download_backup', pdata, null, { action: 'backupplus_download_backup', nonce: backupplus_download_nonce, nonce_key: '_wpnonce' });
}

function backupplus_downloadstage2(timestamp, type, findex) {
	location.href =ajaxurl+'?_wpnonce='+backupplus_download_nonce+'&timestamp='+timestamp+'&type='+type+'&stage=2&findex='+findex+'&action=backupplus_download_backup';
}

function backupplus_show_contents(timestamp, type, findex) {
	var modal_content = '<div id="backupplus_zip_files_container" class="" style="clear:left;"><div id="backupplus_zip_info_container" class="backupplus_jstree_info_container"><p><span id="backupplus_zip_path_text">' + backupplus_i18n.zip_file_contents_info + '</span> - <span id="backupplus_zip_size_text"></span></p>'+backupplus_i18n.browse_download_link+'</div><div id="backupplus_zip_files_jstree_container"><input type="search" id="zip_files_jstree_search" name="zip_files_jstree_search" placeholder="' + backupplus_i18n.search + '"><div id="backupplus_zip_files_jstree" class="backupplus_jstree"></div></div></div>';

	backupplus_html_modal(modal_content, backupplus_i18n.zip_file_contents, 780, 500);

	zip_files_jstree('zipbrowser', timestamp, type, findex);
}

/**
 * Creates the jstree and makes a call to the backend to dynamically get the tree nodes
 *
 * @param {string} entity     Entity for the jstree
 * @param {integer} timestamp Timestamp of the jstree
 * @param {string} type       Type of file to display in the JS tree
 * @param {array} findex      Index of Zip
 */
function zip_files_jstree(entity, timestamp, type, findex) {

	jQuery('#backupplus_zip_files_jstree').jstree({
		"core": {
			"multiple": false,
			"data": function (nodeid, callback) {
				backupplus_send_command('get_jstree_directory_nodes', {entity:entity, node:nodeid, timestamp:timestamp, type:type, findex:findex}, function(response) {
					if (response.hasOwnProperty('error')) {
						alert(response.error);
					} else {
						callback.call(this, response.nodes);
					}
				}, { error_callback: function(response, status, error_code, resp) {
						if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
						console.error(resp.fatal_error_message);
						jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+resp.fatal_error_message+'</p>');
						alert(resp.fatal_error_message);
						} else {
						var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
						jQuery('#backupplus_zip_files_jstree').html('<p style="color:red; margin: 5px;">'+error_message+'</p>');
						console.log(error_message);
						alert(error_message);
						console.log(response);
						}
					}
				});
			},
			"error": function(error) {
				alert(error);
				console.log(error);
			},
		},
		"search": {
			"show_only_matches": true
		},
		"plugins": ["search", "sort"],
	});

	// Update modal title once tree loads
	jQuery('#backupplus_zip_files_jstree').on('ready.jstree', function(e, data) {
		jQuery('#backupplus-iframe-modal').dialog('option', 'title', backupplus_i18n.zip_file_contents + ': ' + data.instance.get_node('#').children[0])
	});

	// Search function for jstree, this will hide nodes that don't match the search
	var timeout = false;
	jQuery('#zip_files_jstree_search').on('keyup', function () {
		if (timeout) { clearTimeout(timeout); }
		timeout = setTimeout(function () {
			var value = jQuery('#zip_files_jstree_search').val();
			jQuery('#backupplus_zip_files_jstree').jstree(true).search(value);
		}, 250);
	});

	// Detect change on the tree and update the input that has been marked as editing
	jQuery('#backupplus_zip_files_jstree').on("changed.jstree", function (e, data) {
		jQuery('#backupplus_zip_path_text').text(data.node.li_attr.path);
		
		if (data.node.li_attr.size) {
			jQuery('#backupplus_zip_size_text').text(data.node.li_attr.size);
			jQuery('#backupplus_zip_download_item').show();
		} else {
			jQuery('#backupplus_zip_size_text').text('');
			jQuery('#backupplus_zip_download_item').hide();
		}
	});

	jQuery('#backupplus_zip_download_item').on('click', function(event) {
		
		event.preventDefault();
		
		var path = jQuery('#backupplus_zip_path_text').text();

		backupplus_send_command('get_zipfile_download', {path:path, timestamp:timestamp, type:type, findex:findex}, function(response) {
			if (response.hasOwnProperty('error')) {
				alert(response.error);
			} else if (response.hasOwnProperty('path')) {
				location.href =ajaxurl+'?_wpnonce='+backupplus_download_nonce+'&timestamp='+timestamp+'&type='+type+'&stage=2&findex='+findex+'&filepath='+response.path+'&action=backupplus_download_backup';
			} else {
				alert(backupplus_i18n.download_timeout);
			}
		}, { error_callback: function(response, status, error_code, resp) {
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
	});
}

/**
 * This function will clean up the BackupPlus downloader UI
 *
 * @param {object} item - the object pressed in the UI
 * @param {string} what - the file entity
 */
function remove_backupplus_downloader(item, what) {
	jQuery(item).closest('.backupplus_downloader').fadeOut().remove();
	if (0 == jQuery('.backupplus_downloader_container_'+what+' .backupplus_downloader').length) jQuery('.backupplus_downloader_container_'+what).remove();
}

/**
 * This function will prepare the downloader UI and kick of the request to download the file entities.
 *
 * @param {string}  base             - the base string for the id
 * @param {integer} backup_timestamp - the backup timestamp
 * @param {string}  what             - the file entity
 * @param {string}  whicharea        - the area we want to append the downloader
 * @param {string}  set_contents     - the contents we want to download
 * @param {string}  prettydate       - the pretty backup date
 * @param {boolean} async            - boolean to indicate if this is a async request or not
 */
function backupplus_downloader(base, backup_timestamp, what, whicharea, set_contents, prettydate, async) {
	
	if (typeof set_contents !== "string") set_contents = set_contents.toString();

	jQuery('.ud_downloadstatus').show();

	var set_contents = set_contents.split(',');
	var prdate = (prettydate) ? prettydate : backup_timestamp;

	// Old-style, from when it was a form
	// var data = jQuery('#backupplus-navtab-backups-content .uddownloadform_'+what+'_'+backup_timestamp+'_'+set_contents[i]).serialize();
	var nonce = jQuery('#backupplus-navtab-backups-content .uddownloadform_'+what+'_'+backup_timestamp+'_'+set_contents[0]).data('wp_nonce').toString();
	
	if (!jQuery('.backupplus_downloader_container_'+what).length) {
		jQuery(whicharea).append('<div class="backupplus_downloader_container_' + what + ' postbox"></div>');
		jQuery('.backupplus_downloader_container_' + what).append('<strong style="clear:left; padding: 8px; margin-top: 4px;">' + backupplus_i18n.download + ' ' + what + ' (' + prdate + '):</strong>');
	}

	for (var i = 0; i < set_contents.length; i++) {
		// Create somewhere for the status to be found
		var stid = base+backup_timestamp+'_'+what+'_'+set_contents[i];
		var stid_selector = '.'+stid;
		var show_index = parseInt(set_contents[i]); show_index++;
		var itext = (0 == set_contents[i]) ? '' : ' ('+show_index+')';
		if (!jQuery(stid_selector).length) {
			jQuery('.backupplus_downloader_container_'+what).append('<div style="clear:left; padding: 8px; margin-top: 4px;" class="'+stid+' backupplus_downloader"><button onclick="remove_backupplus_downloader(this, \''+what+'\');" type="button" style="float:right; margin-bottom: 8px;" class="ud_downloadstatus__close" aria-label="Close"><span class="dashicons dashicons-no-alt"></span></button><strong>'+what+itext+'</strong>:<div class="raw">'+backupplus_i18n.begunlooking+'</div><div class="file '+stid+'_st"><div class="dlfileprogress" style="width: 0;"></div></div></div>');
			jQuery(stid_selector).data('downloaderfor', { base: base, nonce: backup_timestamp, what: what, index: set_contents[i] });
			setTimeout(function() {
					backupplus_activejobs_update(true);
				},
			1500);
		}
		jQuery(stid_selector).data('lasttimebegan', (new Date).getTime());
	}

	// Now send the actual request to kick it all off
	async = async ? true : false;

	var data = {
		type: what,
		timestamp: backup_timestamp,
		findex: set_contents
	};

	var options = {
		action: 'backupplus_download_backup',
		nonce_key: '_wpnonce',
		nonce: nonce,
		timeout: 10000,
		async: async
	}

	backupplus_send_command('backupplus_download_backup', data, null, options);

	// We don't want the form to submit as that replaces the document
	return false;
}
