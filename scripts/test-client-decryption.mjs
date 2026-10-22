/** Independent PHP/age interoperability, boundaries and fail-closed browser decoder checks. */
import { generateAgeIdentity } from '../assets-src/decryption/keygen.mjs';
import { decryptArchive } from '../assets-src/decryption/archive.mjs';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';
import path from 'node:path';
import assert from 'node:assert/strict';
const root = path.resolve(import.meta.dirname, '..'),
	fixture = async (name) =>
		JSON.parse(await readFile(path.join(root, 'scripts/fixtures', name + '.json'), 'utf8'));
const b = (s) => Buffer.from(s, 'base64');
function credentials(password = '', identity = '', keyPassword = '') {
	return { password, identity, keyPassword };
}
let checks = 0;
async function decode(cipher, creds, portable = false, expectedFailure = false) {
	const chunks = [];
	let published = false,
		aborted = false,
		maxRead = 0;
	const blob = new Blob([cipher]);
	const file = {
		size: blob.size,
		slice(start, end) {
			maxRead = Math.max(maxRead, end - start);
			return blob.slice(start, end);
		},
	};
	const sink = {
		write: async (bytes) => chunks.push(bytes.slice()),
		finish: async () => {
			published = true;
			return Buffer.concat(chunks);
		},
		abort: async () => {
			aborted = true;
			chunks.length = 0;
		},
	};
	if (expectedFailure) {
		await assert.rejects(decryptArchive(file, creds, sink, () => {}, portable));
		assert(
			!published && aborted && chunks.length === 0,
			'A failed archive must never publish partial plaintext',
		);
		checks++;
		return;
	}
	const result = await decryptArchive(file, creds, sink, () => {}, portable);
	assert(published && !aborted);
	assert(maxRead <= 1048576, 'Archive reads must stay bounded');
	checks++;
	return result;
}
for (const vector of (await fixture('phpseclib2-compat')).database)
	assert.deepEqual(
		(await decode(b(vector.ciphertext), credentials(b(vector.key).toString()))).output,
		b(vector.plaintext),
	);
console.log('PASS: original UpdraftPlus passphrase fixtures and all legacy key lengths');
const age = await fixture('age-keys');
for (const [type, key] of Object.entries(age.keys)) {
	for (const form of ['identity', 'encrypted_pem'])
		assert.deepEqual(
			(await decode(b(key.ciphertext), credentials('', key[form], age.passphrase))).output,
			b(age.plaintext),
		);
	assert.deepEqual(
		(await decode(b(key.ciphertext), credentials('', key.identity, age.passphrase), true)).output,
		b(age.plaintext),
	);
	if (type !== 'native')
		await decode(b(key.ciphertext), credentials('', key.identity, 'wrong'), false, true);
	await decode(b(key.ciphertext), credentials('', key.encrypted_pem, 'wrong'), false, true);
}
console.log(
	'PASS: age CLI fixtures for X25519, RSA and Ed25519; encrypted OpenSSH/PKCS#8; portable X25519 fallback',
);
for (const [cipher, creds] of [
	[b(age.keys.native.ciphertext), credentials('', age.keys.native.identity)],
]) {
	for (const offset of [0, 8, 10, 26, 40, cipher.length - 17, cipher.length - 1]) {
		const corrupt = Buffer.from(cipher);
		corrupt[offset] ^= 1;
		await decode(corrupt, creds, false, true);
	}
	for (const trim of [1, 16, 17, 32]) await decode(cipher.subarray(0, -trim), creds, false, true);
	await decode(Buffer.concat([cipher, Buffer.from([0])]), creds, false, true);
}
console.log(
	'PASS: header, body and tag corruption; truncated/appended input; no plaintext publication on failure',
);
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-browser-crypto-'));
function run(command, args, input) {
	const result = spawnSync(
		command,
		command === 'php' ? ['-d', 'display_errors=stderr', ...args] : args,
		{
			cwd: root,
			input,
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
		},
	);
	assert.equal(result.status, 0, result.stderr || result.stdout);
	return result.stdout;
}

