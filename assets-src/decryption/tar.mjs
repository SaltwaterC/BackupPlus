// Streaming tar reader: ustar, pax (extended headers) and GNU long names, which covers what BackupPlus, GNU tar,
// bsdtar and Python write. Bytes go in as they arrive; entries and their data come out through the handler.
const BLOCK = 512;
const META_LIMIT = 1048576;
const fail = () => {
	throw new Error('ARCHIVE_FAILED');
};
const decode = (bytes) => new TextDecoder().decode(bytes);

function cstring(block, start, length) {
	let end = start;
	while (end < start + length && block[end]) end++;
	return decode(block.subarray(start, end));
}

/** Octal text field, or the GNU base-256 form for sizes that do not fit. */
function number(block, start, length) {
	if (block[start] & 0x80) {
		if (block[start] & 0x40) fail();
		let value = block[start] & 0x3f;
		for (let i = 1; i < length; i++) value = value * 256 + block[start + i];
		if (!Number.isSafeInteger(value)) fail();
		return value;
	}
	let value = 0,
		digits = 0,
		i = start;
	const end = start + length;
	while (i < end && block[i] === 32) i++;
	for (; i < end && block[i] >= 48 && block[i] <= 55; i++, digits++) value = value * 8 + block[i] - 48;
	while (i < end && (block[i] === 32 || block[i] === 0)) i++;
	if (i !== end && digits) fail();
	return value;
}

function checksumValid(block) {
	let unsigned = 0,
		signed = 0;
	for (let i = 0; i < BLOCK; i++) {
		const byte = i >= 148 && i < 156 ? 32 : block[i];
		unsigned += byte;
		signed += byte > 127 ? byte - 256 : byte;
	}
	let stored = 0,
		i = 148;
	while (i < 156 && block[i] === 32) i++;
	for (; i < 156 && block[i] >= 48 && block[i] <= 55; i++) stored = stored * 8 + block[i] - 48;
	return stored === unsigned || stored === signed;
}

/** True when the bytes begin with a valid tar header and look like tar: a ustar magic, or a tar-like name. */
export function looksLikeTar(head, name = '') {
	if (head.length < BLOCK || !head[0] || !checksumValid(head)) return false;
	const magic = decode(head.subarray(257, 262));
	return magic === 'ustar' || /\.(tar|tgz|tzst)$/i.test(name);
}

function pax(bytes) {
	const records = {};
	let at = 0;
	while (at < bytes.length) {
		let space = at;
		while (space < bytes.length && bytes[space] !== 32) space++;
		const length = Number(decode(bytes.subarray(at, space)));
		if (!Number.isInteger(length) || length < 4 || at + length > bytes.length || bytes[at + length - 1] !== 10) fail();
		const record = decode(bytes.subarray(space + 1, at + length - 1)),
			equals = record.indexOf('=');
		if (equals < 1) fail();
		records[record.slice(0, equals)] = record.slice(equals + 1);
		at += length;
	}
	return records;
}

const HEADER = 1,
	DATA = 2,
	META = 3,
	SKIP = 4,
	PADDING = 5,
	TRAILER = 6;

/**
 * handler.file({path, size, mtime, mode}) returns {write(bytes), end()}; handler.directory({path, mtime, mode});
 * handler.skipped() is called for links, devices and other entries that have no content to extract.
 * Bytes passed to a file's write() are views into the pushed chunk and must be copied if they are kept.
 */
export class TarReader {
	constructor(handler) {
		this.handler = handler;
		this.state = HEADER;
		this.block = new Uint8Array(BLOCK);
		this.filled = 0;
		this.remaining = 0;
		this.padding = 0;
		this.next = {};
	}

	async write(chunk) {
		let at = 0;
		while (at < chunk.length) {
			if (this.state === TRAILER) {
				for (; at < chunk.length; at++) if (chunk[at]) fail();
			} else if (this.state === HEADER) {
				const take = Math.min(BLOCK - this.filled, chunk.length - at);
				this.block.set(chunk.subarray(at, at + take), this.filled);
				this.filled += take;
				at += take;
				if (this.filled === BLOCK) {
					this.filled = 0;
					await this.header();
				}
			} else if (this.state === PADDING) {
				const take = Math.min(this.padding, chunk.length - at);
				this.padding -= take;
				at += take;
				if (!this.padding) this.state = HEADER;
			} else {
				const take = Math.min(this.remaining, chunk.length - at);
				const bytes = chunk.subarray(at, at + take);
				at += take;
				this.remaining -= take;
				if (this.state === DATA) await this.consumer.write(bytes);
				else if (this.state === META) this.collected.push(bytes.slice());
				if (!this.remaining) await this.finishData();
			}
		}
	}

	/** The archive must end with its zero blocks; anything else is a truncated archive. */
	async end() {
		if (this.state !== TRAILER) fail();
	}

	async header() {
		const block = this.block;
		if (block.every((byte) => !byte)) {
			this.state = TRAILER;
			return;
		}
		if (!checksumValid(block)) fail();
		const type = String.fromCharCode(block[156] || 48);
		const size = number(block, 124, 12);
		if ('xgLK'.includes(type)) {
			if (size > META_LIMIT) fail();
			this.type = type;
			this.collected = [];
			await this.beginData(META, size);
			return;
		}
		const extended = this.next;
		this.next = {};
		const posix = decode(block.subarray(257, 263)) === 'ustar\0';
		const prefix = posix ? cstring(block, 345, 155) : '';
		let path = cstring(block, 0, 100);
		if (prefix) path = prefix + '/' + path;
		path = extended.path ?? path;
		const meta = {
			path,
			size: extended.size === undefined ? size : Number(extended.size),
			mtime: Math.floor(extended.mtime === undefined ? number(block, 136, 12) : Number(extended.mtime)),
			mode: number(block, 100, 8),
		};
		if (!Number.isSafeInteger(meta.size) || meta.size < 0 || !Number.isFinite(meta.mtime)) fail();
		if (type === '0' || type === '7') {
			if (type === '0' && path.endsWith('/')) await this.directory(meta, size);
			else {
				this.consumer = await this.handler.file(meta);
				await this.beginData(DATA, meta.size);
			}
		} else if (type === '5') await this.directory(meta, meta.size);
		else {
			if (type === 'S') fail();
			this.handler.skipped();
			await this.beginData(SKIP, meta.size);
		}
	}

	async directory(meta, size) {
		await this.handler.directory(meta);
		await this.beginData(SKIP, size);
	}

	async beginData(state, size) {
		this.state = state;
		this.remaining = size;
		this.padding = (BLOCK - (size % BLOCK)) % BLOCK;
		this.dataSize = size;
		if (!size) await this.finishData();
	}

	async finishData() {
		if (this.state === DATA) {
			const consumer = this.consumer;
			this.consumer = null;
			await consumer.end();
		} else if (this.state === META) {
			const bytes = new Uint8Array(this.dataSize);
			let at = 0;
			for (const part of this.collected) {
				bytes.set(part, at);
				at += part.length;
			}
			this.collected = null;
			if (this.type === 'x') Object.assign(this.next, pax(bytes));
			else if (this.type === 'L') this.next.path = cstring(bytes, 0, bytes.length);
			// Global headers and long link names only describe attributes that are not extracted.
		}
		this.state = this.padding ? PADDING : HEADER;
	}
}
