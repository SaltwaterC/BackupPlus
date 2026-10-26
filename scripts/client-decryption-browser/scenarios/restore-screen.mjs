/** Restore screen scenario of the client decryption browser checks. */
import assert from 'node:assert/strict';

/** Decrypt locally on the restore screen, with chunked upload, wrong keys and cancellation. */
export async function restoreScreen(ctx) {
	const { origin, proxyRequests, keygenToken, restoreFixture, call, evaluate, wait, waitForNoStagedFiles, navigate, age, assertNoSecrets, checkPage } = ctx;
	// Keep history across a real navigation and back, and inspect server-side logs.
	await checkPage();
	await navigate(origin + '/wp-admin/index.php');
	await evaluate('history.back()');
	await wait('location.search.includes("tab=decryption") && document.readyState === "complete"');
	await checkPage();
	// Prepare an isolated recovery point through the actual restore screen and worker.
	ctx.restoreIsolated = true;
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
	ctx.restoreIsolated = false;
	console.log(
		'PASS: restore screen decrypts locally, rejects wrong keys without upload, transfers exact age plaintext in chunks, cleans cancelled transfers and browser staging and keeps keys out of HTTP',
	);
}
