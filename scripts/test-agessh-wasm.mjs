import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { brotliCompressSync, gzipSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';
import { Decrypter } from 'age-encryption';
import { Stanza } from 'age-encryption';
import { base64nopad } from '@scure/base';
import { createAgesshBridge } from '../assets-src/decryption/agessh-bridge.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
globalThis.crypto ??= webcrypto;
const runtimeLoadStart = performance.now();
require(path.join(root, 'js/backupplus-agessh-wasm_exec.js'));
const runtimeLoadMs = performance.now() - runtimeLoadStart;

const [fixture, wasmBytes] = await Promise.all([
	readFile(path.join(root, 'scripts/fixtures/age-keys.json'), 'utf8').then(JSON.parse),
	readFile(path.join(root, 'js/backupplus-agessh.wasm')),
]);
const wasmCompileStart = performance.now();
const wasmModule = await WebAssembly.compile(wasmBytes);
const wasmCompileMs = performance.now() - wasmCompileStart;
const bridge = await createAgesshBridge({ wasmModule, GoConstructor: globalThis.Go });
const plaintext = Buffer.from(fixture.plaintext, 'base64');
const results = [];

try {
	for (const type of ['rsa', 'ed25519']) {
		const key = fixture.keys[type];
		const openStart = performance.now();
		const handle = bridge.open(key.identity, fixture.passphrase);
		const openMs = performance.now() - openStart;
		try {
			const decrypter = new Decrypter();
			decrypter.addIdentity(bridge.identity(handle));
			const decryptStart = performance.now();
			const stream = await decrypter.decrypt(
				new Blob([Buffer.from(key.ciphertext, 'base64')]).stream(),
			);
			const actual = Buffer.from(await new Response(stream).arrayBuffer());
			assert.deepEqual(actual, plaintext, `${type} upstream age stream plaintext`);
			results.push({ type, openMs, decryptMs: performance.now() - decryptStart });
			assert.equal(
				bridge.unwrap(handle, [
					{
						args: ['ssh-ed25519', 'AAAAAA', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
						body: new Uint8Array(32),
					},
				]),
				null,
				`${type} identity reports no stanza match as null`,
			);
			const { args, body } = sshStanza(Buffer.from(key.ciphertext, 'base64'));
			const matched = new Stanza(args, body);
			const fileKey = bridge.unwrap(handle, [matched]);
			assert.equal(fileKey.length, 16);
			assert.deepEqual(
				bridge.unwrap(handle, [
					new Stanza(['example.com/extension', ...Array(20).fill('arg')], new Uint8Array(2048)),
					matched,
				]),
				fileKey,
				'Unrelated extensions must be ignored before SSH-specific limits',
			);
			fileKey.fill(0);
		} finally {
			assert.equal(bridge.release(handle), true);
			assert.equal(bridge.release(handle), false);
		}
	}

	assert.throws(() => bridge.open(fixture.keys.rsa.identity, ''), {
		message: 'KEY_PASSPHRASE_REQUIRED',
	});
	assert.throws(() => bridge.open(fixture.keys.rsa.identity, 'wrong-password'), {
		message: 'KEY_UNLOCK_FAILED',
	});
	for (const key of Object.values(fixture.keys)) {
		const handle = bridge.open(key.encrypted_pem, fixture.passphrase);
		try {
			const d = new Decrypter();
			d.addIdentity(bridge.identity(handle));
			assert.deepEqual(
				Buffer.from(await d.decrypt(Buffer.from(key.ciphertext, 'base64'))),
				plaintext,
			);
		} finally {
			bridge.release(handle);
		}
	}
	await assert.rejects(createAgesshBridge({ wasmModule }), /BRIDGE_BUSY/);
	assert.equal(bridge.startupMs > 0, true);
} finally {
	await bridge.close();
}

let lateResumeRuntime;
class ExitRaceGo extends globalThis.Go {
	constructor() {
		super();
		lateResumeRuntime = this;
	}
	run() {
		this._scheduledTimeouts.set(1, setTimeout(() => this._resume(), 0));
		globalThis.BackupPlusAgeSSH = {
			shutdown: () => {
				this.exited = true;
			},
		};
		return Promise.resolve();
	}
}
const exitRaceBridge = await createAgesshBridge({ wasmModule, GoConstructor: ExitRaceGo });
await exitRaceBridge.close();
await new Promise((resolve) => setTimeout(resolve, 0));
assert.equal(lateResumeRuntime._scheduledTimeouts.size, 0);
assert.doesNotThrow(() => lateResumeRuntime._resume());

const runtimeBytes = await readFile(path.join(root, 'js/backupplus-agessh-wasm_exec.js'));
const size = (bytes) =>
	`${bytes.length} B raw, ${gzipSync(bytes).length} B gzip, ${brotliCompressSync(bytes).length} B Brotli`;
console.log(
	'PASS: official agessh unwrap through typage Identity.unwrapFileKey and upstream age stream decryption',
);
console.log(
	`Local startup: runtime ${runtimeLoadMs.toFixed(1)} ms + WASM compile ${wasmCompileMs.toFixed(1)} ms + Go boot ${bridge.startupMs.toFixed(1)} ms = ${(runtimeLoadMs + wasmCompileMs + bridge.startupMs).toFixed(1)} ms`,
);
for (const result of results)
	console.log(
		`${result.type}: key open ${result.openMs.toFixed(1)} ms, decrypt ${result.decryptMs.toFixed(1)} ms`,
	);
console.log(`Go runtime: ${size(runtimeBytes)}`);
console.log(`agessh WASM: ${size(wasmBytes)}`);
console.log(
	'PASS: no-match, wrong/missing passphrase, release lifecycle, late Go exit callbacks, unknown extensions and RSA/Ed25519/X25519 encrypted PKCS#8',
);

function sshStanza(ciphertext) {
	const header = ciphertext.subarray(0, ciphertext.indexOf('\n--- ')).toString();
	const lines = header.split('\n');
	const args = lines[1].slice(3).split(' ');
	const body = base64nopad.decode(lines.slice(2).join(''));
	return { args, body };
}
