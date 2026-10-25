import { Decrypter } from 'age-encryption';
import { check } from './bytes.mjs';
import { createAgesshBridge } from './agessh-bridge.mjs';

/** Native keys stay in typage; Go's upstream parsers import SSH/PEM keys. */
export async function identities(input, password, options = {}) {
	check(typeof input === 'string' && input.length <= 65536);
	const cleaned = input
		.split(/\r?\n/)
		.filter((line) => !/^\s*#/.test(line))
		.join('\n')
		.trim();
	if (/^AGE-SECRET-KEY-/i.test(cleaned)) {
		const keys = cleaned.split(/\s+/);
		check(keys.length <= 100);
		for (const key of keys) {
			check(key === key.toUpperCase() || key === key.toLowerCase());
			check(/^AGE-SECRET-KEY-(?:PQ-)?1/.test(key.toUpperCase()));
		}
		const native = keys.map((key) => key.toUpperCase());
		const validator = new Decrypter();
		for (const key of native) validator.addIdentity(key);
		return { keys: native, async close() {} };
	}
	if (!cleaned) return { keys: [], async close() {} };
	const bridge = options.createBridge
		? await options.createBridge()
		: await createAgesshBridge(options.agessh);
	try {
		const handle = bridge.open(cleaned, password);
		return { keys: [bridge.identity(handle)], close: () => bridge.close() };
	} catch (error) {
		await bridge.close();
		if (['UNSUPPORTED_KEY', 'KEY_PASSPHRASE_REQUIRED', 'KEY_UNLOCK_FAILED'].includes(error.message))
			throw new Error(error.message === 'UNSUPPORTED_KEY' ? 'UNSUPPORTED_KEY' : 'DECRYPT_FAILED');
		throw error;
	}
}
