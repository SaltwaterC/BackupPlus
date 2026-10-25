import { Decrypter } from 'age-encryption';
import { base64nopad } from '@scure/base';
import CryptoJS from 'crypto-js/core.js';
import 'crypto-js/lib-typedarrays.js';
import 'crypto-js/aes.js';
import 'crypto-js/pad-nopadding.js';
import { utf8, text, equal, check } from './bytes.mjs';
import { identities } from './keys.mjs';

const starts = (bytes, prefix) => equal(bytes.subarray(0, prefix.length), utf8.encode(prefix));
async function read(file, offset, length) {
	check(offset + length <= file.size);
	const bytes = new Uint8Array(await file.slice(offset, offset + length).arrayBuffer());
	check(bytes.length === length);
	return bytes;
}

// Bound the upstream parser's input before it allocates its header transcript.
// This guard only enforces application budgets; typage parses/authenticates age.
function checkHeaderBudget(bytes) {
	let offset = 0,
		stanzas = 0;
	while (offset < bytes.length) {
		const end = bytes.indexOf(10, offset);
		check(end >= offset && end - offset <= 4095);
		const line = text(bytes.subarray(offset, end));
		check(!line.includes('\r'));
		if (line.startsWith('-> ')) check(++stanzas <= 100);
		if (line.startsWith('--- ')) {
			check(/^--- [A-Za-z0-9+/]{43}$/.test(line));
			return;
		}
		offset = end + 1;
	}
	check(false);
}

// Preserve validation of every recognized stanza, including unmatched recipients.
const stanzaPolicy = {
	unwrapFileKey(stanzas) {
		check(stanzas.length <= 100);
		for (const { args, body } of stanzas) {
			if (args[0] === 'X25519')
				check(args.length === 2 && base64nopad.decode(args[1]).length === 32 && body.length === 32);
			if (args[0] === 'ssh-rsa')
				check(
					args.length === 2 &&
						base64nopad.decode(args[1]).length === 4 &&
						body.length >= 256 &&
						body.length <= 1024,
				);
			if (args[0] === 'ssh-ed25519')
				check(
					args.length === 3 &&
						base64nopad.decode(args[1]).length === 4 &&
						base64nopad.decode(args[2]).length === 32 &&
						body.length === 32,
				);
			if (args[0] === 'scrypt') {
				check(stanzas.length === 1 && args.length === 3);
				check(/^[1-9][0-9]*$/.test(args[2]));
				const logN = Number(args[2]);
				check(Number.isSafeInteger(logN) && logN <= 20);
				check(base64nopad.decode(args[1]).length === 16 && body.length === 32);
			}
			if (args[0] === 'mlkem768x25519')
				check(args.length === 2 && base64nopad.decode(args[1]).length === 1120 && body.length === 32);
		}
		return null;
	},
};

async function age(file, input, keyPassword, archivePassphrase, sink, progress, options) {
	const head = await read(file, 0, Math.min(file.size, 65536));
	checkHeaderBudget(head);
	const imported = await identities(input, keyPassword, options);
	let reader;
	try {
		const decrypter = new Decrypter();
		decrypter.addIdentity(stanzaPolicy);
		if (archivePassphrase) {
			check(typeof archivePassphrase === 'string' && archivePassphrase.length <= 65536);
			decrypter.addPassphrase(archivePassphrase);
		}
		for (const key of imported.keys) decrypter.addIdentity(key);
		let offset = 0;
		const source = new ReadableStream({
			async pull(controller) {
				if (offset === file.size) {
					controller.close();
					return;
				}
				const bytes =
					offset === 0 ? head : await read(file, offset, Math.min(65536, file.size - offset));
				offset += bytes.length;
				controller.enqueue(bytes);
				progress(offset / file.size);
			},
		});
		reader = (await decrypter.decrypt(source)).getReader();
		for (;;) {
			const { done, value } = await reader.read();
			if (done) break;
			try {
				await sink.write(value);
			} finally {
				value.fill(0);
			}
		}
	} finally {
		if (reader) {
			try {
				await reader.cancel();
			} catch {}
			reader.releaseLock();
		}
		await imported.close();
	}
}
const words = (bytes) => CryptoJS.lib.WordArray.create(bytes);
const toBytes = (wa) => {
	const out = new Uint8Array(wa.sigBytes);
	for (let i = 0; i < out.length; i++) out[i] = (wa.words[i >>> 2] >>> (24 - (i % 4) * 8)) & 255;
	return out;
};
async function legacy(file, password, sink, progress) {
	check(file.size > 0 && file.size % 16 === 0);
	const pass = utf8.encode(password),
		length = Math.max(16, Math.min(32, 4 * Math.ceil(pass.length / 4))),
		key = new Uint8Array(length);
	key.set(pass.subarray(0, length));
	pass.fill(0);
	let iv = new Uint8Array(16),
		first = true;
	try {
		for (let offset = 0; offset < file.size; offset += 1048576) {
			const encrypted = await read(file, offset, Math.min(1048576, file.size - offset));
			const decoded = CryptoJS.AES.decrypt({ ciphertext: words(encrypted) }, words(key), {
				iv: words(iv),
				mode: CryptoJS.mode.CBC,
				padding: CryptoJS.pad.NoPadding,
			});
			let plain = toBytes(decoded);
			decoded.words.fill(0);
			iv = encrypted.slice(-16);
			if (first) {
				const prefix = String.fromCharCode(...plain.subarray(0, 1024));
				check(
					(plain[0] === 31 && plain[1] === 139) ||
						/^PK(?:\x03\x04|\x05\x06|\x07\x08)/.test(prefix) ||
						/^(?:#[ \t]+[^\r\n]{12,}\r?\n|-- [^\r\n]{12,}\r?\n|\/\*[! *][^\r\n]{12,})/.test(prefix),
				);
				first = false;
			}
			if (offset + encrypted.length === file.size) {
				const pad = plain[plain.length - 1];
				if (pad > 0 && pad < 16 && plain.subarray(-pad).every((v) => v === pad))
					plain = plain.subarray(0, plain.length - pad);
			}
			await sink.write(plain);
			plain.fill(0);
			progress((offset + encrypted.length) / file.size);
		}
	} finally {
		key.fill(0);
	}
}
/** Publish only after the entire upstream stream has authenticated. */
export async function decryptArchive(file, credentials, sink, progress = () => {}, options = {}) {
	let format;
	try {
		check(globalThis.crypto?.subtle);
		const prefix = await read(file, 0, Math.min(file.size, 21));
		if (starts(prefix, 'age-encryption.org/')) {
			format = 'age';
			await age(
				file,
				credentials.identity,
				credentials.keyPassword,
				credentials.password,
				sink,
				progress,
				options,
			);
		} else {
			format = 'legacy';
			await legacy(file, credentials.password, sink, progress);
		}
		return { output: await sink.finish(), format };
	} catch (error) {
		await sink.abort();
		throw error;
	}
}
