/** Real Chromium smoke/integration checks against the local WordPress Decryption tab. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { readFile, writeFile, mkdtemp, rm, truncate } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { createCipheriv, createHash, randomBytes } from 'node:crypto';
import { createServer, request as httpRequest } from 'node:http';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const origin = process.env.BACKUPPLUS_TEST_URL || 'http://localhost:8080';
const candidates = (process.env.PATH || '')
	.split(path.delimiter)
	.flatMap((folder) =>
		['chromium', 'chromium-browser', 'google-chrome', 'chrome-headless-shell'].map((name) =>
			path.join(folder, name),
		),
	);
const playwrightCache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(playwrightCache))
	for (const folder of readdirSync(playwrightCache).sort().reverse()) {
		if (folder.startsWith('chromium_headless_shell-'))
			candidates.push(
				path.join(playwrightCache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'),
			);
	}
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required for browser tests. Set CHROMIUM to its executable.');
// Capture real HTTP access records, including Referer and redirect destinations.
// This proxy is restricted to the disposable WordPress test origin.
assert.equal(new URL(origin).protocol, 'http:', 'Use the local HTTP WordPress test environment.');
const started = new Date().toISOString();
const accessLog = [];
const proxyRequests = [];
const proxy = createServer((req, res) => {
	const url = new URL(req.url, origin);
	const record = { method: req.method, url: url.href, referer: req.headers.referer || '' };
	accessLog.push(record);
	const body = [];
	req.on('data', chunk => body.push(chunk));
	req.on('end', () => proxyRequests.push({
		url: url.href, headers: req.headers, body: Buffer.concat(body).toString(),
	}));
	if (url.origin !== new URL(origin).origin) {
		res.writeHead(403).end();
		return;
	}
	const upstream = httpRequest(url, { method: req.method, headers: req.headers }, (response) => {
		record.status = response.statusCode;
		record.location = response.headers.location || '';
		res.writeHead(response.statusCode, response.headers);
		response.pipe(res);
	});
	upstream.on('error', () => res.destroy());
	res.on('close', () => upstream.destroy());
	req.pipe(upstream);
});
await new Promise((resolve, reject) => {
	proxy.once('error', reject);
	proxy.listen(0, '127.0.0.1', resolve);
});
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-decrypt-browser-'));
const browser = spawn(
	chrome,
	[
		'--headless=new',
		'--disable-gpu',
		'--no-sandbox',
		'--disable-dev-shm-usage',
		'--disable-background-networking',
		'--proxy-server=http://127.0.0.1:' + proxy.address().port,
		'--proxy-bypass-list=<-loopback>',
		'--remote-debugging-port=0',
		'--user-data-dir=' + directory,
		'about:blank',
	],
	{ stdio: ['ignore', 'ignore', 'pipe'] },
);
const browserExited = new Promise((resolve) => browser.once('close', resolve));
let browserError = '';
browser.stderr.on('data', (b) => {
	browserError += b;
});
browser.on('error', (e) => {
	browserError += e.message;
});
const sleep = (n) => new Promise((r) => setTimeout(r, n));
let ws;
try {
	let port;
	for (let i = 0; i < 100; i++) {
		try {
			port = (await readFile(path.join(directory, 'DevToolsActivePort'), 'utf8')).split('\n')[0];
			break;
		} catch {}
		await sleep(100);
	}
	assert(port, 'Chromium failed to start: ' + browserError);
	const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then((r) => r.json());
	ws = new WebSocket(pages.find((p) => p.type === 'page').webSocketDebuggerUrl);
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
		} else if (m.method === 'Browser.downloadWillBegin' || m.method === 'Page.downloadWillBegin') downloads.push(m.params);
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
		throw new Error('Browser condition timed out: ' + expression);
	}
	async function navigate(url) {
		await call('Page.navigate', { url });
		await wait('document.readyState === "complete" && location.href === ' + JSON.stringify(url));
	}
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Network.enable');
	await call('Network.setCacheDisabled', { cacheDisabled: true });
	await call('Browser.setDownloadBehavior', {
		behavior: 'allow', downloadPath: path.join(directory, 'downloads'), eventsEnabled: true,
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
	assert.equal(
		await evaluate(
			'!!document.querySelector("#backupplus-decrypt-download, #plupload-upload-ui2")',
		),
		false,
	);
	assert.equal(
		await evaluate('typeof Buffer + ":" + typeof require + ":" + typeof process'),
		'undefined:undefined:undefined',
	);
	assert.equal(await evaluate('document.querySelectorAll("#backupplus_db_decrypt").length'), 1);
	assert.equal(
		await evaluate("document.querySelector('label[for=backupplus_db_decrypt]').textContent"),
		'Backup passphrase (UpdraftPlus legacy passphrase-encrypted archives)',
	);
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('#backupplus-database-encryption option')].map(option => ({value:option.value,label:option.textContent}))",
		),
		[
			{ value: 'none', label: 'Disabled' },
			{ value: 'age', label: 'age public keys' },
		],
	);
	assert.equal(await evaluate("!!document.querySelector('#backupplus_encryptionphrase')"), false);
	assert.equal(
		await evaluate(
			'[...document.querySelectorAll(".backupplus-nav-tab")].map(e=>e.id).indexOf("backupplus-navtab-decryption") - [...document.querySelectorAll(".backupplus-nav-tab")].map(e=>e.id).indexOf("backupplus-navtab-expert")',
		),
		1,
	);
	const fixture = async (name) =>
		JSON.parse(await readFile(new URL('./fixtures/' + name + '.json', import.meta.url), 'utf8'));
	const age = await fixture('age-keys'),
		legacy = (await fixture('phpseclib2-compat')).database[8];
	let index = 0;
	const sentinel = 'SEC003/+?&=' + randomBytes(10).toString('hex') + '!';
	const secrets = [
		sentinel,
		Buffer.from(legacy.key, 'base64').toString(),
		age.passphrase,
		...Object.values(age.keys).map((k) => k.identity),
		...Object.values(age.keys).map((k) => k.encrypted_pem),
	];
	function assertNoSecrets(content, label) {
		for (const secret of secrets)
			for (const encoded of [secret, encodeURIComponent(secret),
				new URLSearchParams({ key: secret }).toString().slice(4),
				JSON.stringify(secret).slice(1, -1)])
				assert(!content.includes(encoded), label + ' must omit decryption credentials');
	}
	async function checkPage() {
		assertNoSecrets(await evaluate(`JSON.stringify({
			text: document.body.innerText,
			markup: document.querySelector('.backupplus-decryption-tool').outerHTML,
			links: [...document.querySelectorAll('a')].map(a => a.href),
			fields: [...document.querySelectorAll('.backupplus-decryption-tool input, .backupplus-decryption-tool textarea')].map(e => e.value)
		})`), 'Page content, links and cleared fields');
		assertNoSecrets(JSON.stringify(await call('Page.getNavigationHistory')), 'Browser history');
	}
	async function selectPath(file) {
		const { root } = await call('DOM.getDocument');
		const { nodeId } = await call('DOM.querySelector', {
			nodeId: root.nodeId,
			selector: '#backupplus-decrypt-files',
		});
		await call('DOM.setFileInputFiles', { nodeId, files: [file] });
		await wait('!document.getElementById("backupplus-decrypt-start").disabled');
	}
	async function select(cipher) {
		const file = path.join(directory, 'input-' + ++index + '.crypt');
		await writeFile(file, cipher);
		await selectPath(file);
	}
	async function clear() {
		await evaluate('document.getElementById("backupplus-decrypt-clear").click()');
		await wait(
			'!document.querySelector("#backupplus-decrypt-results a") && !document.querySelector(".backupplus-decryption-tool").getAttribute("aria-busy").includes("true")',
		);
	}
	async function run(cipher, plain, creds, { failure = false, legacy = false } = {}) {
		await clear();
		await select(cipher);
		await evaluate(
			`document.getElementById('backupplus_db_decrypt').value=${JSON.stringify(creds.password || '')};document.getElementById('backupplus-manual-age-identity').value=${JSON.stringify(creds.identity || '')};document.getElementById('backupplus-manual-age-passphrase').value=${JSON.stringify(creds.keyPassword || '')};document.getElementById('backupplus-decrypt-start').click();`,
		);
		await wait(
			'document.getElementById("backupplus-decrypt-status").textContent && document.querySelector(".backupplus-decryption-tool").getAttribute("aria-busy") === "false"',
		);
		assert.equal(
			await evaluate(
				'["backupplus_db_decrypt","backupplus-manual-age-identity","backupplus-manual-age-passphrase"].every(id=>document.getElementById(id).value === "")',
			),
			true,
		);
		await checkPage();
		if (failure) {
			assert.equal(
				await evaluate('document.querySelectorAll("#backupplus-decrypt-results a").length'),
				0,
			);
			return;
		}
		const status = await evaluate(
			'document.getElementById("backupplus-decrypt-status").textContent',
		);
		assert.equal(
			await evaluate('document.querySelectorAll("#backupplus-decrypt-results a").length'),
			1,
			status,
		);
		const actual = await evaluate(
			`(async()=>{const a=document.querySelector('#backupplus-decrypt-results a');if(!a.href.startsWith('blob:'))throw Error('Download must be local');const b=await (await fetch(a.href)).arrayBuffer();const hash=await crypto.subtle.digest('SHA-256',b);return [...new Uint8Array(hash)].map(v=>v.toString(16).padStart(2,'0')).join('');})()`,
		);
		assert.equal(actual, createHash('sha256').update(plain).digest('hex'));
		assert.equal(await evaluate('!!document.querySelector(".backupplus-decrypt-legacy")'), legacy);
	}
	const from = (s) => Buffer.from(s, 'base64');
	const networkStart = requests.length;
	// A unique sentinel with URL metacharacters must also succeed, then download
	// through the real anchor. Only the disposable test creates legacy ciphertext.
	const sentinelPlain = Buffer.from('# Disposable SEC-003 synthetic backup\nSELECT 42;\n');
	const cipher = createCipheriv('aes-256-cbc', Buffer.from(sentinel), Buffer.alloc(16));
	await run(Buffer.concat([cipher.update(sentinelPlain), cipher.final()]), sentinelPlain,
		{ password: sentinel }, { legacy: true });
	const downloadName = await evaluate("document.querySelector('#backupplus-decrypt-results a').download");
	await evaluate("document.querySelector('#backupplus-decrypt-results a').click()", true);
	let downloaded;
	for (let attempt = 0; attempt < 100; attempt++) {
		// Chromium may add an extension inferred from the plaintext MIME type.
		const suggested = downloads[0]?.suggestedFilename;
		downloaded = suggested
			? await readFile(path.join(directory, 'downloads', suggested)).catch(() => null) : null;
		if (downloaded) break;
		await sleep(100);
	}
	assert(downloaded, 'The local download must finish: ' + JSON.stringify({
		downloads, files: existsSync(path.join(directory, 'downloads'))
			? readdirSync(path.join(directory, 'downloads')) : [],
	}));
	assert.deepEqual(downloaded, sentinelPlain, 'The real local download must contain exact plaintext');
	assert(downloads.length > 0 && downloads.every(d => d.url.startsWith('blob:')),
		'Plaintext downloads must use local Blob URLs');
	assert(downloads[0].suggestedFilename.startsWith(downloadName));
	assertNoSecrets(JSON.stringify(downloads), 'Download records');
	await run(
		from(legacy.ciphertext),
		from(legacy.plaintext),
		{ password: from(legacy.key).toString() },
		{ legacy: true },
	);
	for (const [type, key] of Object.entries(age.keys))
		for (const form of ['identity', 'encrypted_pem']) {
			await run(from(key.ciphertext), from(age.plaintext), {
				identity: key[form],
				keyPassword: age.passphrase,
			});
			console.log('PASS: actual browser worker ' + type + ' ' + form);
		}
	const corrupt = from(age.keys.native.ciphertext);
	corrupt[corrupt.length - 1] ^= 1;
	await run(corrupt, null, { identity: age.keys.native.identity }, { failure: true });
	await run(
		from(age.keys.native.ciphertext),
		null,
		{ identity: sentinel, keyPassword: sentinel },
		{ failure: true },
	);
	await clear();
	await select(from(age.keys.native.ciphertext));
	await evaluate(
		`document.getElementById('backupplus-manual-age-identity').value=${JSON.stringify(age.keys.native.identity)};document.getElementById('backupplus-decrypt-start').click();`,
	);
	await wait(
		'document.querySelector(".backupplus-decryption-tool").getAttribute("aria-busy") === "true"',
	);
	await evaluate('document.getElementById("backupplus-decrypt-cancel").click()');
	await wait(
		'document.getElementById("backupplus-decrypt-status").textContent === backupplus_decryption.cancelled',
	);
	assert.equal(
		await evaluate('document.querySelectorAll("#backupplus-decrypt-results a").length'),
		0,
	);
	await wait(
		`(async()=>{try{const dir=await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const e of dir.values())if(e.name.startsWith('job-'))return false;}catch{}return true;})()`,
	);
	// A later tag failure must discard plaintext already staged after earlier valid chunks.
	const largePlain = path.join(directory, 'large.zip'),
		largeCipher = largePlain + '.crypt';
	await writeFile(largePlain, Buffer.alloc(16 * 1024 * 1024, 97));
	const encrypted = spawnSync(
		'age',
		['-r', age.keys.native.recipient, '-o', largeCipher, largePlain],
		{ encoding: 'utf8' },
	);
	assert.equal(encrypted.status, 0, encrypted.stderr);
	const damaged = await readFile(largeCipher);
	damaged[damaged.length - 1] ^= 1;
	await run(damaged, null, { identity: age.keys.native.identity }, { failure: true });
	await clear();
	await selectPath(largeCipher);
	await evaluate(
		`document.getElementById('backupplus-manual-age-identity').value=${JSON.stringify(age.keys.native.identity)};document.getElementById('backupplus-decrypt-start').click();`,
	);
	await wait(
		'document.querySelector(".backupplus-decryption-tool").getAttribute("aria-busy") === "true" && document.getElementById("backupplus-decrypt-progress").value > 0',
	);
	await evaluate('document.getElementById("backupplus-decrypt-cancel").click()');
	await wait(
		'document.getElementById("backupplus-decrypt-status").textContent === backupplus_decryption.cancelled',
	);
	await wait(
		`(async()=>{const dir=await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const e of dir.values())if(e.name.startsWith('job-'))return false;return true;})()`,
	);
	console.log(
		'PASS: late authentication failure and cancellation during OPFS writes remove staged plaintext',
	);
	// Simulate a browser without X25519 Web Crypto or OPFS, using the exact shipping bundle.
	await evaluate(`(async()=>{
		window.savedDecryptWorker = backupplus_decryption.worker;
		const source = await (await fetch(backupplus_decryption.worker)).text();
		const harness = "Object.defineProperty(navigator.storage,'getDirectory',{value:async()=>{throw new DOMException('Disabled by test','SecurityError');}});const originalImport=crypto.subtle.importKey.bind(crypto.subtle);crypto.subtle.importKey=(format,key,alg,...args)=>{if(alg?.name==='X25519')return Promise.reject(new DOMException('Unavailable','NotSupportedError'));return originalImport(format,key,alg,...args);};";
		backupplus_decryption.worker=URL.createObjectURL(new Blob([harness,source],{type:'text/javascript'}));
	})()`);
	await run(from(age.keys.native.ciphertext), from(age.plaintext), {
		identity: age.keys.native.identity,
	});
	await run(from(age.keys.ed25519.ciphertext), from(age.plaintext), {
		identity: age.keys.ed25519.identity,
		keyPassword: age.passphrase,
	});
	await run(
		from(legacy.ciphertext),
		from(legacy.plaintext),
		{ password: from(legacy.key).toString() },
		{ legacy: true },
	);
	await clear();
	const oversized = path.join(directory, 'oversized.crypt');
	await writeFile(oversized, '');
	await truncate(oversized, 129 * 1024 * 1024);
	await selectPath(oversized);
	await evaluate('document.getElementById("backupplus-decrypt-start").click()');
	await wait(
		'document.getElementById("backupplus-decrypt-status").textContent === backupplus_decryption.errors.MEMORY_LIMIT',
	);
	assert.equal(
		await evaluate('document.querySelectorAll("#backupplus-decrypt-results a").length'),
		0,
	);
	await clear();
	await evaluate(
		'URL.revokeObjectURL(backupplus_decryption.worker);backupplus_decryption.worker=window.savedDecryptWorker;',
	);
	console.log(
		'PASS: actual browser X25519 fallback, small-file memory fallback, repeated runs/cleanup, and local large-file rejection',
	);
	for (const request of requests.slice(networkStart)) {
		const content = request.url + '\n' + (request.postData || '');
		assert(
			!/plupload_action2|backupplus_decrypt_database/.test(content),
			'The tab must not call server decryption',
		);
		assertNoSecrets(JSON.stringify(request), 'Requests, URLs and headers');
		assert(
			!/multipart\/form-data/.test(JSON.stringify(request.headers)),
			'No file upload request is allowed',
		);
	}
	assert.equal(exceptions.length, 0, 'No uncaught browser errors: ' + JSON.stringify(exceptions));
	await call('Emulation.setDeviceMetricsOverride', {
		width: 390,
		height: 844,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate(
			`['backupplus-manual-age-identity','backupplus-manual-age-passphrase','backupplus-decrypt-drop'].every(id=>{const r=document.getElementById(id).getBoundingClientRect();return r.x>=0&&r.right<=innerWidth;})`,
		),
		true,
	);
	await call('Emulation.setDeviceMetricsOverride', {
		width: 1440,
		height: 1100,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await writeFile(
		'/tmp/backupplus-client-decryption.png',
		Buffer.from(
			(await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data,
			'base64',
		),
	);
	console.log(
		'PASS: local Blob downloads, legacy notice, failure/cancellation cleanup, cleared keys, no archive/key upload, and mobile layout',
	);
	// Keep history across a real navigation and back, and inspect server-side logs.
	await checkPage();
	await navigate(origin + '/wp-admin/index.php');
	await evaluate('history.back()');
	await wait('location.search.includes("tab=decryption") && document.readyState === "complete"');
	await checkPage();
	assertNoSecrets(JSON.stringify(redirects), 'Redirect responses');
	const marker = 'backupplus_sec003_' + randomBytes(12).toString('hex');
	await evaluate(`fetch(${JSON.stringify(origin + '/wp-login.php?' + marker)}, {cache:'no-store'}).then(r=>r.text()).then(()=>true)`);
	assert(accessLog.some(r => r.url.includes(marker) && r.status === 200),
		'The proxy access log must contain the control request');
	assert(accessLog.some(r => r.url.includes('/js/backupplus-decryption-worker.js') && r.status === 200),
		'The proxy must also capture worker traffic');
	assertNoSecrets(JSON.stringify(accessLog), 'Proxy access logs including Referer and Location');
	assertNoSecrets(JSON.stringify(proxyRequests), 'Proxy-captured page and worker requests');
	for (const request of proxyRequests) {
		assert(!/plupload_action2|backupplus_decrypt_database/.test(request.url + request.body),
			'The page and worker must not call server decryption');
		assert(!/multipart\/form-data/.test(JSON.stringify(request.headers)),
			'The page and worker must not upload archives');
	}
	const logs = spawnSync(process.env.PODMAN || 'podman', [
		'logs', '--since', started, process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress',
	], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
	assert.equal(logs.status, 0, 'Local WordPress server logs must be readable');
	const serverLog = logs.stdout + logs.stderr;
	assert(serverLog.includes(marker), 'Server access logs must contain the control request');
	assertNoSecrets(serverLog, 'WordPress server access/error logs');
	console.log('PASS: SEC-003 sentinel download, page/errors/links, redirects, navigation history, proxy and WordPress access/error logs');
	await Promise.race([call('Browser.close'), sleep(1000)]);
} finally {
	if (ws) ws.close();
	browser.kill();
	await browserExited;
	proxy.closeAllConnections();
	await new Promise(resolve => proxy.close(resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
