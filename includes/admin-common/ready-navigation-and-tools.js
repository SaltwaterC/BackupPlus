// Tab navigation, the ping check, the uploader, the expert tools and backup job row actions.

jQuery(function($) {
	// TODO: This is suspected to be obsolete. Confirm + remove.
	jQuery('#backupplus-navtab-settings-content .backupplus-service').on('change', function() {
		var active_class = jQuery(this).val();
		jQuery('#backupplus-navtab-settings-content .backupplusmethod').hide();
		jQuery('#backupplus-navtab-settings-content .'+active_class).show();
	});

	jQuery('#backupplus-phpinfo').on('click', function(e) {
		e.preventDefault();
		backupplus_iframe_modal('phpinfo', backupplus_i18n.phpinfo);
	});

	jQuery('#backupplus-rawbackuphistory').on('click', function(e) {
		e.preventDefault();
		backupplus_iframe_modal('rawbackuphistory', backupplus_i18n.raw);
	});

	jQuery('#backupplus-navtab-status').on('click', function(e) {
		e.preventDefault();
		backupplus_open_main_tab('status');
		backupplus_page_is_visible = 1;
		backupplus_console_focussed_tab = 'status';
		// Refresh the console, as its next update might be far away
		backupplus_activejobs_update(true);
	});
	jQuery('#backupplus-navtab-expert').on('click', function() {
		backupplus_open_main_tab('expert');
		backupplus_page_is_visible = 1;
	});
	jQuery('#backupplus-navtab-extract').on('click', function() {
		backupplus_open_main_tab('extract');
		backupplus_page_is_visible = 1;
	});
	jQuery('#backupplus-navtab-settings, #backupplus-navtab-settings2, #backupplus_backupnow_gotosettings').on('click', function() {
		// These next two should only do anything if the relevant selector was clicked
		jQuery(this).parents('.backupplusmessage').remove();
		jQuery('#backupplus-backupnow-modal').dialog('close');
		backupplus_open_main_tab('settings');
		backupplus_page_is_visible = 1;
	});
	jQuery('#backupplus-navtab-backups').on('click', function() {
		backupplus_console_focussed_tab = 'backups';
		backupplus_historytimertoggle(1);
		backupplus_open_main_tab('backups');
	});
	

	backupplus_send_command('ping', null, function(data, response) {
		if ('success' == response && data != 'pong' && data.indexOf('pong')>=0) {
			jQuery('#backupplus-navtab-backups-content .ud-whitespace-warning').show();
			console.log("BackupPlus: Extra output warning: response (which should be just (string)'pong') follows.");
			console.log(data);
		}
	}, { json_parse: false, type: 'GET' });

	// Section: Plupload
	try {
		if (typeof backupplus_plupload_config !== 'undefined') {
			plupload_init();
		}
	} catch (err) {
		console.log(err);
	}
	
	function plupload_init() {
	
		// create the uploader and pass the config from above
		var uploader = new plupload.Uploader(backupplus_plupload_config);

		// checks if browser supports drag and drop upload, makes some css adjustments if necessary
		uploader.bind('Init', function(up) {
			var uploaddiv = jQuery('#plupload-upload-ui');
			
			if (up.features.dragdrop) {
				uploaddiv.addClass('drag-drop');
				jQuery('#drag-drop-area')
				.on('dragover.wp-uploader', function() {
 uploaddiv.addClass('drag-over'); })
				.on('dragleave.wp-uploader, drop.wp-uploader', function() {
 uploaddiv.removeClass('drag-over'); });
				
			} else {
				uploaddiv.removeClass('drag-drop');
				jQuery('#drag-drop-area').off('.wp-uploader');
			}
		});
					
		uploader.init();

		// a file was added in the queue
		uploader.bind('FilesAdded', function(up, files) {
			if ($('#backupplus-plupload-modal').is(':hidden')) {
				$('#backupplus-plupload-modal').slideToggle();
			}
		// var hundredmb = 100 * 1024 * 1024, max = parseInt(up.settings.max_file_size, 10);
		
		plupload.each(files, function(file) {
				// @codingStandardsIgnoreLine
				if (! /^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+?)?(\.(zip|zip\.crypt|gz|gz\.crypt|tar\.zst|tar\.zst\.crypt|sql\.zst|sql\.zst\.crypt))?$/i.test(file.name) && ! /^log\.([0-9a-f]{12})\.txt$/.test(file.name)) {
					var accepted_file = false;
					for (var i = 0; i<backupplus_accept_archivename.length; i++) {
						if (backupplus_accept_archivename[i].test(file.name)) {
							var accepted_file = true;
						}
					}
					if (!accepted_file) {
						if (/\.(zip|tar|tar\.gz|tar\.bz2)$/i.test(file.name) || /\.sql(\.gz)?$/i.test(file.name)) {
							jQuery('#backupplus-message-modal-innards').html('<p><strong>'+file.name+"</strong></p> "+backupplus_i18n.notarchive2);
							jQuery('#backupplus-message-modal').dialog('open');
						} else {
							alert(file.name+": "+backupplus_i18n.notarchive);
						}
						uploader.removeFile(file);
						return;
					}
					}
			
				// a file was added, you may want to update your DOM here...
				jQuery('#filelist').append(
					'<div class="file" id="' + file.id + '"><b>' +
					file.name + '</b> (<span>' + plupload.formatSize(0) + '</span>/' + plupload.formatSize(file.size) + ') ' +
				'<div class="fileprogress"></div></div>');
		});
			
			up.refresh();
			up.start();
		});
			
		uploader.bind('UploadProgress', function(up, file) {
			jQuery('#' + file.id + " .fileprogress").width(file.percent + "%");
			jQuery('#' + file.id + " span").html(plupload.formatSize(parseInt(file.size * file.percent / 100)));

			if (file.size == file.loaded) {
				jQuery('#' + file.id).html('<div class="file" id="' + file.id + '"><b>' +
				file.name + '</b> (<span>' + plupload.formatSize(parseInt(file.size * file.percent / 100)) + '</span>/' + plupload.formatSize(file.size) + ') - ' + backupplus_i18n.complete +
				'</div>'); // Removed <div class="fileprogress"></div> (just before closing </div>) to make clearer it's complete.
				jQuery('#' + file.id + " .fileprogress").width(file.percent + "%");
			}
		});

		uploader.bind('Error', function(up, error) {

			console.log(error);
			
			var err_makesure;
			if (error.code == "-200") {
				err_makesure = '\n'+backupplus_i18n.makesure2;
			} else {
				err_makesure = backupplus_i18n.makesure;
			}
			
			var msg = backupplus_i18n.uploaderr+' (code '+error.code+') : '+error.message;
			
			if (error.hasOwnProperty('status') && error.status) {
				msg += ' ('+backupplus_i18n.http_code+' '+error.status+')';
			}
			
			if (error.hasOwnProperty('response')) {
				console.log('BackupPlus: plupload error: '+error.response);
				if (error.response.length < 100) msg += ' '+backupplus_i18n.error+' '+error.response+'\n';
			}
			
			msg += ' '+err_makesure;
			
			alert(msg);
		});

		// a file was uploaded
		uploader.bind('FileUploaded', function(up, file, response) {
			
			if (response.status == '200') {
				// this is your ajax response, update the DOM with it or something...
				try {
					resp = ud_parse_json(response.response);
					if (resp.e) {
						alert(backupplus_i18n.uploaderror+" "+resp.e);
					} else if (resp.dm) {
						alert(resp.dm);
						backupplus_updatehistory(1, 0);
					} else if (resp.m) {
						backupplus_updatehistory(1, 0);
					} else {
						alert('Unknown server response: '+response.response);
					}
					
				} catch (err) {
					console.log(response);
					alert(backupplus_i18n.jsonnotunderstood);
				}

			} else {
				alert('Unknown server response status: '+response.code);
				console.log(response);
			}

		});
	}
	
	// Functions in the debugging console
	jQuery('#backupplus_httpget_go').on('click', function(e) {
		e.preventDefault();
		backupplus_httpget_go(0);
	});

	jQuery('#backupplus_httpget_gocurl').on('click', function(e) {
		e.preventDefault();
		backupplus_httpget_go(1);
	});
	
	jQuery('#backupplus_callwpaction_go').on('click', function(e) {
		e.preventDefault();
		params = { wpaction: jQuery('#backupplus_callwpaction').val() };
		backupplus_send_command('call_wordpress_action', params, function(response) {
			if (response.e) {
				alert(response.e);
			} else if (response.s) {
				// Silence
			} else if (response.r) {
				jQuery('#backupplus_callwpaction_results').html(response.r);
			} else {
				console.log(response);
				alert(backupplus_i18n.jsonnotunderstood);
			}
		});
	});
	
	function backupplus_httpget_go(curl) {
		params = { uri: jQuery('#backupplus_httpget_uri').val() };
		params.curl = curl;
		backupplus_send_command('httpget', params, function(resp) {
			if (resp.e) { alert(resp.e); }
			if (resp.r) {
				jQuery('#backupplus_httpget_results').html('<pre>'+resp.r+'</pre>');
			} else {
				console.log(resp);
			}
		}, { type: 'GET' });
	}
	
	jQuery('#backupplus_activejobs_table').on('click', '.backupplus_jobinfo_delete', function(e) {
		e.preventDefault();
		var job_id = jQuery(this).data('jobid');
		if (job_id) {
			$(this).addClass('disabled');
			backupplus_activejobs_delete(job_id);
		} else {
			console.log("BackupPlus: A stop job link was clicked, but the Job ID could not be found");
		}
	});
	
	jQuery(document).on('click', '.backupplus-log-link[data-jobid], .backupplus-log-link[data-fileid]', function(e) {
		e.preventDefault();
		var file_id = jQuery(this).data('fileid');
		var job_id = jQuery(this).data('jobid');
		if (file_id) {
			backupplus_popuplog(file_id);
		} else if (job_id) {
			backupplus_popuplog(job_id);
		} else {
			console.log("BackupPlus: A log link was clicked, but the Job ID could not be found");
		}
	});
});
