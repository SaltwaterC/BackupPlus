// Minimal zip support for local extraction: a store-only streaming writer and a central-directory reader.
// Both handle zip64, so neither the number of entries nor the size of an entry is limited to 4 GiB / 65535.
import { utf8 } from './bytes.mjs';
import { crc32 } from './crc32.mjs';

const SMALL_ENTRY = 1048576;
const MAX_DIRECTORY = 536870912;
const U32 = 0xffffffff;
const fail = () => {
	throw new Error('ARCHIVE_FAILED');
};

/** Normalise an archive member name; anything that could leave the extraction directory is refused. */
export function safePath(name) {
	if (name.includes('\0')) fail();
	const parts = [];
	for (const part of name.split('/')) {
		if (part === '..') fail();
		if (part !== '' && part !== '.') parts.push(part);
	}
	return parts.join('/');
}

function put64(view, offset, value) {
	view.setUint32(offset, value % 4294967296, true);
	view.setUint32(offset + 4, Math.floor(value / 4294967296), true);
}
function get64(view, offset) {
	const value = view.getUint32(offset, true) + view.getUint32(offset + 4, true) * 4294967296;
	if (!Number.isSafeInteger(value)) fail();
	return value;
}
function concat(parts, length) {
	const out = new Uint8Array(length);
	let offset = 0;
	for (const part of parts) {
		out.set(part, offset);
		offset += part.length;
	}
	return out;
}
function dosTime(seconds) {
	const date = new Date(Math.min(Math.max(seconds, 315532800), 4354819198) * 1000);
	return {
		time: (date.getUTCHours() << 11) | (date.getUTCMinutes() << 5) | (date.getUTCSeconds() >> 1),
		date: ((date.getUTCFullYear() - 1980) << 9) | ((date.getUTCMonth() + 1) << 5) | date.getUTCDate(),
	};
}

/** Extended timestamp (UTC) so that readers do not have to guess the time zone of the DOS fields. */
function timestampExtra(mtime) {
	const extra = new Uint8Array(9);
	const view = new DataView(extra.buffer);
	view.setUint16(0, 0x5455, true);
	view.setUint16(2, 5, true);
	extra[4] = 1;
	view.setUint32(5, Math.min(Math.max(mtime, 0), U32), true);
	return extra;
}

function localHeader(entry, crc, size, zip64) {
	const extra = concat(
		[zip64 ? new Uint8Array(20) : new Uint8Array(0), timestampExtra(entry.mtime)],
		(zip64 ? 20 : 0) + 9,
	);
	const header = new Uint8Array(30 + entry.name.length + extra.length);
	const view = new DataView(header.buffer);
	view.setUint32(0, 0x04034b50, true);
	view.setUint16(4, zip64 ? 45 : 20, true);
	view.setUint16(6, 0x0800, true);
	view.setUint16(8, 0, true);
	view.setUint16(10, entry.time, true);
	view.setUint16(12, entry.date, true);
	view.setUint32(14, crc, true);
	view.setUint32(18, Math.min(size, U32), true);
	view.setUint32(22, Math.min(size, U32), true);
	view.setUint16(26, entry.name.length, true);
	view.setUint16(28, extra.length, true);
	header.set(entry.name, 30);
	header.set(extra, 30 + entry.name.length);
	if (zip64) {
		const base = 30 + entry.name.length;
		view.setUint16(base, 1, true);
		view.setUint16(base + 2, 16, true);
		put64(view, base + 4, size);
		put64(view, base + 12, size);
	}
	return header;
}

function centralRecord(entry) {
	const big = entry.size >= U32,
		farOffset = entry.offset >= U32;
	const zip64Length = (big ? 16 : 0) + (farOffset ? 8 : 0);
	const extraLength = (zip64Length ? zip64Length + 4 : 0) + 9;
	const record = new Uint8Array(46 + entry.name.length + extraLength);
	const view = new DataView(record.buffer);
	view.setUint32(0, 0x02014b50, true);
	view.setUint16(4, (3 << 8) | 45, true);
	view.setUint16(6, big || farOffset ? 45 : 20, true);
	view.setUint16(8, 0x0800, true);
	view.setUint16(10, 0, true);
	view.setUint16(12, entry.time, true);
	view.setUint16(14, entry.date, true);
	view.setUint32(16, entry.crc, true);
	view.setUint32(20, Math.min(entry.size, U32), true);
	view.setUint32(24, Math.min(entry.size, U32), true);
	view.setUint16(28, entry.name.length, true);
	view.setUint16(30, extraLength, true);
	view.setUint32(38, entry.attributes, true);
	view.setUint32(42, Math.min(entry.offset, U32), true);
	record.set(entry.name, 46);
	let at = 46 + entry.name.length;
	if (zip64Length) {
		view.setUint16(at, 1, true);
		view.setUint16(at + 2, zip64Length, true);
		at += 4;
		if (big) {
			put64(view, at, entry.size);
			put64(view, at + 8, entry.size);
			at += 16;
		}
		if (farOffset) put64(view, at, entry.offset);
		at += farOffset ? 8 : 0;
	}
	record.set(timestampExtra(entry.mtime), at);
	return record;
}

