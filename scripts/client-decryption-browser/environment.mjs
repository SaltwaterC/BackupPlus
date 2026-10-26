/** Test environment of the client decryption browser checks: Chromium discovery, the recording proxy, the browser process and the WordPress fixture commands. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createServer, request as httpRequest } from 'node:http';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';

/** Starts the proxy and Chromium and returns the shared test context. */
export async function startEnvironment() {
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
	const keygenToken = randomBytes(12).toString('hex');
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
	return {
		origin,
		started,
		accessLog,
		proxyRequests,
		proxy,
		directory,
		browser,
		browserExited,
		sleep,
		keygenToken,
		restoreFixture,
		keygenSettings,
		keygenIsolated: false,
		restoreIsolated: false,
		get browserError() {
			return browserError;
		},
	};
}
