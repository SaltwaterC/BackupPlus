import { x25519, ed25519 } from '@noble/curves/ed25519.js';
import bcrypt from 'bcrypt-pbkdf';
import CryptoJS from 'crypto-js/core.js';
import 'crypto-js/lib-typedarrays.js';
import 'crypto-js/md5.js';
import {
	utf8,
	text,
	concat,
	equal,
	check,
	unbase64,
	b64url,
	hash,
	pbkdf,
	aes,
	Wire,
	wireString,
} from './bytes.mjs';
const empty = new Uint8Array();
const trim = (b) => {
	let i = 0;
	while (i < b.length - 1 && b[i] === 0) i++;
	return b.subarray(i);
};
const big = (b) => BigInt('0x' + Array.from(b, (v) => v.toString(16).padStart(2, '0')).join(''));
const fromBig = (b) => {
	let h = b.toString(16);
	if (h.length % 2) h = '0' + h;
	return Uint8Array.from(h.match(/../g), (v) => parseInt(v, 16));
};
function nativeSecret(s) {
	check(s === s.toUpperCase() || s === s.toLowerCase());
	s = s.toLowerCase();
	const cut = s.lastIndexOf('1');
	check(s.slice(0, cut) === 'age-secret-key-');
	const alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	const words = Array.from(s.slice(cut + 1), (c) => alphabet.indexOf(c));
	check(words.length === 58 && words.every((n) => n >= 0));
	const hrp = s.slice(0, cut);
	const expanded = [
		...Array.from(hrp, (c) => c.charCodeAt(0) >> 5),
		0,
		...Array.from(hrp, (c) => c.charCodeAt(0) & 31),
		...words,
	];
	let chk = 1;
	for (const n of expanded) {
		const top = chk >>> 25;
		chk = ((chk & 0x1ffffff) << 5) ^ n;
		[0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3].forEach((g, i) => {
			if ((top >>> i) & 1) chk ^= g;
		});
	}
	check(chk === 1);
	let acc = 0,
		bits = 0;
	const out = [];
	for (const w of words.slice(0, -6)) {
		acc = ((acc << 5) | w) & 0xffff;
		bits += 5;
		if (bits >= 8) {
			bits -= 8;
			out.push((acc >>> bits) & 255);
		}
	}
	check(bits === 4 && (acc & 15) === 0 && out.length === 32);
	return Uint8Array.from(out);
}
class DER {
	constructor(bytes) {
		this.w = new Wire(bytes);
	}
	node(tag) {
		check(this.w.read(1)[0] === tag);
		let n = this.w.read(1)[0];
		if (n & 128) {
			const count = n & 127;
			check(count > 0 && count <= 3);
			const b = this.w.read(count);
			check(b[0] !== 0);
			n = 0;
			for (const v of b) n = n * 256 + v;
			check(n >= 128);
		}
		return this.w.read(n);
	}
	seq() {
		return new DER(this.node(0x30));
	}
	integer() {
		const b = this.node(2);
		check(b.length && !(b[0] & 128) && !(b.length > 1 && b[0] === 0 && !(b[1] & 128)));
		return trim(b);
	}
	number() {
		const b = this.integer();
		check(b.length <= 4);
		let n = 0;
		for (const v of b) n = n * 256 + v;
		return n;
	}
	oid() {
		return Array.from(this.node(6), (v) => v.toString(16).padStart(2, '0')).join('');
	}
	octet() {
		return this.node(4);
	}
	end() {
		this.w.end();
	}
}
function sequence(b) {
	const d = new DER(b),
		root = d.seq();
	d.end();
	return root;
}
async function rsa(parts) {
	const [n, e, d, p, q, dp, dq, qi] = parts.map(trim);
	check(n.length >= 256 && n.length <= 1024 && e.length <= 8);
	const publicWire = concat(
		wireString(utf8.encode('ssh-rsa')),
		wireString(e[0] & 128 ? concat(Uint8Array.of(0), e) : e),
		wireString(n[0] & 128 ? concat(Uint8Array.of(0), n) : n),
	);
	const jwk = {
		kty: 'RSA',
		n: b64url(n),
		e: b64url(e),
		d: b64url(d),
		p: b64url(p),
		q: b64url(q),
		dp: b64url(dp),
		dq: b64url(dq),
		qi: b64url(qi),
		ext: false,
	};
	const key = await crypto.subtle.importKey(
		'jwk',
		jwk,
		{ name: 'RSA-OAEP', hash: 'SHA-256' },
		false,
		['decrypt'],
	);
	return { type: 'ssh-rsa', key, wire: publicWire };
}
async function ed(seed, expected) {
	check(seed.length === 32);
	const pub = ed25519.getPublicKey(seed);
	if (expected) check(equal(pub, expected));
	return {
		type: 'ssh-ed25519',
		secret: (await hash(seed, 'SHA-512')).slice(0, 32),
		wire: concat(wireString(utf8.encode('ssh-ed25519')), wireString(pub)),
	};
}
async function pkcs1(b) {
	const d = sequence(b);
	check(d.number() === 0);
	const parts = Array.from({ length: 8 }, () => d.integer());
	d.end();
	return rsa(parts);
}
async function pkcs8(b) {
	const d = sequence(b);
	check(d.number() === 0);
	const alg = d.seq(),
		oid = alg.oid();
	if (oid === '2a864886f70d010101') {
		check(alg.node(5).length === 0);
	}
	alg.end();
	const privateBytes = d.octet();
	d.end();
	if (oid === '2a864886f70d010101') return pkcs1(privateBytes);
	const inner = new DER(privateBytes);
	const seed = inner.octet();
	inner.end();
	check(seed.length === 32);
	if (oid === '2b6570') return ed(seed);
	check(oid === '2b656e');
	return { type: 'X25519', secret: seed.slice() };
}
async function encryptedPKCS8(bytes, password) {
	const d = sequence(bytes),
		alg = d.seq();
	check(alg.oid() === '2a864886f70d01050d');
	const params = alg.seq(),
		kdf = params.seq();
	check(kdf.oid() === '2a864886f70d01050c');
	const p = kdf.seq(),
		salt = p.octet();
	check(salt.length >= 8 && salt.length <= 1024);
	const rounds = p.number();
	let keyLength;
	if (p.w.bytes[p.w.pos] === 2) keyLength = p.number();
	let hashName = 'SHA-1';
	if (p.w.pos < p.w.bytes.length) {
		const prf = p.seq();
		const hashes = {
			'2a864886f70d0207': 'SHA-1',
			'2a864886f70d0209': 'SHA-256',
			'2a864886f70d020a': 'SHA-384',
			'2a864886f70d020b': 'SHA-512',
		};
		hashName = hashes[prf.oid()];
		check(hashName);
		if (prf.w.pos < prf.w.bytes.length) check(prf.node(5).length === 0);
		prf.end();
	}
	p.end();
	kdf.end();
	const scheme = params.seq(),
		lengths = { '608648016503040102': 16, '608648016503040116': 24, '60864801650304012a': 32 };
	const length = lengths[scheme.oid()];
	check(length && (!keyLength || keyLength === length));
	const iv = scheme.octet();
	check(iv.length === 16);
	scheme.end();
	params.end();
	alg.end();
	const encrypted = d.octet();
	d.end();
	const key = await pbkdf(password, salt, rounds, length, hashName);
	try {
		return await pkcs8(await aes('AES-CBC', key, iv, encrypted));
	} finally {
		key.fill(0);
	}
}
async function openssh(bytes, password) {
	const w = new Wire(bytes);
	check(text(w.read(15)) === 'openssh-key-v1\0');
	const cipher = text(w.string()),
		kdf = text(w.string()),
		opts = w.string();
	check(w.int() === 1);
	const publicWire = w.string();
	let privateBytes = w.string();
	w.end();
	let blockSize = 8;
	if (cipher !== 'none') {
		if (cipher !== 'aes256-ctr' || kdf !== 'bcrypt') throw new Error('UNSUPPORTED_KEY');
		const o = new Wire(opts),
			salt = o.string(),
			rounds = o.int();
		o.end();
		check(
			salt.length >= 16 &&
				salt.length <= 1024 &&
				rounds > 0 &&
				rounds <= 1024 &&
				privateBytes.length % 16 === 0,
		);
		const pass = utf8.encode(password),
			derived = new Uint8Array(48);
		check(
			pass.length > 0 &&
				bcrypt.pbkdf(pass, pass.length, salt, salt.length, derived, 48, rounds) === 0,
		);
		pass.fill(0);
		try {
			privateBytes = await aes(
				'AES-CTR',
				derived.subarray(0, 32),
				derived.subarray(32),
				privateBytes,
			);
		} finally {
			derived.fill(0);
		}
		blockSize = 16;
	} else check(kdf === 'none' && opts.length === 0 && privateBytes.length % 8 === 0);
	const p = new Wire(privateBytes);
	check(p.int() === p.int());
	const type = text(p.string());
	let result;
	if (type === 'ssh-ed25519') {
		const pub = p.string(),
			priv = p.string();
		check(pub.length === 32 && priv.length === 64 && equal(pub, priv.subarray(32)));
		result = await ed(priv.subarray(0, 32), pub);
	} else if (type === 'ssh-rsa') {
		const n = trim(p.string()),
			e = trim(p.string()),
			d = trim(p.string()),
			qi = trim(p.string()),
			primeP = trim(p.string()),
			primeQ = trim(p.string());
		result = await rsa([
			n,
			e,
			d,
			primeP,
			primeQ,
			fromBig(big(d) % (big(primeP) - 1n)),
			fromBig(big(d) % (big(primeQ) - 1n)),
			qi,
		]);
	} else throw new Error('UNSUPPORTED_KEY');
	p.string();
	const padding = p.read(privateBytes.length - p.pos);
	check(padding.length < blockSize && padding.every((v, i) => v === i + 1));
	check(equal(publicWire, result.wire));
	privateBytes.fill(0);
	return result;
}
async function parseIdentity(input, password) {
	const trimmed = input.trim();
	if (/^AGE-SECRET-KEY-/i.test(trimmed)) return { type: 'X25519', secret: nativeSecret(trimmed) };
	const match =
		/^-----BEGIN (OPENSSH PRIVATE KEY|ENCRYPTED PRIVATE KEY|PRIVATE KEY|RSA PRIVATE KEY)-----\r?\n([\s\S]+?)\r?\n-----END \1-----$/.exec(
			trimmed,
		);
	if (!match) throw new Error('UNSUPPORTED_KEY');
	let body = match[2];
	if (/^Proc-Type:/.test(body)) {
		const header =
			/^Proc-Type: 4,ENCRYPTED\r?\nDEK-Info: AES-(128|192|256)-CBC,([0-9A-Fa-f]{32})\r?\n\r?\n([\s\S]+)$/.exec(
				body,
			);
		if (!header || match[1] !== 'RSA PRIVATE KEY') throw new Error('UNSUPPORTED_KEY');
		const iv = Uint8Array.from(header[2].match(/../g), (h) => parseInt(h, 16));
		const pass = utf8.encode(password);
		let digest = empty,
			key = empty;
		while (key.length < Number(header[1]) / 8) {
			const word = CryptoJS.lib.WordArray.create(concat(digest, pass, iv.subarray(0, 8)));
			digest = Uint8Array.from(CryptoJS.MD5(word).toString().match(/../g), (h) => parseInt(h, 16));
			key = concat(key, digest);
		}
		pass.fill(0);
		try {
			return await pkcs1(
				await aes(
					'AES-CBC',
					key.subarray(0, Number(header[1]) / 8),
					iv,
					unbase64(header[3].replace(/\s/g, '')),
				),
			);
		} finally {
			key.fill(0);
			digest.fill(0);
		}
	}
	const b = unbase64(body.replace(/\s/g, ''));
	if (match[1] === 'OPENSSH PRIVATE KEY') return openssh(b, password);
	if (match[1] === 'ENCRYPTED PRIVATE KEY') return encryptedPKCS8(b, password);
	if (match[1] === 'RSA PRIVATE KEY') return pkcs1(b);
	return pkcs8(b);
}
export async function identities(input, password) {
	check(input.length <= 65536);
	const cleaned = input
		.split(/\r?\n/)
		.filter((l) => !/^\s*#/.test(l))
		.join('\n')
		.trim();
	if (/^AGE-SECRET-KEY-/i.test(cleaned)) {
		const lines = cleaned.split(/\s+/);
		check(lines.length <= 100);
		return Promise.all(lines.map((l) => parseIdentity(l, password)));
	}
	return [await parseIdentity(cleaned, password)];
}
export function publicX(secret) {
	return x25519.getPublicKey(secret);
}
export async function sharedX(secret, pub, portable = false) {
	check(secret.length === 32 && pub.length === 32);
	let shared;
	if (!portable) {
		try {
			const key = await crypto.subtle.importKey(
				'pkcs8',
				concat(Uint8Array.from([48, 46, 2, 1, 0, 48, 5, 6, 3, 43, 101, 110, 4, 34, 4, 32]), secret),
				{ name: 'X25519' },
				false,
				['deriveBits'],
			);
			const other = await crypto.subtle.importKey('raw', pub, { name: 'X25519' }, false, []);
			shared = new Uint8Array(
				await crypto.subtle.deriveBits({ name: 'X25519', public: other }, key, 256),
			);
		} catch {
			/* Browser support varies; use the bundled implementation. */
		}
	}
	if (!shared) shared = x25519.getSharedSecret(secret, pub);
	check(shared.some((v) => v !== 0));
	return shared;
}
