// Chained local extraction: decrypt, decompress and untar in one pass, into one output file.
// The output is the plain file when the chain ends in a single file, otherwise a store-only zip.
import { decryptArchive } from './archive.mjs';
import { crc32 } from './crc32.mjs';
import { TarReader, looksLikeTar } from './tar.mjs';
import { ZipWriter, readZip, openEntry, safePath } from './zip.mjs';
import { ZstdStream } from './zstd.mjs';
import { equal, utf8 } from './bytes.mjs';

const MAX_DEPTH = 4;
const ENCRYPTED = /\.(crypt|age)$/i;
const KNOWN_ERRORS = [
	'CANCELLED',
	'MEMORY_LIMIT',
	'DECRYPT_FAILED',
	'UNSUPPORTED_KEY',
	'SECURE_CONTEXT',
	'ARCHIVE_FAILED',
];
const fail = () => {
	throw new Error('ARCHIVE_FAILED');
};

/** Keep the codes the UI knows; whatever a decoder throws for damaged data becomes ARCHIVE_FAILED. */
function archiveError(error) {
	return error && (KNOWN_ERRORS.includes(error.message) || error.name === 'QuotaExceededError')
		? error
		: new Error('ARCHIVE_FAILED');
}

const startsWith = (bytes, ...prefix) => prefix.every((value, i) => bytes[i] === value);
const isGzip = (head) => startsWith(head, 0x1f, 0x8b, 0x08);
const isZstd = (head) =>
	startsWith(head, 0x28, 0xb5, 0x2f, 0xfd) ||
	((head[0] & 0xf0) === 0x50 && head[1] === 0x2a && head[2] === 0x4d && head[3] === 0x18);
const isZip = (head) => startsWith(head, 0x50, 0x4b, 3, 4) || startsWith(head, 0x50, 0x4b, 5, 6);
const isAge = (head) => equal(head.subarray(0, 19), utf8.encode('age-encryption.org/'));
const base = (path) => path.slice(path.lastIndexOf('/') + 1);

/** Archive members that a container zip lets us process: encrypted ones and BackupPlus compressed archives. */
const isArchiveName = (name) =>
	ENCRYPTED.test(name) || /^backup_.+\.(tar|tgz|tzst|tar\.gz|tar\.zst|gz|zst|zstd)$/i.test(name);

function decrypted(name) {
	return ENCRYPTED.test(name) ? name.replace(ENCRYPTED, '') : name + '.decrypted';
}
function decompressed(name, format) {
	const stripped = name.replace(format === 'gzip' ? /\.gz$/i : /\.(zst|zstd)$/i, '');
	// Legacy database dumps are named ...-db.gz, which holds a .sql file.
	if (stripped !== name) return /-db\d*$/i.test(stripped) ? stripped + '.sql' : stripped;
	if (format === 'gzip' && /\.tgz$/i.test(name)) return name.replace(/\.tgz$/i, '.tar');
	if (format === 'zstd' && /\.tzst$/i.test(name)) return name.replace(/\.tzst$/i, '.tar');
	return name + '.decompressed';
}
const stem = (name) => name.replace(/\.(tar|tgz|tzst)$/i, '');

async function pump(readable, consumer, { size, crc, report } = {}) {
	const reader = readable.getReader();
	let total = 0,
		sum = 0;
	try {
		for (;;) {
			const { done, value } = await reader.read();
			if (done) break;
			total += value.length;
			if (crc !== undefined) sum = crc32(value, sum);
			await consumer.write(value);
			if (report) report(total);
		}
	} catch (error) {
		try {
			await reader.cancel();
		} catch {}
		throw archiveError(error);
	}
	if ((size !== undefined && total !== size) || (crc !== undefined && sum !== crc)) fail();
	await consumer.end();
}

/** A decoder with a streaming native implementation; output flows into the next consumer as it appears. */
class NativeDecompress {
	constructor(format, next) {
		const stream = new DecompressionStream(format);
		this.writer = stream.writable.getWriter();
		this.failure = null;
		const reader = stream.readable.getReader();
		this.pump = (async () => {
			try {
				for (;;) {
					const { done, value } = await reader.read();
					if (done) break;
					await next.write(value);
				}
				await next.end();
			} catch (error) {
				this.failure = error;
				try {
					await reader.cancel(error);
				} catch {}
			}
		})();
	}
	async write(bytes) {
		try {
			// The stream may still read the buffer after write() settles, and callers wipe theirs.
			await this.writer.write(bytes.slice());
		} catch (error) {
			await this.pump;
			throw archiveError(this.failure ?? error);
		}
	}
	async end() {
		try {
			await this.writer.close();
		} catch (error) {
			await this.pump;
			throw archiveError(this.failure ?? error);
		}
		await this.pump;
		if (this.failure) throw archiveError(this.failure);
	}
}

