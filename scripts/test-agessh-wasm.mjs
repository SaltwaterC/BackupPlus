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
	for (const type of ['rsa', 'ed25519']) {
		const key = fixture.keys[type];
		const encrypted = key.encrypted_openssh_gcm;
		assert.throws(() => bridge.open(encrypted, ''), { message: 'KEY_PASSPHRASE_REQUIRED' });
		assert.throws(() => bridge.open(encrypted, 'wrong-password'), { message: 'KEY_UNLOCK_FAILED' });
		const raw = Buffer.from(encrypted.split('\n').slice(1, -2).join(''), 'base64');
		let offset = Buffer.byteLength('openssh-key-v1\0');
		const field = () => { const start = offset + 4; offset = start + raw.readUInt32BE(offset); return {start, end: offset}; };
		const cipher = field(), kdf = field(), options = field();
		assert.equal(raw.subarray(cipher.start, cipher.end).toString(), 'aes256-gcm@openssh.com');
		assert.equal(raw.subarray(kdf.start, kdf.end).toString(), 'bcrypt');
		offset += 4; // Single public key count.
		const publicKey = field(), privateKey = field();
		assert.equal(raw.length - privateKey.end, 16, 'OpenSSH stores the GCM tag outside the private-key string');
		const pem = bytes => '-----BEGIN OPENSSH PRIVATE KEY-----\n' + bytes.toString('base64').match(/.{1,70}/g).join('\n') + '\n-----END OPENSSH PRIVATE KEY-----\n';
		for (const position of [privateKey.start, raw.length - 1]) {
			const damaged = Buffer.from(raw);
			damaged[position] ^= 1;
			assert.throws(() => bridge.open(pem(damaged), fixture.passphrase), { message: 'KEY_UNLOCK_FAILED' });
		}
		for (const malformed of [raw.subarray(0, raw.length - 1), raw.subarray(0, privateKey.end), Buffer.concat([raw, Buffer.from([0])])]) {
			assert.throws(() => bridge.open(pem(malformed), fixture.passphrase), { message: 'UNSUPPORTED_KEY' });
		}
		const expensive = Buffer.from(raw);
		expensive.writeUInt32BE(1025, options.end - 4);
		assert.throws(() => bridge.open(pem(expensive), fixture.passphrase), { message: 'UNSUPPORTED_KEY' });
		const mismatched = Buffer.from(raw);
		mismatched[publicKey.end - 1] ^= 1;
		assert.throws(() => bridge.open(pem(mismatched), fixture.passphrase), { message: 'UNSUPPORTED_KEY' });
		const handle = bridge.open(encrypted, fixture.passphrase);
		try {
			const d = new Decrypter();
			d.addIdentity(bridge.identity(handle));
			assert.deepEqual(Buffer.from(await d.decrypt(Buffer.from(key.ciphertext, 'base64'))), plaintext);
		} finally {
			bridge.release(handle);
		}
	}
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
console.log('PASS: OpenSSH RSA/Ed25519 AES-GCM, wrong/missing passphrases, ciphertext/tag authentication, truncated/trailing tags, KDF limits and public-key consistency');

function sshStanza(ciphertext) {
	const header = ciphertext.subarray(0, ciphertext.indexOf('\n--- ')).toString();
	const lines = header.split('\n');
	const args = lines[1].slice(3).split(' ');
	const body = base64nopad.decode(lines.slice(2).join(''));
	return { args, body };
}
