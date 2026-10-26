/** Settings screen scenario of the client decryption browser checks. */
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

/** Generate an age key through the settings UI and save only its public recipient. */
export async function keygenSettingsScreen(ctx) {
	const { origin, directory, sleep, keygenToken, keygenSettings, requests, call, evaluate, wait, navigate, secrets, checkPage } = ctx;
	// Generate and save through the real settings UI, using disposable server options.
	keygenSettings('setup');
	ctx.keygenIsolated = true;
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
}
