/** Chained decrypt, decompress and extract in the browser code path, checked against the zstd, tar, age and unzip tools. */
import { extractFile, classify } from '../assets-src/decryption/extract.mjs';
import { readZip, ZipWriter } from '../assets-src/decryption/zip.mjs';
import { ZstdStream } from '../assets-src/decryption/zstd.mjs';
import { XXH64 } from '../assets-src/decryption/xxh64.mjs';
import { createAgesshBridge } from '../assets-src/decryption/agessh-bridge.mjs';
import { mkdtemp, mkdir, rm, writeFile, readFile, readdir, symlink, chmod, stat } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { createCipheriv, randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { createRequire } from 'node:module';
import { buildZip } from './fixtures/zip-fixture.mjs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '..');
const require = createRequire(import.meta.url);
require(path.join(root, 'js/backupplus-agessh-wasm_exec.js'));
const wasmModule = await WebAssembly.compile(await readFile(path.join(root, 'js/backupplus-agessh.wasm')));
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-extract-'));
let checks = 0;

function tool(command, args, options = {}) {
	const result = spawnSync(command, args, { cwd: directory, maxBuffer: 1 << 30, ...options });
	assert.equal(result.status, 0, `${command} ${args.join(' ')}: ${result.stderr}`);
	return result.stdout;
}

/** The sink of the worker: appends, can patch what it wrote before, and publishes only on finish(). */
function memorySink() {
	const chunks = [],
		offsets = [];
	let count = 0;
	const sink = {
		published: false,
		aborted: false,
		get position() {
			return count;
		},
		async write(bytes) {
			offsets.push(count);
			chunks.push(bytes.slice());
			count += bytes.length;
		},
		async patch(position, bytes) {
			let i = offsets.length - 1;
			while (offsets[i] > position) i--;
			assert(position + bytes.length <= offsets[i] + chunks[i].length, 'A patch must stay inside one written chunk');
			chunks[i].set(bytes, position - offsets[i]);
		},
		async finish() {
			sink.published = true;
			return new Blob(chunks);
		},
		async abort() {
			sink.aborted = true;
		},
	};
	return sink;
}

const file = (bytes, name) => new File([bytes], name);
async function extract(bytes, name, credentials = {}) {
	const sink = memorySink();
	const statuses = [];
	let last = 0;
	const result = await extractFile({
		file: file(bytes, name),
		name,
		credentials: { password: '', identity: '', keyPassword: '', ...credentials },
		sink,
		progress: (fraction) => {
			assert(fraction >= 0 && fraction <= 1.0000001, 'Progress must stay within 0..1');
			last = fraction;
		},
		status: (status) => statuses.push(status),
		options: { createBridge: () => createAgesshBridge({ wasmModule }) },
	});
	checks++;
	return { ...result, sink, statuses, last, bytes: result.output ? Buffer.from(await result.output.arrayBuffer()) : null };
}
async function rejects(bytes, name, code, credentials) {
	const sink = memorySink();
	await assert.rejects(
		extractFile({
			file: file(bytes, name),
			name,
			credentials: { password: '', identity: '', keyPassword: '', ...credentials },
			sink,
			progress() {},
			status() {},
			options: { createBridge: () => createAgesshBridge({ wasmModule }) },
		}),
		(error) => {
			// The worker reports what it does not know as a decryption failure.
			const reported = ['SECURE_CONTEXT', 'MEMORY_LIMIT', 'UNSUPPORTED_KEY', 'ARCHIVE_FAILED'].includes(error.message) ? error.message : 'DECRYPT_FAILED';
			assert.equal(reported, code);
			return true;
		},
	);
	assert(!sink.published, 'A failed extraction must never publish its output');
	checks++;
}

/** Unpack a zip with unzip, which also tests its integrity, and return the tree. */
async function unzipped(bytes, label) {
	const zip = path.join(directory, label + '.zip'),
		out = path.join(directory, 'out-' + label);
	await writeFile(zip, bytes);
	tool('unzip', ['-tq', zip]);
	await mkdir(out);
	tool('unzip', ['-q', zip, '-d', out]);
	return out;
}
const same = (a, b, ...skip) => tool('diff', ['-r', ...skip.flatMap((name) => ['-x', name]), a, b]);

// A tree with every case a recovery point has: empty files and directories, long names, a link, large files.
const tree = path.join(directory, 'tree');
await mkdir(path.join(tree, 'wp-content/uploads/2026'), { recursive: true });
await mkdir(path.join(tree, 'empty-directory'));
const longName = 'long-'.repeat(19);
const deepName = 'deep-'.repeat(50);
await mkdir(path.join(tree, 'wp-content/plugins', longName), { recursive: true });
await mkdir(path.join(tree, 'deep', deepName), { recursive: true });
await writeFile(path.join(tree, 'deep', deepName, 'file.txt'), 'a path of more than 250 characters');
await writeFile(path.join(tree, 'index.php'), '<?php // hello\n');
await writeFile(path.join(tree, 'empty.txt'), '');
await writeFile(path.join(tree, 'unicode-é-文.txt'), 'unicode');
await writeFile(path.join(tree, 'wp-content/plugins', longName, 'plugin.php'), '<?php // long path\n');
await writeFile(path.join(tree, 'wp-content/uploads/2026/photo.jpg'), randomBytes(1536 * 1024 + 17));
await writeFile(path.join(tree, 'wp-content/uploads/2026/exact.bin'), randomBytes(1048576));
await writeFile(path.join(tree, 'dump.sql'), Buffer.from('INSERT INTO t VALUES (1);\n'.repeat(400000)));
await writeFile(path.join(tree, 'run.sh'), '#!/bin/sh\n', { mode: 0o755 });
await chmod(path.join(tree, 'run.sh'), 0o755);
const treeWithLink = path.join(directory, 'tree-with-link');
tool('cp', ['-a', tree, treeWithLink]);
await symlink('index.php', path.join(treeWithLink, 'link.php'));

const key = path.join(directory, 'key.txt');
tool('age-keygen', ['-o', key]);
const identity = await readFile(key, 'utf8');
const recipient = tool('age-keygen', ['-y', key]).toString().trim();
const encrypt = (plain, name) => {
	const input = path.join(directory, 'plain-' + name);
	const output = path.join(directory, name + '.crypt');
	return writeFile(input, plain).then(() => {
		tool('age', ['-r', recipient, '-o', output, input]);
		return readFile(output);
	});
};

// Every tar flavour and compression through the plain (unencrypted) chain.
const archives = {};
for (const [format, flags] of [
	['pax', ['--format=posix']],
	['gnu', ['--format=gnu']],
	['ustar', ['--format=ustar']],
]) {
	const tarPath = path.join(directory, format + '.tar');
	// ustar cannot hold the 250 character path; pax and GNU can.
	tool('tar', [...flags, ...(format === 'ustar' ? ['--exclude=./deep'] : []), '-C', tree, '-cf', tarPath, '.']);
	archives[format] = await readFile(tarPath);
}
tool('tar', ['-C', tree, '-cf', 'plain.tar', '.']);
tool('zstd', ['-q', '-6', '--long=25', '--check', '-f', 'plain.tar', '-o', 'plain.tar.zst']);
tool('gzip', ['-kf', 'plain.tar']);
const tarZst = await readFile(path.join(directory, 'plain.tar.zst'));
const tarGz = await readFile(path.join(directory, 'plain.tar.gz'));
const tarPlain = await readFile(path.join(directory, 'plain.tar'));
for (const [name, bytes] of [
	['site.tar.zst', tarZst],
	['site.tar.gz', tarGz],
	['site.tgz', tarGz],
	['site.tar', tarPlain],
	['pax.tar', archives.pax],
	['gnu.tar', archives.gnu],
	['ustar.tar', archives.ustar],
]) {
	const result = await extract(bytes, name);
	assert.equal(result.kind, 'zip', name);
	assert.equal(result.name, name.replace(/\.(tar\.zst|tar\.gz|tgz|tar)$/, '.zip'));
	assert.equal(result.last, 1);
	assert(result.sink.published);
	same(tree, await unzipped(result.bytes, 'plain-' + name.replace(/\W/g, '-')), ...(name === 'ustar.tar' ? ['deep'] : []));
	assert((await stat(path.join(directory, 'out-plain-' + name.replace(/\W/g, '-'), 'run.sh'))).mode & 0o100, 'Unix permissions must survive');
	const entries = await readZip(file(result.bytes, 'x.zip'));
	assert(entries.every((entry) => entry.method === 0), 'The zip must not compress');
	assert(entries.some((entry) => entry.isDirectory && entry.name === 'empty-directory/'), 'Empty directories must be kept');
	assert.equal(result.skipped, 0);
}
console.log('PASS: tar (pax, GNU, ustar) with zstd, gzip and no compression becomes a store-only zip that unzip verifies');

// A tarball with a link: the link is skipped and reported.
tool('tar', ['-C', treeWithLink, '-czf', 'link.tar.gz', '.']);
const withLink = await extract(await readFile(path.join(directory, 'link.tar.gz')), 'link.tar.gz');
assert.equal(withLink.skipped, 1);
same(tree, await unzipped(withLink.bytes, 'link'));
console.log('PASS: links are skipped and counted');

// Single files: compressed SQL is decompressed to one plain file, not wrapped.
const sql = await readFile(path.join(tree, 'dump.sql'));
tool('zstd', ['-q', '-3', '-f', path.join(tree, 'dump.sql'), '-o', 'dump.sql.zst']);
await writeFile(path.join(directory, 'dump.sql.gz'), tool('gzip', ['-c', path.join(tree, 'dump.sql')]));
for (const [name, bytes] of [
	['backup_x-db.sql.zst', await readFile(path.join(directory, 'dump.sql.zst'))],
	['backup_x-db.sql.gz', await readFile(path.join(directory, 'dump.sql.gz'))],
]) {
	const result = await extract(bytes, name);
	assert.equal(result.kind, 'file');
	assert.equal(result.name, 'backup_x-db.sql');
	assert(result.bytes.equals(sql));
}
const legacyDump = await extract(await readFile(path.join(directory, 'dump.sql.gz')), 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-db.gz');
assert.equal(legacyDump.name, 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-db.sql');
// Concatenated zstd frames decode to the concatenation, and skippable frames are skipped.
const frame = (text) => {
	const input = path.join(directory, 'frame');
	return writeFile(input, text).then(() => tool('zstd', ['-q', '-c', input]));
};
const skippable = Buffer.from([0x50, 0x2a, 0x4d, 0x18, 3, 0, 0, 0, 1, 2, 3]);
const frames = Buffer.concat([await frame('one,'), skippable, await frame('two')]);
assert.equal((await extract(frames, 'frames.txt.zst')).bytes.toString(), 'one,two');
// A tarball with a single file is that file.
await writeFile(path.join(directory, 'single.txt'), 'only one');
tool('tar', ['-cf', 'single.tar', 'single.txt']);
const single = await extract(await readFile(path.join(directory, 'single.tar')), 'single.tar');
assert.equal(single.kind, 'file');
assert.equal(single.name, 'single.txt');
assert.equal(single.bytes.toString(), 'only one');
// An empty tarball has nothing to extract.
tool('tar', ['-cf', 'empty.tar', '-T', '/dev/null']);
assert.equal((await extract(await readFile(path.join(directory, 'empty.tar')), 'empty.tar')).kind, 'none');
console.log('PASS: single files are returned as is, concatenated frames, skippable frames, empty archives');

// Zstandard content checksums: XXH64 against the reference, and frames of every shape through the verifying decoder.
{
	const hash = new XXH64();
	const hex = (text) => {
		hash.reset();
		hash.update(Buffer.from(text));
		return hash.digestLow32();
	};
	assert.deepEqual([hex(''), hex('a'), hex('abc')], [0x51d8e999, 0xa98c6e5b, 0xad770999]);
	for (const size of [...Array.from({ length: 140 }, (_, i) => i), 4096, 100000, 1048577]) {
		const data = randomBytes(size);
		const frame = tool('zstd', ['-q', '-c', '--check'], { input: data });
		hash.reset();
		for (let at = 0; at < size; at += 37) hash.update(data.subarray(at, at + 37));
		assert.equal(hash.digestLow32(), frame.readUInt32LE(frame.length - 4), `XXH64 of ${size} bytes`);
	}
	const decode = (compressed, step) => {
		const out = [];
		const stream = new ZstdStream((chunk) => out.push(chunk.slice()));
		for (let at = 0; at < compressed.length; at += step) stream.push(compressed.subarray(at, at + step));
		stream.push(new Uint8Array(0), true);
		return Buffer.concat(out);
	};
	const zstd = (data, ...flags) => tool('zstd', ['-q', '-c', ...flags], { input: data });
	const text = Buffer.from('some compressible content, line by line\n'.repeat(60000));
	const random = randomBytes(300000);
	const frames = [Buffer.alloc(0), Buffer.from('x'), Buffer.from('one,'), Buffer.from('a short frame under eighteen bytes'), text, random];
	for (const original of frames)
		for (const flags of [['--check'], ['--no-check'], ['-19', '--long=25'], ['-1']])
			for (const step of original.length < 5000 ? [1, 7, 100, 65536] : [1000, 65536, 1 << 20])
				assert(decode(zstd(original, ...flags), step).equals(original), `frame of ${original.length} bytes with ${flags}, pushed by ${step}`);
	const concatenated = Buffer.concat([zstd(Buffer.from('one,')), Buffer.from([0x5f, 0x2a, 0x4d, 0x18, 0, 0, 0, 0]), zstd(random), zstd(Buffer.from('two'))]);
	for (const step of [3, 4096, 1 << 20]) assert(decode(concatenated, step).equals(Buffer.concat([Buffer.from('one,'), random, Buffer.from('two')])));
	// Incompressible data is stored in raw blocks, so a flipped byte still decodes: only the checksum can tell.
	const damaged = (compressed, offset) => {
		const copy = Buffer.from(compressed);
		copy[offset] ^= 1;
		return copy;
	};
	const checked = zstd(random, '--check');
	assert.throws(() => decode(damaged(checked, 150000), 65536), /ARCHIVE_FAILED/, 'a flipped content byte');
	assert.throws(() => decode(damaged(checked, checked.length - 2), 65536), /ARCHIVE_FAILED/, 'a flipped checksum byte');
	assert.throws(() => decode(Buffer.concat([zstd(text), damaged(zstd(random), 100000), zstd(text)]), 65536), /ARCHIVE_FAILED/, 'a damaged middle frame');
	assert.throws(() => decode(damaged(zstd(Buffer.from('one,')), 12), 3), /ARCHIVE_FAILED/, 'a damaged tiny frame');
	assert.throws(() => decode(checked.subarray(0, checked.length - 2), 65536), /ARCHIVE_FAILED/, 'a missing checksum');
	assert.throws(() => decode(Buffer.concat([checked, Buffer.from('trailing')]), 65536), /ARCHIVE_FAILED/);
	// Frames written without a checksum carry nothing to verify.
	assert.doesNotThrow(() => decode(damaged(zstd(random, '--no-check'), 150000), 65536));
	// The same through the whole chain: a damaged plain .sql.zst is never published.
	await rejects(damaged(checked, 150000), 'dump.sql.zst', 'ARCHIVE_FAILED');
	checks++;
}
console.log('PASS: zstd content checksums are verified for every frame shape, chunking and damage');

// Encryption in the chain.
for (const [name, plain] of [
	['site.tar.zst', tarZst],
	['site.tar.gz', tarGz],
	['site.tar', tarPlain],
]) {
	const result = await extract(await encrypt(plain, name), name + '.crypt', { identity });
	assert.equal(result.kind, 'zip');
	assert.equal(result.name, name.replace(/\.(tar\.zst|tar\.gz|tar)$/, '.zip'));
	assert(result.statuses.some((status) => status.stage === 'decrypt'));
	same(tree, await unzipped(result.bytes, 'age-' + name.replace(/\W/g, '-')));
	assert.equal(result.last, 1);
}
const encryptedSql = await extract(await encrypt(await readFile(path.join(directory, 'dump.sql.zst')), 'backup_x-db.sql.zst'), 'backup_x-db.sql.zst.crypt', { identity });
assert.equal(encryptedSql.name, 'backup_x-db.sql');
assert(encryptedSql.bytes.equals(sql));
// An age file is recognised by its content even when it is not named .crypt, and keeps a marker name.
const renamed = await extract(await encrypt(tarZst, 'renamed'), 'renamed.bin', { identity });
assert.equal(renamed.kind, 'zip');
// Only a decryption layer is peeled off: encrypted data that is not an archive stays as is.
const opaque = randomBytes(300000);
const opaqueResult = await extract(await encrypt(opaque, 'opaque.bin'), 'opaque.bin.crypt', { identity });
assert.equal(opaqueResult.name, 'opaque.bin');
assert(opaqueResult.bytes.equals(opaque));
await rejects(await encrypt(tarZst, 'wrong'), 'wrong.crypt', 'DECRYPT_FAILED', { identity: tool('age-keygen', []).toString() });
console.log('PASS: age decryption chained into decompression and extraction');

// UpdraftPlus legacy encryption keeps working and is flagged.
const passphrase = 'legacy passphrase 1234567890abcd';
const legacyPlain = Buffer.from('# Disposable synthetic backup\nSELECT 42;\n'.repeat(10));
const cipher = createCipheriv('aes-256-cbc', Buffer.from(passphrase), Buffer.alloc(16));
const legacy = await extract(Buffer.concat([cipher.update(legacyPlain), cipher.final()]), 'backup_x-db.sql.crypt', { password: passphrase });
assert.equal(legacy.name, 'backup_x-db.sql');
assert.equal(legacy.legacy, true);
assert(legacy.bytes.equals(legacyPlain));

// Container zips: stored and deflated members, encrypted and plain archives, other files copied through.
await writeFile(path.join(directory, 'other.txt'), 'not an archive');
tool('tar', ['-C', tree, '-cf', 'second.tar', 'index.php', 'empty-directory']);
tool('zstd', ['-q', '-f', 'second.tar', '-o', 'second.tar.zst']);
const second = await readFile(path.join(directory, 'second.tar.zst'));
const prefixName = 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-';
const members = [
	{ name: 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-plugins.tar.zst.crypt', data: await encrypt(tarZst, 'm1') },
	{ name: 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-themes.tar.gz.crypt', data: await encrypt(tarGz, 'm2') },
	{ name: 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-db.sql.zst.crypt', data: await encrypt(await readFile(path.join(directory, 'dump.sql.zst')), 'm3') },
	{ name: 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-others.tar.zst', data: second },
	{ name: 'notes/', data: Buffer.alloc(0) },
	{ name: 'notes/other.txt', data: Buffer.from('not an archive') },
	{ name: 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-uploads.zip.crypt', data: await encrypt(buildZip([{ name: 'a.txt', data: Buffer.from('a') }]), 'm4') },
];
for (const deflate of [false, true]) {
	const container = buildZip(members, deflate);
	await writeFile(path.join(directory, 'container.zip'), container);
	tool('unzip', ['-tq', 'container.zip']);
	assert.deepEqual(await classify(file(container, 'set.zip'), 'set.zip'), { kind: 'container', encrypted: true });
	const result = await extract(container, 'backup_set.zip', { identity });
	assert.equal(result.kind, 'zip');
	assert.equal(result.name, 'backup_set-extracted.zip');
	const out = await unzipped(result.bytes, 'container-' + deflate);
	const prefix = 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-';
	same(tree, path.join(out, prefix + 'plugins'));
	same(tree, path.join(out, prefix + 'themes'));
	assert.equal((await readFile(path.join(out, prefix + 'db.sql'))).length, sql.length);
	assert.equal(await readFile(path.join(out, prefix + 'others/index.php'), 'utf8'), '<?php // hello\n');
	assert((await readdir(path.join(out, prefix + 'others/empty-directory'))).length === 0);
	assert.equal(await readFile(path.join(out, 'notes/other.txt'), 'utf8'), 'not an archive');
	assert.equal((await readFile(path.join(out, prefix + 'uploads.zip'))).subarray(0, 4).toString('hex'), '504b0304', 'A decrypted zip is kept as a zip');
}
// Plain archives and members in directories keep their place.
const nested = buildZip([{ name: 'sets/one/backup_x-others.tar.zst', data: second }, { name: 'sets/one/readme.txt', data: Buffer.from('r') }]);
const nestedResult = await extract(nested, 'sets.zip');
const nestedOut = await unzipped(nestedResult.bytes, 'nested');
assert.equal(await readFile(path.join(nestedOut, 'sets/one/backup_x-others/index.php'), 'utf8'), '<?php // hello\n');
assert.equal(await readFile(path.join(nestedOut, 'sets/one/readme.txt'), 'utf8'), 'r');
console.log('PASS: container zips with encrypted and plain archives, stored and deflated, plus other files');

// The zip the plugin itself streams for a downloaded backup set (BackupPlus_Stored_Zip) is a valid container.
{
	const files = ['plugins.tar.zst.crypt', 'db.sql.zst.crypt'].map((suffix, i) => {
		const member = path.join(directory, 'set-' + i + '.bin');
		return { name: prefixName + suffix, path: member, data: members[i * 2].data };
	});
	for (const file of files) await writeFile(file.path, file.data);
	const output = path.join(directory, 'stored.zip');
	tool('php', ['-r', 'define("ABSPATH", "/"); require $argv[1]; $files = json_decode($argv[2], true); $zip = new BackupPlus_Stored_Zip($files); $out = fopen($argv[3], "wb"); $zip->write($out); fclose($out);', path.join(root, 'includes/class-stored-zip.php'), JSON.stringify(files.map(({ name, path: file }) => ({ name, path: file }))), output]);
	tool('unzip', ['-tq', output]);
	const result = await extract(await readFile(output), 'backup_set.zip', { identity });
	const out = await unzipped(result.bytes, 'plugin-zip');
	same(tree, path.join(out, prefixName + 'plugins'));
	assert.equal((await readFile(path.join(out, prefixName + 'db.sql'))).length, sql.length);
}
console.log('PASS: the plugin\'s own stored backup set zip is extracted');

// The tar the plugin itself writes (BackupPlus_Tar: pax path for long names, directories) unpacks with its paths intact.
{
	const phpTar = path.join(directory, 'php.tar');
	await writeFile(path.join(directory, 'php-source.bin'), randomBytes(1500000));
	tool('php', ['-r', 'define("ABSPATH", "/"); require $argv[1]; $tar = new BackupPlus_Tar(); $tar->open($argv[2]); $tar->addFile($argv[3], "plain.bin"); $tar->addFile($argv[3], str_repeat("longdir/", 40) . "file.bin"); $tar->addEmptyDir("emptydir"); $tar->close();', path.join(root, 'includes/class-tar.php'), phpTar, path.join(directory, 'php-source.bin')]);
	const result = await extract(await readFile(phpTar), 'php.tar');
	const out = await unzipped(result.bytes, 'php-tar');
	const source = await readFile(path.join(directory, 'php-source.bin'));
	assert((await readFile(path.join(out, 'plain.bin'))).equals(source));
	assert((await readFile(path.join(out, 'longdir/'.repeat(40) + 'file.bin'))).equals(source));
	assert.deepEqual(await readdir(path.join(out, 'emptydir')), []);
}
console.log("PASS: the plugin's own tar writer's archives are extracted");

// A zip that does not hold backup archives is left alone, even with .gz files in it.
const siteZip = buildZip([{ name: 'wp-content/data.sql.gz', data: tarGz }, { name: 'index.php', data: Buffer.from('<?php') }]);
assert.equal((await extract(siteZip, 'backup_x-plugins.zip')).kind, 'none');
for (const [bytes, name] of [[randomBytes(5000), 'random.bin'], [Buffer.from('SELECT 1;'), 'dump.sql'], [Buffer.alloc(0), 'empty.dat'], [await readFile(path.join(directory, 'container.zip')).then(() => buildZip([])), 'empty.zip']])
	assert.equal((await extract(bytes, name)).kind, 'none', name);
console.log('PASS: files with nothing to do are reported as such');

// Damage never publishes: truncation, corruption, a missing end marker, unsafe paths, wrong checksums.
await rejects(tarZst.subarray(0, tarZst.length - 40), 'cut.tar.zst', 'ARCHIVE_FAILED');
await rejects(tarGz.subarray(0, tarGz.length - 40), 'cut.tar.gz', 'ARCHIVE_FAILED');
const damagedGz = Buffer.from(tarGz);
damagedGz[Math.floor(damagedGz.length / 2)] ^= 0xff;
await rejects(damagedGz, 'damaged.tar.gz', 'ARCHIVE_FAILED');
let dataEnd = tarPlain.length;
while (!tarPlain[dataEnd - 1]) dataEnd--;
await rejects(tarPlain.subarray(0, Math.ceil(dataEnd / 512) * 512), 'no-end.tar', 'ARCHIVE_FAILED');
await rejects(tarPlain.subarray(0, 1024 + 100), 'cut.tar', 'ARCHIVE_FAILED');
await rejects(Buffer.concat([tarPlain, Buffer.from('trailing garbage')]), 'garbage.tar', 'ARCHIVE_FAILED');
tool('tar', ['-C', tree, '--transform=s,^\\./,../,', '-cf', 'evil.tar', './index.php']);
await rejects(await readFile(path.join(directory, 'evil.tar')), 'evil.tar', 'ARCHIVE_FAILED');
const crcDamaged = buildZip([{ name: 'backup_x-others.tar.zst', data: second }]);
crcDamaged.writeUInt32LE((crcDamaged.readUInt32LE(14) ^ 1) >>> 0, 14);
const central = crcDamaged.lastIndexOf(Buffer.from([0x50, 0x4b, 1, 2]));
crcDamaged.writeUInt32LE((crcDamaged.readUInt32LE(central + 16) ^ 1) >>> 0, central + 16);
await rejects(crcDamaged, 'crc.zip', 'ARCHIVE_FAILED');
const encrypted = await encrypt(tarZst, 'corrupt');
encrypted[encrypted.length - 20] ^= 1;
await rejects(encrypted, 'corrupt.crypt', 'DECRYPT_FAILED', { identity });
await rejects(await readFile(path.join(directory, 'container.zip')).then((zip) => zip.subarray(0, 10000)), 'cut.zip', 'ARCHIVE_FAILED');
console.log('PASS: truncated, damaged and unsafe archives fail without publishing');

// The writer's zip64 paths: more than 65535 entries, and an entry over 4 GiB with a patched header.
{
	const sink = memorySink();
	const zip = new ZipWriter(sink);
	for (let i = 0; i < 70000; i++) {
		const entry = await zip.addFile('many/' + i, { size: 1, mtime: 1700000000 });
		await entry.write(Uint8Array.of(i & 255));
		await entry.end();
	}
	await zip.finish();
	const bytes = Buffer.from(await (await sink.finish()).arrayBuffer());
	const entries = await readZip(file(bytes, 'many.zip'));
	assert.equal(entries.length, 70000);
	await writeFile(path.join(directory, 'many.zip'), bytes);
	tool('unzip', ['-tq', 'many.zip']);
	assert.equal(tool('unzip', ['-Z1', 'many.zip']).toString().trim().split('\n').length, 70000);
	assert.equal(tool('unzip', ['-p', 'many.zip', 'many/258']).toString('latin1'), String.fromCharCode(258 & 255));
}
if (!process.env.BACKUPPLUS_SKIP_LARGE_ZIP) {
	// Zeros are stored sparsely, so the entry above 4 GiB costs the checksum but no disk.
	const { open } = await import('node:fs/promises');
	const target = path.join(directory, 'large.zip');
	const handle = await open(target, 'w');
	let position = 0;
	const sink = {
		get position() {
			return position;
		},
		async write(bytes) {
			// Only the entry's zero data comes in chunks this large; skipping it leaves a hole in the file.
			if (bytes.length < 1 << 20) await handle.write(bytes, 0, bytes.length, position);
			position += bytes.length;
		},
		async patch(at, bytes) {
			await handle.write(bytes, 0, bytes.length, at);
		},
	};
	const zip = new ZipWriter(sink);
	const entry = await zip.addFile('large/zeros.bin', { mtime: 1700000000 });
	const zeros = new Uint8Array(1 << 26);
	const size = 2 ** 32 + 2 ** 20;
	for (let written = 0; written < size; written += zeros.length) await entry.write(zeros.subarray(0, Math.min(zeros.length, size - written)));
	await entry.end();
	const small = await zip.addFile('after.txt', { size: 5 });
	await small.write(Buffer.from('after'));
	await small.end();
	await zip.finish();
	await handle.truncate(position);
	await handle.close();
	tool('unzip', ['-tq', target]);
	const listing = tool('unzip', ['-l', target]).toString();
	assert(listing.includes(String(Math.floor(size))), 'The entry above 4 GiB must be listed with its real size');
	console.log('PASS: zip64 for more than 65535 entries and an entry above 4 GiB');
}

await rm(directory, { recursive: true, force: true });
console.log(`PASS: browser extraction checks (${checks} extractions)`);