/** Zstandard (fzstd, with checksums verified), in small pushes so that a highly compressible input cannot balloon in memory. */
class ZstdDecompress {
	constructor(next) {
		this.next = next;
		this.output = [];
		this.decoder = new ZstdStream((chunk) => {
			if (chunk.length) this.output.push(chunk);
		});
	}
	async drain() {
		const output = this.output;
		this.output = [];
		for (const chunk of output) await this.next.write(chunk);
	}
	async write(bytes) {
		try {
			for (let at = 0; at < bytes.length; at += 32768) {
				// fzstd keeps views of what it is given across calls, and callers wipe their buffers.
				this.decoder.push(bytes.slice(at, at + 32768));
				await this.drain();
			}
		} catch (error) {
			throw archiveError(error);
		}
	}
	async end() {
		try {
			this.decoder.push(new Uint8Array(0), true);
			await this.drain();
		} catch (error) {
			throw archiveError(error);
		}
		await this.next.end();
	}
}

class Tar {
	constructor(job, prefix) {
		const place = (meta) => prefix + safePath(meta.path);
		this.reader = new TarReader({
			file: async (meta) => {
				const path = place(meta);
				if (!path || path.endsWith('/')) fail();
				return job.zip().addFile(path, meta);
			},
			directory: async (meta) => {
				const path = place(meta);
				if (path) await job.zip().addDirectory(path, meta);
			},
			skipped: () => job.skipped++,
		});
	}
	write(bytes) {
		return this.reader.write(bytes);
	}
	end() {
		return this.reader.end();
	}
}

/**
 * Looks at the first bytes of a stream and hands it to the right stage: gzip, zstd, tar or, when it is none of
 * these, the output as a plain file. Stages route their output into a new Router, so layers chain.
 */
class Router {
	constructor(job, options) {
		this.job = job;
		this.options = options;
		this.head = [];
		this.length = 0;
		this.next = null;
	}
	async write(bytes) {
		if (this.next) return this.next.write(bytes);
		this.head.push(bytes.slice());
		this.length += bytes.length;
		if (this.length >= 1024) await this.decide();
	}
	async end() {
		if (!this.next) await this.decide();
		await this.next.end();
	}
	async decide() {
		const head = new Uint8Array(this.length);
		let at = 0;
		for (const part of this.head) {
			head.set(part, at);
			at += part.length;
		}
		this.head = null;
		this.next = await this.route(head);
		await this.next.write(head);
	}
	async route(head) {
		const { job, options } = this;
		const { name, depth } = options;
		const again = (next) => new Router(job, { ...options, name: next, depth: depth + 1 });
		if (depth < MAX_DEPTH) {
			if (isGzip(head)) {
				job.status('extract', name);
				return new NativeDecompress('gzip', again(decompressed(name, 'gzip')));
			}
			if (isZstd(head)) {
				job.status('extract', name);
				return new ZstdDecompress(again(decompressed(name, 'zstd')));
			}
			if (looksLikeTar(head, name)) {
				job.status('extract', name);
				if (options.top) job.zipName = stem(name) + '.zip';
				return new Tar(job, options.top ? '' : options.dir + stem(name) + '/');
			}
		}
		if (options.top) {
			job.rawName = name;
			return { write: (bytes) => job.sink.write(bytes), end: async () => {} };
		}
		return job.zip().addFile(safePath(options.dir + name), { mtime: options.mtime });
	}
}

class Job {
	constructor({ sink, credentials, options, progress, status }) {
		this.sink = sink;
		this.credentials = credentials;
		this.options = options;
		this.progress = progress;
		this.reportStatus = status;
		this.writer = null;
		this.rawName = null;
		this.zipName = '';
		this.skipped = 0;
		this.legacy = false;
	}
	zip() {
		return (this.writer ??= new ZipWriter(this.sink));
	}
	status(stage, name) {
		this.reportStatus({ stage, name });
	}
	/** Decrypt one file-like source into a consumer; plaintext is only ever handed on, never kept. */
	async decrypt(source, consumer, name, progress) {
		this.status('decrypt', name);
		const result = await decryptArchive(
			source,
			this.credentials,
			{ write: (bytes) => consumer.write(bytes), finish: () => consumer.end(), abort: async () => {} },
			progress,
			this.options,
		);
		if (result.format === 'legacy') this.legacy = true;
	}
}

async function head(file) {
	return new Uint8Array(await file.slice(0, Math.min(file.size, 1024)).arrayBuffer());
}

