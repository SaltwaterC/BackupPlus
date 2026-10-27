// Slicing-by-8 CRC-32 (IEEE 802.3), as used by zip.
const table = new Uint32Array(2048);
for (let n = 0; n < 256; n++) {
	let c = n;
	for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	table[n] = c;
}
for (let n = 0; n < 256; n++)
	for (let k = 1; k < 8; k++) {
		const previous = table[(k - 1) * 256 + n];
		table[k * 256 + n] = (previous >>> 8) ^ table[previous & 255];
	}

/** Continue a CRC with more bytes; the first call starts from 0. */
export function crc32(bytes, previous = 0) {
	let c = ~previous;
	let i = 0;
	const end = bytes.length - 7;
	for (; i < end; i += 8) {
		const a = c ^ (bytes[i] | (bytes[i + 1] << 8) | (bytes[i + 2] << 16) | (bytes[i + 3] << 24));
		c =
			table[1792 + (a & 255)] ^
			table[1536 + ((a >>> 8) & 255)] ^
			table[1280 + ((a >>> 16) & 255)] ^
			table[1024 + (a >>> 24)] ^
			table[768 + bytes[i + 4]] ^
			table[512 + bytes[i + 5]] ^
			table[256 + bytes[i + 6]] ^
			table[bytes[i + 7]];
	}
	for (; i < bytes.length; i++) c = table[(c ^ bytes[i]) & 255] ^ (c >>> 8);
	return ~c >>> 0;
}
