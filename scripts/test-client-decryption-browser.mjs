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
	req.on('data', (chunk) => body.push(chunk));
	req.on('end', () =>
		proxyRequests.push({
			url: url.href,
			headers: req.headers,
			body: Buffer.concat(body).toString(),
		}),
	);
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
const keygenToken = randomBytes(12).toString('hex');
let keygenIsolated = false;
let restoreIsolated = false;
function restoreFixture(operation, ticket) {
	const result = spawnSync(
		process.env.PODMAN || 'podman',
		[
			'exec',
			process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress',
			'wp',
			'--skip-plugins=backupplus',
			'eval-file',
			'wp-content/plugins/backupplus/scripts/fixtures/restore-browser.php',
			keygenToken,
			operation,
			...(ticket ? [ticket] : []),
		],
		{ encoding: 'utf8' },
	);
	assert.equal(result.status, 0, 'Restore fixture must succeed: ' + result.stderr);
	return ['setup', 'verify', 'cancelled'].includes(operation) ? JSON.parse(result.stdout) : null;
}
function keygenSettings(operation) {
	const result = spawnSync(
		process.env.PODMAN || 'podman',
		[
			'exec',
			process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress',
			'wp',
			'--skip-plugins=backupplus',
			'eval-file',
			'wp-content/plugins/backupplus/scripts/fixtures/age-keygen-settings.php',
			keygenToken,
			operation,
		],
		{ encoding: 'utf8' },
	);
	assert.equal(result.status, 0, 'Settings test isolation must succeed: ' + result.stderr);
	return ['read', 'snapshot'].includes(operation) ? JSON.parse(result.stdout) : null;
}
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
		'Backup passphrase (UpdraftPlus legacy passphrase-encrypted archives or age -p archives)',
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
	const ageVector = async (name) => {
		const raw = await readFile(new URL('./fixtures/age-testkit/testdata/' + name, import.meta.url));
		const separator = raw.indexOf(Buffer.from('\n\n'));
		assert(separator >= 0, 'Invalid age test vector: ' + name);
		const metadata = {};
		for (const line of raw.subarray(0, separator).toString('utf8').split('\n')) {
			const index = line.indexOf(': ');
			assert(index >= 0, 'Invalid age test vector metadata: ' + name);
			const key = line.slice(0, index);
			(metadata[key] ??= []).push(line.slice(index + 2));
		}
		return { metadata, ciphertext: raw.subarray(separator + 2) };
	};
	const age = await fixture('age-keys'),
		legacy = (await fixture('phpseclib2-compat')).database[8],
		scryptVector = await ageVector('scrypt'),
		hybridVector = await ageVector('hybrid');
	let index = 0;
	const sentinel = 'SEC003/+?&=' + randomBytes(10).toString('hex') + '!';
	const secrets = [
		sentinel,
		Buffer.from(legacy.key, 'base64').toString(),
		age.passphrase,
		...hybridVector.metadata.identity,
		...Object.values(age.keys).map((k) => k.identity),
		...Object.values(age.keys).map((k) => k.encrypted_pem),
		...Object.values(age.keys).flatMap((k) => k.encrypted_openssh_gcm ? [k.encrypted_openssh_gcm] : []),
	];
	function assertNoSecrets(content, label) {
		for (const secret of secrets)
			for (const encoded of [
				secret,
				encodeURIComponent(secret),
				new URLSearchParams({ key: secret }).toString().slice(4),
				JSON.stringify(secret).slice(1, -1),
			])
				assert(!content.includes(encoded), label + ' must omit decryption credentials');
	}
	async function checkPage() {
		assertNoSecrets(
			await evaluate(`JSON.stringify({
			text: document.body.innerText,
			markup: document.querySelector('.backupplus-decryption-tool').outerHTML,
			links: [...document.querySelectorAll('a')].map(a => a.href),
			fields: [...document.querySelectorAll('.backupplus-decryption-tool input, .backupplus-decryption-tool textarea')].map(e => e.value)
		})`),
			'Page content, links and cleared fields',
		);
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
		assert.equal(actual, typeof plain === 'string' ? plain : createHash('sha256').update(plain).digest('hex'));
		assert.equal(await evaluate('!!document.querySelector(".backupplus-decrypt-legacy")'), legacy);
	}
	const from = (s) => Buffer.from(s, 'base64');
	const networkStart = requests.length;
	// A unique sentinel with URL metacharacters must also succeed, then download
	// through the real anchor. Only the disposable test creates legacy ciphertext.
	const sentinelPlain = Buffer.from('# Disposable SEC-003 synthetic backup\nSELECT 42;\n');
	const cipher = createCipheriv('aes-256-cbc', Buffer.from(sentinel), Buffer.alloc(16));
	await run(
		Buffer.concat([cipher.update(sentinelPlain), cipher.final()]),
		sentinelPlain,
		{ password: sentinel },
		{ legacy: true },
	);
	const downloadName = await evaluate(
		"document.querySelector('#backupplus-decrypt-results a').download",
	);
	await evaluate("document.querySelector('#backupplus-decrypt-results a').click()", true);
	let downloaded;
	for (let attempt = 0; attempt < 100; attempt++) {
		// Chromium may add an extension inferred from the plaintext MIME type.
		const suggested = downloads[0]?.suggestedFilename;
		downloaded = suggested
			? await readFile(path.join(directory, 'downloads', suggested)).catch(() => null)
			: null;
		if (downloaded) break;
		await sleep(100);
	}
	assert(
		downloaded,
		'The local download must finish: ' +
			JSON.stringify({
				downloads,
				files: existsSync(path.join(directory, 'downloads'))
					? readdirSync(path.join(directory, 'downloads'))
					: [],
			}),
	);
	assert.deepEqual(
		downloaded,
		sentinelPlain,
		'The real local download must contain exact plaintext',
	);
	assert(
		downloads.length > 0 && downloads.every((d) => d.url.startsWith('blob:')),
		'Plaintext downloads must use local Blob URLs',
	);
	assert(downloads[0].suggestedFilename.startsWith(downloadName));
	assertNoSecrets(JSON.stringify(downloads), 'Download records');
	await run(
		from(legacy.ciphertext),
		from(legacy.plaintext),
		{ password: from(legacy.key).toString() },
		{ legacy: true },
	);
	for (const [type, key] of Object.entries(age.keys))
		for (const form of ['identity', 'encrypted_pem', ...(key.encrypted_openssh_gcm ? ['encrypted_openssh_gcm'] : [])]) {
			await run(from(key.ciphertext), from(age.plaintext), {
				identity: key[form],
				keyPassword: age.passphrase,
			});
			console.log('PASS: actual browser worker ' + type + ' ' + form);
		}
	await run(
		scryptVector.ciphertext,
		scryptVector.metadata.payload[0],
		{ password: scryptVector.metadata.passphrase[0] },
	);
	console.log('PASS: actual browser worker age -p scrypt archive');
	await run(
		hybridVector.ciphertext,
		hybridVector.metadata.payload[0],
		{ identity: hybridVector.metadata.identity[0] },
	);
	console.log('PASS: actual browser worker hybrid ML-KEM-768/X25519 archive');
	assert(
		!JSON.stringify(proxyRequests.slice(networkStart)).includes(scryptVector.metadata.passphrase[0]),
		'The age -p passphrase must never be sent in a request',
	);
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
	// Fail a real OPFS write after a valid chunk has already been staged.
	await evaluate(`(async()=>{
		window.productionWorkerURL = backupplus_decryption.worker;
		window.productionWorkerSource = await (await fetch(backupplus_decryption.worker)).text();
		const harness = "const create=FileSystemFileHandle.prototype.createWritable;FileSystemFileHandle.prototype.createWritable=async function(...args){const output=await create.apply(this,args);const write=output.write.bind(output);let count=0;output.write=async bytes=>{if(++count===2)throw new DOMException('Test quota exhausted','QuotaExceededError');return write(bytes);};return output;};";
		backupplus_decryption.worker=URL.createObjectURL(new Blob([harness,window.productionWorkerSource],{type:'text/javascript'}));
	})()`);
	await run(
		await readFile(largeCipher),
		null,
		{ identity: age.keys.native.identity },
		{ failure: true },
	);
	assert.equal(
		await evaluate('document.getElementById("backupplus-decrypt-status").textContent'),
		await evaluate('backupplus_decryption.errors.STORAGE_FAILED'),
	);
	await wait(
		`(async()=>{const dir=await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const e of dir.values())if(e.name.startsWith('job-'))return false;return true;})()`,
	);
	await evaluate(
		'URL.revokeObjectURL(backupplus_decryption.worker);backupplus_decryption.worker=window.productionWorkerURL;',
	);

	// Signal immediately before the actual Go key-unlock call, then cancel while
	// a deliberately slower, independently re-encrypted OpenSSH key is unlocking.
	const slowKeyPath = path.join(directory, 'slow-rsa-key');
	await writeFile(slowKeyPath, age.keys.rsa.identity, { mode: 0o600 });
	const slowKeygen = spawnSync(
		'ssh-keygen',
		['-p', '-q', '-a', '128', '-P', age.passphrase, '-N', age.passphrase, '-f', slowKeyPath],
		{ encoding: 'utf8' },
	);
	assert.equal(slowKeygen.status, 0, slowKeygen.stderr);
	const slowIdentity = await readFile(slowKeyPath, 'utf8');
	secrets.push(slowIdentity);
	await evaluate(`(()=>{
		const harness = "let testJob,apiValue;self.addEventListener('message',e=>{testJob=e.data.id;});Object.defineProperty(globalThis,'BackupPlusAgeSSH',{configurable:true,get(){return apiValue;},set(api){apiValue=api;const open=api.open;api.open=(...args)=>{self.postMessage({id:testJob,type:'progress',fraction:0.000001});return open(...args);};}});";
		backupplus_decryption.worker=URL.createObjectURL(new Blob([harness,window.productionWorkerSource],{type:'text/javascript'}));
	})()`);
	await clear();
	await select(from(age.keys.rsa.ciphertext));
	await evaluate(
		`document.getElementById('backupplus-manual-age-identity').value=${JSON.stringify(slowIdentity)};document.getElementById('backupplus-manual-age-passphrase').value=${JSON.stringify(age.passphrase)};document.getElementById('backupplus-decrypt-start').click();`,
	);
	await wait(
		'document.querySelector(".backupplus-decryption-tool").getAttribute("aria-busy") === "true" && document.getElementById("backupplus-decrypt-progress").value > 0',
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
		`(async()=>{const dir=await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');for await(const e of dir.values())if(e.name.startsWith('job-'))return false;return true;})()`,
	);
	await evaluate(
		'URL.revokeObjectURL(backupplus_decryption.worker);backupplus_decryption.worker=window.productionWorkerURL;',
	);
	await run(from(age.keys.rsa.ciphertext), from(age.plaintext), {
		identity: age.keys.rsa.identity,
		keyPassword: age.passphrase,
	});
	assert(
		proxyRequests.some((request) => request.url.includes('/backupplus-agessh.wasm.gz')),
		'The production worker must fetch the precompressed module',
	);
	console.log(
		'PASS: OPFS quota failure cleanup, cancellation during Go key unlocking, retry and compressed WASM loading',
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
	// Prepare an isolated recovery point through the actual restore screen and worker.
	restoreIsolated = true;
	const restorePoint = restoreFixture('setup');
	await call('Network.setExtraHTTPHeaders', {
		headers: { 'X-BackupPlus-Restore-Test': keygenToken },
	});
	await navigate(origin + '/wp-admin/options-general.php?page=backupplus');
	await wait('!!window.backupplus_prepare_restore');
	await evaluate(
		`document.querySelector('button.choose-components-button[data-backup_timestamp="${restorePoint.timestamp}"]').click();document.getElementById('backupplus_restore_uploads').checked=true;`,
	);
	assert.equal(
		await evaluate(
			"['backupplus-age-identity','backupplus-age-key-passphrase'].some(id=>document.getElementById(id).hasAttribute('name'))",
		),
		false,
		'Restore secrets must never be successful form controls',
	);
	assert.equal(
		await evaluate("!!document.getElementById('backupplus-restore-passphrase')"),
		false,
		'Restore must not offer a legacy backup passphrase field',
	);
	assert.equal(
		await evaluate("!!document.getElementById('backupplus-age-archive-passphrase')"),
		false,
		'Restore must not offer an age -p archive passphrase field',
	);
	const transferStart = proxyRequests.length;
	await evaluate(
		`document.getElementById('backupplus-age-identity').value=${JSON.stringify(age.keys.ed25519.identity)};document.querySelector('.backupplus-restore--next-step').click();`,
	);
	await wait(
		'!window.backupplus_restore_preparing && document.getElementById("backupplus-restore-modal-stage2a").textContent === backupplus_decryption.errors.DECRYPT_FAILED',
	);
	assert.equal(
		await evaluate("jQuery('#backupplus-age-identity').is(':visible')"),
		true,
		'A decryption failure must show the key controls for retry',
	);
	assert.equal(
		proxyRequests.slice(transferStart).filter((r) => r.body.includes('operation=upload')).length,
		0,
		'Failed authentication must not upload partial plaintext',
	);
	await waitForNoStagedFiles();
	assert.equal(await evaluate("document.getElementById('backupplus-age-identity').value"), '');
	// Cancel after the first plaintext chunk reaches the server, before completion.
	await evaluate(
		`window.restoreOriginalFetch=window.fetch;window.fetch=async function(url, options){if(options && options.body instanceof URLSearchParams && options.body.get('operation')==='upload' && Number(options.body.get('offset'))>0){window.heldRestoreTicket=options.body.get('ticket');return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(new DOMException('Cancelled','AbortError')),{once:true}));}return window.restoreOriginalFetch(url,options);};document.getElementById('backupplus-age-identity').value=${JSON.stringify(age.keys.native.identity)};document.querySelector('.backupplus-restore--next-step').click();`,
	);
	await wait('!!window.heldRestoreTicket');
	const cancelledTicket = await evaluate('window.heldRestoreTicket');
	await evaluate("document.querySelector('.backupplus-restore--cancel').click()");
	await wait('!window.backupplus_restore_preparing');
	assert.deepEqual(
		restoreFixture('cancelled', cancelledTicket),
		{ exists: false, files: [] },
		'Cancellation must delete partially uploaded plaintext and its ticket',
	);
	await waitForNoStagedFiles();
	await evaluate(
		`window.fetch=window.restoreOriginalFetch;document.querySelector('button.choose-components-button[data-backup_timestamp="${restorePoint.timestamp}"]').click();document.getElementById('backupplus_restore_uploads').checked=true;`,
	);
	await evaluate(
		`document.getElementById('backupplus-age-identity').value=${JSON.stringify(age.keys.ed25519.encrypted_openssh_gcm)};document.getElementById('backupplus-age-key-passphrase').value=${JSON.stringify(age.passphrase)};document.querySelector('.backupplus-restore--next-step').click();`,
	);
	await wait('backupplus_restore_stage === 3');
	const restoreTicket = await evaluate("document.getElementById('backupplus-age-ticket').value");
	assert.match(restoreTicket, /^[a-f0-9]{64}$/);
	assert.equal(
		restoreFixture('verify', restoreTicket)[restorePoint.file],
		restorePoint.hash,
		'Server staging must contain the exact browser-decrypted archive',
	);
	const uploadRequests = proxyRequests
		.slice(transferStart)
		.filter(
			(r) =>
				r.body.includes('operation=upload') &&
				new URLSearchParams(r.body).get('ticket') === restoreTicket,
		);
	assert(uploadRequests.length >= 3, 'Large restore copies must upload in bounded chunks');
	assert.deepEqual(
		uploadRequests.map((r) => Number(new URLSearchParams(r.body).get('offset'))),
		[0, 524288, 1048576],
	);
	await waitForNoStagedFiles();
	assertNoSecrets(
		JSON.stringify(proxyRequests.slice(transferStart)),
		'Restore transfer and preflight requests',
	);
	assert.equal(
		await evaluate(
			"['backupplus-age-identity','backupplus-age-key-passphrase'].every(id=>document.getElementById(id).value==='')",
		),
		true,
	);
	await evaluate("document.querySelector('.backupplus-restore--cancel').click()");
	restoreFixture('cleanup');
	restoreIsolated = false;
	console.log(
		'PASS: restore screen decrypts locally, rejects wrong keys without upload, transfers exact age plaintext in chunks, cleans cancelled transfers and browser staging and keeps keys out of HTTP',
	);
	// Generate and save through the real settings UI, using disposable server options.
	keygenSettings('setup');
	keygenIsolated = true;
	await call('Network.setExtraHTTPHeaders', {
		headers: { 'X-BackupPlus-Keygen-Test': keygenToken },
	});
	await navigate(origin + '/wp-admin/options-general.php?page=backupplus&tab=settings');
	await wait(
		'document.getElementById("backupplus-age-generate") && !document.getElementById("backupplus-age-generate").disabled',
	);
	const originalSettings = keygenSettings('read');
	const originalOptionRows = keygenSettings('snapshot');
	const formWasDirty = await evaluate('backupplus_settings_form_changed');
	assert.equal(originalSettings.mode, 'none');
	const generatedNetworkStart = requests.length;
	await evaluate('document.getElementById("backupplus-age-generate").click()', true);
	await wait('document.getElementById("backupplus-age-key-dialog").open');
	assert.equal(await evaluate('document.getElementById("backupplus-age-key-dialog").open'), true);
	assert.equal(
		await evaluate(
			'document.getElementById("backupplus-age-key-add").disabled && document.getElementById("backupplus-age-key-saved").disabled',
		),
		true,
	);
	const generatedRecipient = await evaluate(
		'document.getElementById("backupplus-age-key-public").textContent',
	);
	assert.match(generatedRecipient, /^age1[0-9a-z]{58}$/);
	await call('Emulation.setDeviceMetricsOverride', {
		width: 390,
		height: 844,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate(
			"(()=>{const r=document.getElementById('backupplus-age-key-dialog').getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.top>=0 && r.bottom<=innerHeight;})()",
		),
		true,
		'The private-key prompt must fit a narrow screen',
	);
	await call('Emulation.setDeviceMetricsOverride', {
		width: 1440,
		height: 1100,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await writeFile(
		'/tmp/backupplus-age-keygen-dialog.png',
		Buffer.from(
			(await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })).data,
			'base64',
		),
	);

	assert.equal(await evaluate('document.getElementById("backupplus-age-recipients").value'), '');
	assert.deepEqual(
		keygenSettings('read'),
		originalSettings,
		'Generation must not write server settings',
	);
	const privateDownloadName = await evaluate(
		'document.getElementById("backupplus-age-key-download").download',
	);
	await evaluate('document.getElementById("backupplus-age-key-download").click()', true);
	let generatedFile;
	for (let i = 0; i < 100; i++) {
		generatedFile = await readFile(
			path.join(directory, 'downloads', privateDownloadName),
			'utf8',
		).catch(() => null);
		if (generatedFile) break;
		await sleep(100);
	}
	assert(generatedFile, 'The private key download must finish before adding its public recipient');
	const generatedIdentity = generatedFile.match(/AGE-SECRET-KEY-1[0-9A-Z]{58}/)?.[0];
	assert(generatedIdentity);
	secrets.push(generatedIdentity, generatedFile);
	const generatedPath = path.join(directory, 'downloads', privateDownloadName);
	const publicCheck = spawnSync('age-keygen', ['-y', generatedPath], { encoding: 'utf8' });
	assert.equal(publicCheck.status, 0);
	assert.equal(publicCheck.stdout.trim(), generatedRecipient);
	await evaluate('document.getElementById("backupplus-age-key-saved").click()', true);
	assert.equal(
		await evaluate('backupplus_settings_form_changed'),
		formWasDirty,
		'Local download confirmation must not change settings',
	);
	await evaluate('document.getElementById("backupplus-age-key-add").click()', true);
	assert.equal(await evaluate('document.getElementById("backupplus-age-key-dialog").open'), false);
	assert.equal(
		await evaluate('document.getElementById("backupplus-age-recipients").value'),
		generatedRecipient,
	);
	assert.equal(
		await evaluate('document.getElementById("backupplus-database-encryption").value'),
		'age',
	);
	assert.equal(
		await evaluate('document.getElementById("backupplus-age-key-download").hasAttribute("href")'),
		false,
	);
	assert.deepEqual(
		keygenSettings('read'),
		originalSettings,
		'Adding a public recipient must wait for Save Changes',
	);
	assert(
		!requests.slice(generatedNetworkStart).some((r) => r.method === 'POST'),
		'Generation and adding recipients must not send HTTP requests',
	);
	await checkPage();
	await evaluate('document.getElementById("backupplus-settings-save").click()', true);
	await wait('!document.querySelector(".blockUI.blockOverlay")');
	const savedSettings = keygenSettings('read');
	const currentOptionRows = keygenSettings('snapshot');
	const originalOptionValues = new Map(originalOptionRows.map((row) => [row.option_name, row.value_hash]));
	const currentOptionValues = new Map(currentOptionRows.map((row) => [row.option_name, row.value_hash]));
	const optionNames = new Set([...originalOptionValues.keys(), ...currentOptionValues.keys()]);
	const changedOptions = [...optionNames].filter(
		(name) => originalOptionValues.get(name) !== currentOptionValues.get(name),
	);
	assert.deepEqual(changedOptions, [], 'The Save Changes test must not alter real WordPress settings');
	assert.equal(savedSettings.mode, 'age');
	assert.deepEqual(savedSettings.recipients, [generatedRecipient]);
	assert(
		!JSON.stringify(savedSettings).includes('SECRET-KEY'),
		'Only the public recipient may be saved',
	);
	const saveRequests = requests
		.slice(generatedNetworkStart)
		.filter((r) => (r.postData || '').includes('backupplus_savesettings'));
	assert.equal(saveRequests.length, 1, 'Save Changes must use the existing settings endpoint');
	const formSettings = new URLSearchParams(
		new URLSearchParams(saveRequests[0].postData).get('settings'),
	);
	assert.equal(formSettings.get('backupplus_database_encryption[sources]'), generatedRecipient);
	assert.equal(formSettings.get('backupplus_database_encryption[mode]'), 'age');
	assert(
		!formSettings.has('undefined'),
		'Local confirmation controls must not enter form settings',
	);
	await wait('!document.querySelector(".blockUI.blockOverlay")');
	await navigate(origin + '/wp-admin/options-general.php?page=backupplus&tab=settings');
	assert.equal(
		await evaluate('document.getElementById("backupplus-age-recipients").value'),
		generatedRecipient,
	);
	// Cancelling a second key leaves the saved recipient untouched and clears its local download.
	await evaluate('document.getElementById("backupplus-age-generate").click()', true);
	await wait('document.getElementById("backupplus-age-key-dialog").open');
	await evaluate('document.getElementById("backupplus-age-key-cancel").click()', true);
	assert.equal(
		await evaluate('document.getElementById("backupplus-age-recipients").value'),
		generatedRecipient,
	);
	assert.equal(
		await evaluate('document.getElementById("backupplus-age-key-download").hasAttribute("href")'),
		false,
	);
	await checkPage();
	console.log(
		'PASS: local age generation, private-key download/confirmation, no early writes, public-only Save Changes, reload and cancellation',
	);
	assertNoSecrets(JSON.stringify(redirects), 'Redirect responses');
	const marker = 'backupplus_sec003_' + randomBytes(12).toString('hex');
	await evaluate(
		`fetch(${JSON.stringify(origin + '/wp-login.php?' + marker)}, {cache:'no-store'}).then(r=>r.text()).then(()=>true)`,
	);
	assert(
		accessLog.some((r) => r.url.includes(marker) && r.status === 200),
		'The proxy access log must contain the control request',
	);
	assert(
		accessLog.some(
			(r) => r.url.includes('/js/backupplus-decryption-worker.js') && r.status === 200,
		),
		'The proxy must also capture worker traffic',
	);
	assertNoSecrets(JSON.stringify(accessLog), 'Proxy access logs including Referer and Location');
	assertNoSecrets(JSON.stringify(proxyRequests), 'Proxy-captured page and worker requests');
	for (const request of proxyRequests) {
		assert(
			!/plupload_action2|backupplus_decrypt_database/.test(request.url + request.body),
			'The page and worker must not call server decryption',
		);
		assert(
			!/multipart\/form-data/.test(JSON.stringify(request.headers)),
			'The page and worker must not upload archives',
		);
	}
	const logs = spawnSync(
		process.env.PODMAN || 'podman',
		['logs', '--since', started, process.env.WP_TEST_CONTAINER || 'backupplus-dev-wordpress'],
		{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 },
	);
	assert.equal(logs.status, 0, 'Local WordPress server logs must be readable');
	const serverLog = logs.stdout + logs.stderr;
	assert(serverLog.includes(marker), 'Server access logs must contain the control request');
	assertNoSecrets(serverLog, 'WordPress server access/error logs');
	console.log(
		'PASS: SEC-003 sentinel download, page/errors/links, redirects, navigation history, proxy and WordPress access/error logs',
	);
	await Promise.race([call('Browser.close'), sleep(1000)]);
} finally {
	if (restoreIsolated) restoreFixture('cleanup');
	if (keygenIsolated) keygenSettings('cleanup');
	if (ws) ws.close();
	browser.kill();
	await browserExited;
	proxy.closeAllConnections();
	await new Promise((resolve) => proxy.close(resolve));
	await rm(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
