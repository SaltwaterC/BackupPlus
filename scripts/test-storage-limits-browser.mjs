/** Chromium checks for storage-limit controls and the BackupPlus status panel. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
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

	// The table stays in the shared field column, including long provider IDs on narrow screens.
	await evaluate(`(() => {
		const row = document.querySelector('#backupplus-storage-limits tbody tr').cloneNode(true);
		row.dataset.layoutFixture = 'true';
		row.querySelector('th').textContent = 's3:s-0123456789abcdef0123456789abcdef';
		row.querySelectorAll('input').forEach((input) => input.removeAttribute('name'));
		document.querySelector('#backupplus-storage-limits tbody').append(row);
	})()`);
	for (const width of [1920, 1440, 782, 390, 320]) {
		await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width <= 782 });
		const layout = await evaluate(`(() => {
			const table = document.querySelector('#backupplus-storage-limits');
			const bounds = table.getBoundingClientRect();
			const column = table.parentElement.querySelector('p').getBoundingClientRect();
			return {
				width: bounds.width,
				columnOverflow: bounds.right - column.right,
				viewportOverflow: bounds.right - document.documentElement.clientWidth,
				cellOverflow: [...table.querySelectorAll('input')].some((input) => input.getBoundingClientRect().right > input.closest('td').getBoundingClientRect().right + 1),
				contentOverflow: table.scrollWidth - table.clientWidth,
			};
		})()`);
		assert(layout.width <= 701 && layout.columnOverflow <= 1 && layout.viewportOverflow <= 1 && !layout.cellOverflow && layout.contentOverflow <= 2, 'Storage limits fit the settings column at ' + width + 'px: ' + JSON.stringify(layout));
	}
	await evaluate(`document.querySelector('[data-layout-fixture]').remove()`);
	if (process.env.BACKUPPLUS_STORAGE_LAYOUT_SCREENSHOT) {
		for (const width of [1920, 390]) {
			await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width <= 782 });
			await evaluate(`document.querySelector('#backupplus-storage-limits').scrollIntoView({block: 'center'})`);
			const screenshot = await call('Page.captureScreenshot', { format: 'png' });
			await writeFile(process.env.BACKUPPLUS_STORAGE_LAYOUT_SCREENSHOT + '-' + width + '.png', Buffer.from(screenshot.data, 'base64'));
		}
	}
	await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

	// Storage limits use GB in the form and integer bytes in saved settings.
	await open();
	const alertField = '[name="backupplus_storage_limits[local][__default][alert]"]';
	const quotaField = '[name="backupplus_storage_limits[local][__default][quota]"]';
	await set(alertField, '1.25');
	await set(quotaField, '0');
	await save();
	const storageState = settings('read');
	assert.equal(storageState.options.backupplus_storage_limits.local[''].alert, 1250000000, 'GB saves as integer bytes');
	assert.equal(storageState.options.backupplus_storage_limits.local[''].quota, 0, 'Zero disables the quota');
	assert.equal(storageState.events.backupplus_storage_monitor.length, 1, 'The hourly monitor is scheduled');
	await open();
	assert.equal(await evaluate(`jQuery(${JSON.stringify(alertField)}).val()`), '1.25', 'GB values reload');
	settings('storage-status');
	await call('Page.navigate', { url: origin + '/wp-admin/options-general.php?page=backupplus&tab=backups' });
	await wait('document.readyState === "complete" && document.querySelector("#backupplus-storage-status")');
	assert.match(await evaluate('document.querySelector("#backupplus-storage-status").innerText'), /Critical.*|Protected recovery chains retained/, 'Critical usage and blocked eviction appear in BackupPlus');
	await open();
	await set(alertField, '');
	await save();
	assert.equal(settings('read').options.backupplus_storage_limits.local[''].alert, 0, 'Blank disables the alert');
	console.log('Backup schedule and storage limits browser checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise((resolve) => browser.once('exit', resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
	// The development server handles one request at a time, so this returns after any queued page or cron request that could save settings.
	await fetch(origin + '/wp-includes/js/jquery/jquery.min.js').catch(() => null);
	settings('restore');
}
