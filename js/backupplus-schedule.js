/* Exact backup times and the daily retention cleanup on the settings tab. */
(function($) {
	'use strict';

	var settings = window.backupplus_schedule || { intervals: {}, i18n: {} };
	var interval_selectors = {
		files: '#backupplus-navtab-settings-content select.backupplus_interval',
		db: '#backupplus-navtab-settings-content select.backupplus_interval_database',
		increments: '#backupplus_interval_increments'
	};
	var disabled = { files: 'manual', db: 'manual', increments: 'none' };

	function interval_info(interval) {
		return settings.intervals[interval] || { exact: false, day: null };
	}

	function pad(number) {
		return (number < 10 ? '0' : '') + number;
	}

	function format_minutes(minutes) {
		return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
	}

	/** Minutes after midnight of an HH:MM time, or null. */
	function parse_time(time) {
		var match = /^([01]?[0-9]|2[0-3]):([0-5][0-9])$/.exec(time || '');
		return match ? parseInt(match[1], 10) * 60 + parseInt(match[2], 10) : null;
	}

	/**
	 * The submitted start time. Schedules shorter than a day repeat from their first run of the day,
	 * which is what the server stores, so compare and show that.
	 */
	function start_time($timing, info) {
		var start = parse_time($timing.find('input.backupplus-starttime').val());
		if (null === start) return '';
		return format_minutes(info.step ? start % info.step : start);
	}

	function fill_select($select, options, value) {
		var html = $.map(options, function(option) {
			return $('<option>').val(option[0]).text(option[1]).prop('outerHTML');
		}).join('');
		if ($select.data('options') !== html) {
			$select.html(html).data('options', html);
		}
		$select.val(String(value));
	}

	/** Show the time field, or the minute and run-time selects, for the interval's start time. */
	function refresh_start_time($timing, info, checked) {
		var mode = info.exact ? info.mode : 'time';
		var $hidden = $timing.find('input.backupplus-starttime');
		var start = parse_time($hidden.val());
		$timing.find('.backupplus-exact-time').toggle('time' === mode);
		$timing.find('.backupplus-exact-minute').toggle('minute' === mode);
		$timing.find('.backupplus-exact-hours').toggle('hours' === mode);
		if ('time' === mode) {
			$timing.find('input[type="time"]').val(null === start ? '' : format_minutes(start));
			return;
		}
		// Choosing exact timing for a shorter schedule starts on the hour rather than with an empty time.
		if (null === start) {
			start = 0;
			if (checked) $hidden.val(format_minutes(0));
		}
		start %= info.step;
		var minute = start % 60;
		var options = [];
		var i, j, runs;
		if ('minute' === mode) {
			for (i = 0; i < info.step; i++) {
				runs = [];
				for (j = i; j < 60; j += info.step) runs.push(':' + pad(j));
				options.push([i, runs.join(', ')]);
			}
			fill_select($timing.find('select[data-part="minute"]'), options, start);
			return;
		}
		for (i = 0; i < 60; i++) options.push([i, pad(i)]);
		fill_select($timing.find('select[data-part="hour-minute"]'), options, minute);
		options = [];
		for (i = 0; i < info.step; i += 60) {
			runs = [];
			for (j = i + minute; j < 1440; j += info.step) runs.push(format_minutes(j));
			options.push([i / 60, runs.join(', ')]);
		}
		fill_select($timing.find('select[data-part="hours"]'), options, Math.floor(start / 60));
	}

	/** Write the start time chosen with the minute or run-time selects. */
	function select_start_time() {
		var $timing = $(this).closest('.backupplus-schedule-timing');
		var minute = 'minute' === $(this).data('part') ? $(this).val() : $timing.find('select[data-part="hour-minute"]').val();
		var hour = 'minute' === $(this).data('part') ? 0 : $timing.find('select[data-part="hours"]').val();
		$timing.find('input.backupplus-starttime').val(format_minutes(parseInt(hour, 10) * 60 + parseInt(minute, 10)));
		refresh();
	}

	/** The day select that applies to the interval, or nothing for daily and sub-daily schedules. */
	function day_select($timing, day) {
		if ('week' === day) return $timing.find('.backupplus-schedule-weekday select');
		if ('month' === day) return $timing.find('.backupplus-schedule-monthday select');
		return $();
	}

	function timing_state(which) {
		var $timing = $('.backupplus-schedule-timing[data-schedule="' + which + '"]');
		var interval = $(interval_selectors[which]).val();
		var info = interval_info(interval);
		var exact = info.exact && $timing.find('.backupplus-exact-toggle').prop('checked');
		return {
			interval: interval,
			exact: !!exact,
			time: exact ? start_time($timing, info) : '',
			day: exact && info.day ? $timing.find('input.backupplus-startday').val() : ''
		};
	}

	function refresh_timing(which) {
		var $timing = $('.backupplus-schedule-timing[data-schedule="' + which + '"]');
		if (!$timing.length) return;
		var interval = $(interval_selectors[which]).val();
		var info = interval_info(interval);
		var $toggle = $timing.find('.backupplus-exact-toggle');
		var $startday = $timing.find('input.backupplus-startday');

		$timing.toggle(disabled[which] !== interval);

		$toggle.closest('label').toggle(!!info.exact);
		$timing.find('.backupplus-exact-fields').toggle(!!info.exact && $toggle.prop('checked'));
		$timing.find('.backupplus-schedule-weekday').toggle('week' === info.day);
		$timing.find('.backupplus-schedule-monthday').toggle('month' === info.day);
		refresh_start_time($timing, info, $toggle.prop('checked'));

		// Keep the submitted day in the range of the visible select.
		var $select = day_select($timing, info.day);
		if ($select.length) {
			var day = $startday.val();
			// Otherwise keep the select's own choice, e.g. the weekday chosen before switching to monthly.
			if (!$select.find('option').filter(function() { return this.value === day; }).length) {
				day = $select.val() || $select.find('option').first().val();
			}
			$select.val(day);
			$startday.val(day);
		}
	}

	function refresh_same_schedule() {
		var $message = $('#backupplus-navtab-settings-content .backupplus_same_schedules_message');
		var files = timing_state('files');
		var db = timing_state('db');
		var same = 'manual' !== files.interval && files.interval === db.interval && files.exact === db.exact && files.time === db.time && files.day === db.day;
		$message.text(same ? settings.i18n.same : '').toggle(same);
	}

	function refresh_cleanup() {
		var daily = 'daily' === $('#backupplus_retention_cleanup_mode').val();
		$('.backupplus-retention-cleanup-time').toggle(daily);
	}

	function refresh() {
		refresh_timing('files');
		refresh_timing('db');
		refresh_timing('increments');
		refresh_same_schedule();
		refresh_cleanup();
	}

	window.backupplus_schedule_refresh = refresh;

	$(function() {
		var $content = $('#backupplus-navtab-settings-content');
		$content.on('change', '.backupplus-schedule-timing input[type="time"]', function() {
			$(this).closest('.backupplus-schedule-timing').find('input.backupplus-starttime').val($(this).val());
			refresh();
		});
		$content.on('change', '.backupplus-schedule-timing select[data-part]', select_start_time);
		$content.on('change', '.backupplus-exact-toggle, ' + interval_selectors.files + ', ' + interval_selectors.db + ', ' + interval_selectors.increments, refresh);
		$content.on('change', '.backupplus-schedule-timing select[data-day-for]', function() {
			$(this).closest('.backupplus-schedule-timing').find('input.backupplus-startday').val($(this).val());
			refresh();
		});
		$content.on('change', '#backupplus_retention_cleanup_mode', refresh_cleanup);
		refresh();
	});
})(jQuery);
