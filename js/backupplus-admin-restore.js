var backupplus_restore_screen = true;
jQuery(function($) {

	var job_id = $('#backupplus_ajax_restore_job_id').val();
	var action = $('#backupplus_ajax_restore_action').val();
	var backupplus_restore_update_timer;
	var last_received = 0;
	var $output = $('#backupplus_ajax_restore_output');
	var $steps_list = $('.backupplus_restore_components_list');
	var previous_stage;
	var current_stage;
	var logged_out = false;
	var auto_resume_count = 0;
	var server_500_count = 0;

	$('#backupplus-restore-hidethis').remove();

	backupplus_restore_command(job_id, action);

	/**
	 * This function will start the restore over ajax for the passed in job_id.
	 *
	 * @param {string}  job_id - the restore job id
	 * @param {string}  action - the restore action
	 */
	function backupplus_restore_command(job_id, action) {

		var xhttp = new XMLHttpRequest();
		var xhttp_data = 'action=' + action + '&backupplus_ajax_restore=do_ajax_restore&job_id=' + job_id;
		if ('backupplus_ajaxrestore' === action) xhttp_data += '&nonce=' + backupplus_credentialtest_nonce;
		var previous_data_length = 0;
		var show_alert = true;
		var debug = $('#backupplus_ajax_restore_debug').length;

		xhttp.open("POST", ajaxurl, true);
		xhttp.onprogress = function(response) {
			if (response.currentTarget.status >= 200 && response.currentTarget.status < 300) {
				if (-1 !== response.currentTarget.responseText.indexOf('<html')) {
					if (show_alert) {
						show_alert = false;
						alert("BackupPlus " + backupplus_i18n.ajax_restore_error + ' ' + backupplus_i18n.ajax_restore_invalid_response);
					}
					$output.append("BackupPlus " + backupplus_i18n.ajax_restore_error + ' ' + backupplus_i18n.ajax_restore_invalid_response);
					console.log("BackupPlus restore error: HTML detected in response could be a copy of the WordPress front page caused by mod_security");
					console.log(response.currentTarget.responseText);
					return;
				}

				if (previous_data_length == response.currentTarget.responseText.length) return;

				last_received = Math.round(Date.now() / 1000);

				var responseText = response.currentTarget.responseText.substr(previous_data_length);

				previous_data_length = response.currentTarget.responseText.length;

				var i = 0;
				var end_of_json = 0;

				// Check if there is restore information json in the response if so process it and remove it from the response so that it does not make it to page
				while (i < responseText.length) {
					var buffer = responseText.substr(i, 7);
					if ('RINFO:{' == buffer) {
						// Output what precedes the RINFO:
						$output
							.append(responseText.substring(end_of_json, i).trim()) // add the text to the activity log
							.scrollTop($output[0].scrollHeight); // Scroll to the bottom of the box
						// Grab what follows RINFO:
						var analyse_it = ud_parse_json(responseText.substr(i), true);

						if (1 == debug) { console.log(analyse_it); }

						backupplus_restore_process_data(analyse_it.parsed);

						// move the for loop counter to the end of the json
						end_of_json = i + analyse_it.json_last_pos - analyse_it.json_start_pos + 6;
						// When the for loop goes round again, it will start with the end of the JSON
						i = end_of_json;
					} else {
						i++;
					}
				}
				$output.append(responseText.substr(end_of_json).trim()).scrollTop($output[0].scrollHeight);
				// check if the fylesystem form is displayed
				if ($output.find('input[name=connection_type]').length && $output.find('#upgrade').length) {
					backupplus_restore_setup_filesystem_form();
				}
			} else {
				if (0 == response.currentTarget.status) {
					$output.append("BackupPlus " + backupplus_i18n.ajax_restore_error + ' ' + backupplus_i18n.ajax_restore_contact_failed);
				} else {
					$output.append("BackupPlus " + backupplus_i18n.ajax_restore_error + ' ' + response.currentTarget.status + ' ' + response.currentTarget.statusText);
				}
				console.log("BackupPlus restore error: " + response.currentTarget.status + ' ' + response.currentTarget.statusText);
				console.log(response.currentTarget);
			}
		}
		xhttp.onload = function() {
			var $result = $output.find('.backupplus_restore_successful, .backupplus_restore_error');

			// if we don't find the result, exit
			if (!$result.length) return;

			var $result_output = $('.backupplus_restore_result');
			$result_output.slideDown();
			$steps_list.slideUp();
			$steps_list.siblings('h2').slideUp();

			if ($result.is('.backupplus_restore_successful')) {
				$result_output.find('.dashicons').addClass('dashicons-yes');
				$result_output.find('.backupplus_restore_result--text').text($result.text());
				$result_output.addClass('restore-success');
			} else if ($result.is('.backupplus_restore_error')) {
				$result_output.find('.dashicons').addClass('dashicons-no-alt');
				$result_output.find('.backupplus_restore_result--text').text($result.text());
				$result_output.addClass('restore-error');
			}
			// scroll log to the bottom
			setTimeout(function() {
				$output.scrollTop($output[0].scrollHeight);
			}, 500);
		}
		xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
		xhttp.send(xhttp_data);
	}

	/**
	 * This function will process the parsed restore data and make updates to the front end
	 *
	 * @param {object} restore_data - the restore data object contains information on the restore progress to update the front end
	 */
	function backupplus_restore_process_data(restore_data) {

		// If the stage is started then we want to start our restore timer as the restore has now actually began
		if ('started' == restore_data.stage) {
			backupplus_restore_update_timer = setInterval(function () {
				backupplus_restore_update();
			}, 5000);
		}
		
		// If the stage is finished then we want to remove our timer and clean up the UI
		if ('finished' == restore_data.stage && backupplus_restore_update_timer) {
			clearInterval(backupplus_restore_update_timer);
			$('#backupplus_ajax_restore_last_activity').html('');
		}

		if (restore_data) {
			if ('state' == restore_data.type || 'state_change' == restore_data.type) {
				console.log(restore_data.stage, restore_data.data);
				if ('files' == restore_data.stage) {
					current_stage = restore_data.data.entity;
				} else {
					current_stage = restore_data.stage;
				}

				var $current = $steps_list.find('[data-component='+current_stage+']');

				// show simplified activity log next to the component's label
				if ('files' == restore_data.stage) {
					$current.find('.backupplus_component--progress').html(' — '+backupplus_i18n.restore_files_progress.replace('%1$s', '<strong>'+(restore_data.data.fileindex)+'</strong>').replace('%2$s', '<strong>'+restore_data.data.total_files+'</strong>'));
				}

				if ('db' == restore_data.stage) {
					if (restore_data.data.hasOwnProperty('stage')) {
						if ('table' == restore_data.data.stage) {
							$current.find('.backupplus_component--progress').html(' — '+backupplus_i18n.restore_db_table_progress.replace('%s', '<strong>'+(restore_data.data.table)+'</strong>'));
						} else if ('stored_routine' == restore_data.data.stage) {
							$current.find('.backupplus_component--progress').html(' — '+backupplus_i18n.restore_db_stored_routine_progress.replace('%s', '<strong>'+(restore_data.data.routine_name)+'</strong>'));
						} else if ('finished' == restore_data.data.stage) {
							$current.find('.backupplus_component--progress').html(' — '+backupplus_i18n.finished);
						} else if ('begun' == restore_data.data.stage) {
							$current.find('.backupplus_component--progress').html(' — '+backupplus_i18n.begun+'...');
						}
					}
				}

				if (previous_stage !== current_stage) {
					if (previous_stage) {
						var $prev = $steps_list.find('[data-component='+previous_stage+']');
						// empty the line's status
						$prev.find('.backupplus_component--progress').html('');
						$prev.removeClass('active').addClass('done');
					}
					if ('finished' == current_stage) {
						$current.addClass('done');
						$steps_list.find('[data-component]').each(function(index, el) {
							$el = $(el);
							if (!$el.is('.done')) {
								$el.addClass('error');
							}
						});
						if (restore_data.data.hasOwnProperty('actions') && 'object' == typeof restore_data.data.actions) {
							backupplus_restore_get_pages(restore_data.data.urls, function(pages_found) {
								if (!$.isEmptyObject(pages_found)) {
									$('.backupplus_restore_result').before(backupplus_i18n.ajax_restore_404_detected);
									$.each(pages_found, function(index, url) {
										$('.backupplus_missing_pages').append('<li>'+url+'</li>');
									});
								}
							});

							$.each(restore_data.data.actions, function(index, item) {
								$steps_list.after('<a href="'+item+'" class="button button-primary">'+index+'</a>');
							});
						}

					} else {
						$current.addClass('active');
					}
				}
				previous_stage = current_stage;
			}
		}

	}

	/**
	 * This function will update the time in the front end that we last received data, after 120 seconds call the resume restore notice
	 */
	function backupplus_restore_update() {
		var current_time = Math.round(Date.now() / 1000);
		var last_activity = current_time - last_received;
		if (60 > last_activity) {
			$('#backupplus_ajax_restore_last_activity').html(backupplus_i18n.last_activity.replace('%d', last_activity));
		} else {
			var resume_in = 120 - last_activity;
			if (0 < resume_in) {
				$('#backupplus_ajax_restore_last_activity').html(backupplus_i18n.no_recent_activity.replace('%d', resume_in));
			} else {
				$('#backupplus_ajax_restore_last_activity').html('');
				backupplus_restore_resume_notice();
			}
		}
	}

	/**
	 * This will move the filesystem form to take all the required space
	 */
	function backupplus_restore_setup_filesystem_form() {
		// Hiding things is handled via CSS
		$('.backupplus_restore_main').addClass('show-credentials-form');
		if ($('#message').length) {
			$('.restore-credential-errors .restore-credential-errors--list').appendTo($('#message'));
			$('.restore-credential-errors .restore-credential-errors--link').appendTo($('#message'));
		}
	}

	/**
	 * This function will make a call to the backend to get the resume restore notice so the user can resume the timed out restore from the same page
	 */
	function backupplus_restore_resume_notice() {
		backupplus_send_command('get_restore_resume_notice', { job_id: job_id }, function(response) {
			if (response.hasOwnProperty('status') && 'success' == response.status && response.hasOwnProperty('html')) {
				if (backupplus_restore_update_timer) clearInterval(backupplus_restore_update_timer);
				if ('plugins' != current_stage && 'db' != current_stage && 5 > auto_resume_count) {
					auto_resume_count++;
					backupplus_restore_command(job_id, 'backupplus_ajaxrestore_continue');
				} else {
					$('.backupplus_restore_main--components').prepend(response.html);
				}
			} else if (response.hasOwnProperty('error_code') && response.hasOwnProperty('error_message')) {
				if (backupplus_restore_update_timer) clearInterval(backupplus_restore_update_timer);
				alert(response.error_code + ': ' + response.error_message);
				console.log(response.error_code + ': ' + response.error_message);
			}
		}, {
			error_callback: function (response, status, error_code, resp) {
				if (500 == response.status && 3 > server_500_count) {
					server_500_count++;
					backupplus_restore_command(job_id, 'backupplus_ajaxrestore_continue');
				} else {
					backupplus_restore_process_data({stage: 'finished', type: 'state_change'})
					var error_message = "backupplus_send_command: error: " + status + " (" + error_code + ")";
					alert(error_message);
					console.log(error_message);
					console.log(response);
				}
			}
		});
	}

	/**
	 * This function will make a call to the passed in urls and check if the response code is a 404 if it is then add it to the array of urls that are not found and return it via a callback
	 *
	 * @param {array}    urls     - the urls we want to test
	 * @param {Function} callback - will be called with the array of urls not found
	 */
	function backupplus_restore_get_pages(urls, callback) {
		var urls_not_found = [];
		var ajax_requests = [];

		$.each(urls, function(index, url) {
			var d = $.Deferred();
			ajax_requests.push(d.promise());

			var xhttp = new XMLHttpRequest();
			xhttp.onreadystatechange = function() {
				if (4 == this.readyState) {
					if (404 == this.status) urls_not_found.push(url);
					d.resolve();
				}
			};
			xhttp.open('GET', url, true);
			xhttp.send(null);
		});

		$.when.apply($, ajax_requests).done(function() {
			callback(urls_not_found);
		});
	}

	$('#backupplus_ajax_restore_progress').on('click', '#backupplus_restore_resume', function(e) {
		e.preventDefault();
		$("#backupplus_ajax_restore_progress").slideUp(1000, function () {
			$(this).remove();
		});
		backupplus_restore_command(job_id, 'backupplus_ajaxrestore_continue');
	});

	$(document).on('heartbeat-tick', function (event, heartbeat_data) {

		if (!heartbeat_data.hasOwnProperty('wp-auth-check')) return;
		
		// check if we are logged out
		if (!heartbeat_data["wp-auth-check"]) {
			logged_out = true;
			return;
		}

		// if we were previously logged out but are now logged in retry the restore
		if (logged_out && heartbeat_data["wp-auth-check"]) {
			last_received = Math.round(Date.now() / 1000);
			logged_out = false;
		}
		
		if (!heartbeat_data.hasOwnProperty('backupplus')) return;

		var backupplus_data = heartbeat_data.backupplus;

		// if we are logged in, check if theres a new nonce
		if (backupplus_data.hasOwnProperty('backupplus_credentialtest_nonce')) {
			backupplus_credentialtest_nonce = backupplus_data.backupplus_credentialtest_nonce;
			last_received = Math.round(Date.now() / 1000);
		}
	});
});
