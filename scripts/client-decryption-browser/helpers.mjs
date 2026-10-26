/** Fixtures, secret scanning and page helpers shared by the client decryption browser scenarios. */
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createHash, randomBytes } from 'node:crypto';
import path from 'node:path';

/** Builds the fixtures and the page helpers shared by the scenarios. */
export async function createHelpers(ctx) {
	const { directory, call, evaluate, wait } = ctx;
	const fixture = async (name) =>
		JSON.parse(await readFile(new URL('../fixtures/' + name + '.json', import.meta.url), 'utf8'));
	const ageVector = async (name) => {
		const raw = await readFile(new URL('../fixtures/age-testkit/testdata/' + name, import.meta.url));
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
Object.assign(ctx, { age, legacy, scryptVector, hybridVector, sentinel, secrets, assertNoSecrets, checkPage, selectPath, select, clear, run, from });
}
