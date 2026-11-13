#!/usr/bin/env node
/**
 * Capture the WordPress.org plugin directory screenshots from a running site with BackupPlus and a few backups:
 *
 *   node scripts/capture-wporg-screenshots.mjs URL DEV_LOGIN_TOKEN OUT_DIR [only=N,...]
 *
 * dev/screenshots.sh prepares the site and runs this. Each screenshot matches its caption under `== Screenshots ==` in
 * readme.txt, and is written as OUT_DIR/screenshot-N.png. Headless Chromium is driven over the DevTools protocol, as in
 * the browser tests; CHROMIUM selects the executable.
 */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { homedir, tmpdir } from 'node:os';
import path from 'node:path';

const [origin, token, outDir, onlyArg] = process.argv.slice(2);
assert(origin && token && outDir, 'Usage: capture-wporg-screenshots.mjs URL DEV_LOGIN_TOKEN OUT_DIR [only=N,...]');
const only = onlyArg ? new Set(onlyArg.replace(/^only=/, '').split(',').map(Number)) : null;
const width = 1440,
	height = 900;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function findChromium() {
	const names = ['chromium', 'chromium-browser', 'google-chrome', 'chrome-headless-shell'];
	const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((dir) => names.map((n) => path.join(dir, n)));
	const cache = path.join(homedir(), '.cache/ms-playwright');
	if (existsSync(cache))
		for (const dir of readdirSync(cache).sort().reverse())
			if (dir.startsWith('chromium_headless_shell-'))
				candidates.push(path.join(cache, dir, 'chrome-headless-shell-linux64/chrome-headless-shell'));
	const chrome = process.env.CHROMIUM || candidates.find(existsSync);
	assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
	return chrome;
}

/** Start Chromium and return a DevTools session on its page. */
async function openBrowser() {
	const profile = await mkdtemp(path.join(tmpdir(), 'backupplus-screenshots-browser-'));
	const browser = spawn(
		findChromium(),
		[
			'--headless=new',
			'--disable-gpu',
			'--no-sandbox',
			'--disable-dev-shm-usage',
			'--disable-background-networking',
			'--hide-scrollbars',
			'--font-render-hinting=none',
			'--remote-debugging-port=0',
			'--user-data-dir=' + profile,
			'--window-size=' + width + ',' + height,
			'about:blank',
		],
		{ stdio: ['ignore', 'ignore', 'pipe'] },
	);
	let stderr = '';
	browser.stderr.on('data', (b) => (stderr += b));
	let port;
	for (let i = 0; i < 100 && !port; i++) {
		port = await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')
			.then((s) => s.split('\n')[0])
			.catch(() => null);
		if (!port) await sleep(100);
	}
	assert(port, 'Chromium failed to start: ' + stderr);
	const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then((r) => r.json());
	const ws = new WebSocket(pages.find((p) => p.type === 'page').webSocketDebuggerUrl);
	await new Promise((resolve, reject) => {
		ws.addEventListener('open', resolve, { once: true });
		ws.addEventListener('error', reject, { once: true });
	});
	let seq = 0;
	const pending = new Map(),
		exceptions = [];
	ws.addEventListener('message', ({ data }) => {
		const m = JSON.parse(data);
		if (m.id && pending.has(m.id)) {
			const p = pending.get(m.id);
			pending.delete(m.id);
			m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
		} else if (m.method === 'Runtime.exceptionThrown') exceptions.push(m.params.exceptionDetails);
	});
	const call = (method, params = {}) =>
		new Promise((resolve, reject) => {
			const id = ++seq;
			pending.set(id, { resolve, reject });
			ws.send(JSON.stringify({ id, method, params }));
		});
	const evaluate = async (expression) => {
		const r = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
		if (r.exceptionDetails) throw new Error(expression + ': ' + JSON.stringify(r.exceptionDetails));
		return r.result.value;
	};
	const wait = async (expression, timeout = 60000) => {
		for (const end = Date.now() + timeout; Date.now() < end; await sleep(200))
			if (await evaluate('!!(' + expression + ')').catch(() => false)) return;
		throw new Error('Timed out waiting for: ' + expression);
	};
	const navigate = async (url) => {
		await call('Page.navigate', { url });
		await wait('document.readyState === "complete" && location.href.startsWith(' + JSON.stringify(url) + ')');
	};
	const close = async () => {
		ws.close();
		browser.kill();
		await new Promise((resolve) => browser.once('close', resolve));
		await rm(profile, { recursive: true, force: true });
	};
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
	return { call, evaluate, wait, navigate, close, exceptions };
}

const s = await openBrowser();
const settings = origin + '/wp-admin/options-general.php?page=backupplus';

/** Settle the page (fonts, animations), hide what does not belong in a product screenshot, and save it. */
async function shoot(n, clip) {
	await s.evaluate(`(async () => {
		await document.fonts.ready;
		const style = document.createElement('style');
		style.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }';
		document.head.appendChild(style);
	})()`);
	await sleep(400);
	const { data } = await s.call('Page.captureScreenshot', { format: 'png', ...(clip ? { clip: { ...clip, scale: 1 } } : {}) });
	const file = path.join(outDir, 'screenshot-' + n + '.png');
	await writeFile(file, Buffer.from(data, 'base64'));
	console.log('screenshot-' + n + '.png');
}