try {
	const plainPath = path.join(directory, 'archive'),
		encryptedPath = plainPath + '.crypt';
	const originalRNG = crypto.getRandomValues;
	let randomCalls = 0,
		randomBuffer;
	let generated;
	try {
		crypto.getRandomValues = function (bytes) {
			randomCalls++;
			if (randomCalls === 1) {
				assert.equal(bytes.length, 32);
				randomBuffer = bytes;
			}
			return originalRNG.call(crypto, bytes);
		};
		generated = generateAgeIdentity();
		assert(randomCalls >= 1);
		assert(
			randomBuffer.every((byte) => byte === 0),
			'Temporary raw private bytes must be cleared',
		);
		crypto.getRandomValues = () => {
			throw new Error('No entropy');
		};
		assert.throws(generateAgeIdentity, /No entropy/, 'There must be no insecure RNG fallback');
	} finally {
		crypto.getRandomValues = originalRNG;
	}
	assert.match(generated.identity, /^AGE-SECRET-KEY-1[0-9A-Z]{58}$/);
	assert.match(generated.recipient, /^age1[0-9a-z]{58}$/);
	assert.notEqual(generated.identity, generateAgeIdentity().identity);
	const generatedPath = path.join(directory, 'generated-key.txt');
	await writeFile(generatedPath, generated.identity + '\n', { mode: 0o600 });
	assert.equal(
		run('age-keygen', ['-y', generatedPath]).trim(),
		generated.recipient,
		'The independent age CLI must derive the same public recipient',
	);
	const generatedPlain = Buffer.from('independent generated key interoperability');
	await writeFile(plainPath, generatedPlain);
	for (const producer of ['age', 'php']) {
		if (producer === 'age') run('age', ['-r', generated.recipient, '-o', encryptedPath, plainPath]);
		else
			run(
				'php',
				['scripts/fixtures/age-interop.php'],
				JSON.stringify({
					operation: 'encrypt',
					input: plainPath,
					output: encryptedPath,
					recipients: [generated.recipient],
				}),
			);
		assert.deepEqual(
			(await decode(await readFile(encryptedPath), credentials('', generated.identity))).output,
			generatedPlain,
		);
		assert.equal(run('age', ['-d', '-i', generatedPath, encryptedPath]), generatedPlain.toString());
		await rm(encryptedPath);
	}
	console.log(
		'PASS: Web Crypto RNG, entropy failure, independent age-keygen derivation and generated identities with PHP/age archives',
	);
	const legacyFixture = (await fixture('phpseclib2-compat')).chunked;
	const legacyPHP = `define('ABSPATH',getcwd().'/'); require 'vendor/autoload.php'; require 'includes/class-backupplus-crypto.php'; $plain=str_repeat("-- chunked database fixture\n",90000).'trailing data'; $cipher=BackupPlus_Crypto::rijndael($argv[2]); $cipher->disablePadding(); $pad=(16-strlen($plain)%16)%16; file_put_contents($argv[1],$cipher->encrypt($plain.str_repeat(chr($pad),$pad)));`;
	run('php', ['-r', legacyPHP, encryptedPath, legacyFixture.key]);
	const legacyCipher = await readFile(encryptedPath);
	assert.equal(
		createHash('sha256').update(legacyCipher).digest('hex'),
		legacyFixture.ciphertext_sha256,
	);
	const legacyResult = await decode(legacyCipher, credentials(legacyFixture.key));
	assert.equal(
		createHash('sha256').update(legacyResult.output).digest('hex'),
		legacyFixture.plaintext_sha256,
	);
	await rm(encryptedPath);
	console.log(
		'PASS: multi-slice legacy CBC chaining matches pre-migration ciphertext and plaintext hashes',
	);
	for (const length of [0, 1, 65535, 65536, 65537, 131072, 196609]) {
		const plain = Buffer.alloc(length);
		for (let i = 0; i < length; i++) plain[i] = (i * 37 + 29) % 256;
		await writeFile(plainPath, plain);
		run('age', ['-r', age.keys.native.recipient, '-o', encryptedPath, plainPath]);
		const cipher = await readFile(encryptedPath),
			creds = credentials('', age.keys.native.identity);
		assert.deepEqual((await decode(cipher, creds)).output, plain);
		if (length === 196609) {
			const payloadOffset =
					cipher.indexOf(Buffer.from('\n'), cipher.indexOf(Buffer.from('--- '))) + 17,
				capacity = 65552;
			await decode(cipher.subarray(0, payloadOffset + capacity), creds, false, true);
			const corrupt = Buffer.from(cipher);
			corrupt[corrupt.length - 1] ^= 1;
			await decode(corrupt, creds, false, true);
			const reordered = Buffer.concat([
				cipher.subarray(0, payloadOffset),
				cipher.subarray(payloadOffset + capacity, payloadOffset + 2 * capacity),
				cipher.subarray(payloadOffset, payloadOffset + capacity),
				cipher.subarray(payloadOffset + 2 * capacity),
			]);
			await decode(reordered, creds, false, true);
		}
		await rm(encryptedPath);
	}
	// Cross-check every recipient against the PHP writer, including mixed recipient headers.
	await writeFile(plainPath, Buffer.alloc(131073, 73));
	for (const keys of [
		[age.keys.native],
		[age.keys.ed25519],
		[age.keys.rsa],
		Object.values(age.keys),
	]) {
		run(
			'php',
			['scripts/fixtures/age-interop.php'],
			JSON.stringify({
				operation: 'encrypt',
				input: plainPath,
				output: encryptedPath,
				recipients: keys.map((k) => k.recipient),
			}),
		);
		for (const key of keys)
			assert.deepEqual(
				(
					await decode(
						await readFile(encryptedPath),
						credentials('', key.identity, age.passphrase),
						true,
					)
				).output,
				await readFile(plainPath),
			);
		await rm(encryptedPath);
	}
	console.log(
		'PASS: independent age and PHP writers; empty/exact/adjacent 64 KiB boundaries; multi-segment failure cleanup; mixed recipients',
	);
	for (const [type, key] of Object.entries(age.keys)) {
		const pemPath = path.join(directory, 'key.pem'),
			clearPath = path.join(directory, 'key-clear.pem');
		await writeFile(pemPath, key.encrypted_pem, { mode: 0o600 });
		run('openssl', [
			'pkey',
			'-in',
			pemPath,
			'-passin',
			'pass:' + age.passphrase,
			'-out',
			clearPath,
		]);
		assert.deepEqual(
			(await decode(b(key.ciphertext), credentials('', await readFile(clearPath, 'utf8')))).output,
			b(age.plaintext),
		);
		if (type !== 'native') {
			const clearSSH = run(
				'php',
				['scripts/fixtures/age-interop.php'],
				JSON.stringify({
					operation: 'identity',
					identity: key.identity,
					passphrase: age.passphrase,
				}),
			);
			assert.deepEqual(
				(await decode(b(key.ciphertext), credentials('', clearSSH))).output,
				b(age.plaintext),
			);
		}
		if (type === 'rsa') {
			for (const bits of [128, 192, 256]) {
				run('openssl', [
					'rsa',
					'-in',
					clearPath,
					'-traditional',
					'-aes' + bits,
					'-passout',
					'pass:' + age.passphrase,
					'-out',
					pemPath,
				]);
				assert.deepEqual(
					(
						await decode(
							b(key.ciphertext),
							credentials('', await readFile(pemPath, 'utf8'), age.passphrase),
						)
					).output,
					b(age.plaintext),
				);
			}
			run('openssl', ['rsa', '-in', clearPath, '-traditional', '-out', pemPath]);
			assert.deepEqual(
				(await decode(b(key.ciphertext), credentials('', await readFile(pemPath, 'utf8')))).output,
				b(age.plaintext),
			);
		}
	}
	console.log(
		'PASS: unencrypted OpenSSH, PKCS#8 and PKCS#1; traditional encrypted RSA PEM with AES-128/192/256-CBC',
	);
	// Explicit malformed headers cannot fall back to the unauthenticated legacy decoder.
	await decode(Buffer.from('age-encryption.org/v2\n'), credentials(), false, true);
	await decode(
		Buffer.from('age-encryption.org/v1\n' + 'x'.repeat(65536)),
		credentials('', age.keys.native.identity),
		false,
		true,
	);
	await decode(Buffer.alloc(16), credentials('wrong'), false, true);
	console.log(`PASS: ${checks} client decoder checks`);
} finally {
	await rm(directory, { recursive: true, force: true });
}
