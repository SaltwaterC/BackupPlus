/* Restore preparation: only ciphertext downloads and completed plaintext uploads cross the network. */
(function () {
	'use strict';
	var active = null;
	var ids = ['backupplus-age-identity', 'backupplus-age-key-passphrase'];
	function wipe() {
		ids.forEach(function (id) { var field = document.getElementById(id); if (field) field.value = ''; });
	}
	async function remove(id) {
		if (!id || !navigator.storage || !navigator.storage.getDirectory) return;
		for (var attempt = 0; attempt < 5; attempt++) {
			try {
				var directory = await (await navigator.storage.getDirectory()).getDirectoryHandle('backupplus-decryption');
				await directory.removeEntry(id);
				return;
			} catch (e) {
				if (e.name === 'NotFoundError') return;
				await new Promise(function (resolve) { setTimeout(resolve, 50 * (attempt + 1)); });
			}
		}
		throw new Error('CLEANUP_FAILED');
	}
	async function request(params, signal, binary) {
		var body = new URLSearchParams({ action: 'backupplus_ajax', subaction: 'restore_decryption', nonce: backupplus_credentialtest_nonce });
		Object.keys(params).forEach(function (key) {
			if (Array.isArray(params[key])) params[key].forEach(function (value) { body.append(key + '[]', value); });
			else body.set(key, params[key]);
		});
		var response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body, signal: signal, cache: 'no-store' });
		if (!response.ok) throw new Error('TRANSFER_FAILED');
		if (binary) {
			if (!(response.headers.get('Content-Type') || '').includes('application/octet-stream')) throw new Error('TRANSFER_FAILED');
			return response.blob();
		}
		var result = await response.json();
		if (result.error || result.fatal_error) { var error = new Error('TRANSFER_FAILED'); if (typeof result.error === 'string') error.publicMessage = result.error; throw error; }
		return result;
	}
	window.backupplus_cancel_restore_decryption = function () {
		wipe();
		if (active) active.controller.abort();
		else {
			var field = document.getElementById('backupplus-age-ticket');
			if (field && field.value) {
				request({ operation: 'discard', ticket: field.value }).catch(function () {});
				field.value = '';
			}
		}
	};
	window.addEventListener('pagehide', function () { wipe(); if (active) active.controller.abort(); });
	window.backupplus_prepare_restore = async function () {
		if (active) throw new Error('BUSY');
		var config = window.backupplus_decryption;
		var state = { controller: new AbortController(), worker: null, job: null, ticket: '' };
		active = state;
		var credentials = { password: '', identity: '', keyPassword: '' };
		var fields = ids.map(function (id) { return document.getElementById(id); });
		credentials.identity = fields[0].value;
		credentials.keyPassword = fields[1].value;
		wipe();
		fields.forEach(function (field) { field.disabled = true; });
		var signal = state.controller.signal;
		signal.addEventListener('abort', function () { credentials.password = credentials.identity = credentials.keyPassword = ''; }, { once: true });
		var status = document.getElementById('backupplus-restore-modal-stage2a');
		var ticketField = document.getElementById('backupplus-age-ticket');
		function check() { signal.throwIfAborted(); }
		function show(message, file, fraction) {
			check();
			status.textContent = message + ' ' + file + (fraction === undefined ? '' : ' (' + Math.round(fraction * 100) + '%)');
		}
		try {
			var entities = Array.from(document.querySelectorAll('#backupplus_restore_form [name="backupplus_restore[]"]:checked:not(:disabled)')).map(function (field) { return field.value; });
			var pointField = document.getElementById('backupplus_pitr_restore_point');
			var manifest = await request({ operation: 'manifest', timestamp: document.getElementById('backupplus_restore_timestamp').value, point: pointField && !pointField.disabled ? pointField.value : '', entities: entities, ticket: ticketField.value }, signal);
			state.ticket = manifest.ticket;
			check();
			if (manifest.files.length && (!window.isSecureContext || !window.Worker || !window.crypto || !crypto.subtle || !crypto.randomUUID)) throw new Error('SECURE_CONTEXT');
			for (var file of manifest.files) {
				show(config.downloading, file);
				var ciphertext = await request({ operation: 'download', ticket: state.ticket, file: file }, signal, true);
				check();
				state.job = 'job-' + crypto.randomUUID();
				show(config.decrypting, file, 0);
				var result = await new Promise(function (resolve, reject) {
					var id = state.job;
					var worker = state.worker = new Worker(config.worker);
					function abort() { worker.terminate(); reject(new DOMException('Cancelled', 'AbortError')); }
					signal.addEventListener('abort', abort, { once: true });
					function finish(error, value) {
						signal.removeEventListener('abort', abort);
						worker.terminate();
						state.worker = null;
						if (error) reject(error); else resolve(value);
					}
					worker.onerror = function (e) { e.preventDefault(); finish(new Error('WORKER_FAILED')); };
					worker.onmessage = function (e) {
						if (e.data.id !== id || signal.aborted) return;
						if (e.data.type === 'progress') show(config.decrypting, file, e.data.fraction);
						else if (e.data.type === 'error') finish(new Error(e.data.code));
						else if (e.data.type === 'complete') finish(null, e.data);
					};
					worker.postMessage({ id: id, file: ciphertext, credentials: credentials, hashOutput: true });
				});
				ciphertext = null;
				check();
				// The worker returns output only after authenticating the complete archive.
				var output = result.output;
				for (var offset = 0; offset < output.size || offset === 0; offset += 524288) {
					check();
					show(config.uploading, file, output.size ? offset / output.size : 1);
					var chunk = new Uint8Array(await output.slice(offset, offset + 524288).arrayBuffer());
					var binary = '';
					for (var index = 0; index < chunk.length; index += 8192) binary += String.fromCharCode.apply(null, chunk.subarray(index, index + 8192));
					var params = { operation: 'upload', ticket: state.ticket, file: file, offset: offset, chunk: btoa(binary) };
					if (offset + chunk.length === output.size) { params.size = output.size; params.hash = result.hash; }
					await request(params, signal);
				}
				await remove(state.job);
				state.job = null;
			}
			check();
			ticketField.value = state.ticket;
			return true;
		} catch (e) {
			ticketField.value = '';
			if (state.worker) state.worker.terminate();
			try { await remove(state.job); } catch (cleanupError) { e = cleanupError; }
			if (state.ticket) await request({ operation: 'discard', ticket: state.ticket }).catch(function () {});
			if (!signal.aborted) status.textContent = e.publicMessage || config.errors[e.message] || config.errors.TRANSFER_FAILED;
			return false;
		} finally {
			credentials.password = credentials.identity = credentials.keyPassword = '';
			wipe();
			fields.forEach(function (field) { field.disabled = false; });
			active = null;
		}
	};
})();