/**
 * Writes a store-only zip to a sink with write(bytes), position and patch(position, bytes).
 * Entries of at most 1 MiB with a known size are buffered and written in one piece with their final header. Larger
 * or unsized entries stream their data and patch the header afterwards, which needs no data descriptors, so
 * every common unzip tool (and the macOS Archive Utility) reads the result.
 */
export class ZipWriter {
	constructor(sink) {
		this.sink = sink;
		this.records = [];
		this.files = [];
		this.open = false;
	}

	#entry(path, options) {
		const name = utf8.encode(path);
		if (!name.length || name.length > 65535) fail();
		const mtime = Math.floor(options.mtime ?? Date.now() / 1000);
		return { name, mtime, ...dosTime(mtime), offset: this.sink.position, crc: 0, size: 0, attributes: 0 };
	}

	async addDirectory(path, options = {}) {
		if (this.open) fail();
		const entry = this.#entry(path.replace(/\/*$/, '/'), options);
		entry.attributes = (((0o40000 | ((options.mode ?? 0o755) & 0o777)) << 16) | 0x10) >>> 0;
		await this.sink.write(localHeader(entry, 0, 0, false));
		this.records.push(centralRecord(entry));
	}

	/** Returns {write(bytes), end()}; the bytes handed to write() are copied when they are retained. */
	async addFile(path, options = {}) {
		if (this.open) fail();
		const entry = this.#entry(path, options);
		const known = Number.isSafeInteger(options.size) && options.size >= 0;
		entry.attributes = ((0o100000 | ((options.mode ?? 0o644) & 0o777)) << 16) >>> 0;
		this.open = true;
		let crc = 0,
			total = 0,
			chunks = [],
			finished = false;
		const small = known && options.size <= SMALL_ENTRY;
		let headerLength = 0;
		if (!small) {
			const header = localHeader(entry, 0, 0, true);
			headerLength = header.length;
			await this.sink.write(header);
		}
		const record = () => {
			entry.crc = crc;
			entry.size = total;
			this.records.push(centralRecord(entry));
			this.files.push({ path, dataOffset: entry.offset + headerLength, size: total });
			this.open = false;
		};
		return {
			write: async (bytes) => {
				if (finished) fail();
				total += bytes.length;
				if (known && total > options.size) fail();
				if (small) chunks.push(bytes.slice());
				else {
					crc = crc32(bytes, crc);
					await this.sink.write(bytes);
				}
			},
			end: async () => {
				if (finished || (known && total !== options.size)) fail();
				finished = true;
				if (small) {
					const data = concat(chunks, total);
					chunks = [];
					crc = crc32(data);
					const header = localHeader(entry, crc, total, false);
					headerLength = header.length;
					await this.sink.write(concat([header, data], header.length + total));
				} else {
					// The header is rewritten in full: crc, sizes and the zip64 extra field carry their final values.
					const header = localHeader(entry, crc, total, true);
					await this.sink.patch(entry.offset, header);
				}
				record();
				return { crc, size: total };
			},
		};
	}

	async finish() {
		if (this.open) fail();
		const offset = this.sink.position;
		let size = 0,
			batch = [],
			batchLength = 0;
		for (const record of this.records) {
			batch.push(record);
			batchLength += record.length;
			size += record.length;
			if (batchLength >= 1048576) {
				await this.sink.write(concat(batch, batchLength));
				batch = [];
				batchLength = 0;
			}
		}
		const count = this.records.length;
		const zip64 = count >= 0xffff || size >= U32 || offset >= U32;
		const tail = new Uint8Array((zip64 ? 56 + 20 : 0) + 22);
		const view = new DataView(tail.buffer);
		let at = 0;
		if (zip64) {
			view.setUint32(0, 0x06064b50, true);
			put64(view, 4, 44);
			view.setUint16(12, (3 << 8) | 45, true);
			view.setUint16(14, 45, true);
			put64(view, 24, count);
			put64(view, 32, count);
			put64(view, 40, size);
			put64(view, 48, offset);
			view.setUint32(56, 0x07064b50, true);
			put64(view, 64, offset + size);
			view.setUint32(72, 1, true);
			at = 76;
		}
		view.setUint32(at, 0x06054b50, true);
		view.setUint16(at + 8, Math.min(count, 0xffff), true);
		view.setUint16(at + 10, Math.min(count, 0xffff), true);
		view.setUint32(at + 12, Math.min(size, U32), true);
		view.setUint32(at + 16, Math.min(offset, U32), true);
		await this.sink.write(concat([...batch, tail], batchLength + tail.length));
	}
}

async function readAt(file, offset, length) {
	if (offset < 0 || offset + length > file.size) fail();
	const bytes = new Uint8Array(await file.slice(offset, offset + length).arrayBuffer());
	if (bytes.length !== length) fail();
	return bytes;
}

