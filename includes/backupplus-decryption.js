/* Local archive decryption UI. Files and credentials are sent only to a same-origin worker. */
(function () {
	'use strict';
	function init() {
		var tool = document.querySelector('.backupplus-decryption-tool');
		if (!tool) return;
		var config = window.backupplus_decryption,
			input = document.getElementById('backupplus-decrypt-files');
		var start = document.getElementById('backupplus-decrypt-start'),
			cancel = document.getElementById('backupplus-decrypt-cancel');
		var clear = document.getElementById('backupplus-decrypt-clear'),
			drop = document.getElementById('backupplus-decrypt-drop');
		var status = document.getElementById('backupplus-decrypt-status'),
			results = document.getElementById('backupplus-decrypt-results');
		var progress = document.getElementById('backupplus-decrypt-progress'),
			selection = document.getElementById('backupplus-decrypt-selection');
		var fields = [
			'backupplus_db_decrypt',
			'backupplus-manual-age-identity',
			'backupplus-manual-age-passphrase',
		].map(function (id) {
			return document.getElementById(id);
		});
		var supported =
			window.isSecureContext &&
			window.Worker &&
			window.crypto &&
			crypto.subtle &&
			crypto.randomUUID;
		var files = [],
			worker = null,
			workerId = null,
			workerStopped = null,
			busy = false,
			generation = 0,
			urls = [],
			jobs = new Map();
		function wipe() {
			fields.forEach(function (field) {
				field.value = '';
			});
		}
		async function prepareStorage(id) {
			try {
				var directory = await (
					await navigator.storage.getDirectory()
				).getDirectoryHandle('backupplus-decryption', { create: true });
				return await directory.getFileHandle(id, { create: true });
			} catch (e) {
				return null;
			}
		}
		async function remove(id) {
			// Creation belongs to the page: even a worker still returning from WASM
			// after terminate() cannot recreate a file once cleanup removes it.
			await jobs.get(id);
			if (!navigator.storage || !navigator.storage.getDirectory) {
				jobs.delete(id);
				return true;
			}
			for (var attempt = 0; attempt < 5; attempt++) {
				try {
					var dir = await (
						await navigator.storage.getDirectory()
					).getDirectoryHandle('backupplus-decryption');
					for (var name of [id, id + '.crswap']) {
						try {
							await dir.removeEntry(name);
						} catch (e) {
							if (e.name !== 'NotFoundError') throw e;
						}
					}
					jobs.delete(id);
					return true;
				} catch (e) {
					// Worker termination can take a moment to release a writable file lock.
					await new Promise(function (resolve) {
						setTimeout(resolve, 50 * (attempt + 1));
					});
				}
			}
			return false;
		}
		async function stopWorker() {
			if (!worker) return;
			var current = worker,
				id = workerId,
				stopped = workerStopped;
			if (id) {
				try {
					current.postMessage({ type: 'cancel', id: id });
				} catch (e) {}
			}
			var timer,
				finished = stopped
					? await Promise.race([
							stopped.then(function () { return true; }),
							new Promise(function (resolve) { timer = setTimeout(function () { resolve(false); }, 10000); }),
						])
					: false;
			clearTimeout(timer);
			if (!finished) current.terminate();
			if (worker === current) {
				worker = null;
				workerId = null;
				workerStopped = null;
			}
		}
		function controls() {
			input.disabled = busy || !supported;
			start.disabled = busy || !supported || !files.length;
			cancel.hidden = !busy;
			fields.forEach(function (field) {
				field.disabled = busy;
			});
			tool.setAttribute('aria-busy', String(busy));
		}
		async function reset(keepBusy) {
			generation++;
			await stopWorker();
			urls.forEach(function (url) {
				URL.revokeObjectURL(url);
			});
			urls = [];
			results.replaceChildren();
			busy = Boolean(keepBusy);
			progress.hidden = true;
			controls();
			return (await Promise.all(Array.from(jobs.keys(), remove))).every(Boolean);
		}
		function select(list) {
			if (busy) return;
			files = Array.from(list);
			selection.textContent = files
				.map(function (file) {
					return file.name;
				})
				.join(', ');
			status.textContent = '';
			controls();
		}
		input.addEventListener('change', function () {
			select(input.files);
		});
		['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (event) {
			drop.addEventListener(event, function (e) {
				e.preventDefault();
				drop.classList.toggle('drag-over', event === 'dragenter' || event === 'dragover');
				if (event === 'drop') select(e.dataTransfer.files);
			});
		});
		cancel.addEventListener('click', async function () {
			wipe();
			var resetting = reset(),
				run = generation;
			var removed = await resetting;
			if (run === generation)
				status.textContent = removed ? config.cancelled : config.errors.CLEANUP_FAILED;
		});
		clear.addEventListener('click', async function () {
			wipe();
			var resetting = reset(),
				run = generation;
			var removed = await resetting;
			if (run !== generation) return;
			files = [];
			input.value = '';
			selection.textContent = '';
			status.textContent = removed ? '' : config.errors.CLEANUP_FAILED;
			controls();
		});
		window.addEventListener('pagehide', function () {
			wipe();
			reset();
		});
		start.addEventListener('click', async function () {
			if (busy) return;
			busy = true;
			controls();
			var clearing = reset(true),
				run = generation,
				removed = await clearing;
			if (run !== generation) return;
			if (!removed) {
				wipe();
				busy = false;
				controls();
				status.textContent = config.errors.CLEANUP_FAILED;
				return;
			}
			var activeJob = null;
			var credentials = {
				password: fields[0].value,
				identity: fields[1].value,
				keyPassword: fields[2].value,
			};
			wipe();
			busy = true;
			controls();
			progress.hidden = false;
			try {
				for (var file of files) {
					if (run !== generation) return;
					var id = 'job-' + crypto.randomUUID();
					activeJob = id;
					jobs.set(id, prepareStorage(id));
					var stagingFile = await jobs.get(id);
					if (run !== generation) return;
					progress.value = 0;
					status.textContent = config.decrypting + ' ' + file.name;
					var result = await new Promise(function (resolve, reject) {
						var current = (worker = new Worker(config.worker)),
							finishStop;
						workerId = id;
						workerStopped = new Promise(function (done) { finishStop = done; });
						function finish(error, value) {
							current.terminate();
							if (worker === current) {
								worker = null;
								workerId = null;
								workerStopped = null;
							}
							finishStop();
							if (error) reject(error);
							else resolve(value);
						}
					current.onerror = function (e) {
							e.preventDefault();
							finish(new Error('WORKER_FAILED'));
						};
						current.onmessage = function (e) {
							if (e.data.id !== id) return;
							if (e.data.type === 'cancelled') {
								finish(new Error('CANCELLED'));
								return;
							}
							if (run !== generation) return;
							if (e.data.type === 'progress') progress.value = e.data.fraction;
							else if (e.data.type === 'error') finish(new Error(e.data.code));
							else if (e.data.type === 'complete') finish(null, e.data);
						};
						current.postMessage({
							id: id,
							file: file,
							credentials: credentials,
							stagingFile: stagingFile,
							agessh: config.agessh,
						});
					});
					if (run !== generation) return;
					var row = document.createElement('p'),
						link = document.createElement('a');
					link.href = URL.createObjectURL(result.output);
					urls.push(link.href);
					link.className = 'button';
					link.download = /\.(crypt|age)$/i.test(file.name)
						? file.name.replace(/\.(crypt|age)$/i, '')
						: file.name + '.decrypted';
					link.textContent = config.download + ' ' + link.download;
					row.appendChild(link);
					if (result.format === 'legacy') {
						var note = document.createElement('span');
						note.className = 'backupplus-decrypt-legacy';
						note.textContent = config.legacy;
						row.appendChild(note);
					}
					results.appendChild(row);
					activeJob = null;
				}
				status.textContent = config.complete;
			} catch (e) {
				if (run === generation) {
					await stopWorker();
					var removed = !activeJob || !jobs.has(activeJob) || (await remove(activeJob));
					if (run !== generation) return;
					status.textContent = !removed
						? config.errors.CLEANUP_FAILED
						: config.errors[e.message] || config.errors.DECRYPT_FAILED;
				}
			} finally {
				credentials.password = credentials.identity = credentials.keyPassword = '';
				if (run === generation) {
					busy = false;
					progress.hidden = true;
					controls();
				}
			}
		});
		if (!supported) {
			status.textContent = config.errors.SECURE_CONTEXT;
			start.disabled = true;
			input.disabled = true;
			return;
		}
		// Remove abandoned plaintext left by an interrupted browser session after one day.
		(async function () {
			try {
				var dir = await (
					await navigator.storage.getDirectory()
				).getDirectoryHandle('backupplus-decryption');
				for await (var entry of dir.values()) {
					var name = entry.name.replace(/\.crswap$/, '');
					if (
						/^job-[a-f0-9-]{36}$/.test(name) &&
						entry.kind === 'file' &&
						Date.now() - (await entry.getFile()).lastModified > 86400000
					)
						await remove(name);
				}
			} catch (e) {}
		})();
		controls();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
