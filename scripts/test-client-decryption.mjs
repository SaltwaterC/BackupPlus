/** Independent PHP/age interoperability, boundaries and fail-closed browser decoder checks. */
import { generateAgeIdentity } from '../assets-src/decryption/keygen.mjs';
import { decryptArchive } from '../assets-src/decryption/archive.mjs';
import { readFile, writeFile, mkdtemp, rm, readdir, open } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';
import { createHmac, hkdfSync } from 'node:crypto';
import { Encrypter, Decrypter } from 'age-encryption';
import { createRequire } from 'node:module';
import { createAgesshBridge } from '../assets-src/decryption/agessh-bridge.mjs';
import { inflateSync } from 'node:zlib';
import path from 'node:path';
import assert from 'node:assert/strict';
const root = path.resolve(import.meta.dirname, '..'),
	fixture = async (name) =>
		JSON.parse(await readFile(path.join(root, 'scripts/fixtures', name + '.json'), 'utf8'));
const require = createRequire(import.meta.url);
require(path.join(root, 'js/backupplus-agessh-wasm_exec.js'));
const wasmModule = await WebAssembly.compile(
	await readFile(path.join(root, 'js/backupplus-agessh.wasm')),
);
const cryptoOptions = { createBridge: () => createAgesshBridge({ wasmModule }) };
const b = (s) => Buffer.from(s, 'base64');
function credentials(password = '', identity = '', keyPassword = '') {
	return { password, identity, keyPassword };
}
let checks = 0;
async function decryptWithOptions(file, creds, sink, portable) {
	const originalImport = crypto.subtle.importKey;
	if (portable)
		crypto.subtle.importKey = function (format, key, algorithm, ...args) {
			if (algorithm?.name === 'X25519')
				return Promise.reject(new DOMException('Unavailable', 'NotSupportedError'));
			return originalImport.call(this, format, key, algorithm, ...args);
		};
	try {
		return await decryptArchive(file, creds, sink, () => {}, cryptoOptions);
	} finally {
		crypto.subtle.importKey = originalImport;
	}
}
async function decode(cipher, creds, portable = false, expectedFailure = false) {
	const chunks = [];
	const writtenHash = createHash('sha256');
	let published = false,
		aborted = false,
		maxRead = 0,
		writtenBytes = 0;
	const blob = new Blob([cipher]);
	const file = {
		size: blob.size,
		slice(start, end) {
			maxRead = Math.max(maxRead, end - start);
			return blob.slice(start, end);
		},
	};
	const sink = {
		write: async (bytes) => {
			chunks.push(bytes.slice());
			writtenHash.update(bytes);
			writtenBytes += bytes.length;
		},
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
		await assert.rejects(decryptWithOptions(file, creds, sink, portable));
		assert(
			!published && aborted && chunks.length === 0,
			'A failed archive must never publish partial plaintext',
		);
		checks++;
		return { writtenHash: writtenHash.digest('hex'), writtenBytes };
	}
	const result = await decryptWithOptions(file, creds, sink, portable);
	assert(published && !aborted);
	assert(maxRead <= 1048576, 'Archive reads must stay bounded');
	checks++;
	return { ...result, writtenHash: writtenHash.digest('hex'), writtenBytes };
}
function parseAgeTestVector(raw, name) {
	const separator = raw.indexOf(Buffer.from('\n\n'));
	assert(separator >= 0, `Invalid age test kit vector: ${name}`);
	const metadata = {};
	for (const line of raw.subarray(0, separator).toString('utf8').split('\n')) {
		const index = line.indexOf(': ');
		assert(index >= 0, `Invalid age test kit metadata: ${name}`);
		const key = line.slice(0, index);
		(metadata[key] ??= []).push(line.slice(index + 2));
	}
	return { metadata, ciphertext: raw.subarray(separator + 2) };
}
for (const vector of (await fixture('phpseclib2-compat')).database)
	assert.deepEqual(
		(await decode(b(vector.ciphertext), credentials(b(vector.key).toString()))).output,
		b(vector.plaintext),
	);
