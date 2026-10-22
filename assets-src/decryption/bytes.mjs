export const utf8 = new TextEncoder();
export const text = (b) => new TextDecoder('utf-8', { fatal: true }).decode(b);
export const concat = (...parts) => {
	const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
	let i = 0;
	for (const p of parts) {
		out.set(p, i);
		i += p.length;
	}
	return out;
};
export function equal(a, b) {
	let d = a.length ^ b.length;
	for (let i = 0; i < a.length; i++) d |= a[i] ^ b[i];
	return d === 0;
}
export function check(condition) {
	if (!condition) throw new Error('DECRYPT_FAILED');
}
export function unbase64(s, raw = false) {
	check(
		typeof s === 'string' &&
			s.length <= 100000 &&
			(raw ? /^[A-Za-z0-9+/]*$/.test(s) && s.length % 4 !== 1 : /^[A-Za-z0-9+/]*={0,2}$/.test(s)),
	);
	const b = Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
	if (raw) check(base64(b).replace(/=+$/, '') === s);
	return b;
}
export function base64(b) {
	let s = '';
	for (let i = 0; i < b.length; i += 8192) s += String.fromCharCode(...b.subarray(i, i + 8192));
	return btoa(s);
}
export const b64url = (b) => base64(b).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
export const uint32 = (n) => {
	const b = new Uint8Array(4);
	new DataView(b.buffer).setUint32(0, n);
	return b;
};
export async function hash(b, alg = 'SHA-256') {
	return new Uint8Array(await crypto.subtle.digest(alg, b));
}
export async function hkdf(key, salt, info) {
	const k = await crypto.subtle.importKey('raw', key, 'HKDF', false, ['deriveBits']);
	return new Uint8Array(
		await crypto.subtle.deriveBits(
			{
				name: 'HKDF',
				hash: 'SHA-256',
				salt,
				info: typeof info === 'string' ? utf8.encode(info) : info,
			},
			k,
			256,
		),
	);
}
export async function hmac(key, data) {
	const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, [
		'sign',
	]);
	return new Uint8Array(await crypto.subtle.sign('HMAC', k, data));
}
export async function pbkdf(password, salt, iterations, length, hashName = 'SHA-256') {
	check(Number.isSafeInteger(iterations) && iterations > 0 && iterations <= 10000000);
	const k = await crypto.subtle.importKey('raw', utf8.encode(password), 'PBKDF2', false, [
		'deriveBits',
	]);
	return new Uint8Array(
		await crypto.subtle.deriveBits(
			{ name: 'PBKDF2', hash: hashName, salt, iterations },
			k,
			length * 8,
		),
	);
}
export async function aes(mode, key, iv, bytes) {
	const k = await crypto.subtle.importKey('raw', key, mode, false, ['decrypt']);
	return new Uint8Array(
		await crypto.subtle.decrypt({ name: mode, iv, counter: iv, length: 128 }, k, bytes),
	);
}
export class Wire {
	constructor(bytes) {
		this.bytes = bytes;
		this.pos = 0;
	}
	read(n) {
		check(Number.isSafeInteger(n) && n >= 0 && this.pos + n <= this.bytes.length);
		const b = this.bytes.subarray(this.pos, this.pos + n);
		this.pos += n;
		return b;
	}
	int() {
		return new DataView(this.read(4).slice().buffer).getUint32(0);
	}
	string() {
		return this.read(this.int());
	}
	end() {
		check(this.pos === this.bytes.length);
	}
}
export const wireString = (b) => concat(uint32(b.length), b);
