/** Chromium checks for exact backup times, the daily cleanup controls and refreshed schedule displays. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const origin = process.env.BACKUPPLUS_TEST_URL || 'http://localhost:8080';
const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((p) => ['chromium', 'chromium-browser', 'google-chrome'].map((n) => path.join(p, n)));
const cache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(cache)) for (const folder of readdirSync(cache).sort().reverse()) if (folder.startsWith('chromium_headless_shell-')) candidates.push(path.join(cache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'));
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
// The test saves real settings; they and the cron schedule are restored afterwards.
const token = randomBytes(12).toString('hex');
function settings(operation) {
	const result = spawnSync(process.env.PODMAN || 'podman', ['exec', process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress', 'wp', '--skip-plugins=backupplus', 'eval-file', 'wp-content/plugins/backupplus/scripts/fixtures/backup-schedule-settings.php', token, operation], { encoding: 'utf8' });
	assert.equal(result.status, 0, 'Settings fixture ' + operation + ' must succeed: ' + result.stderr);
	return 'read' === operation ? JSON.parse(result.stdout.trim().split('\n').pop()) : null;
}
settings('snapshot');
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-schedule-browser-'));
const browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--remote-debugging-port=0', '--user-data-dir=' + directory, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
let browserError = '';
browser.stderr.on('data', (d) => { browserError += d; });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let ws;
try {
	let port;
	for (let i = 0; i < 100; i++) {
		try { port = (await readFile(path.join(directory, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch {}
		await sleep(100);
	}
	assert(port, 'Chromium did not start: ' + browserError);
	const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then((r) => r.json());
	ws = new WebSocket(pages.find((p) => p.type === 'page').webSocketDebuggerUrl);
	await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
	let sequence = 0;
	const pending = new Map();
	ws.addEventListener('message', ({ data }) => {
		const message = JSON.parse(data);
		if (!message.id) return;
		const request = pending.get(message.id);
		pending.delete(message.id);
		message.error ? request.reject(new Error(JSON.stringify(message.error))) : request.resolve(message.result);
	});
	const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); });
	const evaluate = async (expression) => {
		const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
		if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
		return result.result.value;
	};
	async function wait(expression) {
		for (let i = 0; i < 200; i++) { try { if (await evaluate(expression)) return; } catch {} await sleep(100); }
		throw new Error('Timed out: ' + expression);
	}
	const visible = (selector) => evaluate(`jQuery(${JSON.stringify(selector)}).is(':visible')`);
	const set = (selector, value) => evaluate(`jQuery(${JSON.stringify(selector)}).val(${JSON.stringify(value)}).trigger('change'); true`);
	const files = '.backupplus-schedule-timing[data-schedule="files"]';
	const db = '.backupplus-schedule-timing[data-schedule="db"]';
	const increments = '.backupplus-schedule-timing[data-schedule="increments"]';
	async function open() {
		await call('Page.navigate', { url: origin + '/wp-admin/options-general.php?page=backupplus&tab=settings' });
		await wait('document.readyState === "complete" && window.jQuery && window.backupplus_schedule_refresh && document.querySelector(".backupplus-schedule-timing")');
		const text = await evaluate('document.body.innerText');
		assert(!/(Fatal error|Warning|Notice|Deprecated):/.test(text), 'The settings page must render without PHP errors');
	}
	async function save() {
		await evaluate(`window.schedule_test_response = null; jQuery(document).one('ajaxComplete', function(e, xhr, options) { if (String(options.data).indexOf('savesettings') !== -1) window.schedule_test_response = xhr.responseText; }); jQuery('#backupplus-settings-save').trigger('click'); true`);
		await wait('window.schedule_test_response !== null && !jQuery(".blockUI").length');
	}
	await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login') });
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');
	await open();

	// Manual schedules hide timing; exact fields appear only when chosen.
	await set('#backupplus_interval', 'manual');
	await set('#backupplus_interval_database', 'manual');
	await evaluate(`jQuery('.backupplus-exact-toggle').prop('checked', false).trigger('change'); true`);
	assert.equal(await visible('.backupplus_files_timings'), false, 'Manual file backups hide timing');
	await set('#backupplus_interval', 'weekly');
	assert.equal(await visible(files + ' .backupplus-exact-toggle'), true, 'Weekly file backups offer an exact time');
	assert.equal(await visible(files + ' .backupplus-exact-fields'), false, 'Exact fields stay hidden until chosen');
	await evaluate(`jQuery('${files} .backupplus-exact-toggle').prop('checked', true).trigger('change'); true`);
	assert.equal(await visible(files + ' .backupplus-exact-fields'), true, 'Choosing an exact time shows its fields');
	assert.equal(await visible(files + ' .backupplus-schedule-weekday'), true, 'Weekly schedules show a weekday');
	assert.equal(await visible(files + ' .backupplus-schedule-monthday'), false, 'Weekly schedules hide the day of the month');
	await set(files + ' .backupplus-schedule-weekday select', '3');
	assert.equal(await evaluate(`jQuery('${files} input.backupplus-startday').val()`), '3', 'The chosen weekday is submitted');
	await set('#backupplus_interval', 'monthly');
	assert.equal(await visible(files + ' .backupplus-schedule-monthday') && !(await visible(files + ' .backupplus-schedule-weekday')), true, 'Monthly schedules show the day of the month');
	assert.deepEqual(await evaluate(`jQuery('${files} .backupplus-schedule-monthday option').map(function() { return this.value; }).get()`), Array.from({ length: 28 }, (_, i) => String(i + 1)), 'Monthly days run from 1 to 28');
	await set(files + ' .backupplus-schedule-monthday select', '28');
	assert.equal(await evaluate(`jQuery('${files} input.backupplus-startday').val()`), '28', 'The chosen day of the month is submitted');
	await set('#backupplus_interval', 'weekly');
	assert.equal(await evaluate(`jQuery('${files} input.backupplus-startday').val()`), '3', 'Switching back restores the chosen weekday');
	// Schedules of whole hours choose their run hours and minute instead of a time of day.
	await set('#backupplus_interval', 'daily');
	await set(files + ' input[type="time"]', '13:30');
	await set('#backupplus_interval', 'every4hours');
	assert.equal(await visible(files + ' .backupplus-schedule-weekday') || await visible(files + ' .backupplus-schedule-monthday') || await visible(files + ' input[type="time"]'), false, 'Sub-daily schedules have no day or time of day');
	assert.equal(await visible(files + ' select[data-part="hours"]') && await visible(files + ' select[data-part="hour-minute"]') && !(await visible(files + ' select[data-part="minute"]')), true, 'Every four hours chooses run hours and a minute');
	const run_times = (which) => evaluate(`jQuery('${which} select[data-part="hours"] option').map(function() { return this.text; }).get()`);
	assert.deepEqual(await run_times(files), ['00:30, 04:30, 08:30, 12:30, 16:30, 20:30', '01:30, 05:30, 09:30, 13:30, 17:30, 21:30', '02:30, 06:30, 10:30, 14:30, 18:30, 22:30', '03:30, 07:30, 11:30, 15:30, 19:30, 23:30'], 'Each run-time choice lists its runs');
	assert.equal(await evaluate(`jQuery('${files} select[data-part="hours"]').val() + ' ' + jQuery('${files} select[data-part="hour-minute"]').val()`), '1 30', 'A time of day becomes the runs that include it');
	await set(files + ' select[data-part="hours"]', '2');
	await set(files + ' select[data-part="hour-minute"]', '45');
	assert.equal(await evaluate(`jQuery('${files} input.backupplus-starttime').val()`), '02:45', 'The chosen runs are submitted as the first run of the day');
	assert.equal((await run_times(files))[2], '02:45, 06:45, 10:45, 14:45, 18:45, 22:45', 'Run times follow the chosen minute');
	await set('#backupplus_interval', 'twicedaily');
	assert.deepEqual((await run_times(files)).length, 12, 'Twice daily offers each pair of hours');
	await set('#backupplus_interval', 'daily');
	assert.equal(await visible(files + ' input[type="time"]') && !(await visible(files + ' select[data-part="hours"]')), true, 'Daily schedules take a time of day');

	// Database timing stays editable on the same frequency; the combined message reflects the complete schedule.
	await set('#backupplus_interval_database', 'daily');
	assert.equal(await visible('.backupplus_db_timings') && await visible(db + ' .backupplus-exact-toggle'), true, 'Database timing stays editable on the same frequency');
	await set(files + ' input[type="time"]', '02:15');
	await evaluate(`jQuery('${db} .backupplus-exact-toggle').prop('checked', true).trigger('change'); true`);
	await set(db + ' input[type="time"]', '02:15');
	assert.equal(await visible('.backupplus_same_schedules_message'), true, 'Identical schedules say they run together');
	await set(db + ' input[type="time"]', '03:40');
	assert.equal(await visible('.backupplus_same_schedules_message'), false, 'Different exact times run separately');
	await set('#backupplus_interval_database', 'weekly');
	await set(db + ' .backupplus-schedule-weekday select', '6');

	// Incremental backups take run times only, and only while enabled; WordPress's duplicate hourly schedule is not offered.
	assert.deepEqual(await evaluate(`jQuery('#backupplus_interval_increments option').map(function() { return this.value; }).get()`), ['none', 'everyhour', 'every2hours', 'every4hours', 'every8hours', 'twicedaily', 'daily'], 'Increments offer each schedule once');
	await set('#backupplus_interval_increments', 'none');
	assert.equal(await visible(increments), false, 'Disabled increments hide timing');
	await set('#backupplus_interval_increments', 'every2hours');
	await evaluate(`jQuery('${increments} .backupplus-exact-toggle').prop('checked', false).trigger('change'); true`);
	assert.equal(await visible(increments + ' .backupplus-exact-toggle') && !(await visible(increments + ' .backupplus-exact-fields')), true, 'Increments offer an exact time');
	await evaluate(`jQuery('${increments} .backupplus-exact-toggle').prop('checked', true).trigger('change'); true`);
	assert.equal(await visible(increments + ' select[data-part="hours"]'), true, 'Choosing an exact incremental time shows the run times');
	assert.equal(await evaluate(`jQuery('${increments} select[data-day-for], ${increments} input.backupplus-startday').length`), 0, 'Increments have no day');
	// Hourly schedules take only the minute.
	await set('#backupplus_interval_increments', 'everyhour');
	assert.equal(await visible(increments + ' select[data-part="minute"]') && !(await visible(increments + ' select[data-part="hours"]')) && !(await visible(increments + ' input[type="time"]')), true, 'Hourly schedules choose only the minute');
	assert.deepEqual(await evaluate(`[jQuery('${increments} select[data-part="minute"] option').length, jQuery('${increments} select[data-part="minute"] option').eq(20).text()]`), [60, ':20'], 'Every minute past the hour is offered');
	await set(increments + ' select[data-part="minute"]', '20');
	assert.equal(await evaluate(`jQuery('${increments} input.backupplus-starttime').val()`), '00:20', 'The minute is submitted');
	await set('#backupplus_interval_increments', 'every2hours');
	assert.equal(await evaluate(`jQuery('${increments} select[data-part="hours"] option:selected').text()`), '00:20, 02:20, 04:20, 06:20, 08:20, 10:20, 12:20, 14:20, 16:20, 18:20, 20:20, 22:20', 'The minute carries over to longer intervals');

	// Cleanup time appears only in daily mode.
	await set('#backupplus_retention_cleanup_mode', 'after_backup');
	assert.equal(await visible('.backupplus-retention-cleanup-time'), false, 'After-backup cleanup has no time');
	await set('#backupplus_retention_cleanup_mode', 'daily');
	assert.equal(await visible('.backupplus-retention-cleanup-time'), true, 'Daily cleanup shows its time');
	await set('#backupplus_retention_cleanup_time', '04:05');
	assert.equal(await evaluate(`!!document.querySelector('.backupplus-schedule-help a[href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/"]')`), true, 'Server cron guidance is linked');

	// Saving refreshes the next file, database and cleanup times.
	await save();
	const saved = JSON.parse(await evaluate('window.schedule_test_response'));
	assert.equal(saved.saved, true, 'Valid settings must save: ' + JSON.stringify(saved).slice(0, 500));
	let state = settings('read');
	assert.deepEqual([state.options.backupplus_schedule_exact_files, state.options.backupplus_starttime_files, state.options.backupplus_schedule_exact_db, state.options.backupplus_startday_db, state.options.backupplus_retention_cleanup_mode, state.options.backupplus_retention_cleanup_time].map(String), ['1', '02:15', '1', '6', 'daily', '04:05'], 'Settings must persist: ' + JSON.stringify(state.options));
	assert.equal(state.events.backupplus_backup.length, 1, 'One file event');
	assert.match(state.events.backupplus_backup[0], / 02:15 daily$/, 'The file event uses the chosen time');
	assert.deepEqual(state.events.backupplus_backup_database, ['6 03:40 weekly'], 'The database event uses the chosen weekday and time');
	assert.match(state.events.backupplus_retention_cleanup.join(), /^\d 04:05 daily$/, 'The cleanup event uses the chosen time');
	assert.deepEqual([state.options.backupplus_interval_increments, String(state.options.backupplus_schedule_exact_increments), state.options.backupplus_starttime_increments], ['every2hours', '1', '00:20'], 'Incremental timing must persist');
	assert.match(state.events.backupplus_backup_increments.join(), /^\d \d[02468]:20 every2hours$|^\d [02468]\d:20 every2hours$/, 'The incremental event is anchored to the chosen time');
	assert.match(await evaluate(`jQuery('#backupplus-next-incremental-backup-inner').text()`), /:20$/, 'The incremental display refreshes');
	assert.match(await evaluate(`jQuery('#backupplus-next-files-backup-inner').text()`), /02:15/, 'The file display refreshes');
	assert.match(await evaluate(`jQuery('#backupplus-next-database-backup-inner').text()`), /Sat.*03:40/, 'The database display refreshes');
	assert.match(await evaluate(`jQuery('#backupplus-next-cleanup-inner').text()`), /04:05/, 'The cleanup display refreshes');

	// Invalid input is rejected and nothing changes.
	await set(files + ' input[type="time"]', '');
	await set('#backupplus_retain', '7');
	await save();
	const rejected = JSON.parse(await evaluate('window.schedule_test_response'));
	assert.equal(rejected.saved, false, 'An empty exact time must be rejected');
	assert.match(await evaluate('document.body.innerText'), /HH:MM/, 'The error explains the time format');
	const after = settings('read');
	assert.deepEqual(after, state, 'A rejected save must change nothing');
	await set(files + ' input[type="time"]', '02:15');
	await set('#backupplus_retain', '2');

	// Reloaded controls show the saved state; unchecked exact settings export as 0.
	await open();
	assert.equal(await evaluate(`jQuery('${files} .backupplus-exact-toggle').prop('checked') && jQuery('${db} .backupplus-exact-toggle').prop('checked')`), true, 'Exact settings reload');
	assert.equal(await evaluate(`jQuery('${db} .backupplus-schedule-weekday select').val() + ' ' + jQuery('${db} input[type="time"]').val()`), '6 03:40', 'Database timing reloads');
	assert.equal(await visible('.backupplus-retention-cleanup-time') && '04:05' === await evaluate(`jQuery('#backupplus_retention_cleanup_time').val()`), true, 'Cleanup timing reloads');
	assert.equal(await evaluate(`!!document.querySelector('#backupplus-next-cleanup-inner')`), true, 'The next scheduled box shows cleanup');
	assert.equal(await evaluate(`jQuery('${increments} .backupplus-exact-toggle').prop('checked') && jQuery('${increments} select[data-part="hours"]').val() + ' ' + jQuery('${increments} select[data-part="hour-minute"]').val()`), '0 20', 'Incremental timing reloads');
	await evaluate(`jQuery('${db} .backupplus-exact-toggle').prop('checked', false).trigger('change'); true`);
	const form = await evaluate(`(() => { const data = jQuery("#backupplus-navtab-settings-content form input[name!='action'][name!='option_page'][name!='_wpnonce'][name!='_wp_http_referer'], #backupplus-navtab-settings-content form textarea, #backupplus-navtab-settings-content form select, #backupplus-navtab-settings-content form input[type=checkbox]").serializeJSON({checkboxUncheckedValue: '0', useIntKeysAsArrayIndex: true}); return [data.backupplus_schedule_exact_files, data.backupplus_schedule_exact_db, data.backupplus_starttime_files, data.backupplus_retention_cleanup_mode, data.backupplus_retention_cleanup_time]; })()`);
	assert.deepEqual(form, ['1', '0', '02:15', 'daily', '04:05'], 'Exported settings include exact timing and cleanup');

	// The fields after "Keep", "at" and "on" start on one vertical.
	const lefts = await evaluate(`[...new Set([...document.querySelectorAll('.backup-schedule .backupplus-field-prefix')].filter((p) => p.getClientRects().length).map((p) => Math.round(p.parentNode.querySelector('input:not([type=hidden]), select').getBoundingClientRect().left)))]`);
	assert.equal(lefts.length, 1, 'Schedule fields must line up: ' + JSON.stringify(lefts));

	// Narrow screens keep the controls inside the settings column.
	await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
	await evaluate(`jQuery('${db} .backupplus-exact-toggle').prop('checked', true).trigger('change'); true`);
	const overflow = await evaluate(`(() => [...document.querySelectorAll('.backupplus-schedule-timing input, .backupplus-schedule-timing select, #backupplus_retention_cleanup_mode, #backupplus_retention_cleanup_time')].filter((el) => el.getClientRects().length && el.getBoundingClientRect().right > document.documentElement.clientWidth + 1).map((el) => el.outerHTML))()`);
	assert.deepEqual(overflow, [], 'Schedule controls fit narrow screens');
	console.log('Backup schedule browser checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise((resolve) => browser.once('exit', resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
	// The development server handles one request at a time, so this returns after any queued page or cron request that could save settings.
	await fetch(origin + '/wp-includes/js/jquery/jquery.min.js').catch(() => null);
	settings('restore');
}