/** Open the restore screen for the newest backup of the whole site, with the themes, uploads and database chosen. */
async function chooseComponents() {
	await s.navigate(settings + '&tab=backups');
	await s.evaluate(
		`[...document.querySelectorAll('button.choose-components-button')].find((b) => b.closest('tr').querySelector('button[class*="uddownloadform_themes_"]')).click()`,
	);
	await s.wait('document.getElementById("backupplus_restore_themes")?.offsetParent');
	await s.evaluate('for (const c of ["themes", "uploads", "db"]) document.getElementById("backupplus_restore_" + c).click()');
}

/** The restore screen has no admin menu: crop the empty page below its panel. */
const restorePanel = async () => ({
	x: 0,
	y: 0,
	width,
	height:
		Math.ceil(
			await s.evaluate('[...document.querySelectorAll(".backupplus_restore_main")].find((e) => e.offsetParent).getBoundingClientRect().bottom'),
		) + 64,
});

/** Scroll so that the element the expression finds is just below the admin bar. */
const scrollTo = (expression, margin = 24) =>
	s.evaluate(`window.scrollTo(0, (${expression}).getBoundingClientRect().top + scrollY - 32 - ${margin})`);

const existingBackups = `[...document.querySelectorAll('h2, h3')].find((e) => e.offsetParent && e.textContent.trim().startsWith('Existing backups'))`;

const steps = {
	// 1. Configuring your backups: the schedules through to the storage destinations, in one tall screenshot.
	1: async (n) => {
		await s.navigate(settings + '&tab=settings');
		const bottom = await s.evaluate(
			'Math.ceil(document.getElementById("remote-storage-container").getBoundingClientRect().bottom + scrollY)',
		);
		await s.call('Emulation.setDeviceMetricsOverride', { width, height: bottom, deviceScaleFactor: 1, mobile: false });
		await s.evaluate('window.scrollTo(0, 0)');
		await shoot(n);
		await s.call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
	},
	// 2. Take a backup.
	2: async (n) => {
		await s.navigate(settings + '&tab=backups');
		await s.evaluate('document.getElementById("backupplus-backupnow-button").click()');
		await s.wait('document.getElementById("backupplus-backupnow-modal")?.offsetParent');
		await shoot(n);
	},
	// 3. Choose which components to restore.
	3: async (n) => {
		await chooseComponents();
		await shoot(n, await restorePanel());
	},
	// 5. To migrate, download the backup files.
	5: async (n) => {
		await s.navigate(settings + '&tab=backups');
		for (const what of ['db', 'plugins']) await s.evaluate(`document.querySelector('button[class*="uddownloadform_${what}_"]').click()`);
		await s.wait(`[...document.querySelectorAll('.ud_downloadstatus .backupplus_downloader')].filter((e) => e.innerText.includes('File ready')).length === 2`);
		await scrollTo(existingBackups);
		await shoot(n);
	},
	// 6. On the destination site, upload the backup files.
	6: async (n) => {
		await s.navigate(settings + '&tab=backups');
		await s.evaluate('document.querySelector(".backupplus_uploader_toggle").click()');
		await s.wait('document.getElementById("plupload-upload-ui")?.offsetParent || document.querySelector(".drag-drop-area, #drag-drop-area")?.offsetParent');
		await scrollTo(existingBackups);
		await shoot(n);
	},
	// 4. Restoration shows as successful. A real restore, so it runs last: restoring the database ends the session.
	4: async (n) => {
		await chooseComponents();
		await s.evaluate('document.querySelector(".backupplus-restore--next-step").click()');
		await s.wait('document.querySelector(".backupplus-restore--next-step")?.textContent.trim() === "Restore" && !window.backupplus_restore_preparing', 120000);
		await s.evaluate('document.querySelector(".backupplus-restore--next-step").click()');
		await s.wait('/Restore (successful|failed)/.test(document.body.innerText)', 300000);
		assert(await s.evaluate('/Restore successful!/.test(document.body.innerText)'), 'The restore failed');
		// Show the end of the activity log, where it reports the result.
		await sleep(1000);
		await s.evaluate('for (const e of document.querySelectorAll(".backupplus_restore_main--activity, .backupplus_restore_main--activity *")) e.scrollTop = e.scrollHeight');
		await shoot(n, await restorePanel());
	},
};


try {
	await s.call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(token) });
	await s.wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');
	for (const n of [1, 2, 3, 5, 6, 4]) {
		if (only && !only.has(n)) continue;
		try {
			await steps[n](n);
		} catch (error) {
			// Keep what the page showed, to see why the step failed.
			const { data } = await s.call('Page.captureScreenshot', { format: 'png' });
			await writeFile(path.join(tmpdir(), 'backupplus-screenshot-' + n + '-failed.png'), Buffer.from(data, 'base64'));
			console.error('Screenshot ' + n + ' failed; the page is in ' + path.join(tmpdir(), 'backupplus-screenshot-' + n + '-failed.png'));
			throw error;
		}
	}
	assert.deepEqual(s.exceptions.map((e) => e.exception?.description || e.text), [], 'The pages threw JavaScript errors');
} finally {
	await s.close();
}
