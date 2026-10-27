// fzstd with the one thing it skips: verifying each frame's content checksum.
// The compressed stream is walked frame by frame (headers and block sizes only, the block bodies are skipped) to learn
// how many blocks each frame has and what checksum it ends with. fzstd emits exactly one chunk per block, so once a
// frame's last block has been emitted, the XXH64 of its output can be compared with that checksum.
import { Decompress } from 'fzstd';
import { XXH64 } from './xxh64.mjs';

const fail = () => {
	throw new Error('ARCHIVE_FAILED');
};
const MAGIC = 0xfd2fb528;
const DICTIONARY_BYTES = [0, 1, 2, 4];
const CONTENT_SIZE_BYTES = [0, 2, 4, 8];

const STATE = {
	MAGIC: 0,
	SKIP_SIZE: 1,
	SKIP: 2,
	DESCRIPTOR: 3,
	HEADER: 4,
	BLOCK_HEADER: 5,
	BLOCK: 6,
	CHECKSUM: 7,
};

/** Tracks where frames end in a compressed stream that arrives in arbitrary pieces. */
class FrameScanner {
	constructor() {
		this.state = STATE.MAGIC;
		this.need = 4;
		this.field = new Uint8Array(4);
		this.have = 0;
		this.checksum = false;
		this.last = false;
		this.blocks = 0;
		this.frames = [];
	}

	/** Between frames, with nothing of the next one seen yet. */
	get idle() {
		return this.state === STATE.MAGIC && !this.have;
	}

	#frameEnd(expected) {
		this.frames.push({ blocks: this.blocks, expected });
		this.blocks = 0;
		this.state = STATE.MAGIC;
		this.need = 4;
		this.have = 0;
	}

	/** Collect a small little-endian field; returns its value once complete, otherwise -1. */
	#collect(chunk, at, length) {
		const take = Math.min(length - this.have, chunk.length - at);
		this.field.set(chunk.subarray(at, at + take), this.have);
		this.have += take;
		this.consumed = take;
		if (this.have < length) return -1;
		this.have = 0;
		let value = 0;
		for (let i = length - 1; i >= 0; i--) value = value * 256 + this.field[i];
		return value;
	}

	/** Walk the chunk; frames that end in it are queued in this.frames as {blocks, expected} (expected is null without a checksum). */
	scan(chunk) {
		let at = 0;
		while (at < chunk.length) {
			switch (this.state) {
				case STATE.MAGIC: {
					const magic = this.#collect(chunk, at, 4);
					at += this.consumed;
					if (magic < 0) break;
					if (magic === MAGIC) this.state = STATE.DESCRIPTOR;
					else if ((magic & 0xfffffff0) >>> 0 === 0x184d2a50) this.state = STATE.SKIP_SIZE;
					else fail();
					break;
				}
				case STATE.SKIP_SIZE: {
					const size = this.#collect(chunk, at, 4);
					at += this.consumed;
					if (size < 0) break;
					this.need = size;
					if (size) this.state = STATE.SKIP;
					else this.#frameEnd(null);
					break;
				}
				case STATE.SKIP:
				case STATE.HEADER:
				case STATE.BLOCK: {
					const take = Math.min(this.need, chunk.length - at);
					at += take;
					this.need -= take;
					if (this.need) break;
					if (this.state === STATE.SKIP) this.#frameEnd(null);
					else if (this.state === STATE.HEADER) this.state = STATE.BLOCK_HEADER;
					else if (!this.last) this.state = STATE.BLOCK_HEADER;
					else if (this.checksum) this.state = STATE.CHECKSUM;
					else this.#frameEnd(null);
					break;
				}
				case STATE.DESCRIPTOR: {
					const descriptor = chunk[at++];
					const single = (descriptor >> 5) & 1,
						contentSize = descriptor >> 6;
					if (descriptor & 8) fail();
					this.checksum = Boolean((descriptor >> 2) & 1);
					this.need =
						(single ? 0 : 1) +
						DICTIONARY_BYTES[descriptor & 3] +
						(contentSize ? CONTENT_SIZE_BYTES[contentSize] : single);
					this.state = this.need ? STATE.HEADER : STATE.BLOCK_HEADER;
					break;
				}
				case STATE.BLOCK_HEADER: {
					const header = this.#collect(chunk, at, 3);
					at += this.consumed;
					if (header < 0) break;
					const type = (header >> 1) & 3;
					if (type === 3) fail();
					this.blocks++;
					this.last = Boolean(header & 1);
					// A run-length block stores one byte; the others store their size.
					this.need = type === 1 ? 1 : header >>> 3;
					this.state = STATE.BLOCK;
					if (!this.need) {
						if (!this.last) this.state = STATE.BLOCK_HEADER;
						else if (this.checksum) this.state = STATE.CHECKSUM;
						else this.#frameEnd(null);
					}
					break;
				}
				case STATE.CHECKSUM: {
					const checksum = this.#collect(chunk, at, 4);
					at += this.consumed;
					if (checksum >= 0) this.#frameEnd(checksum);
					break;
				}
			}
		}
	}
}

/**
 * Streaming Zstandard decoder. push(bytes, final) emits decoded chunks through onData and throws ARCHIVE_FAILED for a
 * damaged frame, a content checksum mismatch or a truncated stream. Bytes pushed are retained by fzstd until it has
 * a whole block, so callers must hand over a buffer they will not change.
 */
export class ZstdStream {
	constructor(onData) {
		this.hash = new XXH64();
		this.scanner = new FrameScanner();
		this.emitted = 0;
		this.decoder = new Decompress((chunk, final) => {
			// fzstd ends an input that stops between frames with one empty chunk flagged final; it is no block.
			if (final) return;
			this.hash.update(chunk);
			this.emitted++;
			this.#settle();
			onData(chunk);
		});
	}

	/** Verify every queued frame whose blocks have all been emitted. */
	#settle() {
		const frames = this.scanner.frames;
		while (frames.length && frames[0].blocks === this.emitted) {
			const { expected } = frames.shift();
			if (expected !== null && this.hash.digestLow32() !== expected) fail();
			this.hash.reset();
			this.emitted = 0;
		}
	}

	push(bytes, final = false) {
		try {
			this.scanner.scan(bytes);
			this.#settle();
			this.decoder.push(bytes);
			if (final) {
				this.decoder.push(new Uint8Array(0), true);
				if (!this.scanner.idle || this.scanner.frames.length) fail();
			}
		} catch (error) {
			// fzstd's own errors carry a numeric code; everything else is ours or the consumer's and stays as it is.
			if (typeof error?.code === 'number') fail();
			throw error;
		}
	}
}
