/* global backupplusPreUpdate, jQuery, wp */
(function ($) {
	'use strict';
	var config = backupplusPreUpdate;
	var active = null;

	function request(operation, data) {
		return $.ajax({ url: config.url, method: 'POST', dataType: 'json', data: $.extend({ action: 'backupplus_pre_update', nonce: config.nonce, operation: operation }, data) }).then(function (result) {
			if (!result.success) return $.Deferred().reject(result.data).promise();
			return result.data;
		});
	}

	function waitForBackup(targets, host, existing) {
		var deferred = $.Deferred();
		var batch = existing || '';
		var stopped = false;
		var panel = $('<div class="notice notice-info backupplus-pre-update-progress" role="status" aria-live="polite"></div>');
		$('<p><strong></strong></p>').find('strong').text(config.title).end().appendTo(panel);
		var message = $('<p></p>').appendTo(panel);
		var progress = $('<progress max="100" value="0"></progress>').appendTo(panel);
		var controls = $('<p></p>').appendTo(panel);
		var retry = $('<button type="button" class="button"></button>').text(config.retry).hide().appendTo(controls);
		var cancel = $('<button type="button" class="button"></button>').text(config.cancel).prop('disabled', !batch).appendTo(controls);
		var log = $('<a target="_blank" rel="noopener"></a>').text(config.log).css('margin-left', '1em').hide().appendTo(controls);
		var container = host && host.length ? host : $('#wpbody-content .wrap').first();
		if (!container.length) container = $('#plugin-information-footer, #plugin-information').first();
		if (!container.length) container = $('body');
		container.prepend(panel);

		function failed(error) {
			message.text(error && error.message ? error.message : config.error);
			panel.removeClass('notice-info').addClass('notice-error');
			retry.show();
		}
		function show(status) {
			if (stopped) return;
			batch = status.id;
			cancel.prop('disabled', false);
			message.text(status.message);
			progress.val(status.progress);
			log.attr('href', status.log).show();
			if (status.state === 'ready') {
				stopped = true;
				panel.remove();
				deferred.resolve(batch);
			} else if (['failed', 'cancelled', 'expired', 'complete'].indexOf(status.state) !== -1) {
				failed(status);
			} else {
				window.setTimeout(poll, 2000);
			}
		}
		function poll() {
			if (!stopped) request('status', { batch: batch }).then(show, failed);
		}
		retry.on('click', function () {
			retry.hide();
			panel.removeClass('notice-error').addClass('notice-info');
			// A lost status response can leave a healthy job running. Read before retrying.
			if (batch) request('status', { batch: batch }).then(function (status) {
				if (['failed', 'cancelled', 'expired'].indexOf(status.state) !== -1) request('retry', { batch: batch }).then(show, failed);
				else show(status);
			}, failed);
			else request('prepare', { targets: targets }).then(show, failed);
		});
		cancel.on('click', function () {
			if (!batch) { stopped = true; deferred.reject({ errorCode: 'backupplus_cancelled', errorMessage: config.error }); panel.remove(); return; }
			request('cancel', { batch: batch }).then(function () {
				stopped = true;
				panel.remove();
				deferred.reject({ errorCode: 'backupplus_cancelled', errorMessage: config.error });
			}, failed);
		});
		if (batch) poll();
		else request('prepare', { targets: targets }).then(show, failed);
		return deferred.promise();
	}

	$(function () {
		var recovery = $('#backupplus-pre-update-recovery');
		if (recovery.length) waitForBackup([], recovery, recovery.data('batch'));
		var classic = $('#backupplus-pre-update-classic');
		if (classic.length) {
			waitForBackup(classic.data('targets'), classic).done(function (batch) {
				var form = $('#backupplus-pre-update-resume');
				$('<input type="hidden" name="backupplus_batch">').val(batch).appendTo(form);
				form[0].submit();
			});
			return;
		}
		if (!window.wp || !wp.updates || !wp.updates.ajax) {
			// Older shiny-update screens return to the native classic operation.
			document.addEventListener('click', function (event) {
				var link = $(event.target).closest('a.update-now, a.update-link, a.update-theme, #plugin_update_from_iframe');
				if (!link.length) return;
				var plugin = link.data('plugin') || link.closest('[data-plugin]').data('plugin');
				var theme = link.data('slug') || link.closest('[data-slug]').data('slug');
				var key = plugin ? 'plugin:' + plugin : 'theme:' + theme;
				var href = link.attr('href') || '';
				var url = (config.classicLinks || {})[key] || (/update\.php\?.*action=upgrade-(plugin|theme)/.test(href) ? href : '');
				if (!url) return;
				event.preventDefault();
				event.stopImmediatePropagation();
				window.location.assign(url);
			}, true);
			return;
		}
		var nativeAjax = wp.updates.ajax;
		wp.updates.ajax = function (action, data) {
			if (action !== 'update-plugin' && action !== 'update-theme') return nativeAjax.apply(this, arguments);
			var key = (action === 'update-plugin' ? 'plugin:' + data.plugin : 'theme:' + data.slug);
			var targets = [key];
			// WordPress has already assembled the selected bulk queue at this point.
			(wp.updates.queue || []).forEach(function (item) {
				if (item.action === 'update-plugin') targets.push('plugin:' + item.data.plugin);
				if (item.action === 'update-theme') targets.push('theme:' + item.data.slug);
			});
			if (!active || active.targets.indexOf(key) === -1) {
				active = { targets: targets, promise: waitForBackup(targets) };
			}
			var current = active;
			var deferred = $.Deferred();
			current.promise.done(function (batch) {
				data.backupplus_batch = batch;
				current.targets = current.targets.filter(function (target) { return target !== key; });
				nativeAjax.call(wp.updates, action, data).then(deferred.resolve, deferred.reject);
			}).fail(function (error) {
				active = null;
				wp.updates.queue = (wp.updates.queue || []).filter(function (item) {
					var queued = item.action === 'update-plugin' ? 'plugin:' + item.data.plugin : (item.action === 'update-theme' ? 'theme:' + item.data.slug : '');
					return current.targets.indexOf(queued) === -1;
				});
				error = $.extend({ slug: data.slug, plugin: data.plugin, update: action === 'update-plugin' ? 'plugin' : 'theme' }, error);
				if (data.error) data.error(error);
				if (wp.updates.ajaxAlways) wp.updates.ajaxAlways(error);
				deferred.reject(error);
			});
			return deferred.promise();
		};
	});
})(jQuery);
