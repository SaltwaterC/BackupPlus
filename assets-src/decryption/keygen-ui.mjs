import { generateAgeIdentity } from './keygen.mjs';

function init() {
	const generate = document.getElementById('backupplus-age-generate');
	if (!generate) return;
	const dialog = document.getElementById('backupplus-age-key-dialog');
	const download = document.getElementById('backupplus-age-key-download');
	const saved = document.getElementById('backupplus-age-key-saved');
	const add = document.getElementById('backupplus-age-key-add');
	const cancel = document.getElementById('backupplus-age-key-cancel');
	const status = document.getElementById('backupplus-age-key-status');
	const publicKey = document.getElementById('backupplus-age-key-public');
	const recipients = document.getElementById('backupplus-age-recipients');
	const mode = document.getElementById('backupplus-database-encryption');
	const config = window.backupplus_age_keygen;
	let url = null,
		pending = null;
	function clear() {
		if (url) URL.revokeObjectURL(url);
		url = pending = null;
		download.removeAttribute('href');
		saved.checked = false;
		saved.disabled = true;
		add.disabled = true;
		publicKey.textContent = '';
	}
	function close() {
		dialog.close();
		clear();
		generate.focus();
	}
	generate.addEventListener('click', () => {
		clear();
		status.textContent = '';
		if (recipients.value.split(/\r?\n/).filter((line) => line.trim()).length >= 100) {
			status.textContent = config.limit;
			return;
		}
		try {
			const key = generateAgeIdentity();
			pending = key.recipient;
			const contents =
				'# created: ' +
				new Date().toISOString() +
				'\n# public key: ' +
				key.recipient +
				'\n' +
				key.identity +
				'\n';
			url = URL.createObjectURL(new Blob([contents], { type: 'text/plain;charset=utf-8' }));
			key.identity = '';
			download.href = url;
			download.download = 'backupplus-age-' + pending.slice(-10) + '.txt';
			publicKey.textContent = pending;
			dialog.showModal();
			download.focus();
		} catch {
			clear();
			status.textContent = config.failed;
		}
	});
	download.addEventListener('click', () => {
		saved.disabled = false;
	});
	saved.addEventListener('change', () => {
		add.disabled = !saved.checked || saved.disabled;
	});
	add.addEventListener('click', () => {
		if (!pending || !saved.checked || saved.disabled) return;
		const current = recipients.value.trim();
		recipients.value = current ? current + '\n' + pending : pending;
		mode.value = 'age';
		recipients.dispatchEvent(new Event('input', { bubbles: true }));
		recipients.dispatchEvent(new Event('change', { bubbles: true }));
		mode.dispatchEvent(new Event('change', { bubbles: true }));
		close();
		status.textContent = config.added;
	});
	cancel.addEventListener('click', close);
	dialog.addEventListener('cancel', () => {
		clear();
	});
	dialog.addEventListener('close', () => {
		if (!dialog.open) clear();
	});
	window.addEventListener('pagehide', () => {
		if (dialog.open) dialog.close();
		clear();
	});
	if (
		!window.isSecureContext ||
		!window.crypto?.getRandomValues ||
		typeof dialog.showModal !== 'function'
	) {
		generate.disabled = true;
		status.textContent = config.unsupported;
	}
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
