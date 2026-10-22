/** Chromium checks for the real admin script and deferred native updater API. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const origin = process.env.BACKUPPLUS_TEST_URL || 'http://localhost:8080';
const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((p) => ['chromium', 'chromium-browser', 'google-chrome'].map((n) => path.join(p, n)));
const cache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(cache)) for (const folder of readdirSync(cache).sort().reverse()) if (folder.startsWith('chromium_headless_shell-')) candidates.push(path.join(cache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'));
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-pre-update-browser-'));
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
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login') });
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');
	await call('Page.navigate', { url: origin + '/wp-admin/plugins.php' });
	await wait('document.readyState === "complete" && window.jQuery && window.wp && wp.updates && window.backupplusPreUpdate');
	assert.equal(await evaluate(`fetch(backupplusPreUpdate.url, {method:'POST', body:new URLSearchParams({action:'backupplus_pre_update', operation:'prepare', nonce:'invalid'})}).then(r => r.status)`), 403, 'Coordinator must reject an invalid nonce');
	const source = await readFile(new URL('../js/pre-update-backups.js', import.meta.url), 'utf8');
	// Real jQuery/DOM and the shipped script, with deterministic backup and native-update transports.
	async function setup(classic = false) {
		await evaluate(`(() => {
			jQuery('.backupplus-pre-update-progress, #backupplus-pre-update-classic, #backupplus-pre-update-resume, #backupplus-pre-update-recovery').remove();
			window.fixture = {state:'backing_up', calls:[], native:[], submissions:0};
			wp.updates.queue = [];
			wp.updates.ajax = function(action, data) { fixture.native.push({action, batch:data.backupplus_batch}); return jQuery.Deferred().resolve({ok:true}).promise(); };
			window.originalTestAjax = window.originalTestAjax || jQuery.ajax;
			jQuery.ajax = function(options) {
				if (!options.data || options.data.action !== 'backupplus_pre_update') return originalTestAjax.apply(this, arguments);
				fixture.calls.push(options.data);
				if (options.data.operation === 'retry') fixture.state = 'backing_up';
				if (options.data.operation === 'cancel') fixture.state = 'cancelled';
				return jQuery.Deferred().resolve({success:true, data:{id:'verified-fixture-batch', state:fixture.state, progress:fixture.state === 'ready' ? 100 : 70, message:fixture.state, log:'/authorized-log'}}).promise();
			};
			if (${classic}) {
				jQuery('<div id="backupplus-pre-update-classic"></div>').attr('data-targets', JSON.stringify(['core:99.0:en_US'])).prependTo('#wpbody-content .wrap');
				jQuery('#wpbody-content .wrap').prepend('<form id="backupplus-pre-update-resume"><input name="upgrade" value="1"></form>');
				document.querySelector('#backupplus-pre-update-resume').submit = function() { fixture.submissions++; fixture.formBatch = this.querySelector('[name="backupplus_batch"]').value; };
			}
		})()`);
		await evaluate(source);
		await sleep(100);
	}
	await setup();
	await evaluate(`wp.updates.queue = [{action:'update-plugin',data:{plugin:'second/second.php',slug:'second'}}]; wp.updates.ajax('update-plugin',{plugin:'first/first.php'}); true;`);
	await wait('fixture.calls.length > 0');
	assert.equal(await evaluate('fixture.native.length'), 0, 'Inline updates must wait for uploads');
	assert.deepEqual(await evaluate('fixture.calls[0].targets'), ['plugin:first/first.php', 'plugin:second/second.php'], 'One backup must cover the native bulk queue');
	await evaluate(`fixture.state = 'ready'`);
	await wait('fixture.native.length === 1');
	await evaluate(`wp.updates.ajax('update-plugin',{plugin:'second/second.php'}); true;`);
	await wait('fixture.native.length === 2');
	assert.equal(await evaluate(`fixture.calls.filter(c => c.operation === 'prepare').length`), 1, 'Bulk update must prepare once');
	assert.equal(await evaluate(`fixture.native.every(c => c.batch === 'verified-fixture-batch')`), true, 'Every native request must carry the verified batch');
	await setup();
	await evaluate(`fixture.state='failed'; wp.updates.ajax('update-theme',{slug:'sample'}); true;`);
	await wait('!!document.querySelector(".backupplus-pre-update-progress.notice-error")');
	assert.equal(await evaluate('fixture.native.length'), 0, 'Failure must block theme updates');
	await evaluate(`jQuery('.backupplus-pre-update-progress button').first().trigger('click')`);
	await wait(`fixture.calls.some(c => c.operation === 'retry')`);
	await evaluate(`fixture.state='ready'`);
	await wait('fixture.native.length === 1');
	assert.equal(await evaluate('fixture.native[0].action'), 'update-theme');
	await setup();
	await evaluate(`wp.updates.ajax('update-plugin',{plugin:'first/first.php',error:function(){fixture.cancelled=true;}}); true;`);
	await wait('document.querySelectorAll(".backupplus-pre-update-progress button").length === 2');
	await evaluate(`jQuery('.backupplus-pre-update-progress button').last().trigger('click')`);
	await wait('fixture.cancelled');
	await evaluate(`fixture.state='ready'`);
	await sleep(2200);
	assert.equal(await evaluate('fixture.native.length'), 0, 'Cancellation must suppress automatic continuation');
	await setup();
	await evaluate(`jQuery('#wpbody-content .wrap').removeClass('wrap').attr('data-test-wrap','1'); jQuery('body').append('<div id="plugin-information-footer"></div>'); wp.updates.ajax('update-plugin',{plugin:'dialog/dialog.php'}); true;`);
	await wait('!!document.querySelector("#plugin-information-footer .backupplus-pre-update-progress")');
	assert.equal(await evaluate('fixture.native.length'), 0, 'Plugin information dialogs must show progress while waiting');
	await evaluate(`fixture.state='ready'`);
	await wait('fixture.native.length === 1');
	await evaluate(`jQuery('[data-test-wrap]').addClass('wrap').removeAttr('data-test-wrap'); jQuery('#plugin-information-footer').remove();`);
	await setup(true);
	await wait('fixture.calls.length > 0');
	assert.equal(await evaluate('fixture.submissions'), 0, 'Classic form must wait');
	await evaluate(`fixture.state='ready'`);
	await wait('fixture.submissions === 1');
	assert.equal(await evaluate('fixture.formBatch'), 'verified-fixture-batch', 'Classic form must resume with batch authorization');
	console.log('Chromium pre-update bulk, theme, remote-wait, failure/retry, cancellation, plugin dialogs, classic continuation and nonce checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise((resolve) => browser.exitCode !== null ? resolve() : browser.once('exit', resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
}