/** Entries of a zip from its central directory; the file must provide size and slice(). */
export async function readZip(file) {
	const tail = await readAt(file, Math.max(0, file.size - 65557), Math.min(file.size, 65557));
	const tailView = new DataView(tail.buffer);
	let eocd = -1;
	for (let i = tail.length - 22; i >= 0; i--)
		if (tailView.getUint32(i, true) === 0x06054b50 && i + 22 + tailView.getUint16(i + 20, true) === tail.length) {
			eocd = i;
			break;
		}
	if (eocd < 0 || tailView.getUint16(eocd + 4, true) || tailView.getUint16(eocd + 6, true)) fail();
	let count = tailView.getUint16(eocd + 10, true),
		size = tailView.getUint32(eocd + 12, true),
		offset = tailView.getUint32(eocd + 16, true);
	if (count === 0xffff || size === U32 || offset === U32) {
		const absolute = file.size - tail.length + eocd;
		const locator = new DataView((await readAt(file, absolute - 20, 20)).buffer);
		if (locator.getUint32(0, true) !== 0x07064b50) fail();
		const record = new DataView((await readAt(file, get64(locator, 8), 56)).buffer);
		if (record.getUint32(0, true) !== 0x06064b50) fail();
		count = get64(record, 32);
		size = get64(record, 40);
		offset = get64(record, 48);
	}
	if (size > MAX_DIRECTORY || count * 46 > size) fail();
	const directory = await readAt(file, offset, size);
	const view = new DataView(directory.buffer);
	const entries = [];
	let at = 0;
	for (let n = 0; n < count; n++) {
		if (at + 46 > size || view.getUint32(at, true) !== 0x02014b50) fail();
		const flags = view.getUint16(at + 8, true);
		const nameLength = view.getUint16(at + 28, true),
			extraLength = view.getUint16(at + 30, true),
			commentLength = view.getUint16(at + 32, true);
		const next = at + 46 + nameLength + extraLength + commentLength;
		if (next > size) fail();
		const entry = {
			name: new TextDecoder().decode(directory.subarray(at + 46, at + 46 + nameLength)),
			flags,
			madeBy: view.getUint16(at + 4, true),
			method: view.getUint16(at + 10, true),
			crc: view.getUint32(at + 16, true),
			compressedSize: view.getUint32(at + 20, true),
			size: view.getUint32(at + 24, true),
			offset: view.getUint32(at + 42, true),
			attributes: view.getUint32(at + 38, true),
			mtime: 0,
		};
		const { time, date } = { time: view.getUint16(at + 12, true), date: view.getUint16(at + 14, true) };
		entry.mtime =
			Date.UTC(
				1980 + (date >> 9),
				Math.max(0, ((date >> 5) & 15) - 1),
				Math.max(1, date & 31),
				time >> 11,
				(time >> 5) & 63,
				(time & 31) * 2,
			) / 1000;
		for (let extra = at + 46 + nameLength; extra + 4 <= at + 46 + nameLength + extraLength; ) {
			const id = view.getUint16(extra, true),
				length = view.getUint16(extra + 2, true);
			let field = extra + 4;
			if (field + length > next) fail();
			if (id === 1) {
				if (entry.size === U32) {
					entry.size = get64(view, field);
					field += 8;
				}
				if (entry.compressedSize === U32) {
					entry.compressedSize = get64(view, field);
					field += 8;
				}
				if (entry.offset === U32) entry.offset = get64(view, field);
			} else if (id === 0x5455 && length >= 5 && directory[field] & 1) entry.mtime = view.getUint32(field + 1, true);
			extra += 4 + length;
		}
		entry.isDirectory = entry.name.endsWith('/');
		entries.push(entry);
		at = next;
	}
	return entries;
}

/** A ReadableStream over file.slice(start, end) in bounded reads. */
export function sliceStream(file, start, end) {
	let offset = start;
	return new ReadableStream({
		async pull(controller) {
			if (offset >= end) return controller.close();
			const bytes = new Uint8Array(await file.slice(offset, Math.min(end, offset + 1048576)).arrayBuffer());
			if (!bytes.length) fail();
			offset += bytes.length;
			controller.enqueue(bytes);
		},
	});
}

/** Locate an entry's data; returns the uncompressed stream and, when the entry is stored, a seekable view of it. */
export async function openEntry(file, entry) {
	if (entry.flags & 0x2041 || (entry.method !== 0 && entry.method !== 8)) fail();
	const local = new DataView((await readAt(file, entry.offset, 30)).buffer);
	if (local.getUint32(0, true) !== 0x04034b50) fail();
	const start = entry.offset + 30 + local.getUint16(26, true) + local.getUint16(28, true);
	const end = start + entry.compressedSize;
	if (end > file.size || (entry.method === 0 && entry.compressedSize !== entry.size)) fail();
	return {
		stream: () =>
			entry.method === 0
				? sliceStream(file, start, end)
				: sliceStream(file, start, end).pipeThrough(new DecompressionStream('deflate-raw')),
		view: () =>
			entry.method === 0
				? { size: entry.size, slice: (from, to) => file.slice(start + from, start + to) }
				: null,
	};
}
