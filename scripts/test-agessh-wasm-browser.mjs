import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:http';
import * as esbuild from 'esbuild-wasm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixture = JSON.parse(
	await readFile(path.join(root, 'scripts/fixtures/age-keys.json'), 'utf8'),
);
const chromeCandidates = [
	process.env.CHROMIUM,
	...(process.env.PATH || '')
		.split(path.delimiter)
		.flatMap((directory) =>
			['chromium', 'chromium-browser', 'google-chrome', 'chrome-headless-shell'].map((name) =>
				path.join(directory, name),
			),
		),
	'/usr/bin/google-chrome',
	'/usr/bin/chromium',
].filter(Boolean);
const chrome = chromeCandidates.find(existsSync);
assert(chrome, 'Chromium is required; set CHROMIUM to its executable.');

const workerSource = `
import { Decrypter } from 'age-encryption';
import { createAgesshBridge } from './assets-src/decryption/agessh-bridge.mjs';

const decode = value => Uint8Array.from(atob(value), c => c.charCodeAt(0));
const equal = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
self.onmessage = async ({ data }) => {
  let bridge;
  try {
    const bridgeStart = performance.now();
    bridge = await createAgesshBridge({
      wasmURL: new URL('backupplus-agessh.wasm', self.location.href),
      runtimeURL: new URL('backupplus-agessh-wasm_exec.js', self.location.href),
    });
    const startupMs = performance.now() - bridgeStart;
    const results = [];
    for (const type of ['rsa', 'ed25519']) {
      const key = data.keys[type];
      const openStart = performance.now();
      const handle = bridge.open(key.identity, data.passphrase);
      const openMs = performance.now() - openStart;
      try {
        const decrypter = new Decrypter();
        decrypter.addIdentity(bridge.identity(handle));
        const decryptStart = performance.now();
        const stream = await decrypter.decrypt(new Blob([decode(key.ciphertext)]).stream());
        const actual = new Uint8Array(await new Response(stream).arrayBuffer());
        results.push({ type, matched: equal(actual, decode(data.plaintext)), openMs, decryptMs: performance.now() - decryptStart });
      } finally {
        bridge.release(handle);
      }
    }
    await bridge.close();
    self.postMessage({ ok: results.every(result => result.matched), startupMs, results });
  } catch (error) {
    if (bridge) await bridge.close();
    self.postMessage({ ok: false, error: error.message });
  }
};
`;
const bundle = await esbuild.build({
	stdin: { contents: workerSource, resolveDir: root, sourcefile: 'agessh-prototype-worker.mjs' },
	bundle: true,
	write: false,
	platform: 'browser',
	format: 'iife',
	target: ['es2022'],
	minify: true,
});
const workerJS = bundle.outputFiles[0].contents;
const runtime = await readFile(path.join(root, 'js/backupplus-agessh-wasm_exec.js'));
const wasm = await readFile(path.join(root, 'js/backupplus-agessh.wasm'));
const fixtureJSON = Buffer.from(JSON.stringify(fixture));
const served = new Map();
const server = createServer((request, response) => {
	const pathname = new URL(request.url, 'http://localhost').pathname;
	const routes = new Map([
		['/worker.js', [workerJS, 'text/javascript']],
		['/backupplus-agessh-wasm_exec.js', [runtime, 'text/javascript']],
		['/backupplus-agessh.wasm', [wasm, 'application/wasm']],
		['/fixture.json', [fixtureJSON, 'application/json']],
		[
			'/test.html',
			[
				`<!doctype html><meta charset="utf-8"><script>
window.browserResult = null;
const worker = new Worker('/worker.js');
worker.onmessage = ({data}) => { window.browserResult = data; worker.terminate(); };
worker.onerror = (event) => { window.browserResult = {ok:false,error:event.message}; worker.terminate(); };
fetch('/fixture.json').then(response => response.json()).then(fixture => worker.postMessage(fixture));
</script>`,
				'text/html; charset=utf-8',
			],
		],
	]);
	const entry = routes.get(pathname);
	if (!entry) {
		response.writeHead(404).end();
		return;
	}
	served.set(pathname, (served.get(pathname) || 0) + 1);
	response.writeHead(200, { 'Content-Type': entry[1], 'Cache-Control': 'no-store' });
	response.end(entry[0]);
});
await new Promise((resolve, reject) => {
	server.once('error', reject);
	server.listen(0, '127.0.0.1', resolve);
});

