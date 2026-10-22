import { decryptArchive } from './archive.mjs';
const MEMORY_LIMIT = 128 * 1024 * 1024;
self.onmessage = async ({ data }) => {
	const { file, id, credentials } = data;
	let directory,
		writable,
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
		if (directory) {
			try {
				await directory.removeEntry(id);
			} catch {}
		}
		chunks = [];
	};
	try {
		if (!self.isSecureContext || !crypto.subtle) throw new Error('SECURE_CONTEXT');
		if (!/^job-[a-f0-9-]{36}$/.test(id)) throw new Error('DECRYPT_FAILED');
		try {
			directory = await (
				await navigator.storage.getDirectory()
			).getDirectoryHandle('backupplus-decryption', { create: true });
			const handle = await directory.getFileHandle(id, { create: true });
			writable = await handle.createWritable();
		} catch {
			await cleanup();
			directory = null;
			send({ type: 'storage', persisted: false });
			if (file.size > MEMORY_LIMIT) throw new Error('MEMORY_LIMIT');
		}
		if (directory) send({ type: 'storage', persisted: true });
		const sink = {
			async write(bytes) {
				count += bytes.length;
				if (writable) await writable.write(bytes);
				else {
					if (count > MEMORY_LIMIT) throw new Error('MEMORY_LIMIT');
					chunks.push(bytes.slice());
				}
			},
			async finish() {
				if (writable) {
					await writable.close();
					writable = null;
					return (await directory.getFileHandle(id)).getFile();
				}
				const blob = new Blob(chunks, { type: 'application/octet-stream' });
				chunks = [];
				return blob;
			},
			abort: cleanup,
		};
		const result = await decryptArchive(file, credentials, sink, (fraction) => {
			const now = Date.now();
			if (now - lastProgress > 100 || fraction === 1) {
				send({ type: 'progress', fraction });
				lastProgress = now;
			}
		});
		send({ type: 'complete', ...result });
	} catch (e) {
		await cleanup();
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
	} finally {
		credentials.password = credentials.identity = credentials.keyPassword = '';
	}
};
