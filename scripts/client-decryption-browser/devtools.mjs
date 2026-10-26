/** DevTools protocol connection of the client decryption browser checks. */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

/** Connects to Chromium over DevTools, signs in and opens the Decryption tab. */
export async function connect(ctx) {
	const { origin, directory, sleep } = ctx;
	let port;
	for (let i = 0; i < 100; i++) {
		try {
			port = (await readFile(path.join(directory, 'DevToolsActivePort'), 'utf8')).split('\n')[0];
			break;
		} catch {}
		await sleep(100);
	}
	assert(port, 'Chromium failed to start: ' + ctx.browserError);
	const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then((r) => r.json());
	const ws = (ctx.ws = new WebSocket(pages.find((p) => p.type === 'page').webSocketDebuggerUrl));
	await new Promise((r, j) => {
		ws.addEventListener('open', r, { once: true });
		ws.addEventListener('error', j, { once: true });
	});
	let seq = 0;
	const pending = new Map(),
		requests = [],
		redirects = [],
		downloads = [],
		exceptions = [];
	ws.addEventListener('message', ({ data }) => {
		const m = JSON.parse(data);
		if (m.id) {
			const p = pending.get(m.id);
			if (p) {
				pending.delete(m.id);
				m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
			}
		} else if (m.method === 'Network.requestWillBeSent') {
			requests.push(m.params.request);
			if (m.params.redirectResponse) redirects.push(m.params.redirectResponse);
		} else if (m.method === 'Browser.downloadWillBegin' || m.method === 'Page.downloadWillBegin')
			downloads.push(m.params);
		else if (m.method === 'Runtime.exceptionThrown') exceptions.push(m.params.exceptionDetails);
	});
	const call = (method, params = {}) =>
		new Promise((resolve, reject) => {
			const id = ++seq;
			pending.set(id, { resolve, reject });
			ws.send(JSON.stringify({ id, method, params }));
		});
	const evaluate = async (expression, userGesture = false) => {
		const r = await call('Runtime.evaluate', {
			expression,
			awaitPromise: true,
			returnByValue: true,
			userGesture,
		});
		if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
		return r.result.value;
	};
	async function wait(expression) {
		for (let i = 0; i < 300; i++) {
			try {
				if (await evaluate(expression)) return;
			} catch {}
			await sleep(100);
		}
		const state = await evaluate(
			`(async()=>{const opfs=[];try{const dir=await(await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const entry of dir.values()){const f=await entry.getFile();opfs.push({name:entry.name,size:f.size});}}catch{}const files=document.getElementById('backupplus-decrypt-files');return {status:document.getElementById('backupplus-decrypt-status')?.textContent,busy:document.querySelector('.backupplus-decryption-tool')?.getAttribute('aria-busy'),progress:document.getElementById('backupplus-decrypt-progress')?.value,results:document.querySelectorAll('#backupplus-decrypt-results a').length,startDisabled:document.getElementById('backupplus-decrypt-start')?.disabled,inputDisabled:files?.disabled,fileCount:files?.files?.length,selection:document.getElementById('backupplus-decrypt-selection')?.textContent,opfs};})()`,
		).catch(() => null);
		throw new Error('Browser condition timed out: ' + expression + ' ' + JSON.stringify(state));
	}
	async function waitForNoStagedFiles() {
		await wait(
			`(async()=>{const dir=await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const e of dir.values())if(e.name.startsWith('job-'))return false;return true;})()`,
		);
	}
	async function navigate(url) {
		await call('Page.navigate', { url });
		await wait('document.readyState === "complete" && location.href === ' + JSON.stringify(url));
	}
Object.assign(ctx, { requests, redirects, downloads, exceptions, call, evaluate, wait, waitForNoStagedFiles, navigate });
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Network.enable');
	await call('Network.setCacheDisabled', { cacheDisabled: true });
	await call('Browser.setDownloadBehavior', {
		behavior: 'allow',
		downloadPath: path.join(directory, 'downloads'),
		eventsEnabled: true,
	});
	await call('Page.navigate', {
		url:
			origin +
			'/?backupplus_dev_login=' +
			encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login'),
	});
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');
	await navigate(origin + '/wp-admin/options-general.php?page=backupplus&tab=decryption');
	await wait(
		'window.backupplus_decryption && document.getElementById("backupplus-decrypt-start") && document.querySelector("#backupplus-navtab-decryption.nav-tab-active")',
	);
}
