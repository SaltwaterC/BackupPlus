import { bech32 } from '@scure/base';

const API_NAME = 'BackupPlusAgeSSH';
let runtimeInUse = false;

function checkResult(result) {
	if (!result || result.ok !== true) throw new Error(result?.error || 'BRIDGE_ERROR');
	return result;
}

function normalizeStanzas(stanzas) {
	if (!Array.isArray(stanzas)) throw new Error('UNWRAP_FAILED');
	return stanzas.map((stanza) => {
		if (!stanza || !Array.isArray(stanza.args) || !(stanza.body instanceof Uint8Array))
			throw new Error('UNWRAP_FAILED');
		if (typeof stanza.type === 'string')
			return { type: stanza.type, args: stanza.args, body: stanza.body };
		if (typeof stanza.args[0] !== 'string') throw new Error('UNWRAP_FAILED');
		return { type: stanza.args[0], args: stanza.args.slice(1), body: stanza.body };
	});
}

async function instantiateFromURL(url, importObject, compressedURL) {
	if (compressedURL && typeof DecompressionStream === 'function') {
		const compressed = await fetch(compressedURL, { credentials: 'same-origin' });
		if (compressed.ok) {
			const bytes = new Uint8Array(await compressed.arrayBuffer());
			// Some hosts decode .gz responses themselves; accept either representation.
			const module =
				bytes[0] === 0x1f && bytes[1] === 0x8b
					? await new Response(
							new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip')),
						).arrayBuffer()
					: bytes;
			return WebAssembly.instantiate(module, importObject);
		}
	}
	const response = await fetch(url, { credentials: 'same-origin' });
	if (!response.ok) throw new Error('WASM_LOAD_FAILED');
	if (WebAssembly.instantiateStreaming) {
		try {
			return await WebAssembly.instantiateStreaming(response.clone(), importObject);
		} catch {
			// Some WordPress hosts still serve .wasm with a generic MIME type.
		}
	}
	return WebAssembly.instantiate(await response.arrayBuffer(), importObject);
}

async function waitForAPI(runPromise, getRuntimeError) {
	const deadline = Date.now() + 10000;
	while (Date.now() < deadline) {
		const api = globalThis[API_NAME];
		if (api) return api;
		const error = getRuntimeError();
		if (error) throw error;
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
	runPromise.catch(() => {});
	throw new Error('WASM_START_FAILED');
}

function guardLateResume(go) {
	const resume = go._resume;
	if (typeof resume !== 'function') throw new Error('WASM_RUNTIME_UNAVAILABLE');
	go._resume = function (...args) {
		if (this.exited) return;
		return resume.apply(this, args);
	};
}

function clearRuntimeTimeouts(go) {
	if (!go?.exited || !(go._scheduledTimeouts instanceof Map)) return;
	for (const timeout of go._scheduledTimeouts.values()) clearTimeout(timeout);
	go._scheduledTimeouts.clear();
}

/**
 * Load the upstream Go key importer/agessh in a classic worker and expose typage's
 * Identity.unwrapFileKey shape. Go receives stanza fields only; archive bytes
 * remain with typage and its ReadableStream implementation.
 */
export async function createAgesshBridge({
	wasmURL,
	compressedWasmURL,
	runtimeURL,
	wasmModule,
	GoConstructor = globalThis.Go,
} = {}) {
	if (runtimeInUse) throw new Error('BRIDGE_BUSY');
	runtimeInUse = true;
	const startedAt = performance.now();
	let api,
		go,
		runPromise,
		runtimeError = null;
	try {
		if (!wasmModule && (!wasmURL || !runtimeURL)) {
			const base = new URL(globalThis.location.href);
			wasmURL ||= new URL('backupplus-agessh.wasm' + base.search, base);
			runtimeURL ||= new URL('backupplus-agessh-wasm_exec.js' + base.search, base);
		}
		if (!GoConstructor) {
			if (typeof importScripts !== 'function' || !runtimeURL)
				throw new Error('WASM_RUNTIME_UNAVAILABLE');
			importScripts(runtimeURL);
			GoConstructor = globalThis.Go;
		}
		if (typeof GoConstructor !== 'function') throw new Error('WASM_RUNTIME_UNAVAILABLE');
		go = new GoConstructor();
		// wasm_exec can leave scheduled callbacks queued when a Go program exits.
		// They must not resume an exited runtime after this worker has moved on.
		guardLateResume(go);
		let instance;
		try {
			const result = wasmModule
				? await WebAssembly.instantiate(wasmModule, go.importObject)
				: await instantiateFromURL(wasmURL, go.importObject, compressedWasmURL);
			instance = result instanceof WebAssembly.Instance ? result : result.instance;
			runPromise = go.run(instance);
			runPromise.catch((error) => {
				if (!runtimeError) runtimeError = error;
			});
		} catch {
			throw new Error('WASM_LOAD_FAILED');
		}
		api = await waitForAPI(runPromise, () => runtimeError);
		const handles = new Map();
		let closed = false;
		function ensureOpen() {
			if (closed) throw new Error('BRIDGE_CLOSED');
			if (runtimeError) throw new Error('BRIDGE_ERROR');
		}
		const bridge = {
			startupMs: performance.now() - startedAt,
			open(privateKey, password = '') {
				ensureOpen();
				if (typeof privateKey !== 'string' || typeof password !== 'string')
					throw new Error('UNSUPPORTED_KEY');
				const { handle, nativeSecret } = checkResult(api.open(privateKey, password));
				let native = null;
				if (nativeSecret) {
					try {
						if (!(nativeSecret instanceof Uint8Array) || nativeSecret.length !== 32)
							throw new Error('UNSUPPORTED_KEY');
						native = bech32.encodeFromBytes('AGE-SECRET-KEY-', nativeSecret).toUpperCase();
					} finally {
						nativeSecret.fill(0);
					}
				}
				handles.set(handle, native);
				return handle;
			},
			unwrap(handle, stanzas) {
				ensureOpen();
				if (!handles.has(handle)) throw new Error('INVALID_HANDLE');
				const result = checkResult(api.unwrap(handle, normalizeStanzas(stanzas)));
				if (result.fileKey === null) return null;
				if (!(result.fileKey instanceof Uint8Array)) throw new Error('UNWRAP_FAILED');
				return result.fileKey;
			},
			release(handle) {
				ensureOpen();
				if (!handles.has(handle)) return false;
				const released = api.release(handle) === true;
				handles.delete(handle);
				return released;
			},
			identity(handle) {
				ensureOpen();
				if (!handles.has(handle)) throw new Error('INVALID_HANDLE');
				return (
					handles.get(handle) || { unwrapFileKey: (stanzas) => bridge.unwrap(handle, stanzas) }
				);
			},
			async close() {
				if (closed) return;
				for (const handle of handles.keys()) api.release(handle);
				handles.clear();
				closed = true;
				try {
					api.shutdown();
					await runPromise;
				} finally {
					clearRuntimeTimeouts(go);
					if (globalThis[API_NAME] === api) delete globalThis[API_NAME];
					runtimeInUse = false;
				}
			},
		};
		return bridge;
	} catch (error) {
		// Initialization failures must allow a later retry in the same worker.
		if (api) {
			try {
				api.shutdown();
				await runPromise;
			} catch {}
			if (globalThis[API_NAME] === api) delete globalThis[API_NAME];
		}
		clearRuntimeTimeouts(go);
		runtimeInUse = false;
		throw error;
	}
}
