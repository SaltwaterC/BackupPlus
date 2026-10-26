// Restore component selection, notice dismissal and advanced tools.

jQuery(function($) {
	jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups').on('dblclick', '.backupplus_existingbackup_date', function (e) {
		e.preventDefault();
		var nonce = jQuery(this).data('nonce').toString();
		var timestamp = jQuery(this).data('timestamp').toString();
		backupplus_send_command('rawbackup_history', { timestamp: timestamp, nonce: nonce }, function (response) {
			if (response.hasOwnProperty('rawbackup')) {
				var textArea = document.createElement('textarea');
				textArea.innerHTML = response.rawbackup;
				backupplus_html_modal(textArea.value, backupplus_i18n.raw, 780, 500);
			} else {
				backupplus_html_modal(backupplus_i18n.jsonnotunderstood, backupplus_i18n.raw, 780, 500);
			}
		}, { type: 'POST' });

		backupplus_html_modal('<div style="margin:auto;text-align:center;margin-top:150px;"><span class="dashicons dashicons-update rotate" style="font-size:40px;"></span><br>'+ backupplus_i18n.loading +'</div>', backupplus_i18n.raw, 780, 500);
	});

	jQuery('#backupnow_database_moreoptions').on('click', 'div.backupnow-db-tables > a', function(e) {
		e.preventDefault();
		jQuery('> input', jQuery(this).parents('div.backupnow-db-tables')).prop('checked', false);
		if (jQuery(this).hasClass('backupnow-select-all-table')) {
			jQuery('> input', jQuery(this).parents('div.backupnow-db-tables')).prop('checked', true);
		} else if (jQuery(this).hasClass('backupnow-select-all-this-site')) {
			jQuery('> input', jQuery(this).parents('div.backupnow-db-tables')).not('[data-non_wp_table]').prop('checked', true);
			
		}
	});
	jQuery('#backupplus-restore-modal').on('click', '.backupplus_restore_select_all_themes', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_themes_options').prop('checked', true);
	});
	jQuery('#backupplus-restore-modal').on('click', '.backupplus_restore_deselect_all_themes', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_themes_options').prop('checked', false);
	});
	jQuery('#backupplus-restore-modal').on('click', '.backupplus_restore_select_all_plugins', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_plugins_options').prop('checked', true);
	});
	jQuery('#backupplus-restore-modal').on('click', '.backupplus_restore_deselect_all_plugins', function(e) {
		e.preventDefault();
		jQuery('.backupplus_restore_plugins_options').prop('checked', false);
	});
	jQuery('.backupplusmessage.admin-warning-litespeed').on('click', '.notice-dismiss', function(e) {
		e.preventDefault();
		backupplus_send_command('dismiss_admin_warning_litespeed');
	});
	jQuery('.backupplusmessage.admin-warning-pclzip').on('click', '.notice-dismiss', function(e) {
		e.preventDefault();
		backupplus_send_command('dismiss_admin_warning_pclzip');
	});
	
	function apply_search_on_db_size() {
		var value = jQuery('.db-search').val().toLowerCase();
		jQuery(".db-size-content tr").filter(function() {
			jQuery(this).toggle(jQuery(this).text().toLowerCase().indexOf(value) > -1)
		});
	}

	jQuery('#db_size.advanced_tools_button, .db-size-refresh').on('click', function(e) {
		e.preventDefault();

		var $total_size = jQuery('.advanced_settings_content .advanced_tools.db_size .total-size');
		var $table_body = jQuery('.advanced_settings_content .advanced_tools.db_size tbody.db-size-content');

		// trigger the ajax from the 'Database size' menu only for the first time
		if (jQuery(this).hasClass('advanced_tools_button') && '' != $table_body.html()) { return; }
		
		$table_body.html('');

		backupplus_send_command('db_size', 1, function (response) {
			$total_size.html(response.size);
			var html = '';
			$.each(response.tables, function(i, table) {
			html += '<tr>' +
						'<td>' + table.name + '</td>' +
						'<td>' + table.records + '</td>' +
						'<td>' + convert_numeric_size_to_text(table.data_length) + '</td>' +
						'<td>' + convert_numeric_size_to_text(table.index_length) + '</td>' +
						'<td>' + table.type + '</td>' +
					'</tr>';
			});
			$table_body.html(html);
			apply_search_on_db_size();
		});
	});

	jQuery('.db-search').on('input', function() {
		apply_search_on_db_size();
	});

	jQuery('.db-search-clear').on('click', function(e) {
		e.preventDefault();
		jQuery('.db-search').val('');
		apply_search_on_db_size();
	});

	jQuery('.backupplus_restore_main').on('click', 'button#backupplus_restore_abort', function(e) {
		e.preventDefault();
		jQuery('#backupplus_restore_continue_action').val('backupplus_restore_abort');
		jQuery(this).parent('form').trigger('submit');
	});

	jQuery('#cron_events.advanced_tools_button').on('click', function(e) {
		e.preventDefault();

		var $table_body = jQuery('.advanced_settings_content .advanced_tools.cron_events tbody');
		$table_body.html('');

		backupplus_send_command('get_cron_events', 1, function (response) {
			$.each(response, function(index, item) {
				var first_column = '<td>';
				if (item.overdue) first_column = '<td style="border-left:4px solid #DB6A03;">';

				$table_body.append($('<tr>').append(
					$(first_column).text(item.hook),
					$('<td>').text(item.name)
				));
				if (item.overdue) {
					$table_body.find('tr:last').append('<td><span></span><br><span class="dashicons dashicons-warning" aria-hidden="true" style="color:#DB6A03"></span> <span></span></td>');
				} else {
					$table_body.find('tr:last').append('<td><span></span><br><span></span></td>');
				}
				$table_body.find('tr:last td:last span').not('.dashicons').first().text(item.time);
				$table_body.find('tr:last td:last span').last().text(item.interval);
			});
		});
	});
});
