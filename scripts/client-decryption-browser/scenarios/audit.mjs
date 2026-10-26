/** Request, layout and log audits of the client decryption browser checks. */
import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

/** No decryption request or upload reached the server; the tab fits a narrow screen. */
export async function auditRequestsAndLayout(ctx) {
	const { requests, exceptions, call, evaluate, assertNoSecrets, networkStart } = ctx;
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
}

/** The proxy and WordPress logs contain no decryption credentials. */
export async function auditLogs(ctx) {
	const { origin, started, accessLog, proxyRequests, redirects, evaluate, assertNoSecrets } = ctx;
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
}
