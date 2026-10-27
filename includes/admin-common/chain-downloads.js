// Browsing and downloading the recovery points of an incremental backup chain, for the database or a file entity.

function backupplus_chain_downloads_open(timestamp, type, title, prettydate) {
	var i18n = backupplus_i18n;
	var html = '<div class="backupplus-chain">'
		+ '<p class="backupplus-chain-note"></p>'
		+ '<div class="backupplus-chain-bar">'
		+ '<input type="search" class="backupplus-chain-filter" autocomplete="off">'
		+ '<span class="backupplus-chain-count" role="status" aria-live="polite"></span>'
		+ '</div>'
		+ '<div class="backupplus-chain-bar">'
		+ '<button type="button" class="button backupplus-chain-select"></button>'
		+ '<button type="button" class="button backupplus-chain-clear"></button>'
		+ '<button type="button" class="button button-primary backupplus-chain-download-selected"></button>'
		+ '<button type="button" class="button backupplus-chain-download-all"></button>'
		+ '<button type="button" class="button backupplus-chain-delete-selected"></button>'
		+ '<button type="button" class="button backupplus-chain-delete-remote-selected" hidden></button>'
		+ '</div>'
		+ '<p class="backupplus-chain-status" role="status" aria-live="polite"></p>'
		+ '<div class="backupplus-chain-scroll"><ul class="backupplus-chain-list"></ul>'
		+ '<p class="backupplus-chain-more-row"><button type="button" class="button backupplus-chain-more" hidden></button></p></div>'
		+ '</div>';
	backupplus_html_modal(html, i18n.chain_points_title + ': ' + title + ' (' + prettydate + ')', 860, 680);

	// The list is the only part that scrolls; the dialog itself must not.
	var dialog = jQuery('#backupplus-iframe-modal').addClass('backupplus-chain-modal').one('dialogclose', function() { jQuery(this).removeClass('backupplus-chain-modal'); });
	var root = jQuery('#backupplus-iframe-modal-innards .backupplus-chain');
	var state = { timestamp: timestamp, type: type, search: '', ids: [], all: [], total: 0, loaded: 0, selected: {}, busy: false, request: 0, remote: false, notice: '' };
	var page_size = 50;

	root.find('.backupplus-chain-note').text(i18n.chain_points_note);
	root.find('.backupplus-chain-filter').attr({ placeholder: i18n.chain_points_filter, 'aria-label': i18n.chain_points_filter });
	root.find('.backupplus-chain-select').text(i18n.chain_points_select);
	root.find('.backupplus-chain-clear').text(i18n.chain_points_clear);
	root.find('.backupplus-chain-more').text(i18n.chain_points_more);

	function selected_ids() {
		var ids = [];
		for (var id in state.selected) { if (state.selected.hasOwnProperty(id)) ids.push(parseInt(id, 10)); }
		return ids.sort(function(a, b) { return a - b; });
	}

	function refresh_controls() {
		var count = selected_ids().length;
		var filtered = '' !== state.search;
		root.find('.backupplus-chain-count').text(state.total + ' ' + (1 === state.total ? i18n.chain_points_one : i18n.chain_points_many));
		root.find('.backupplus-chain-download-selected').text(i18n.chain_points_download_selected + ' (' + count + ')').prop('disabled', state.busy || !count);
		root.find('.backupplus-chain-download-all').text((filtered ? i18n.chain_points_download_matching : i18n.chain_points_download_all) + ' (' + state.total + ')').prop('disabled', state.busy || !state.total);
		root.find('.backupplus-chain-delete-selected').prop('hidden', 'db' === state.type).text(i18n.chain_points_delete_selected + ' (' + count + ')').prop('disabled', state.busy || !count);
		root.find('.backupplus-chain-delete-remote-selected').prop('hidden', 'db' === state.type || !state.remote).text(i18n.chain_points_delete_remote_selected + ' (' + count + ')').prop('disabled', state.busy || !count);
		root.find('.backupplus-chain-select').prop('disabled', !state.total);
		root.find('.backupplus-chain-clear').prop('disabled', !count);
		root.find('.backupplus-chain-more').prop('hidden', state.loaded >= state.total).prop('disabled', state.busy);
		root.find('.backupplus-chain-list button').prop('disabled', state.busy);
	}

	function status(message, error) {
		root.find('.backupplus-chain-status').text(message || '').toggleClass('backupplus-chain-error', !!error);
	}

	function add_rows(rows) {
		var list = root.find('.backupplus-chain-list');
		jQuery.each(rows, function(x, row) {
			var item = jQuery('<li></li>').attr('data-id', row.id);
			var label = jQuery('<label class="backupplus-chain-label"></label>');
			jQuery('<input type="checkbox">').prop('checked', !!state.selected[row.id]).appendTo(label);
			jQuery('<span></span>').text(row.label).appendTo(label);
			item.append(label);
			jQuery('<span class="backupplus-chain-meta"></span>').text(row.size + ' · ' + row.files + ' ' + i18n.chain_points_files).appendTo(item);
			var actions = jQuery('<span class="backupplus-chain-actions"></span>').appendTo(item);
			jQuery('<button type="button" class="button-link backupplus-chain-one"></button>').text(i18n.chain_points_this).appendTo(actions);
			jQuery('<button type="button" class="button-link backupplus-chain-through"></button>').attr('title', i18n.chain_points_through_title).text(i18n.chain_points_through).appendTo(actions);
			if ('db' !== state.type) {
				jQuery('<button type="button" class="button-link button-link-delete backupplus-chain-delete"></button>').attr('title', state.remote ? i18n.chain_points_delete_title : i18n.chain_points_delete_title_only).text(i18n.chain_points_delete).appendTo(actions);
				if (state.remote) {
					jQuery('<button type="button" class="button-link button-link-delete backupplus-chain-delete-remote"></button>').attr('title', i18n.chain_points_delete_remote_title).text(i18n.chain_points_delete_remote).appendTo(actions);
				}
			}

			if (null !== row.browse) {
				jQuery('<button type="button" class="button-link backupplus-chain-browse"></button>').attr('data-findex', row.browse).text(i18n.browse_contents).appendTo(actions);
			}
			list.append(item);
		});
		state.loaded += rows.length;
	}

	function load(reset) {
		var request = ++state.request;
		if (reset) {
			state.loaded = 0;
			root.find('.backupplus-chain-list').empty();
			root.find('.backupplus-chain-scroll').scrollTop(0);
		}
		state.busy = true;
		refresh_controls();
		status(i18n.fetching);
		backupplus_send_command('get_chain_download_points', { timestamp: state.timestamp, type: state.type, search: state.search, offset: state.loaded, limit: page_size }, function(response) {
			if (request !== state.request) return;
			state.busy = false;
			if (response.hasOwnProperty('error')) {
				status(state.notice ? state.notice + ' ' + i18n.chain_points_none : response.error, !state.notice);
				state.notice = '';
				state.total = 0;
				refresh_controls();
				return;
			}
			state.ids = response.ids;
			state.all = response.all;
			state.total = response.total;
			state.remote = !!response.remote;
			status(state.notice || (response.total ? '' : i18n.chain_points_none));
			state.notice = '';
			add_rows(response.rows);
			if (response.encrypted && 'db' !== state.type) {
				root.find('.backupplus-chain-note').text(i18n.chain_points_note + ' ' + i18n.chain_points_encrypted);
			}
			refresh_controls();
		}, { alert_on_error: false, error_callback: function() {
			if (request !== state.request) return;
			state.busy = false;
			status(i18n.chain_points_failed, true);
			refresh_controls();
		} });
	}

	function prepare(ids, done_callback, archive) {
		backupplus_send_command('prepare_chain_download', { timestamp: state.timestamp, type: state.type, points: ids.join(','), archive: archive ? 1 : 0 }, function(response) {
			if (response.hasOwnProperty('error')) {
				state.busy = false;
				status(response.error, true);
				refresh_controls();
				return;
			}
			done_callback(response);
		}, { alert_on_error: false, error_callback: function() {
			state.busy = false;
			status(i18n.chain_points_failed, true);
			refresh_controls();
		} });
	}

	function download(ids) {
		if (!ids.length || state.busy) return;
		state.busy = true;
		refresh_controls();
		status(i18n.chain_points_preparing.replace('%d', ids.length));
		prepare(ids, function(response) {
			var frame = jQuery('#backupplus-chain-download-frame');
			if (!frame.length) frame = jQuery('<iframe hidden id="backupplus-chain-download-frame" name="backupplus-chain-download-frame" title=""></iframe>').appendTo(document.body);
			var form = jQuery('<form method="post" hidden></form>').attr({ action: ajaxurl, target: 'backupplus-chain-download-frame' });
			jQuery.each({ action: 'backupplus_download_backup', _wpnonce: backupplus_download_nonce, timestamp: state.timestamp, type: state.type, stage: 'bundle', points: ids.join(',') }, function(name, value) {
				jQuery('<input type="hidden">').attr({ name: name, value: value }).appendTo(form);
			});
			form.appendTo(document.body).trigger('submit');
			form.remove();
			state.busy = false;
			status(i18n.chain_points_started.replace('%s', response.name).replace('%d', response.files).replace('%s', response.size));
			refresh_controls();
		}, true);
	}

	// Deleting from this server and from remote storage are separate actions; the server reports what each involves before anything is removed.
	function remove(ids, where) {
		if (!ids.length || state.busy) return;
		var params = { timestamp: state.timestamp, type: state.type, points: ids.join(','), where: where };
		var fail = { alert_on_error: false, error_callback: function() {
			state.busy = false;
			status(i18n.chain_points_failed, true);
			refresh_controls();
		} };
		state.busy = true;
		refresh_controls();
		status(i18n.fetching);
		backupplus_send_command('delete_chain_files', jQuery.extend({ dry: 1 }, params), function(plan) {
			state.busy = false;
			if (plan.hasOwnProperty('error')) {
				status(plan.error, true);
				refresh_controls();
				return;
			}
			var message;
			if ('remote' === where) {
				message = plan.remote ? i18n.chain_points_confirm_remote.replace('%1$d', plan.remote).replace('%2$s', title) : '';
				if (message && plan.lost) message += ' ' + i18n.chain_points_confirm_lost.replace('%d', plan.lost);
			} else if (plan.lost) {
				message = i18n.chain_points_confirm_permanent.replace('%1$d', plan.lost).replace('%2$s', title);
				if (plan.remote) message += ' ' + i18n.chain_points_confirm_permanent_remote;
			} else {
				message = plan.local ? i18n.chain_points_confirm_server.replace('%1$d', plan.local).replace('%2$s', title) : '';
			}
			if (!message) {
				status(i18n.chain_points_nothing);
				refresh_controls();
				return;
			}
			if (!window.confirm(message)) {
				status('');
				refresh_controls();
				return;
			}
			state.busy = true;
			refresh_controls();
			status(i18n.chain_points_deleting);
			backupplus_send_command('delete_chain_files', params, function(response) {
				state.busy = false;
				if (response.hasOwnProperty('error')) {
					status(response.error, true);
					refresh_controls();
					return;
				}
				state.selected = {};
				var notice = [];
				if (response.remote_deleted) notice.push(i18n.chain_points_deleted_remote.replace('%d', response.remote_deleted));
				if (response.deleted || !response.remote_deleted) notice.push(i18n.chain_points_deleted.replace('%d', response.deleted).replace('%s', response.size));
				state.notice = notice.join(' ');
				backupplus_updatehistory(0, 0);
				load(true);
			}, fail);
		}, fail);
	}

	var filter_timer = null;
	root.on('input', '.backupplus-chain-filter', function() {
		var value = jQuery(this).val();
		clearTimeout(filter_timer);
		filter_timer = setTimeout(function() { state.search = value; load(true); }, 250);
	});
	root.on('change', '.backupplus-chain-list input[type=checkbox]', function() {
		var id = jQuery(this).closest('li').attr('data-id');
		if (this.checked) { state.selected[id] = true; } else { delete state.selected[id]; }
		refresh_controls();
	});
	root.on('click', '.backupplus-chain-select', function() {
		jQuery.each(state.ids, function(x, id) { state.selected[id] = true; });
		root.find('.backupplus-chain-list input[type=checkbox]').prop('checked', true);
		refresh_controls();
	});
	root.on('click', '.backupplus-chain-clear', function() {
		state.selected = {};
		root.find('.backupplus-chain-list input[type=checkbox]').prop('checked', false);
		refresh_controls();
	});
	root.on('click', '.backupplus-chain-more', function() { load(false); });
	root.find('.backupplus-chain-scroll').on('scroll', function() {
		if (!state.busy && state.loaded < state.total && this.scrollTop + this.clientHeight >= this.scrollHeight - 120) load(false);
	});
	root.on('click', '.backupplus-chain-download-selected', function() { download(selected_ids()); });
	root.on('click', '.backupplus-chain-download-all', function() { download(state.ids.slice()); });
	root.on('click', '.backupplus-chain-delete-selected', function() { remove(selected_ids(), 'local'); });
	root.on('click', '.backupplus-chain-delete-remote-selected', function() { remove(selected_ids(), 'remote'); });
	root.on('click', '.backupplus-chain-delete', function() { remove([parseInt(jQuery(this).closest('li').attr('data-id'), 10)], 'local'); });
	root.on('click', '.backupplus-chain-delete-remote', function() { remove([parseInt(jQuery(this).closest('li').attr('data-id'), 10)], 'remote'); });
	root.on('click', '.backupplus-chain-one', function() { download([parseInt(jQuery(this).closest('li').attr('data-id'), 10)]); });
	root.on('click', '.backupplus-chain-through', function() {
		// A point is restorable from downloaded files only together with the full backup and every increment before it.
		var through = parseInt(jQuery(this).closest('li').attr('data-id'), 10);
		download(jQuery.grep(state.all, function(id) { return id <= through; }));
	});
	root.on('click', '.backupplus-chain-browse', function() {
		if (state.busy) return;
		var id = parseInt(jQuery(this).closest('li').attr('data-id'), 10), findex = jQuery(this).attr('data-findex');
		state.busy = true;
		refresh_controls();
		status(i18n.fetching);
		// An archive held only in remote storage has to be on the server before it can be read.
		prepare([id], function() {
			state.busy = false;
			dialog.removeClass('backupplus-chain-modal');
			backupplus_show_contents(state.timestamp, state.type, findex);
		}, false);
	});

	load(true);
}