const profile = await mkdtemp(path.join(tmpdir(), 'backupplus-agessh-browser-'));
const browser = spawn(
	chrome,
	[
		'--headless=new',
		'--disable-gpu',
		'--no-sandbox',
		'--disable-dev-shm-usage',
		'--disable-background-networking',
		'--no-first-run',
		'--remote-debugging-port=0',
		'--user-data-dir=' + profile,
		'about:blank',
	],
	{ stdio: ['ignore', 'ignore', 'pipe'] },
);
let browserError = '';
const browserExited = new Promise((resolve) => browser.once('close', resolve));
browser.stderr.on('data', (chunk) => (browserError += chunk));
const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
let socket;
try {
	let debuggingPort;
	for (let i = 0; i < 150; i++) {
		try {
			debuggingPort = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split(
				'\n',
			)[0];
			break;
		} catch {
			await sleep(100);
		}
	}
	assert(debuggingPort, `Chromium failed to start: ${browserError}`);
	const pages = await fetch(`http://127.0.0.1:${debuggingPort}/json/list`).then((response) =>
		response.json(),
	);
	socket = new WebSocket(pages.find((page) => page.type === 'page').webSocketDebuggerUrl);
	await new Promise((resolve, reject) => {
		socket.addEventListener('open', resolve, { once: true });
		socket.addEventListener('error', reject, { once: true });
	});
	let sequence = 0;
	const pending = new Map();
	socket.addEventListener('message', ({ data }) => {
		const message = JSON.parse(data);
		if (!message.id) return;
		const operation = pending.get(message.id);
		if (!operation) return;
		pending.delete(message.id);
		message.error
			? operation.reject(new Error(JSON.stringify(message.error)))
			: operation.resolve(message.result);
	});
	const call = (method, params = {}) =>
		new Promise((resolve, reject) => {
			const id = ++sequence;
			pending.set(id, { resolve, reject });
			socket.send(JSON.stringify({ id, method, params }));
		});
	const evaluate = async (expression) => {
		const result = await call('Runtime.evaluate', {
			expression,
			awaitPromise: true,
			returnByValue: true,
		});
		if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
		return result.result.value;
	};
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: `http://127.0.0.1:${server.address().port}/test.html` });
	let result;
	for (let i = 0; i < 600; i++) {
		result = await evaluate('window.browserResult');
		if (result) break;
		await sleep(100);
	}
	assert(result, 'Browser agessh worker timed out.');
	assert.equal(result.ok, true, JSON.stringify(result));
	for (const asset of [
		'/worker.js',
		'/fixture.json',
		'/backupplus-agessh-wasm_exec.js',
		'/backupplus-agessh.wasm',
	])
		assert(served.has(asset), `Browser did not load ${asset}`);
	console.log(
		'PASS: Chromium worker loaded Go runtime/WASM and decrypted RSA + Ed25519 with upstream age streams',
	);
	console.log(`Browser worker startup: ${result.startupMs.toFixed(1)} ms`);
	for (const measurement of result.results)
		console.log(
			`${measurement.type}: key open ${measurement.openMs.toFixed(1)} ms, decrypt ${measurement.decryptMs.toFixed(1)} ms`,
		);
} finally {
	socket?.close();
	browser.kill('SIGTERM');
	await Promise.race([browserExited, sleep(5000)]);
	await new Promise((resolve) => server.close(resolve));
	await rm(profile, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
	esbuild.stop();
}