console.log('PASS: original UpdraftPlus passphrase fixtures and all legacy key lengths');
const age = await fixture('age-keys');
const testkitDirectory = path.join(root, 'scripts/fixtures/age-testkit/testdata');
const testkitVectors = (await readdir(testkitDirectory)).sort();
assert(testkitVectors.length > 0, 'The pinned age test kit must be present');
const testkitSkips = { armor: 0 };
let testkitChecks = 0;
for (const name of testkitVectors) {
	const { metadata, ciphertext: encodedCiphertext } = parseAgeTestVector(
		await readFile(path.join(testkitDirectory, name)),
		name,
	);
	if (metadata.armored?.[0] === 'yes') {
		testkitSkips.armor++;
		continue;
	}
	const expected = metadata.expect?.[0];
	let ciphertext = encodedCiphertext;
	if (metadata.compressed?.[0] === 'zlib') ciphertext = inflateSync(ciphertext);
	const identity = (metadata.identity ?? (metadata.passphrase ? [] : [age.keys.native.identity])).join('\n');
	const passphrase = metadata.passphrase?.[0] ?? '';
	for (const portable of [false, true]) {
		if (expected === 'success') {
			const result = await decode(ciphertext, credentials(passphrase, identity), portable);
			assert.equal(
				createHash('sha256').update(result.output).digest('hex'),
				metadata.payload?.[0],
				`age test kit plaintext hash mismatch (${portable ? 'portable' : 'WebCrypto'}): ${name}`,
			);
		} else {
			assert(
				['no match', 'HMAC failure', 'header failure', 'payload failure'].includes(expected),
				`Unknown age test kit expectation: ${name}`,
			);
			const result = await decode(ciphertext, credentials(passphrase, identity), portable, true);
			// Upstream can withhold a full final buffer before reporting truncation.
			// The product contract is rejection and zero publication (checked by decode),
			// rather than an exact amount of private, subsequently discarded staging.
			if (expected !== 'payload failure')
				assert.equal(
					result.writtenBytes,
					0,
					`Unexpected plaintext output before ${expected}: ${name}`,
				);
		}
	}
	testkitChecks++;
}
console.log(
	`PASS: age C2SP test kit (${testkitChecks} vectors in WebCrypto and portable modes; skipped armor ${testkitSkips.armor})`,
);
const scryptVector = parseAgeTestVector(
	await readFile(path.join(testkitDirectory, 'scrypt')),
	'scrypt',
);
await decode(scryptVector.ciphertext, credentials('wrong passphrase'), false, true);
console.log('PASS: scrypt archive rejects a wrong passphrase without publishing plaintext');
for (const [type, key] of Object.entries(age.keys)) {
	for (const form of ['identity', 'encrypted_pem', ...(key.encrypted_openssh_gcm ? ['encrypted_openssh_gcm'] : [])])
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
	if (key.encrypted_openssh_gcm)
		await decode(b(key.ciphertext), credentials('', key.encrypted_openssh_gcm, 'wrong'), false, true);
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
	let randomCalls = 0;
	let generated;
	try {
		crypto.getRandomValues = function (bytes) {
			randomCalls++;
			if (randomCalls === 1) {
				assert.equal(bytes.length, 32);
			}
			return originalRNG.call(crypto, bytes);
		};
		generated = await generateAgeIdentity();
		assert(randomCalls >= 1);
		crypto.getRandomValues = () => {
			throw new Error('No entropy');
		};
		await assert.rejects(
			generateAgeIdentity,
			/No entropy/,
			'There must be no insecure RNG fallback',
		);
	} finally {
		crypto.getRandomValues = originalRNG;
	}
	assert.match(generated.identity, /^AGE-SECRET-KEY-1[0-9A-Z]{58}$/);
	assert.match(generated.recipient, /^age1[0-9a-z]{58}$/);
	assert.notEqual(generated.identity, (await generateAgeIdentity()).identity);
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
		for (const bits of [128, 192, 256]) {
			for (const prf of ['SHA1', 'SHA256', 'SHA384', 'SHA512']) {
				run('openssl', [
					'pkcs8',
					'-topk8',
					'-in',
					clearPath,
					'-out',
					pemPath,
					'-v2',
					`aes-${bits}-cbc`,
					'-v2prf',
					`hmacWith${prf}`,
					'-iter',
					'1024',
					'-passout',
					'pass:' + age.passphrase,
				]);
				assert.deepEqual(
					(
						await decode(
							b(key.ciphertext),
							credentials('', await readFile(pemPath, 'utf8'), age.passphrase),
							type === 'native',
						)
					).output,
					b(age.plaintext),
					`${type} PKCS#8 AES-${bits}/HMAC-${prf}`,
				);
			}
		}
		run('openssl', [
			'pkcs8',
			'-topk8',
			'-in',
			clearPath,
			'-out',
			pemPath,
			'-v2',
			'aes-256-cbc',
			'-v2prf',
			'hmacWithSHA512',
			'-iter',
			'1024',
			'-passout',
			'pass:',
		]);
		assert.deepEqual(
			(await decode(b(key.ciphertext), credentials('', await readFile(pemPath, 'utf8')))).output,
			b(age.plaintext),
			`${type} PKCS#8 with empty password`,
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
			const emptyPasswords = await fixture('rsa-pem-empty-password');
			for (const bits of [128, 192, 256]) {
				assert.deepEqual(
					(await decode(b(key.ciphertext), credentials('', emptyPasswords[`AES-${bits}-CBC`])))
						.output,
					b(age.plaintext),
					`Traditional RSA AES-${bits} PEM with an empty password`,
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
		'PASS: unencrypted OpenSSH/PKCS#8/PKCS#1; traditional encrypted RSA PEM; all 36 PKCS#8 key/cipher/PRF combinations',
	);
	// Check unknown extensions against an authentic SSH archive, recomputing only
	// the header MAC with the file key obtained through the public upstream API.
	const original = b(age.keys.ed25519.ciphertext);
	const headerEnd = original.indexOf('\n', original.indexOf('\n--- ') + 1) + 1;
	const bridge = await cryptoOptions.createBridge();
	let extended;
	try {
		const d = new Decrypter();
		d.addIdentity(bridge.identity(bridge.open(age.keys.ed25519.identity, age.passphrase)));
		const fileKey = await d.decryptHeader(original.subarray(0, headerEnd));
		const extension =
			'-> example.com/unknown ' +
			Array(20).fill('argument').join(' ') +
			'\n' +
			Buffer.alloc(2048)
				.toString('base64')
				.replace(/=+$/, '')
				.match(/.{1,64}/g)
				.join('\n') +
			'\n';
		const header = Buffer.concat([
			original.subarray(0, original.indexOf('\n--- ') + 1),
			Buffer.from(extension + '---'),
		]);
		const macKey = hkdfSync('sha256', fileKey, Buffer.alloc(0), Buffer.from('header'), 32);
		const mac = createHmac('sha256', macKey).update(header).digest('base64').replace(/=+$/, '');
		extended = Buffer.concat([header, Buffer.from(' ' + mac + '\n'), original.subarray(headerEnd)]);
		fileKey.fill(0);
	} finally {
		await bridge.close();
	}
	assert.deepEqual(
		(await decode(extended, credentials('', age.keys.ed25519.identity, age.passphrase))).output,
		b(age.plaintext),
	);
	assert.deepEqual(
		(
			await decode(
				b(age.keys.native.ciphertext),
				credentials(
					'',
					'# multiple native keys\n' +
						generated.identity.toLowerCase() +
						'\n' +
						age.keys.native.identity,
				),
			)
		).output,
		b(age.plaintext),
	);
	for (const size of [60000, 4096]) {
		const e = new Encrypter();
		e.addRecipient(age.keys.native.recipient);
		e.addRecipient({
			wrapFileKey: () => [
				{
					args: [size === 4096 ? 'x'.repeat(size) : 'example.com/unknown'],
					body: new Uint8Array(size === 4096 ? 0 : size),
				},
			],
		});
		await decode(
			await e.encrypt('oversized header'),
			credentials('', age.keys.native.identity),
			false,
			true,
		);
	}
	const many = new Encrypter();
	for (let i = 0; i < 101; i++) many.addRecipient(age.keys.native.recipient);
	await decode(
		await many.encrypt('too many recipients'),
		credentials('', age.keys.native.identity),
		false,
		true,
	);
	console.log(
		'PASS: SSH archive with large unknown extension, multiple/commented/lowercase native identities, header and stanza budgets',
	);

	const rsa8192 = path.join(directory, 'rsa8192');
	run('ssh-keygen', ['-q', '-t', 'rsa', '-b', '8192', '-N', '', '-f', rsa8192]);
	await writeFile(plainPath, Buffer.alloc(65537, 61));
	run('age', [
		'-r',
		(await readFile(rsa8192 + '.pub', 'utf8')).trim(),
		'-o',
		encryptedPath,
		plainPath,
	]);
	assert.deepEqual(
		(await decode(await readFile(encryptedPath), credentials('', await readFile(rsa8192, 'utf8'))))
			.output,
		await readFile(plainPath),
	);
	await rm(encryptedPath);
	console.log('PASS: independently generated 8192-bit RSA SSH recipient and multi-chunk archive');
	// Neither the source nor the sink offers a whole-file buffer for this archive.
	const block = Buffer.alloc(1024 * 1024, 73);
	const expectedHash = createHash('sha256');
	const large = await open(plainPath, 'w');
	try {
		for (let i = 0; i < 160; i++) {
			await large.write(block);
			expectedHash.update(block);
		}
	} finally {
		await large.close();
	}
	run('age', [
		'-r',
		(await readFile(rsa8192 + '.pub', 'utf8')).trim(),
		'-o',
		encryptedPath,
		plainPath,
	]);
	const ciphertextFile = await open(encryptedPath, 'r');
	let readBound = 0,
		writeBound = 0,
		count = 0,
		aborted = false;
	const actualHash = createHash('sha256');
	try {
		const input = {
			size: (await ciphertextFile.stat()).size,
			slice(start, end) {
				readBound = Math.max(readBound, end - start);
				return {
					async arrayBuffer() {
						const buffer = Buffer.alloc(end - start);
						const { bytesRead } = await ciphertextFile.read(buffer, 0, buffer.length, start);
						assert.equal(bytesRead, buffer.length);
						return buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.length);
					},
				};
			},
		};
		const result = await decryptWithOptions(
			input,
			credentials('', await readFile(rsa8192, 'utf8')),
			{
				async write(bytes) {
					writeBound = Math.max(writeBound, bytes.length);
					count += bytes.length;
					actualHash.update(bytes);
				},
				async finish() {
					return actualHash.digest('hex');
				},
				async abort() {
					aborted = true;
				},
			},
			false,
		);
		assert.equal(result.output, expectedHash.digest('hex'));
		assert.equal(count, 160 * 1024 * 1024);
		assert(readBound <= 65536 && writeBound <= 65536 && !aborted);
		checks++;
	} finally {
		await ciphertextFile.close();
	}
	await rm(encryptedPath);
	console.log(
		'PASS: 160 MiB RSA archive with file-backed input, hash-only output and reads/writes bounded to 64 KiB',
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
