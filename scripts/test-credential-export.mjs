/** Exercise the shipped export function with saved and unsaved synthetic secrets. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../includes/backupplus-admin-common.js', import.meta.url), 'utf8');
const start = source.indexOf('\tfunction export_settings()');
const end = source.indexOf('\n\tfunction import_settings(', start);
assert(start > 0 && end > start);
const omitted = ['backupplus_ftp', 'backupplus_sftp', 'backupplus_dropbox', 'backupplus_future', 'backupplus_encryptionphrase', 'backupplus_extradbs', 'backupplus_pitr_capture', 'backupplus_pitr_recovery'];
const data = {backupplus_interval: 'daily', backupplus_database_encryption: {mode: 'age', recipients: ['public recipient']}};
for (const option of omitted) data[option] = {settings: {instance: {pass: `synthetic-export-secret-${option}`, hidden_oauth: 'synthetic-export-hidden-token'}}};
let download;
let clicks = 0;
vm.runInNewContext(source.slice(start, end) + '\nexport_settings();', {
  gather_backupplus_settings: format => { assert.equal(format, 'object'); return structuredClone(data); },
  backupplus_i18n: {credential_export_omissions: omitted, network_site_url: 'https://example.test', export_settings_file_name: 'settings.json'},
  document: {createElement: () => ({}), body: {appendChild: () => ({setAttribute: (key, value) => { if (key === 'href') download = value; }, click: () => { clicks++; }})}}
});
assert.equal(clicks, 1);
assert(!decodeURIComponent(download).includes('synthetic-export-'));
const exported = JSON.parse(decodeURIComponent(download.split(',')[1]));
assert.equal(exported.data.backupplus_interval, 'daily');
assert.deepEqual(exported.data.backupplus_database_encryption, data.backupplus_database_encryption);
for (const option of omitted) assert(!Object.hasOwn(exported.data, option));
console.log('PASS: settings export omits saved, unsaved and hidden provider credentials while preserving ordinary settings');
