/** Chromium checks for admin actions, backup source controls and responsive connection layouts. */
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
	assert.equal(await evaluate(`/Fatal error:|Warning:|Notice:|Deprecated:/.test(document.body.innerText)`), false, 'Settings render without PHP errors');
	assert.deepEqual(await evaluate(`(() => {
		const ids = [...document.querySelectorAll('[id]')].map(element => element.id);
		return ids.filter((id, index) => id.trim() !== id || ids.indexOf(id) !== index);
	})()`), [], 'Admin controls must have unique IDs without surrounding whitespace');
	assert.deepEqual(await evaluate(`(() => {
		const original = window.backupplus_popuplog, calls = [];
		const panel = jQuery('<div><div><a class="backupplus-log-link" data-jobid="test-job">Log</a></div></div>').appendTo('body');
		window.backupplus_popuplog = id => calls.push(id);
		try {
			panel.find('a')[0].click();
			panel.html('<div><a class="backupplus-log-link" data-jobid="test-job" data-fileid="test-file">Log</a></div>');
			panel.find('a')[0].click();
			return calls;
		} finally {
			window.backupplus_popuplog = original;
			panel.remove();
		}
	})()`), ['test-job', 'test-file'], 'Refreshed log links open once and use the file ID when provided');
	for (const email of [true, false]) {
		await evaluate(`set_email_report_storage_interface(${email}); true`);
		assert.deepEqual(await evaluate(`[
			jQuery('#cb_email_storage_label').css('display') !== 'none',
			jQuery('#cb_not_email_storage_label').css('display') !== 'none',
			jQuery('#backupplus_email').prop('disabled')
		]`), [email, !email, email], 'Email reporting text and controls follow the storage selection');
	}
	await evaluate(`backupplus_open_main_tab('settings'); jQuery('.backupplus_add_exclude_item').first().trigger('click'); jQuery('.backupplus-exclude-link[data-panel="contain-clause"]').trigger('click'); document.querySelector('label.contain-clause-sub-label').click(); true`);
	assert.equal(await evaluate(`document.activeElement.id`), 'backupplus_exclude_clause_field', 'The wildcard label focuses its own field');
	await evaluate(`jQuery('#backupplus_exclude_modal').dialog('close'); true`);
	assert.equal(await evaluate(`(() => {
		const files = document.querySelector('#backupplus_include_wpcore').closest('tr');
		const databases = document.querySelector('#backupplus-database-settings');
		const email = document.querySelector('#backupplus_report_row_no_addon');
		return !!(files.compareDocumentPosition(databases) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(databases.compareDocumentPosition(email) & Node.DOCUMENT_POSITION_FOLLOWING);
	})()`), true, 'Database connections follow file inclusion and precede Email');
	assert.equal(await evaluate(`!!document.querySelector('#backupplus_include_wpcore')`), true, 'Core opt-in checkbox is rendered with a valid ID');
	assert.equal(await evaluate(`!!document.querySelector('[name="backupplus_include_more_path"]')`), true, 'Additional paths editor is rendered');
	for (const entity of ['wpcore', 'more']) {
		await evaluate(`jQuery.fx.off = true; jQuery('#backupplus_include_${entity}').prop('checked', false).trigger('click'); true`);
		await wait(`jQuery('#backupplus_include_${entity}_exclude_container').is(':visible')`);
		await evaluate(`jQuery('#backupplus_include_${entity}_exclude_container .backupplus_add_exclude_item').trigger('click'); jQuery('.backupplus-exclude-link[data-panel="extension"]').trigger('click'); jQuery('#backupplus_exclude_extension_field').val('bptest'); jQuery('.backupplus-exclude-submit[data-panel="extension"]').trigger('click'); true`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bptest')`), true, entity + ' modal adds a rule to the submitted value');
		await evaluate(`(() => {
			const row = jQuery('#backupplus_include_${entity}_exclude_container .backupplus_exclude_entity_wrapper').last();
			row.find('.backupplus_exclude_entity_edit').trigger('click');
			row.find('input').val('ext:bpedited');
			row.find('.backupplus_exclude_entity_update').trigger('click');
		})()`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bpedited')`), true, entity + ' rule edits update the submitted value');
		await evaluate(`(() => {
			const confirm = window.confirm;
			window.confirm = () => true;
			jQuery('#backupplus_include_${entity}_exclude_container .backupplus_exclude_entity_delete').last().trigger('click');
			window.confirm = confirm;
		})()`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bpedited')`), false, entity + ' rule deletion updates the submitted value');
		await evaluate(`jQuery('#backupplus_include_${entity}_exclude_container .backupplus_add_exclude_item').trigger('click'); jQuery('.backupplus-exclude-link[data-panel="file-dir"]').trigger('click'); true`);
		await wait(`jQuery('#backupplus_exclude_files_folders_jstree .jstree-anchor').length > 0`);
		await evaluate(`jQuery('#backupplus_exclude_modal').dialog('close'); true`);
		await evaluate(`jQuery('#backupplus_include_${entity}').trigger('click'); true`);
		await wait(`!jQuery('#backupplus_include_${entity}_exclude_container').is(':visible')`);
		await evaluate(`jQuery('#backupplus_include_${entity}').trigger('click'); true`);
	}
	if (process.env.BACKUPPLUS_FILES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus_include_wpcore').scrollIntoView(); window.scrollBy(0,-80); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_FILES_SCREENSHOT, Buffer.from(screenshot.data,'base64'));
	}
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
	assert.equal(await evaluate(`['wpcore', 'more'].every(entity => {
		const panel = document.querySelector('#backupplus_include_' + entity + '_exclude_container');
		const bounds = panel.getBoundingClientRect();
		return bounds.left >= 0 && bounds.right <= innerWidth && [...panel.querySelectorAll('input:not([type="hidden"]), textarea, .backupplus_add_exclude_item')].every(input => input.getBoundingClientRect().right <= bounds.right);
	})`), true, 'Core and Additional files controls fit the mobile viewport');
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