/**
 * What a file is, without touching storage: {kind: 'none'} when there is nothing to do, otherwise
 * {kind: 'stream' | 'container', encrypted}. A zip is only opened when it holds BackupPlus archives or
 * encrypted files, so that a backup of site files that happens to contain .gz files is left alone.
 */
export async function classify(file, name) {
	const bytes = await head(file);
	if (isAge(bytes) || ENCRYPTED.test(name)) return { kind: 'stream', encrypted: true };
	if (isZip(bytes)) {
		const entries = (await readZip(file)).filter((entry) => !entry.isDirectory);
		const members = entries.filter((entry) => isArchiveName(base(entry.name)));
		if (!members.length) return { kind: 'none' };
		return {
			kind: 'container',
			encrypted: members.some((entry) => ENCRYPTED.test(entry.name)),
		};
	}
	if (isGzip(bytes) || isZstd(bytes) || looksLikeTar(bytes, name)) return { kind: 'stream', encrypted: false };
	return { kind: 'none' };
}

function fileStream(file, report) {
	let offset = 0;
	return new ReadableStream({
		async pull(controller) {
			if (offset >= file.size) return controller.close();
			const bytes = new Uint8Array(await file.slice(offset, Math.min(file.size, offset + 1048576)).arrayBuffer());
			if (!bytes.length) fail();
			offset += bytes.length;
			report(offset);
			controller.enqueue(bytes);
		},
	});
}

async function runContainer(job, file, name) {
	job.zipName = stem(name.replace(/\.zip$/i, '')) + '-extracted.zip';
	const zip = job.zip();
	for (const entry of await readZip(file)) {
		const path = safePath(entry.name);
		if (!path) continue;
		const options = {
			mtime: entry.mtime,
			mode: entry.madeBy >> 8 === 3 ? (entry.attributes >>> 16) & 0o777 || undefined : undefined,
		};
		if (entry.isDirectory) {
			await zip.addDirectory(path, options);
			continue;
		}
		const opened = await openEntry(file, entry);
		const report = (fraction) => job.progress((entry.offset + fraction * entry.compressedSize) / file.size);
		const member = base(path),
			dir = path.slice(0, path.length - member.length);
		if (!isArchiveName(member)) {
			const writer = await zip.addFile(path, { ...options, size: entry.size });
			await pump(opened.stream(), writer, {
				size: entry.size,
				crc: entry.crc,
				report: (done) => report(done / Math.max(entry.size, 1)),
			});
			continue;
		}
		const router = new Router(job, {
			name: ENCRYPTED.test(member) ? decrypted(member) : member,
			dir,
			top: false,
			depth: 0,
			mtime: entry.mtime,
		});
		if (ENCRYPTED.test(member)) {
			// Decryption wants a seekable source; a stored member is one, a deflated member is staged in memory.
			let source = opened.view();
			if (!source) {
				if (entry.size > job.options.memoryLimit) throw new Error('MEMORY_LIMIT');
				source = new Blob([await new Response(opened.stream()).arrayBuffer()]);
				if (source.size !== entry.size) fail();
			}
			await job.decrypt(source, router, path, report);
		} else {
			job.status('extract', path);
			await pump(opened.stream(), router, {
				size: entry.size,
				crc: entry.crc,
				report: (done) => report(done / Math.max(entry.size, 1)),
			});
		}
	}
}

/**
 * Extract one selected file. Returns {kind: 'none'} when there is nothing to do, otherwise
 * {kind: 'file' | 'zip', name, output, legacy, skipped}. Failure aborts nothing itself: the caller owns the sink.
 */
export async function extractFile({ file, name, credentials, sink, progress, status, options = {} }) {
	options = { memoryLimit: 134217728, ...options };
	const plan = await classify(file, name);
	if (plan.kind === 'none') return { kind: 'none' };
	const job = new Job({ sink, credentials, options, progress, status });
	if (plan.kind === 'container') await runContainer(job, file, name);
	else if (plan.encrypted) {
		const router = new Router(job, { name: decrypted(name), dir: '', top: true, depth: 0 });
		await job.decrypt(file, router, name, progress);
	} else {
		await pump(fileStream(file, (done) => progress(done / Math.max(file.size, 1))), new Router(job, { name, dir: '', top: true, depth: 0 }));
	}
	const result = { legacy: job.legacy, skipped: job.skipped };
	if (!job.writer) return { ...result, kind: 'file', name: job.rawName, output: await sink.finish() };
	await job.writer.finish();
	const output = await sink.finish();
	const [only] = job.writer.files;
	if (job.writer.files.length === 1)
		return {
			...result,
			kind: 'file',
			name: base(only.path),
			output: output.slice(only.dataOffset, only.dataOffset + only.size, 'application/octet-stream'),
		};
	return { ...result, kind: 'zip', name: job.zipName || stem(name) + '.zip', output };
}
