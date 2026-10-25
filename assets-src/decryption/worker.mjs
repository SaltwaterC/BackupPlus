import { decryptArchive } from './archive.mjs';
import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex } from '@noble/hashes/utils.js';
const MEMORY_LIMIT = 128 * 1024 * 1024;

let activeJob = null;
let cancelRequested = false;

self.onmessage = async ({ data }) => {
	if (data?.type === 'cancel') {
		if (data.id === activeJob) cancelRequested = true;
		return;
	}
	const { file, id, credentials, hashOutput, stagingFile, agessh } = data;
	activeJob = id;
	cancelRequested = false;
	const checkCancelled = () => {
		if (cancelRequested) throw new Error('CANCELLED');
	};
	const hash = hashOutput ? sha256.create() : null;
	let directory,
		fileHandle,
		writable,
		storageReady = false,
		chunks = [],
		count = 0,
		lastProgress = 0;
	const send = (message) => self.postMessage({ id, ...message });
	const cleanup = async () => {
		if (writable) {
			try {
				await writable.abort();
			} catch {}
			writable = null;
		}
		if (directory && /^job-[a-f0-9-]{36}$/.test(id)) {
			for (const name of [id, id + '.crswap']) {
				try {
					await directory.removeEntry(name);
				} catch {}
			}
		}
		chunks = [];
	};
	try {
		if (!self.isSecureContext || !crypto.subtle) throw new Error('SECURE_CONTEXT');
		if (!/^job-[a-f0-9-]{36}$/.test(id)) throw new Error('DECRYPT_FAILED');
		try {
			if (!stagingFile) throw new Error('NO_OPFS');
			directory = await (
				await navigator.storage.getDirectory()
			).getDirectoryHandle('backupplus-decryption', { create: true });
		} catch {
			await cleanup();
			directory = null;
			storageReady = true;
			send({ type: 'storage', persisted: false });
			if (file.size > MEMORY_LIMIT) throw new Error('MEMORY_LIMIT');
		}
		// Key import can spend time in synchronous WASM. Hold no OPFS write lock
		// until plaintext arrives. Only the page creates staging entries, so a
		// terminated worker returning from WASM cannot recreate a removed file.
		const prepareStorage = async () => {
			if (storageReady) return;
			storageReady = true;
			try {
				fileHandle = stagingFile;
				writable = await fileHandle.createWritable();
				send({ type: 'storage', persisted: true });
			} catch {
				await cleanup();
				directory = fileHandle = null;
				send({ type: 'storage', persisted: false });
				if (file.size > MEMORY_LIMIT) throw new Error('MEMORY_LIMIT');
			}
		};
		const sink = {
			async write(bytes) {
				checkCancelled();
				await prepareStorage();
				checkCancelled();
				count += bytes.length;
				if (hash) hash.update(bytes);
				if (writable) {
					await writable.write(bytes);
					checkCancelled();
				}
				else {
					if (count > MEMORY_LIMIT) throw new Error('MEMORY_LIMIT');
					chunks.push(bytes.slice());
				}
			},
			async finish() {
				checkCancelled();
				await prepareStorage();
				checkCancelled();
				if (writable) {
					await writable.close();
					writable = null;
					checkCancelled();
					const output = await (await directory.getFileHandle(id)).getFile();
					checkCancelled();
					return output;
				}
				const blob = new Blob(chunks, { type: 'application/octet-stream' });
				chunks = [];
				return blob;
			},
			abort: cleanup,
		};
		const result = await decryptArchive(
			file,
			credentials,
			sink,
			(fraction) => {
				const now = Date.now();
				if (now - lastProgress > 100 || fraction === 1) {
					send({ type: 'progress', fraction });
					lastProgress = now;
				}
			},
			{ agessh },
		);
		checkCancelled();
		send({ type: 'complete', ...result, ...(hash ? { hash: bytesToHex(hash.digest()) } : {}) });
	} catch (e) {
		await cleanup();
		if (cancelRequested) send({ type: 'cancelled' });
		else {
			const known = ['SECURE_CONTEXT', 'MEMORY_LIMIT', 'UNSUPPORTED_KEY'];
			send({
				type: 'error',
				code:
					e.name === 'QuotaExceededError'
						? 'STORAGE_FAILED'
						: known.includes(e.message)
							? e.message
							: 'DECRYPT_FAILED',
			});
		}
	} finally {
		if (hash) hash.destroy();
		credentials.password = credentials.identity = credentials.keyPassword = '';
		activeJob = null;
		cancelRequested = false;
	}
};
