// The restore modal and the restore dialog setup for a backup.

jQuery(function($) {
	var backupplus_restore_modal = {
		initialized: false,
		init: function() {
			if (this.initialized) return;
			
			this.initialized = true;

			// Setup cancel button events
			$('.backupplus-restore--cancel').on('click', function(e) {
				e.preventDefault();
				jQuery('#ud_downloadstatus2').html('');
				this.close();
			}.bind(this));

			this.default_next_text = $('.backupplus-restore--next-step').eq(0).text();
			this.default_stage_heading = $('#backupplus-restore-stage-heading').text();
			$('.backupplus-restore--next-step').data('default-text', this.default_next_text);

			// Setup next button event
			$('.backupplus-restore--next-step').on('click', function(e) {
				e.preventDefault();
				if (this.pitr_completed) { this.close(); return; }
				this.process_next_action();
			}.bind(this));
		},
		close: function() {
			if (this.pitr_restoring) return;
			$('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').val('');
			this.pitr_generation = (this.pitr_generation || 0) + 1;
			if (window.backupplus_cancel_restore_decryption) window.backupplus_cancel_restore_decryption();
			$('.backupplus_restore_container').hide();
			$('body').removeClass('backupplus-modal-is-opened');
			if (this.pitr_completed) {
				this.pitr_completed = false;
				backupplus_history_lastchecksum = false;
				backupplus_updatehistory(0, 0);
			}
		},
		open: function() {
			this.init();
			this.pitr_generation = (this.pitr_generation || 0) + 1;
			this.pitr_completed = false;
			// reset elements
			$('#backupplus-restore-stage-heading').text(this.default_stage_heading);
			$('.backupplus-restore--cancel').prop('disabled', false).show();
			$('#backupplus-restore-modal-stage1').show();
			$('#backupplus-restore-modal-stage2').hide();
			$('#backupplus-restore-modal-stage2a').html('');
			$('.backupplus-restore--next-step').text(this.default_next_text);
			$('.backupplus-restore--next-step').prop('disabled', false).show();
			$('.backupplus-restore--stages li').removeClass('active').first().addClass('active');
			// Show restoration window
			$('.backupplus_restore_container').show();
			$('body').addClass('backupplus-modal-is-opened');
		},
		process_pitr: async function() {
			if (this.pitr_busy) return;
			if (!$('#backupplus_restore_db').is(':checked')) { alert(backupplus_i18n.excludedeverything); return; }
			var generation = this.pitr_generation;
			var status = $('#backupplus-restore-modal-stage2a');
			var params = {timestamp: $('#backupplus_restore_timestamp').val(), point: $('#backupplus_pitr_restore_point').val(), ticket: $('#backupplus-age-ticket').val(), username: $('#backupplus-pitr-restore-user').val(), password: $('#backupplus-pitr-restore-password').val()};
			if (!params.point) { alert(backupplus_i18n.pitr_unavailable); return; }
			this.pitr_busy = true;
			$('.backupplus-restore--next-step').prop('disabled', true);
			var command = function(data) { return new Promise(function(resolve, reject) {
				backupplus_send_command('pitr_restore', data, function(result) { if (!result || result.error) reject(new Error(result && result.error || backupplus_i18n.emptyresponse)); else resolve(result); }, {error_callback: function() { reject(new Error(backupplus_i18n.servererrorcode)); }});
			}); };
			try {
				if (1 == backupplus_restore_stage) {
					$('#backupplus-restore-modal-stage1').hide();
					$('#backupplus-restore-modal-stage2').show();
					$('.backupplus-restore--stages li').removeClass('active').eq(1).addClass('active');
					status.text(backupplus_i18n.preparing_backup_files);
					if (!await window.backupplus_prepare_restore()) {
						$('#backupplus-restore-modal-stage1').show();
						return;
					}
					if (generation !== this.pitr_generation) return;
					params.ticket = $('#backupplus-age-ticket').val();
					await command(Object.assign(params, {operation: 'verify'}));
					if (generation !== this.pitr_generation) return;
					status.text(backupplus_i18n.pitr_verify_success);
					backupplus_restore_stage = 3;
					$('.backupplus-restore--next-step').text(backupplus_i18n.pitr_restore_database);
				} else if (3 == backupplus_restore_stage) {
					this.pitr_restoring = true;
					$('.backupplus-restore--cancel').prop('disabled', true);
					$('#backupplus-restore-stage-heading').text(backupplus_i18n.pitr_restore_database);
					$('.backupplus-restore--stages li').removeClass('active').eq(2).addClass('active');
					status.text(backupplus_i18n.pitr_restore_running);
					$('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').val('');
					await command(Object.assign(params, {operation: 'restore'}));
					$('#backupplus-age-ticket').val('');
					status.text(backupplus_i18n.pitr_restore_success);
					$('#backupplus-restore-stage-heading').text(backupplus_i18n.pitr_restore_complete);
					backupplus_restore_stage = 4;
					this.pitr_completed = true;
					$('.backupplus-restore--cancel').hide();
					$('.backupplus-restore--next-step').text(backupplus_i18n.restore_done);
				}
			} catch (error) {
				status.text(error.message);
				if (1 == backupplus_restore_stage) $('#backupplus-restore-modal-stage1').show();
			} finally {
				delete params.password;
				delete params.username;
				this.pitr_busy = this.pitr_restoring = false;
				$('.backupplus-restore--cancel').prop('disabled', false);
				$('.backupplus-restore--next-step').prop('disabled', false);
			}
		},
		process_next_action: function() {
			if ($('#backupplus_restore_form').data('pitr')) { this.process_pitr(); return; }
			var anyselected = 0;
			var moreselected = 0;
			var dbselected = 0;
			var pluginselected = 0;
			var themeselected = 0;
			var whichselected = [];
			// Make a list of what files we want
			var already_added_wpcore = 0;
			var meta_foreign = $('#backupplus_restore_meta_foreign').val();
			$('input[name="backupplus_restore[]"]').each(function(x, y) {
				if ($(y).is(':checked') && !$(y).is(':disabled')) {
					anyselected = 1;
					var howmany = $(y).data('howmany');
					var type = $(y).val();
					if ('more' == type) moreselected = 1;
					if ('db' == type) dbselected = 1;
					if ('plugins' == type) pluginselected = 1;
					if ('themes' == type) themeselected = 1;
					if (1 == meta_foreign || (2 == meta_foreign && 'db' != type)) {
						if ('wpcore' != type) {
							howmany = $('#backupplus_restore_form #backupplus_restore_wpcore').data('howmany');
						}
						type = 'wpcore';
					}
					if ('wpcore' != type || already_added_wpcore == 0) {
						var restobj = [ type, howmany ];
						whichselected.push(restobj);
						// alert($(y).val());
						if ('wpcore' == type) { already_added_wpcore = 1; }
					}
				}
			});
			if (1 == anyselected) {
				// Work out what to download
				if (1 == backupplus_restore_stage) {
					// meta_foreign == 1 : All-in-one format: the only thing to download, always, is wpcore
					// if ('1' == meta_foreign) {
					// whichselected = [];
					// whichselected.push([ 'wpcore', 0 ]);
					// } else if ('2' == meta_foreign) {
					// $(whichselected).each(function(x,y) {
					// restobj = whichselected[x];
					// });
					// whichselected = [];
					// whichselected.push([ 'wpcore', 0 ]);
					// }
					$('.backupplus-restore--stages li').removeClass('active').eq(1).addClass('active');
					$('#backupplus-restore-modal-stage1').slideUp('slow', function() {
						$('#backupplus-restore-modal-stage2').show(100, function() {
							backupplus_restore_stage = 2;
							var pretty_date = $('.backupplus_restore_date').first().text();
							// Create the downloader active widgets
			
							// See if we some are already known to be downloaded - in which case, skip creating the download widget. (That saves on HTTP round-trips, as each widget creates a new POST request. Of course, this is at the expense of one extra one here).
							var which_to_download = whichselected;
							var backup_timestamp = $('#backupplus_restore_timestamp').val();
			
							try {
								$('.backupplus-restore--next-step').prop('disabled', true);
								$('#backupplus-restore-modal-stage2a').html('<span class="dashicons dashicons-update rotate"></span> '+backupplus_i18n.maybe_downloading_entities);
								backupplus_send_command('whichdownloadsneeded', {
									downloads: whichselected,
									timestamp: backup_timestamp
								}, function(response) {
									$('.backupplus-restore--next-step').prop('disabled', false);
									if (response.hasOwnProperty('downloads')) {
										console.log('BackupPlus: items which still require downloading follow');
										which_to_download = response.downloads;
										console.log(which_to_download);
									}
			
									// Kick off any downloads, if needed
									if (0 == which_to_download.length) {
										backupplus_restorer_checkstage2(0);
									} else {
										for (var i=0; i<which_to_download.length; i++) {
											// backupplus_downloader(base, backup_timestamp, what, whicharea, set_contents, prettydate, async)
											backupplus_downloader('udrestoredlstatus_', backup_timestamp, which_to_download[i][0], '#ud_downloadstatus2', which_to_download[i][1], pretty_date, true);
										}
									}
		
								}, { alert_on_error: false, error_callback: function(response, status, error_code, resp) {
										if (typeof resp !== 'undefined' && resp.hasOwnProperty('fatal_error')) {
										console.error(resp.fatal_error_message);
										$('#backupplus-restore-modal-stage2a').html('<p style="color:red;">'+resp.fatal_error_message+'</p>');
										} else {
										var error_message = "backupplus_send_command: error: "+status+" ("+error_code+")";
										$('#backupplus-restore-modal-stage2a').html('<p style="color:red; margin: 5px;">'+error_message+'</p>');
										console.log(error_message);
										console.log(response);
										}
									}
								});
							} catch (err) {
								console.log("BackupPlus: error (follows) when looking for items needing downloading");
								console.log(err);
								alert(backupplus_i18n.jsonnotunderstood);
							}
						});
					});
	
					// Make sure all are downloaded
				} else if (2 == backupplus_restore_stage) {
					backupplus_restorer_checkstage2(1);
				} else if (3 == backupplus_restore_stage) {
					var continue_restore = 1;
					jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', true);
					$('#backupplus_restoreoptions_ui input.required').each(function(index) {
						if (continue_restore == 0) return;
						var sitename = $(this).val();
						if (sitename == '') {
							alert(backupplus_i18n.pleasefillinrequired);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							continue_restore = 0;
						} else if ($(this).attr('pattern') != '') {
							var pattern = $(this).attr('pattern');
							var re = new RegExp(pattern, "g");
							if (!re.test(sitename)) {
								alert($(this).data('invalidpattern'));
								jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
								continue_restore = 0;
							}
						}
					});

					if (1 == dbselected) {
						
						anyselected = 0;
					
						jQuery('input[name="backupplus_restore_tables_options[]"').each(function (x, y) {
							if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) anyselected = 1;
						});
						
						if (0 == anyselected && !skipped_db_scan) {
							alert(backupplus_i18n.youdidnotselectany);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							return;
						}
					}

					if (1 == pluginselected) {

						anyselected = 0;

						if (!jQuery(".backupplus_restore_plugins_options_container").length) anyselected = 1;

						jQuery('input[name="backupplus_restore_plugins_options[]"').each(function (x, y) {
							if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) anyselected = 1;
						});
						
						if (0 == anyselected) {
							alert(backupplus_i18n.youdidnotselectany);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							return;
						}
					}

					if (1 == themeselected) {

						anyselected = 0;

						if (!jQuery(".backupplus_restore_themes_options_container").length) anyselected = 1;

						jQuery('input[name="backupplus_restore_themes_options[]"').each(function (x, y) {
							if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) anyselected = 1;
						});
						
						if (0 == anyselected) {
							alert(backupplus_i18n.youdidnotselectany);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							return;
						}
					}

					if (1 == moreselected) {
					
						anyselected = 0;
					
						jQuery('input[name="backupplus_include_more_index[]"').each(function (x, y) {
							if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) {
								anyselected = 1;
								if ('' == jQuery('#backupplus_include_more_path_restore' + x).val()) {
									alert(backupplus_i18n.emptyrestorepath);
								}
							}
						});
						
						if (0 == anyselected) {
							alert(backupplus_i18n.youdidnotselectany);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							return;
						}
					}

					if (!continue_restore) return;
					var restore_options = $('#backupplus_restoreoptions_ui select, #backupplus_restoreoptions_ui input, #backupplus_incremental_restore_point, #backupplus_pitr_restore_point').serialize();

					// jQuery serialize does not pick up unchecked checkboxes, but we want to include these so that we have a list of table/plugins/themes the user does not want to restore we prepend these with udp-skip-{entity}- and check this on the backend
					var entities = ['tables', 'plugins', 'themes'];

					jQuery.each(entities, function(i, entity) {
						jQuery.each(jQuery('input[name="backupplus_restore_' + entity + '_options[]').filter(function(idx) {
							return jQuery(this).prop('checked') === false
						}), function(idx, el) {
							restore_options += '&' + jQuery(el).attr('name') + '=' + 'udp-skip-' + entity + '-' + jQuery(el).val();
						});
					})



					if (typeof php_max_input_vars !== 'undefined') {
						var restore_options_length = restore_options.split("&").length;
						var warning_template_start = '<div class="udp-notice notice-warning"><p><span class="dashicons dashicons-warning"></span> <strong>' + backupplus_i18n.warnings +'</strong></p><ul id="backupplus_restore_warnings">';
						var warning_template_end = '</ul></div>';

						// If we can't detect the php_max_input_vars assume the PHP default of 1000
						if (!php_max_input_vars && 1000 <= restore_options_length) {
							console.log('Restore options: ' + restore_options_length + ' PHP max input vars not detected; using default: 1000');
						} else if (php_max_input_vars && restore_options_length >= php_max_input_vars) {
							var warning = '<li>' + backupplus_i18n.php_max_input_vars_detected_warning + '</li>';
							if (1 != jQuery('#backupplus-restore-modal-stage2a .notice-warning').length) {
								var final_warning = warning_template_start + warning + warning_template_end;
								jQuery('#backupplus_restoreoptions_ui').prepend(final_warning);
							} else {
								jQuery('#backupplus-restore-modal-stage2a #backupplus_restore_warnings').append(warning);
							}
							console.log('Restore options: ' + restore_options_length + ' PHP max input vars: ' + php_max_input_vars);
							jQuery('.backupplus-restore--next-step, .backupplus-restore--cancel').prop('disabled', false);
							php_max_input_vars = undefined;
							return;
						}
					}

					$('#backupplus_restorer_restore_options').val(restore_options);
					// This must be done last, as it wipes out the section with #backupplus_restoreoptions_ui
					$('#backupplus-restore-modal-stage2a').html(backupplus_i18n.restore_proceeding);
					$('#backupplus_restore_form').trigger('submit');
					// In progress; prevent the button being pressed again
					backupplus_restore_stage = 4;
				}
			} else {
				alert(backupplus_i18n.youdidnotselectany);
			}
		}
	}
	
	function backupplus_restore_setup(entities, key, show_data, restore_points, pitr) {
		pitr = 1 == pitr;
		var heading = jQuery('#backupplus_restore_heading');
		heading.text(heading.data(pitr ? 'pitr-label' : 'original-label') + ':');
		jQuery('#backupplus_restore_form').data('pitr', pitr);
		jQuery('#backupplus_pitr_restore_row').toggle(pitr);
		jQuery('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').val('').prop('disabled', !pitr);
		var pitr_select = jQuery('#backupplus_pitr_restore_point').empty().prop('disabled', !pitr);
		if (pitr) {
			jQuery.each(restore_points || [], function(index, point) { pitr_select.append(jQuery('<option>').val(point.backup_id).text(point.label).data('encrypted', !!point.encrypted).data('baseline', 0 === point.increment)); });
			pitr_select.off('change.pitr').on('change.pitr', function() {
				if (window.backupplus_cancel_restore_decryption) window.backupplus_cancel_restore_decryption();
				// The baseline is an ordinary SQL dump; only later points need native replay.
				var baseline = !!pitr_select.find('option:selected').data('baseline');
				jQuery('#backupplus_restore_form').data('pitr', !baseline);
				jQuery('#backupplus-pitr-restore-replay').toggle(!baseline);
				jQuery('#backupplus-pitr-restore-baseline').toggle(baseline);
				jQuery('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').prop('disabled', baseline);
				if (baseline) jQuery('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').val('');
				jQuery('#backupplus_restorer_dboptions').toggle(baseline && jQuery('#backupplus_restore_db').is(':checked'));
				jQuery('.backupplus_restore_date').text(pitr_select.find('option:selected').text().split(' - ')[0]);
				jQuery('.backupplus-restore-encryption').toggle(!!pitr_select.find('option:selected').data('encrypted'));
			});
			restore_points = [];
		}
		var point_select = jQuery('#backupplus_incremental_restore_point').empty();
		restore_points = Array.isArray(restore_points) ? restore_points : [];
		jQuery.each(restore_points, function(index, point) {
			point_select.append(jQuery('<option>').val(point.timestamp).text(point.label));
		});
		// The restore header names the chosen point rather than only the full backup the chain started from
		var show_chosen_point = function() {
			var chosen = point_select.find('option:selected').text();
			if (chosen) jQuery('.backupplus_restore_date').text(chosen.split(' - ')[0]);
		};
		point_select.off('change.backupplus_restore_point').on('change.backupplus_restore_point', show_chosen_point);
		// A disabled select is not submitted, so backups without a chain fall back to restoring everything
		point_select.prop('disabled', 0 == restore_points.length);
		jQuery('#backupplus_restore_point_row').toggle(restore_points.length > 0);
		jQuery('#backupplus-age-ticket').val('');
		jQuery('#backupplus-age-identity, #backupplus-age-key-passphrase').val('').prop('disabled', false);
		backupplus_restore_setoptions(entities);
		if (pitr) jQuery('#backupplus_restore_db').prop('checked', true);
		jQuery('#backupplus_restorer_dboptions').toggle(!pitr && jQuery('#backupplus_restore_db').is(':checked'));
		if (key.toString().match(/^[0-9]+$/i)) jQuery('#backupplus_restore_timestamp').val(key);
		jQuery('.backupplus_restore_date').text(show_data);
		if (restore_points.length > 0) point_select.trigger('change.backupplus_restore_point');
		if (pitr) pitr_select.trigger('change.pitr');
		
		backupplus_restore_stage = 1;
		
		// jQuery('#backupplus-restore-modal').dialog('open');
		backupplus_restore_modal.open();
		backupplus_activejobs_update(true);
	}
	
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('click', 'button.choose-components-button', function(e) {
		var entities = jQuery(this).data('entities');
		var backup_timestamp = jQuery(this).data('backup_timestamp');
		var show_data = jQuery(this).data('showdata');
		backupplus_restore_setup(entities, backup_timestamp, show_data, jQuery(this).data('restorepoints'), jQuery(this).data('pitr'));
	});
	
	/**
	 * Get the value of a named URL parameter - https://stackoverflow.com/questions/4548487/jquery-read-query-string
	 *
	 * @param {string} name - URL parameter to return the value of
	 *
	 * @returns {string}
	 */
	function get_parameter_by_name(name) {
		name = name.replace(/[\[]/,"\\\[").replace(/[\]]/,"\\\]");
		var regex_s = "[\\?&]"+name+"=([^&#]*)";
		var regex = new RegExp(regex_s);
		var results = regex.exec(window.location.href);
		if (results == null) {
			return '';
		} else {
			return decodeURIComponent(results[1].replace(/\+/g, ' '));
		}
	}
	
	if (get_parameter_by_name('udaction') == 'initiate_restore') {
		var entities = get_parameter_by_name('entities');
		var backup_timestamp = get_parameter_by_name('backup_timestamp');
		var show_data = get_parameter_by_name('showdata');
		backupplus_restore_setup(entities, backup_timestamp, show_data);
	}
});
