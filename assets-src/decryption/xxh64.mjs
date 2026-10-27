// Streaming XXH64 (seed 0) on pairs of 32-bit halves, so that it needs no BigInt. Zstandard's frame content checksum is
// the low 32 bits of the XXH64 of the decompressed frame.
const P1H = 0x9e3779b1,
	P1L = 0x85ebca87,
	P2H = 0xc2b2ae3d,
	P2L = 0x27d4eb4f,
	P3H = 0x165667b1,
	P3L = 0x9e3779f9,
	P4H = 0x85ebca77,
	P4L = 0xc2b2ae63,
	P5H = 0x27d4eb2f,
	P5L = 0x165667c5;

// The result registers of the 64-bit helpers: they return through these to avoid allocating.
let H = 0,
	L = 0;

/** (ah:al) * (bh:bl) modulo 2^64. */
function mul(ah, al, bh, bl) {
	const a0 = al & 0xffff,
		a1 = al >>> 16,
		b0 = bl & 0xffff,
		b1 = bl >>> 16;
	let t = a0 * b0;
	const w0 = t & 0xffff;
	t = a1 * b0 + (t >>> 16);
	let w1 = t & 0xffff;
	const w2 = t >>> 16;
	t = a0 * b1 + w1;
	w1 = t & 0xffff;
	L = ((w1 << 16) | w0) >>> 0;
	H = (a1 * b1 + w2 + (t >>> 16) + Math.imul(ah, bl) + Math.imul(al, bh)) >>> 0;
}
function add(ah, al, bh, bl) {
	const low = al + bl;
	L = low >>> 0;
	H = (ah + bh + (low > 0xffffffff ? 1 : 0)) >>> 0;
}
/** Rotate left by n, 0 < n < 32. */
function rotl(h, l, n) {
	H = ((h << n) | (l >>> (32 - n))) >>> 0;
	L = ((l << n) | (h >>> (32 - n))) >>> 0;
}

/** acc = rotl(acc + lane * P2, 31) * P1, stored in v[at], v[at + 1]. */
function round(v, at, laneH, laneL) {
	mul(laneH, laneL, P2H, P2L);
	add(v[at], v[at + 1], H, L);
	rotl(H, L, 31);
	mul(H, L, P1H, P1L);
	v[at] = H;
	v[at + 1] = L;
}

export class XXH64 {
	constructor() {
		this.v = new Uint32Array(8);
		this.buffer = new Uint8Array(32);
		this.reset();
	}

	reset() {
		const v = this.v;
		add(P1H, P1L, P2H, P2L);
		v[0] = H;
		v[1] = L;
		v[2] = P2H;
		v[3] = P2L;
		v[4] = 0;
		v[5] = 0;
		// seed - P1 modulo 2^64
		v[6] = (0 - P1H - (P1L ? 1 : 0)) >>> 0;
		v[7] = (0 - P1L) >>> 0;
		this.length = 0;
		this.filled = 0;
	}

	#stripe(bytes, at) {
		const v = this.v;
		for (let lane = 0; lane < 4; lane++, at += 8)
			round(
				v,
				lane * 2,
				(bytes[at + 4] | (bytes[at + 5] << 8) | (bytes[at + 6] << 16) | (bytes[at + 7] << 24)) >>> 0,
				(bytes[at] | (bytes[at + 1] << 8) | (bytes[at + 2] << 16) | (bytes[at + 3] << 24)) >>> 0,
			);
	}

	update(bytes) {
		this.length += bytes.length;
		let at = 0;
		if (this.filled) {
			const take = Math.min(32 - this.filled, bytes.length);
			this.buffer.set(bytes.subarray(0, take), this.filled);
			this.filled += take;
			at = take;
			if (this.filled < 32) return;
			this.#stripe(this.buffer, 0);
			this.filled = 0;
		}
		for (; at + 32 <= bytes.length; at += 32) this.#stripe(bytes, at);
		if (at < bytes.length) {
			this.buffer.set(bytes.subarray(at), 0);
			this.filled = bytes.length - at;
		}
	}

	/** The low 32 bits of the hash, which is what a zstd frame stores. */
	digestLow32() {
		const v = this.v,
			data = this.buffer;
		let h, l;
		if (this.length >= 32) {
			rotl(v[0], v[1], 1);
			let ah = H,
				al = L;
			for (const [i, n] of [
				[2, 7],
				[4, 12],
				[6, 18],
			]) {
				rotl(v[i], v[i + 1], n);
				add(ah, al, H, L);
				ah = H;
				al = L;
			}
			// merge each accumulator: h = (h ^ round(0, v)) * P1 + P4
			for (let i = 0; i < 8; i += 2) {
				mul(v[i], v[i + 1], P2H, P2L);
				rotl(H, L, 31);
				mul(H, L, P1H, P1L);
				mul(ah ^ H, al ^ L, P1H, P1L);
				add(H, L, P4H, P4L);
				ah = H;
				al = L;
			}
			h = ah;
			l = al;
		} else {
			h = P5H;
			l = P5L;
		}
		// total length is below 2^53, which the two halves hold
		add(h, l, Math.floor(this.length / 4294967296) >>> 0, this.length >>> 0);
		h = H;
		l = L;
		let at = 0;
		for (; at + 8 <= this.filled; at += 8) {
			mul(
				(data[at + 4] | (data[at + 5] << 8) | (data[at + 6] << 16) | (data[at + 7] << 24)) >>> 0,
				(data[at] | (data[at + 1] << 8) | (data[at + 2] << 16) | (data[at + 3] << 24)) >>> 0,
				P2H,
				P2L,
			);
			rotl(H, L, 31);
			mul(H, L, P1H, P1L);
			rotl(h ^ H, l ^ L, 27);
			mul(H, L, P1H, P1L);
			add(H, L, P4H, P4L);
			h = H;
			l = L;
		}
		if (at + 4 <= this.filled) {
			mul(0, (data[at] | (data[at + 1] << 8) | (data[at + 2] << 16) | (data[at + 3] << 24)) >>> 0, P1H, P1L);
			rotl(h ^ H, l ^ L, 23);
			mul(H, L, P2H, P2L);
			add(H, L, P3H, P3L);
			h = H;
			l = L;
			at += 4;
		}
		for (; at < this.filled; at++) {
			mul(0, data[at], P5H, P5L);
			rotl(h ^ H, l ^ L, 11);
			mul(H, L, P1H, P1L);
			h = H;
			l = L;
		}
		// avalanche: h ^= h >> 33; h *= P2; h ^= h >> 29; h *= P3; h ^= h >> 32; only the low half is returned
		l = (l ^ (h >>> 1)) >>> 0;
		mul(h, l, P2H, P2L);
		h = H;
		l = L;
		const shiftedLow = ((l >>> 29) | (h << 3)) >>> 0;
		h = (h ^ (h >>> 29)) >>> 0;
		l = (l ^ shiftedLow) >>> 0;
		mul(h, l, P3H, P3L);
		h = H;
		l = L;
		l = (l ^ h) >>> 0;
		return l;
	}
}
