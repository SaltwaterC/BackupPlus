export const utf8 = new TextEncoder();
export const text = (bytes) => new TextDecoder('utf-8', { fatal: true }).decode(bytes);
export function equal(a, b) {
	let difference = a.length ^ b.length;
	for (let i = 0; i < a.length; i++) difference |= a[i] ^ b[i];
	return difference === 0;
}
export function check(condition) {
	if (!condition) throw new Error('DECRYPT_FAILED');
}
