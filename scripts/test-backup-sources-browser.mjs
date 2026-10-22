/** Chromium checks for backup source controls and responsive connection layouts. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const origin = process.env.BACKUPPLUS_TEST_URL || 'http://localhost:8080';
const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((p) => ['chromium', 'chromium-browser', 'google-chrome'].map((n) => path.join(p, n)));
const cache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(cache)) for (const folder of readdirSync(cache).sort().reverse()) if (folder.startsWith('chromium_headless_shell-')) candidates.push(path.join(cache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'));
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-sources-browser-'));
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
	await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login') });
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');

	await call('Page.navigate', {url: origin + '/wp-admin/options-general.php?page=backupplus&tab=settings'});
	await wait('document.readyState === "complete" && window.jQuery && window.backupplus_database_override && document.querySelector(".bp-databases")');
	assert.equal(await evaluate(`(() => {
		const files = document.querySelector('#backupplus_include_wpcore').closest('tr');
		const databases = document.querySelector('#backupplus-database-settings');
		const email = document.querySelector('#backupplus_report_row_no_addon');
		return !!(files.compareDocumentPosition(databases) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(databases.compareDocumentPosition(email) & Node.DOCUMENT_POSITION_FOLLOWING);
	})()`), true, 'Database connections follow file inclusion and precede Email');
	assert.equal(await evaluate(`!!document.querySelector('#backupplus_include_wpcore')`), true, 'Core opt-in checkbox is rendered with a valid ID');
	assert.equal(await evaluate(`!!document.querySelector('[name="backupplus_include_more_path"]')`), true, 'Additional paths editor is rendered');
	assert.equal(await evaluate(`fetch(backupplus_sources.ajax, {method:'POST',body:new URLSearchParams({action:'backupplus_source_tables',nonce:'invalid'})}).then(r=>r.status)`), 403, 'Table discovery rejects invalid nonces');
	await evaluate(`jQuery('.bp-add-database').first().trigger('click'); jQuery('.bp-add-database').first().trigger('click'); true`);
	assert.equal(await evaluate(`new Set(jQuery('.bp-databases[data-manual="0"] .bp-connection').map(function(){return jQuery(this).data('id')}).get()).size`), await evaluate(`jQuery('.bp-databases[data-manual="0"] .bp-connection').length`), 'New database IDs are unique');
	const idBefore = await evaluate(`Number(jQuery('.bp-next-db-id').val())`);
	await evaluate(`jQuery('.bp-remove-database').last().trigger('click'); jQuery('.bp-add-database').first().trigger('click'); true`);
	assert.equal(await evaluate(`Number(jQuery('.bp-next-db-id').val())`), idBefore+1, 'Removed database IDs are not reused');
	assert.equal(await evaluate(`(() => {
		const card = document.querySelector('#backupplus-database-settings .bp-connection');
		const fields = [...card.querySelectorAll('[data-field]')];
		const boxes = fields.map(input => input.getBoundingClientRect());
		return boxes.every(box => Math.abs(box.left - boxes[0].left) < 1 && Math.abs(box.width - boxes[0].width) < 1) && fields.every(input => !!document.querySelector('label[for="'+input.id+'"]'));
	})()`), true, 'Desktop connection fields align and have associated labels');
	if (process.env.BACKUPPLUS_SOURCES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus-database-settings').scrollIntoView(); window.scrollBy(0,-60); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_SOURCES_SCREENSHOT, Buffer.from(screenshot.data,'base64'));
	}
	await call('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
	await evaluate(`jQuery('#backupplus-database-settings .bp-connection .bp-table-mode').first().val('selected').trigger('change'); true`);
	const mobileLayout = await evaluate(`(() => {
		const card = document.querySelector('#backupplus-database-settings .bp-connection');
		const bounds = card.getBoundingClientRect();
		return {width: innerWidth, columns: getComputedStyle(card.querySelector('.bp-connection-field')).gridTemplateColumns, overflow: [...card.querySelectorAll('[data-field], button, select')].filter(input => { const box = input.getBoundingClientRect(); return input.getClientRects().length && (box.left < bounds.left || box.right > bounds.right + 1); }).map(input => input.outerHTML)};
	})()`);
	assert.equal(mobileLayout.columns.split(' ').length, 1, JSON.stringify(mobileLayout));
	assert.deepEqual(mobileLayout.overflow, [], 'Mobile controls stay within their card');
	if (process.env.BACKUPPLUS_SOURCES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus-database-settings .bp-connection').scrollIntoView(); window.scrollBy(0,-60); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_SOURCES_SCREENSHOT.replace(/\.png$/, '-mobile.png'), Buffer.from(screenshot.data,'base64'));
	}
	await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
	const tables = await evaluate(`new Promise(resolve => jQuery.post(backupplus_sources.ajax,{action:'backupplus_source_tables',nonce:backupplus_sources.nonce}).done(resolve))`);
	assert.equal(tables.success, true, 'WordPress table discovery succeeds');
	assert(tables.data.tables.length > 0, 'Discovery lists actual database tables');
	await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table-mode').first().val('selected').trigger('change'); jQuery('#backupnow_database_moreoptions .bp-table').prop('checked',false); true`);
	assert.equal(await evaluate(`backupplus_database_override()`), null, 'Empty one-off selected lists cannot proceed');
	const one = tables.data.tables[0];
	await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table').filter(function(){return this.value===${JSON.stringify(one)}}).prop('checked',true); true`);
	assert.deepEqual((await evaluate(`backupplus_database_override()`)).wp.tables, [one], 'One-off table payload contains exact selected names');
	assert.equal(await evaluate(`jQuery('.bp-databases[data-manual="0"] .bp-table-mode').first().val()`), 'all', 'One-off changes leave saved-settings controls unchanged');
	await evaluate(`jQuery('#backupnow_database_moreoptions').show(); jQuery('#backupnow_database_moreoptions .bp-table-search').val('no-such-table').trigger('input'); true`);
	assert.equal(await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table-list label:visible').length`), 0, 'Search filters table checkboxes');
	assert.equal(await evaluate(`!!document.querySelector('[name="backupplus_restorer_wpcore_includewpconfig"]')`), true, 'Restore wizard offers explicit wp-config replacement');
	console.log('Backup source browser checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise(resolve => browser.once('exit', resolve));
	await rm(directory, {recursive:true,force:true,maxRetries:10,retryDelay:100});
}
