/** Chromium restores one site of a network backup and imports a single-site backup, through Network Admin's restore dialog on the sub-directory network of dev/test-sites.sh. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { execFileSync, spawn } from 'node:child_process';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const podman = process.env.PODMAN || 'podman';
const network = process.env.BACKUPPLUS_TEST_MS_CONTAINER || 'backupplus-test-ms-wordpress';
const single = process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress';
const tag = Math.random().toString(16).slice(2, 8);
const run = (container, ...command) => execFileSync(podman, ['exec', container, ...command], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 900000 }).trim();
const wp = (container, ...args) => run(container, 'wp', '--allow-root', ...args);
const cleanup = [];
const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((p) => ['chromium', 'chromium-browser', 'google-chrome'].map((n) => path.join(p, n)));
const cache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(cache)) for (const folder of readdirSync(cache).sort().reverse()) if (folder.startsWith('chromium_headless_shell-')) candidates.push(path.join(cache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'));
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-multisite-browser-'));
const browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--remote-debugging-port=0', '--user-data-dir=' + directory, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
let browserError = '';
browser.stderr.on('data', (d) => { browserError += d; });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let ws;
try {
	assert.equal(run(network, 'sh', '-c', 'wp --allow-root core is-installed --network && echo yes'), 'yes', network + ' must be a WordPress network: run dev/test-sites.sh');
	assert.equal(wp(network, 'eval', 'echo (int) is_subdomain_install();'), '0', 'The dialog is checked on the sub-directory network');
	const origin = wp(network, 'option', 'get', 'home');

	// A site of the network, in a network backup, then changed
	const site = Number(wp(network, 'site', 'create', '--slug=ms-browser-' + tag, '--title=Browser check', '--porcelain'));
	cleanup.push(() => wp(network, 'site', 'delete', String(site), '--yes'));
	// Where a restore of the site keeps its previous uploads
	const siteOld = wp(network, 'eval', 'echo WP_CONTENT_DIR;') + '/uploads-old/sites/' + site;
	cleanup.push(() => run(network, 'sh', '-c', 'rm -rf ' + siteOld + ' && (rmdir ' + path.dirname(siteOld) + ' ' + path.dirname(path.dirname(siteOld)) + ' 2>/dev/null || true)'));
	const siteUrl = origin + '/ms-browser-' + tag + '/';
	const sitePost = wp(network, '--url=' + siteUrl, 'post', 'create', '--post_title=Site original ' + tag, '--post_status=publish', '--porcelain');
	const mainPost = wp(network, 'post', 'create', '--post_title=Main original ' + tag, '--post_status=publish', '--porcelain');
	cleanup.push(() => wp(network, 'post', 'delete', mainPost, '--force'));
	const networkBackup = wp(network, 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-browser-' + tag, '--porcelain');
	cleanup.push(() => wp(network, 'backupplus', 'delete', networkBackup, '--yes'));
	wp(network, '--url=' + siteUrl, 'post', 'update', sitePost, '--post_title=Site changed');
	wp(network, 'post', 'update', mainPost, '--post_title=Main changed');

	// A single-site backup, taken on the leased single site and copied into the network's backup directory
	const singlePost = wp(single, 'post', 'create', '--post_title=Imported ' + tag, '--post_status=publish', '--porcelain');
	const singleBackup = wp(single, 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-browser-single-' + tag, '--porcelain');
	const files = JSON.parse(wp(single, 'backupplus', 'restore', singleBackup, '--dry-run', '--format=json')).map((row) => row.file);
	const backups = (container) => wp(container, 'eval', 'echo $GLOBALS["backupplus"]->backups_dir_location();');
	const from = backups(single), to = backups(network);
	const transfer = await mkdtemp(path.join(tmpdir(), 'backupplus-multisite-transfer-'));
	try {
		for (const file of files) {
			execFileSync(podman, ['cp', single + ':' + from + '/' + file, path.join(transfer, file)]);
			execFileSync(podman, ['cp', path.join(transfer, file), network + ':' + to + '/' + file]);
		}
	} finally {
		await rm(transfer, { recursive: true, force: true });
	}
	wp(single, 'backupplus', 'delete', singleBackup, '--yes');
	wp(single, 'post', 'delete', singlePost, '--force');
	wp(network, 'backupplus', 'rescan');
	const imported = JSON.parse(wp(network, 'backupplus', 'list', '--format=json')).find((row) => 'ms-browser-single-' + tag === row.label);
	assert(imported, 'The single-site backup is in the network history after a rescan');
	cleanup.push(() => wp(network, 'backupplus', 'delete', String(imported.timestamp), '--yes'));
	const slug = 'ms-browser-import-' + tag;
	cleanup.push(() => wp(network, 'site', 'delete', '--slug=' + slug, '--yes'));

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
	const dialogs = [];
	ws.addEventListener('message', ({ data }) => {
		const message = JSON.parse(data);
		// An alert from the dialog is a failure of the step that raised it; dismiss it so the page does not hang
		if ('Page.javascriptDialogOpening' === message.method) {
			dialogs.push(message.params.message);
			ws.send(JSON.stringify({ id: ++sequence, method: 'Page.handleJavaScriptDialog', params: { accept: true } }));
		}
		if (!message.id || !pending.has(message.id)) return;
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
	async function wait(expression, seconds = 30) {
		for (let i = 0; i < seconds * 10; i++) { try { if (await evaluate(expression)) return; } catch {} await sleep(100); }
		throw new Error('Timed out: ' + expression + (dialogs.length ? '\nAlerts: ' + dialogs.join('\n') : ''));
	}
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login') });
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');

	const exists = (selector) => `!!document.querySelector('${selector}')`;
	/** Open the restore dialog of a backup in Network Admin's history, keep the database and uploads, and wait for the restore options. */
	async function open(timestamp) {
		await call('Page.navigate', { url: origin + '/wp-admin/network/settings.php?page=backupplus' });
		const button = `jQuery('button.choose-components-button').filter(function() { return String(jQuery(this).data('backup_timestamp')) === '${timestamp}'; })`;
		await wait(`document.readyState === "complete" && window.jQuery && ${button}.length === 1`);
		await evaluate(`jQuery.fx.off = true; ${button}.trigger('click'); jQuery('input[name="backupplus_restore[]"]').each(function() { jQuery(this).prop('checked', ['db', 'uploads'].includes(this.value)); }); jQuery('.backupplus-restore--next-step').trigger('click'); true`);
		await wait(exists('#backupplus_restore_multisite'), 120);
	}
	/** Start the restore with the options chosen, and wait for the restore screen's result. */
	async function restore() {
		await evaluate(`jQuery('.backupplus-restore--next-step').trigger('click'); true`);
		await wait(`document.readyState === "complete" && jQuery('.backupplus_restore_result').is('.restore-success, .restore-error')`, 600);
		return evaluate(`({success: jQuery('.backupplus_restore_result').is('.restore-success'), text: jQuery('.backupplus_restore_result--text').text(), log: jQuery('#backupplus_ajax_restore_output').text().slice(-3000)})`);
	}

	// One site of the network backup: the dialog lists the network's sites, as they were in the backup
	await open(networkBackup);
	await wait(exists('#backupplus_restore_ms_whichsites'));
	const choices = await evaluate(`jQuery('#backupplus_restore_ms_whichsites option').get().map(o => ({value: o.value, text: o.textContent, disabled: o.disabled}))`);
	assert.equal(choices[0].value, '0', 'The entire network is the default');
	assert.equal(await evaluate(`jQuery('#backupplus_restore_ms_whichsites').val()`), '0');
	assert(choices.some((o) => '1' === o.value && !o.disabled), 'The main site can be restored on its own');
	const choice = choices.find((o) => String(site) === o.value);
	assert(choice && !choice.disabled && choice.text.includes('ms-browser-' + tag), 'The site is offered with its address: ' + JSON.stringify(choices));
	assert.equal(await evaluate(exists('#backupplus_restore_ms_import')), false, 'A network backup cannot be imported');
	await evaluate(`jQuery('#backupplus_restore_ms_whichsites').val('${site}'); true`);
	let result = await restore();
	assert(result.success, 'Restoring one site from the dialog succeeds: ' + result.text + '\n' + result.log);
	assert.equal(wp(network, '--url=' + siteUrl, 'post', 'get', sitePost, '--field=post_title'), 'Site original ' + tag, 'The site is restored');
	assert.equal(wp(network, 'post', 'get', mainPost, '--field=post_title'), 'Main changed', 'The main site is left alone');

	// A single-site backup: the dialog offers a new site or the network's sites other than the main one
	await open(imported.timestamp);
	await wait(exists('#backupplus_restore_ms_import'));
	const targets = await evaluate(`jQuery('#backupplus_restore_ms_import option').get().map(o => o.value)`);
	assert.equal(targets[0], 'new', 'A new site is the default');
	assert(targets.includes(String(site)) && !targets.includes('1'), 'Existing sites other than the main one can be replaced: ' + targets.join(', '));
	assert.equal(await evaluate(exists('#backupplus_restore_ms_whichsites')), false, 'A single-site backup has no network sites to choose from');
	assert.equal(await evaluate(`jQuery('#backupplus_restore_ms_import_slug').closest('p').text().includes('${new URL(origin).host}/')`), true, "The new site's address is under the network's");

	// An address that is taken is refused before anything changes
	await evaluate(`jQuery('#backupplus_restore_ms_import_slug').val('ms-browser-${tag}'); true`);
	result = await restore();
	assert(!result.success && result.log.includes('already exists'), 'Importing into a taken address is refused: ' + result.text + '\n' + result.log);
	assert.equal(wp(network, '--url=' + siteUrl, 'post', 'get', sitePost, '--field=post_title'), 'Site original ' + tag, 'A refused import changes nothing');

	await open(imported.timestamp);
	await wait(exists('#backupplus_restore_ms_import_slug'));
	await evaluate(`jQuery('#backupplus_restore_ms_import').val('new'); jQuery('#backupplus_restore_ms_import_slug').val('${slug}'); true`);
	result = await restore();
	assert(result.success, 'Importing into a new site from the dialog succeeds: ' + result.text + '\n' + result.log);
	const newUrl = origin + '/' + slug + '/';
	assert.equal(wp(network, '--url=' + newUrl, 'option', 'get', 'siteurl'), origin + '/' + slug, 'The new site has its own address');
	assert.equal(wp(network, '--url=' + newUrl, 'post', 'list', '--post_type=post', '--title=Imported ' + tag, '--format=count'), '1', 'The new site has the imported content');

	// Over the site restored above, whose previous uploads that restore kept: as on a single site, they must be deleted first
	assert.equal(run(network, 'sh', '-c', 'test -d ' + siteOld + ' && echo kept'), 'kept', "Restoring the site kept its previous uploads");
	await open(imported.timestamp);
	await evaluate(`jQuery('#backupplus_restore_ms_import').val('${site}'); true`);
	result = await restore();
	assert(!result.success && result.log.includes('Existing unremoved folders') && result.log.includes('uploads-old/sites/' + site), "A restore that would replace the site's kept uploads is refused: " + result.text + '\n' + result.log);
	await call('Page.navigate', { url: origin + '/wp-admin/network/settings.php?page=backupplus' });
	await wait(`document.readyState === "complete" && !!document.querySelector('#backupplus_delete_old_dirs_pagediv form')`);
	await evaluate(`document.querySelector('#backupplus_delete_old_dirs_pagediv form').submit(); true`);
	await sleep(500);
	await wait(`document.readyState === "complete" && document.body.textContent.includes('Old folders successfully removed')`, 60);
	assert.equal(run(network, 'sh', '-c', 'test -e ' + siteOld + ' || echo deleted'), 'deleted', "Network Admin's Delete old folders removes them");
	await open(imported.timestamp);
	await evaluate(`jQuery('#backupplus_restore_ms_import').val('${site}'); true`);
	result = await restore();
	assert(result.success, 'Importing over a site from the dialog succeeds: ' + result.text + '\n' + result.log);
	assert.equal(wp(network, '--url=' + siteUrl, 'post', 'list', '--post_type=post', '--title=Imported ' + tag, '--format=count'), '1', "The site has the backup's content");
	assert.equal(wp(network, '--url=' + siteUrl, 'option', 'get', 'siteurl'), siteUrl.slice(0, -1), 'The site keeps its address');
	assert.equal(wp(network, 'post', 'get', mainPost, '--field=post_title'), 'Main changed', 'The main site is left alone');
	assert.deepEqual(dialogs, [], 'The dialog raised no alerts');
	console.log('Chromium Network Admin restore of one site, import into a new and an existing site, refused import address and kept old uploads, and Delete old folders checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise((resolve) => browser.exitCode !== null ? resolve() : browser.once('exit', resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
	for (const step of cleanup.reverse()) {
		try { step(); } catch (error) { console.error('Cleanup: ' + error.message); }
	}
}
