/** Extraction scenarios of the client decryption browser checks: the credential prompt, chained decrypt/decompress/untar into a zip, single files, containers, nothing to do and damage. */
import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { buildZip } from '../../fixtures/zip-fixture.mjs';

export async function extractTab(ctx) {
	const { directory, downloads, evaluate, wait, sleep, age, clear, selectPath, checkPage, waitForNoStagedFiles } = ctx;
	const native = age.keys.native;
	const work = path.join(directory, 'extract');
	await mkdir(work);
	const tool = (command, args) => {
		const result = spawnSync(command, args, { cwd: work, maxBuffer: 1 << 28 });
		assert.equal(result.status, 0, command + ' ' + args.join(' ') + ': ' + result.stderr);
		return result.stdout;
	};
	// A tree with an empty file and directory, a long path and a file above the 1 MiB threshold of in-place zip headers.
	const tree = path.join(work, 'tree');
	await mkdir(path.join(tree, 'wp-content/uploads'), { recursive: true });
	await mkdir(path.join(tree, 'empty-directory'));
	await mkdir(path.join(tree, 'deep', 'long-'.repeat(30)), { recursive: true });
	await writeFile(path.join(tree, 'index.php'), '<?php // browser extraction\n');
	await writeFile(path.join(tree, 'empty.txt'), '');
	await writeFile(path.join(tree, 'wp-content/uploads/photo.jpg'), randomBytes(3 * 1024 * 1024 + 5));
	await writeFile(path.join(tree, 'deep', 'long-'.repeat(30), 'file.txt'), 'long path');
	const archive = (name, compress) => {
		tool('tar', ['-C', tree, '-cf', name + '.tar', '.']);
		if (compress === 'zst') tool('zstd', ['-q', '-6', '--long=25', '--check', '-f', name + '.tar', '-o', name + '.tar.zst']);
		else if (compress === 'gz') tool('gzip', ['-kf', name + '.tar']);
		return path.join(work, name + '.tar' + (compress ? '.' + compress : ''));
	};
	const encrypt = async (file, name) => {
		tool('age', ['-r', native.recipient, '-o', name, file]);
		return path.join(work, name);
	};
	const tab = '.backupplus-decryption-tool';
	const promptHidden = () => evaluate('document.getElementById("backupplus-decrypt-credentials").hidden');
	const inspected = () => wait(`document.querySelector("${tab}").getAttribute("data-inspecting") === "false"`);
	const start = async (credentials = {}) => {
		await evaluate(
			`document.getElementById('backupplus-manual-age-identity').value=${JSON.stringify(credentials.identity || '')};document.getElementById('backupplus-manual-age-passphrase').value=${JSON.stringify(credentials.keyPassword || '')};document.getElementById('backupplus-decrypt-start').click();`,
		);
		await wait(
			`document.getElementById("backupplus-decrypt-status").textContent && document.querySelector("${tab}").getAttribute("aria-busy") === "false"`,
		);
	};
	const links = () => evaluate('[...document.querySelectorAll("#backupplus-decrypt-results a")].map(a => a.download)');
	async function download(index = 0) {
		const before = downloads.length;
		await evaluate(`document.querySelectorAll("#backupplus-decrypt-results a")[${index}].click()`, true);
		for (let attempt = 0; attempt < 200; attempt++) {
			const suggested = downloads[before]?.suggestedFilename;
			const content = suggested ? await readFile(path.join(directory, 'downloads', suggested)).catch(() => null) : null;
			if (content) return { name: suggested, content, file: path.join(directory, 'downloads', suggested) };
			await sleep(100);
		}
		throw new Error('The download did not finish');
	}
	/** Unzip with the independent unzip tool, which also verifies every checksum. */
	const unzipped = async (zip, label) => {
		const output = path.join(work, 'unzipped-' + label);
		await mkdir(output);
		tool('unzip', ['-tq', zip.file]);
		tool('unzip', ['-q', zip.file, '-d', output]);
		return output;
	};
	const same = (a, b) => tool('diff', ['-r', a, b]);

	assert.equal(await promptHidden(), true, 'No credentials are asked for before an encrypted file is selected');
	assert.equal(await evaluate('document.getElementById("backupplus-decrypt-start").textContent'), 'Extract locally');

	// An encrypted tarball: the prompt appears, and decrypt, unzstd and untar produce one zip.
	await clear();
	await selectPath(await encrypt(archive('site-one', 'zst'), 'site-one.tar.zst.crypt'));
	await inspected();
	assert.equal(await promptHidden(), false, 'A .crypt file needs credentials');
	await start({ identity: native.identity });
	assert.deepEqual(await links(), ['site-one.zip']);
	const siteOne = await download();
	same(tree, await unzipped(siteOne, 'one'));
	assert.equal(await evaluate('["backupplus_db_decrypt","backupplus-manual-age-identity","backupplus-manual-age-passphrase"].every(id=>document.getElementById(id).value === "")'), true);
	await checkPage();
	console.log('PASS: actual browser worker decrypts, decompresses and unpacks a .tar.zst.crypt into a zip that unzip verifies');

	// A plain tarball needs no credentials: the prompt goes away again and the keys typed meanwhile are forgotten.
	await clear();
	await selectPath(archive('site-two', 'gz'));
	await inspected();
	assert.equal(await promptHidden(), true, 'A plain archive needs no credentials');
	await start();
	assert.deepEqual(await links(), ['site-two.zip']);
	same(tree, await unzipped(await download(), 'two'));
	// A single compressed file is decompressed, not wrapped.
	const sql = Buffer.from('INSERT INTO `wp_options` VALUES (1);\n'.repeat(5000));
	await writeFile(path.join(work, 'dump-three.sql'), sql);
	tool('gzip', ['-kf', 'dump-three.sql']);
	await clear();
	await selectPath(path.join(work, 'dump-three.sql.gz'));
	await inspected();
	await start();
	assert.deepEqual(await links(), ['dump-three.sql']);
	assert.deepEqual((await download()).content, sql);
	console.log('PASS: plain tar.gz becomes a zip without a credential prompt; a .sql.gz becomes the plain .sql');

	// A downloaded backup set: its zip is looked into for encrypted members, which brings the prompt up.
	const prefix = 'backup_2026-10-26-1710_Site_0aa9e71ebfc5-';
	tool('zstd', ['-q', '-f', 'dump-three.sql', '-o', 'dump-three.sql.zst']);
	const container = buildZip([
		{ name: prefix + 'plugins.tar.zst.crypt', data: await readFile(await encrypt(archive('set-plugins', 'zst'), 'set-plugins.tar.zst.crypt')) },
		{ name: prefix + 'db.sql.zst.crypt', data: await readFile(await encrypt(path.join(work, 'dump-three.sql.zst'), 'set-db.sql.zst.crypt')) },
		{ name: prefix + 'themes.tar.gz', data: await readFile(archive('set-themes', 'gz')) },
		{ name: 'README.txt', data: Buffer.from('copied as is') },
	]);
	await writeFile(path.join(work, 'backup-set.zip'), container);
	await clear();
	await selectPath(path.join(work, 'backup-set.zip'));
	await inspected();
	assert.equal(await promptHidden(), false, 'A zip with encrypted members needs credentials');
	await start({ identity: native.identity });
	assert.deepEqual(await links(), ['backup-set-extracted.zip']);
	const set = await unzipped(await download(), 'set');
	same(tree, path.join(set, prefix + 'plugins'));
	same(tree, path.join(set, prefix + 'themes'));
	assert.deepEqual(await readFile(path.join(set, prefix + 'db.sql')), sql);
	assert.equal(await readFile(path.join(set, 'README.txt'), 'utf8'), 'copied as is');
	console.log('PASS: a backup set zip of encrypted and plain archives comes back as one zip of extracted recovery points');

	// Nothing to do, and damage.
	await writeFile(path.join(work, 'notes-four.txt'), 'plain notes');
	await clear();
	await selectPath(path.join(work, 'notes-four.txt'));
	await inspected();
	await start();
	assert.deepEqual(await links(), []);
	assert.equal(await evaluate('document.getElementById("backupplus-decrypt-status").textContent === backupplus_decryption.nothing'), true);
	assert.equal(await evaluate('document.getElementById("backupplus-decrypt-results").textContent.includes(backupplus_decryption.none)'), true);
	await waitForNoStagedFiles();
	const whole = await readFile(archive('cut-five', 'zst'));
	await writeFile(path.join(work, 'cut-five-truncated.tar.zst'), whole.subarray(0, whole.length - 30));
	await clear();
	await selectPath(path.join(work, 'cut-five-truncated.tar.zst'));
	await inspected();
	await start();
	assert.deepEqual(await links(), []);
	assert.equal(await evaluate('document.getElementById("backupplus-decrypt-status").textContent === backupplus_decryption.errors.ARCHIVE_FAILED'), true);
	await waitForNoStagedFiles();
	await clear();
	assert.equal(await promptHidden(), true, 'Clearing hides the prompt again');
	console.log('PASS: files with nothing to extract and damaged archives publish nothing and leave no staged files');
}
