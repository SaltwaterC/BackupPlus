import { chacha20poly1305 } from '@noble/ciphers/chacha.js';
import CryptoJS from 'crypto-js/core.js';
import 'crypto-js/lib-typedarrays.js';
import 'crypto-js/aes.js';
import 'crypto-js/pad-nopadding.js';
import { utf8, text, concat, equal, check, unbase64, hash, hkdf, hmac } from './bytes.mjs';
import { identities, publicX, sharedX } from './keys.mjs';
const empty = new Uint8Array();
const starts = (b, s) => equal(b.subarray(0, s.length), utf8.encode(s));
async function read(file, offset, n) {
	check(offset + n <= file.size);
	const b = new Uint8Array(await file.slice(offset, offset + n).arrayBuffer());
	check(b.length === n);
	return b;
}
function chacha(key, nonce, body) {
	check(body.length >= 16 && body.length <= 65552);
	return chacha20poly1305(key, nonce).decrypt(body);
}
function parseAgeHeader(bytes) {
	let pos = 0;
	const line = () => {
		const end = bytes.indexOf(10, pos);
		check(end >= pos && end - pos <= 4095);
		const result = text(bytes.subarray(pos, end));
		check(!result.includes('\r'));
		pos = end + 1;
		return result;
	};
	check(line() === 'age-encryption.org/v1');
	const stanzas = [];
	let current = line();
	while (current.startsWith('-> ')) {
		check(stanzas.length < 100);
		const fields = current.slice(3).split(' ');
		check(fields.length > 0 && fields.every((s) => /^[!-~]+$/.test(s)));
		let body = '',
			bodyLine;
		do {
			bodyLine = line();
			check(/^[A-Za-z0-9+/]*$/.test(bodyLine) && bodyLine.length <= 64);
			body += bodyLine;
		} while (bodyLine.length === 64);
		const stanza = { type: fields[0], args: fields.slice(1), body: unbase64(body, true) };
		if (stanza.type === 'X25519')
			check(
				stanza.args.length === 1 &&
					unbase64(stanza.args[0], true).length === 32 &&
					stanza.body.length === 32,
			);
		if (stanza.type === 'ssh-rsa')
			check(
				stanza.args.length === 1 &&
					unbase64(stanza.args[0], true).length === 4 &&
					stanza.body.length >= 256 &&
					stanza.body.length <= 1024,
			);
		if (stanza.type === 'ssh-ed25519')
			check(
				stanza.args.length === 2 &&
					unbase64(stanza.args[0], true).length === 4 &&
					unbase64(stanza.args[1], true).length === 32 &&
					stanza.body.length === 32,
			);
		stanzas.push(stanza);
		current = line();
	}
	check(/^--- [A-Za-z0-9+/]{43}$/.test(current));
	const mac = unbase64(current.slice(4), true),
		authenticated = bytes.slice(0, pos - current.length - 1 + 3);
	check(pos <= 65536);
	return { stanzas, mac, authenticated, offset: pos };
}
async function unwrap(stanza, identity, portable) {
	const { type, args, body } = stanza;
	if (type === 'X25519') {
		check(args.length === 1 && body.length === 32);
		const ephemeral = unbase64(args[0], true);
		check(ephemeral.length === 32);
		if (identity.type !== type) return null;
		const shared = await sharedX(identity.secret, ephemeral, portable);
		const key = await hkdf(
			shared,
			concat(ephemeral, publicX(identity.secret)),
			'age-encryption.org/v1/X25519',
		);
		shared.fill(0);
		try {
			return chacha(key, new Uint8Array(12), body);
		} finally {
			key.fill(0);
		}
	}
	if (type === 'ssh-rsa' || type === 'ssh-ed25519') {
		check(args.length === (type === 'ssh-rsa' ? 1 : 2) && unbase64(args[0], true).length === 4);
		const ephemeral = type === 'ssh-ed25519' ? unbase64(args[1], true) : null;
		if (ephemeral) check(ephemeral.length === 32 && body.length === 32);
		else check(body.length >= 256 && body.length <= 1024);
		if (
			identity.type !== type ||
			!equal(unbase64(args[0], true), (await hash(identity.wire)).subarray(0, 4))
		)
			return null;
		const label = 'age-encryption.org/v1/' + type;
		if (type === 'ssh-rsa')
			return new Uint8Array(
				await crypto.subtle.decrypt(
					{ name: 'RSA-OAEP', label: utf8.encode(label) },
					identity.key,
					body,
				),
			);
		const shared = await sharedX(identity.secret, ephemeral, portable),
			tweak = await hkdf(empty, identity.wire, label);
		let tweaked;
		try {
			tweaked = await sharedX(tweak, shared, portable);
		} finally {
			shared.fill(0);
			tweak.fill(0);
		}
		const key = await hkdf(tweaked, concat(ephemeral, publicX(identity.secret)), label);
		tweaked.fill(0);
		try {
			return chacha(key, new Uint8Array(12), body);
		} finally {
			key.fill(0);
		}
	}
	return null;
}
async function age(file, input, keyPassword, sink, progress, portable) {
	const header = parseAgeHeader(await read(file, 0, Math.min(file.size, 65536)));
	const keys = await identities(input, keyPassword);
	let fileKey;
	try {
		for (const stanza of header.stanzas) {
			// Validate recognized stanzas even if no identity matches; unknown extensions are ignored.
			for (const identity of keys) {
				let candidate;
				try {
					candidate = await unwrap(stanza, identity, portable);
					if (!candidate) continue;
					check(candidate.length === 16);
					const macKey = await hkdf(candidate, empty, 'header');
					let actual;
					try {
						actual = await hmac(macKey, header.authenticated);
					} finally {
						macKey.fill(0);
					}
					if (equal(actual, header.mac) && !fileKey) fileKey = candidate.slice();
				} catch {
					/* A different recipient or a failed unwrap cannot authenticate this header. */
				} finally {
					if (candidate) candidate.fill(0);
				}
			}
		}
		check(fileKey);
		const nonce = await read(file, header.offset, 16),
			key = await hkdf(fileKey, nonce, 'payload');
		let offset = header.offset + 16,
			index = 0n;
		try {
			do {
				const length = Math.min(65552, file.size - offset),
					last = offset + length === file.size;
				check(length >= 16 && (index === 0n || length > 16));
				const chunkNonce = new Uint8Array(12);
				let n = index;
				for (let i = 10; i >= 0; i--) {
					chunkNonce[i] = Number(n & 255n);
					n >>= 8n;
				}
				check(n === 0n);
				chunkNonce[11] = last ? 1 : 0;
				const plain = chacha(key, chunkNonce, await read(file, offset, length));
				await sink.write(plain);
				plain.fill(0);
				offset += length;
				index++;
				progress(offset / file.size);
			} while (offset < file.size);
		} finally {
			key.fill(0);
		}
	} finally {
		if (fileKey) fileKey.fill(0);
		for (const key of keys) if (key.secret) key.secret.fill(0);
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
/** Reads bounded slices and only finishes the sink after the entire archive is verified. */
export async function decryptArchive(
	file,
	credentials,
	sink,
	progress = () => {},
	portable = false,
) {
	let format;
	try {
		check(globalThis.crypto?.subtle);
		const prefix = await read(file, 0, Math.min(file.size, 21));
		if (starts(prefix, 'age-encryption.org/')) {
			format = 'age';
			await age(file, credentials.identity, credentials.keyPassword, sink, progress, portable);
		} else {
			format = 'legacy';
			await legacy(file, credentials.password, sink, progress);
		}
		return { output: await sink.finish(), format };
	} catch (e) {
		await sink.abort();
		throw e;
	}
}
