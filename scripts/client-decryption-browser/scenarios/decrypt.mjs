/** Decryption scenarios of the client decryption browser checks: tab markup, legacy and age archives, cancellation and storage failures, and the fallbacks without X25519 or OPFS. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { readFile, writeFile, truncate } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { createCipheriv } from 'node:crypto';
import path from 'node:path';

/** Static checks of the Decryption tab. */
export async function checkTabMarkup(ctx) {
	const { evaluate } = ctx;
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
}

/** Legacy and age archives through the real worker, with the real local download. */
export async function decryptVectors(ctx) {
	const { proxyRequests, directory, sleep, downloads, evaluate, age, legacy, scryptVector, hybridVector, sentinel, assertNoSecrets, run, from, networkStart } = ctx;
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
}

/** Cancellation, late authentication failure, OPFS quota failure and key unlocking. */
export async function cancellationAndStorageFailures(ctx) {
	const { proxyRequests, directory, evaluate, wait, age, secrets, selectPath, select, clear, run, from } = ctx;
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
}

/** Browser without X25519 or OPFS, and local rejection of an oversized file. */
export async function fallbacksAndLimits(ctx) {
	const { directory, evaluate, wait, age, legacy, selectPath, clear, run, from } = ctx;
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
}
