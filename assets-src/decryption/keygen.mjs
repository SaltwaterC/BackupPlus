import { x25519 } from '@noble/curves/ed25519.js';

// age uses the original Bech32 checksum (not Bech32m), with 32-byte key material.
function encodeKey(hrp, bytes) {
	const alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	const words = [];
	let acc = 0,
		bits = 0;
	for (const byte of bytes) {
		acc = ((acc << 8) | byte) & 0xffff;
		bits += 8;
		while (bits >= 5) {
			bits -= 5;
			words.push((acc >>> bits) & 31);
		}
	}
	if (bits) words.push((acc << (5 - bits)) & 31);
	const values = [
		...Array.from(hrp, (c) => c.charCodeAt(0) >>> 5),
		0,
		...Array.from(hrp, (c) => c.charCodeAt(0) & 31),
		...words,
		0,
		0,
		0,
		0,
		0,
		0,
	];
	let checksum = 1;
	for (const value of values) {
		const top = checksum >>> 25;
		checksum = ((checksum & 0x1ffffff) << 5) ^ value;
		[0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3].forEach((g, i) => {
			if ((top >>> i) & 1) checksum ^= g;
		});
	}
	checksum ^= 1;
	for (let i = 0; i < 6; i++) words.push((checksum >>> (5 * (5 - i))) & 31);
	return hrp + '1' + words.map((word) => alphabet[word]).join('');
}

/** Generate locally with Web Crypto's CSPRNG; no alternative random source is permitted. */
export function generateAgeIdentity() {
	const secret = new Uint8Array(32);
	try {
		crypto.getRandomValues(secret);
		return {
			identity: encodeKey('age-secret-key-', secret).toUpperCase(),
			recipient: encodeKey('age', x25519.getPublicKey(secret)),
		};
	} finally {
		secret.fill(0);
	}
}
