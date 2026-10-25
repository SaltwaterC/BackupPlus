/** Chromium checks for admin actions, backup source controls and responsive connection layouts. */
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { tmpdir, homedir } from 'node:os';
import path from 'node:path';
const origin = process.env.BACKUPPLUS_TEST_URL || 'http://localhost:8080';
const candidates = (process.env.PATH || '').split(path.delimiter).flatMap((p) => ['chromium', 'chromium-browser', 'google-chrome'].map((n) => path.join(p, n)));
const cache = path.join(homedir(), '.cache/ms-playwright');
if (existsSync(cache)) for (const folder of readdirSync(cache).sort().reverse()) if (folder.startsWith('chromium_headless_shell-')) candidates.push(path.join(cache, folder, 'chrome-headless-shell-linux64/chrome-headless-shell'));
const chrome = process.env.CHROMIUM || candidates.find(existsSync);
assert(chrome, 'Chromium is required. Set CHROMIUM to its executable.');
const directory = await mkdtemp(path.join(tmpdir(), 'backupplus-sources-browser-'));
const browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--remote-debugging-port=0', '--user-data-dir=' + directory, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
let browserError = '';
browser.stderr.on('data', (d) => { browserError += d; });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let ws;
try {
	let port;
	for (let i = 0; i < 100; i++) {
		try { port = (await readFile(path.join(directory, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch {}
		await sleep(100);
	}
	assert(port, 'Chromium did not start: ' + browserError);
	const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then((r) => r.json());
	ws = new WebSocket(pages.find((p) => p.type === 'page').webSocketDebuggerUrl);
	await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
	let sequence = 0;
	const pending = new Map();
	ws.addEventListener('message', ({ data }) => {
		const message = JSON.parse(data);
		if (!message.id) return;
		const request = pending.get(message.id);
		pending.delete(message.id);
		message.error ? request.reject(new Error(JSON.stringify(message.error))) : request.resolve(message.result);
	});
	const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); });
	const evaluate = async (expression) => {
		const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
		if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
		return result.result.value;
	};
	async function wait(expression) {
		for (let i = 0; i < 200; i++) { try { if (await evaluate(expression)) return; } catch {} await sleep(100); }
		throw new Error('Timed out: ' + expression);
	}
	await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
	await call('Page.enable');
	await call('Runtime.enable');
	await call('Page.navigate', { url: origin + '/?backupplus_dev_login=' + encodeURIComponent(process.env.BACKUPPLUS_DEV_LOGIN_TOKEN || 'backupplus-local-dev-login') });
	await wait('location.pathname.includes("/wp-admin/") && document.readyState === "complete"');

	await call('Page.navigate', {url: origin + '/wp-admin/options-general.php?page=backupplus&tab=settings'});
	await wait('document.readyState === "complete" && window.jQuery && window.backupplus_database_override && document.querySelector(".bp-databases")');
	assert.equal(await evaluate(`/Fatal error:|Warning:|Notice:|Deprecated:/.test(document.body.innerText)`), false, 'Settings render without PHP errors');
	assert.match(await evaluate(`jQuery('label[for="backupplus_retain_db"]').text().replace(/\\s+/g,' ').trim()`), /^Keep backup chains$/, 'Database retention uses the same chain wording as file backups');
	await evaluate(`backupplus_open_main_tab('backups'); true`);
	const scheduledLayout = await evaluate(`(() => {
		const box=id=>document.getElementById(id).closest('.backupplus_next_scheduled_entity').getBoundingClientRect();
		const files=box('backupplus-next-files-backup-inner'), increments=box('backupplus-next-incremental-backup-inner'), db=box('backupplus-next-database-backup-inner'), pitr=box('backupplus-next-pitr-backup-inner'), cleanup=box('backupplus-next-cleanup-inner');
		return {firstRow:Math.abs(files.top-increments.top)<2,secondRow:Math.abs(db.top-pitr.top)<2,pitrRight:pitr.left>db.left,cleanupBelow:cleanup.top>=Math.max(db.bottom,pitr.bottom)-1,cleanupLeft:Math.abs(cleanup.left-db.left)<2};
	})()`);
	assert.deepEqual(scheduledLayout,{firstRow:true,secondRow:true,pitrRight:true,cleanupBelow:true,cleanupLeft:true}, 'PITR occupies the second row beside Database and Cleanup starts the third row on the left');
	if(process.env.BACKUPPLUS_SCHEDULE_SCREENSHOT) {
		const screenshot=await call('Page.captureScreenshot',{format:'png'});
		await writeFile(process.env.BACKUPPLUS_SCHEDULE_SCREENSHOT,Buffer.from(screenshot.data,'base64'));
	}
	await evaluate(`backupplus_open_main_tab('settings'); true`);

	assert.deepEqual(await evaluate(`(() => {
		const send=window.backupplus_send_command;
		const requests=[];
		const secret='fixture-db-admin-'+Date.now();
		let ready=false, pending=null;
		try {
			window.backupplus_send_command=(command,params,done,options)=>{
				if(command!=='pitr_setup') return send(command,params,done,options);
				requests.push({...params});
				if(params.operation==='status') done({ready,message:ready?'Ready':'Set up recovery'});
				else if(params.operation==='wordpress') done({ready,message:'Access checked'});
				else pending=done;
			};
			jQuery('#backupplus-pitr-setup-open').trigger('click');
			const visible=jQuery('#backupplus-pitr-setup-modal').dialog('isOpen') && jQuery('#backupplus-pitr-setup-signin').is(':visible');
			const content=document.querySelector('#backupplus-pitr-setup-modal');
			const bounds=jQuery(content).dialog('widget')[0].getBoundingClientRect();
			const fits=bounds.width>=700 && bounds.height>=400 && content.scrollHeight<=content.clientHeight+1;
			jQuery('#backupplus-pitr-setup-wordpress').trigger('click');
			const automatic=requests[1].operation==='wordpress' && !requests[1].password;
			jQuery('#backupplus-pitr-setup-user').val('fixture-admin');
			jQuery('#backupplus-pitr-setup-password').val(secret);
			const separate=!jQuery('form').serialize().includes(secret) && !jQuery('#backupplus-pitr-setup-password').attr('name');
			jQuery('#backupplus-pitr-setup-check').trigger('click');
			const kept=requests[2].username==='fixture-admin' && requests[2].password===secret && jQuery('#backupplus-pitr-setup-user').val()==='fixture-admin' && jQuery('#backupplus-pitr-setup-password').val()===secret;
			jQuery('#backupplus-pitr-setup-connect').trigger('click');
			jQuery('#backupplus-pitr-setup-modal').dialog('close');
			const busy=!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen') && jQuery('#backupplus-pitr-setup-password').prop('disabled') && jQuery('#backupplus-pitr-setup-password').val()==='';
			pending({error:'Insufficient database privileges'});
			const failed=!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen') && jQuery('#backupplus-pitr-setup-password').val()==='' && jQuery('#backupplus-pitr-setup-status').text()==='Insufficient database privileges';
			jQuery('#backupplus-pitr-setup-open').trigger('click');
			jQuery('#backupplus-pitr-setup-user').val('fixture-recovery');
			jQuery('#backupplus-pitr-setup-password').val(secret);
			jQuery('#backupplus-pitr-setup-connect').trigger('click');
			ready=true; pending({ready:true,message:'Ready'});
			const connected=!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen') && jQuery('#backupplus-pitr-setup-user').val()==='' && jQuery('#backupplus-pitr-setup-password').val()==='' && jQuery('#backupplus-pitr-setup-summary').text()==='Ready';
			jQuery('#backupplus-pitr-setup-open').trigger('click');
			jQuery('#backupplus-pitr-setup-close').trigger('click');
			return {visible,fits,automatic,separate,kept,busy,failed,connected,closed:!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen'),operations:requests.map(r=>r.operation),noSecretEcho:!document.body.innerText.includes(secret) && !Object.values(localStorage).some(v=>v.includes(secret))};
		} finally {
			window.backupplus_send_command=send;
			jQuery('#backupplus-pitr-setup-user,#backupplus-pitr-setup-password').val('');
			jQuery('#backupplus-pitr-setup-modal').dialog('close');
		}
	})()`), {visible:true,fits:true,automatic:true,separate:true,kept:true,busy:true,failed:true,connected:true,closed:true,operations:['status','wordpress','status','connect','status','connect','status'],noSecretEcho:true}, 'Backup account setup fits its contents, permits dismissal during requests, checks typed credentials without clearing them, and closes with a summary once saved');
	await evaluate(`(() => {
		window.pitrDialogTestSend=window.backupplus_send_command;
		window.backupplus_send_command=(command,params,done,options)=>{
			if(command!=='pitr_setup') return window.pitrDialogTestSend(command,params,done,options);
			window.pitrDialogTestFinish=done;
		};
		jQuery('#backupplus-pitr-setup-open').trigger('click');
		return true;
	})()`);
	await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
	await call('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
	assert.equal(await evaluate(`!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen')`), true, 'Escape dismisses the backup account dialog while its access check is pending');
	await evaluate(`pitrDialogTestFinish({ready:true,message:'Ready'}); true`);
	assert.equal(await evaluate(`!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen')`), true, 'Completing a dismissed access check does not reopen the dialog');
	await evaluate(`window.backupplus_send_command=(command,params,done,options)=>command==='pitr_setup'?done({ready:true,message:'Ready'}):pitrDialogTestSend(command,params,done,options); jQuery('#backupplus-pitr-setup-open').trigger('click'); true`);
	await call('Emulation.setDeviceMetricsOverride', {width:380,height:650,deviceScaleFactor:1,mobile:false});
	await wait(`(() => {const r=jQuery('#backupplus-pitr-setup-modal').dialog('widget')[0].getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.top>=0 && r.bottom<=innerHeight;})()`);
	await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
	await call('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
	assert.equal(await evaluate(`!jQuery('#backupplus-pitr-setup-modal').dialog('isOpen')`), true, 'Escape also dismisses the resized dialog after a completed check');
	await evaluate(`window.backupplus_send_command=pitrDialogTestSend; delete window.pitrDialogTestSend; delete window.pitrDialogTestFinish; true`);
	await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
	assert.deepEqual(await evaluate(`(() => {
		const container = jQuery('#backupnow_database_container');
		const enabled = container.data('pitr-enabled');
		const send = window.backupplus_send_command, override = window.backupplus_database_override;
		const confirm = window.confirm, alert = window.alert, changed = backupplus_settings_form_changed;
		const requests = [], alerts = [];
		try {
			window.confirm = () => true;
			window.alert = message => alerts.push(message);
			window.backupplus_send_command = (command, params) => requests.push({command, params});
			backupplus_settings_form_changed = false;
			container.data('pitr-enabled', 0);
			backupplus_backup_dialog_open('incremental');
			const disabled = !container.is(':visible') && !jQuery('#backupnow_includedb').is(':checked') && jQuery('#backupnow_pitr_disabled').is(':visible');
			jQuery('#backupplus-backupnow-modal').dialog('close');
			container.data('pitr-enabled', 1);
			backupplus_backup_dialog_open('incremental');
			const enabledUI = container.is(':visible') && jQuery('#backupnow_includedb').is(':checked') && jQuery('#backupnow_includefiles').is(':visible') && !jQuery('#backupnow_database_showmoreoptions').is(':visible');
			jQuery('#backupnow_includefiles').prop('checked', false).trigger('change');
			const databaseOnly = !jQuery('#backupnow_remote_container').is(':visible');
			window.backupplus_database_override = () => null;
			jQuery('#backupnow_includecloud').prop('checked', true);
			jQuery("input[name^='backupplus_include_remote_service_']").prop('checked', false);
			const modal = jQuery('#backupplus-backupnow-modal');
			modal.dialog('option', 'buttons')[backupplus_i18n.backupnow].call(modal[0]);
			backupplus_backup_dialog_open('new');
			const ordinary = jQuery('#backupnow_database_showmoreoptions').is(':visible') && jQuery('#backupnow_includedb_label').text() === backupplus_i18n.database_new_backup;
			return {disabled, enabledUI, databaseOnly, ordinary, alerts, requests: requests.map(({command, params}) => ({command, incremental: params.incremental, nofiles: params.backupnow_nofiles, nodb: params.backupnow_nodb, selection: Object.hasOwn(params, 'database_selection'), tables: Object.hasOwn(params, 'onlythesetableentities')}))};
		} finally {
			jQuery('#backupplus-backupnow-modal').dialog('close');
			jQuery('#backupnow_includefiles').prop('checked', true);
			container.data('pitr-enabled', enabled);
			window.backupplus_send_command = send;
			window.backupplus_database_override = override;
			window.confirm = confirm;
			window.alert = alert;
			backupplus_settings_form_changed = changed;
		}
	})()`), {disabled: true, enabledUI: true, databaseOnly: true, ordinary: true, alerts: [], requests: [{command: 'backupnow', incremental: 1, nofiles: 1, nodb: 0, selection: false, tables: false}]}, 'Incremental Backup offers a PITR-only request without logical-table or temporary-storage validation');

	assert.deepEqual(await evaluate(`(async () => {
		const root = jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups tbody').first();
		const row = jQuery('<tr><td><button type="button" class="choose-components-button">Fixture</button></td></tr>').appendTo(root);
		const button = row.find('button');
		const send = window.backupplus_send_command, prepare = window.backupplus_prepare_restore, alert = window.alert, updatehistory = window.backupplus_updatehistory;
		const requests = [], alerts = [];
		let refreshed = 0;
		try {
			window.backupplus_updatehistory = () => refreshed++;
			window.alert = message => alerts.push(message);
			window.backupplus_prepare_restore = async () => true;
			window.backupplus_send_command = (command, params, done, options) => {
				if (command !== 'pitr_restore') return send(command, params, done, options);
				requests.push({...params});
				done({verified: true});
			};
			const points = [3,2,1].map((n,index) => ({backup_id: String(n).repeat(32),increment:n-1,timestamp:100,encrypted:true,label:'Point '+n+' - '+(n===1?'full database backup':'database incremental '+(n-1))}));
			button.data({pitr:1,backup_timestamp:100,entities:'/db=0/crypted=1/',showdata:'Full date',restorepoints:points}).trigger('click');
			const selector = jQuery('#backupplus_pitr_restore_point');
			const visible = jQuery('#backupplus_pitr_restore_row').is(':visible') && !jQuery('#backupplus_restore_point_row').is(':visible');
			const values = selector.find('option').map(function(){return this.value}).get();
			const databaseSelected = jQuery('#backupplus_restore_db').is(':checked');
			jQuery('#backupplus_restore_db').prop('checked',false);
			jQuery('.backupplus-restore--next-step').trigger('click');
			const deselected = requests.length === 0 && alerts.length === 1;
			jQuery('#backupplus_restore_db').prop('checked',true);
			const automatic = !document.querySelector('#backupplus_pitr_writers_stopped');
			jQuery('#backupplus-pitr-restore-user').val('fixture-restore');
			jQuery('#backupplus-pitr-restore-password').val('fixture-restore-secret');
			selector.val(points[1].backup_id).trigger('change');
			jQuery('.backupplus-restore--next-step').trigger('click');
			await new Promise(resolve => setTimeout(resolve,50));
			const verified = backupplus_restore_stage === 3 && jQuery('.backupplus-restore--next-step').text() === backupplus_i18n.pitr_restore_database;
			jQuery('.backupplus-restore--next-step').trigger('click');
			await new Promise(resolve => setTimeout(resolve,50));
			const credentialsCleared = !jQuery('#backupplus-pitr-restore-user').val() && !jQuery('#backupplus-pitr-restore-password').val();
			const completed = credentialsCleared && backupplus_restore_stage === 4 && jQuery('.backupplus-restore--next-step').text() === backupplus_i18n.restore_done && !jQuery('.backupplus-restore--next-step').prop('disabled') && !jQuery('.backupplus-restore--cancel').is(':visible') && jQuery('#backupplus-restore-stage-heading').text() === backupplus_i18n.pitr_restore_complete;
			jQuery('.backupplus-restore--next-step').trigger('click');
			const dismissed = !jQuery('.backupplus_restore_container').is(':visible') && refreshed === 1 && requests.length === 2;
			button.data({pitr:0,entities:'/plugins=0/',restorepoints:[{timestamp:200,label:'Second - incremental',encrypted:false},{timestamp:100,label:'First - full',encrypted:false}]}).trigger('click');
			const ordinary = !jQuery('#backupplus_pitr_restore_row').is(':visible') && jQuery('#backupplus_pitr_restore_point').prop('disabled') && jQuery('#backupplus_restore_point_row').is(':visible') && jQuery('.backupplus-restore--cancel').is(':visible') && jQuery('.backupplus-restore--next-step').text() !== backupplus_i18n.restore_done && jQuery('#backupplus-restore-stage-heading').text() !== backupplus_i18n.pitr_restore_complete;
			return {visible, values, databaseSelected, deselected, automatic, verified, completed, dismissed, ordinary, requests:requests.map(p=>({operation:p.operation,point:p.point,keys:Object.keys(p).sort()}))};
		} finally {
			jQuery('.backupplus-restore--cancel').trigger('click');
			window.backupplus_send_command=send; window.backupplus_prepare_restore=prepare; window.alert=alert;
			window.backupplus_updatehistory=updatehistory;
			row.remove();
			backupplus_open_main_tab('settings');
		}
	})()`), {visible:true,values:['3'.repeat(32),'2'.repeat(32),'1'.repeat(32)],databaseSelected:true,deselected:true,automatic:true,verified:true,completed:true,dismissed:true,ordinary:true,requests:[{operation:'verify',point:'2'.repeat(32),keys:['operation','password','point','ticket','timestamp','username']},{operation:'restore',point:'2'.repeat(32),keys:['operation','password','point','ticket','timestamp','username']}]}, 'PITR restore verifies points, completes with Done, refreshes history on dismissal, and resets ordinary restore controls');
	assert.deepEqual(await evaluate(`(async () => {
		backupplus_open_main_tab('backups');
		const row = jQuery('<tr><td><button type="button" class="choose-components-button">Baseline fixture</button></td></tr>').appendTo('#backupplus-navtab-backups-content .backupplus_existing_backups tbody:first');
		const button = row.find('button');
		const send = window.backupplus_send_command, prepare = window.backupplus_prepare_restore, animations = jQuery.fx.off, skippedScan = skipped_db_scan;
		const requests = [], cases = [];
		try {
			jQuery.fx.off = true;
			skipped_db_scan = 1;
			jQuery('#backupplus_restore_form').on('submit.baseline_test', event => event.preventDefault());
			window.backupplus_prepare_restore = async () => true;
			window.backupplus_send_command = (command, params, done, options) => {
				if (!['whichdownloadsneeded', 'restore_alldownloaded', 'pitr_restore'].includes(command)) return send(command, params, done, options);
				requests.push(command);
				if (command === 'whichdownloadsneeded') done({downloads: []});
				else if (command === 'restore_alldownloaded') done({m: '', w: '', e: '', i: '{}'});
				else throw new Error('Full snapshot was sent to native replay');
			};
			for (const encrypted of [false, true]) {
				const baseline = {backup_id: '1'.repeat(32), increment: 0, timestamp: 100, encrypted, label: 'First - full database backup'};
				const increment = {backup_id: '2'.repeat(32), increment: 1, timestamp: 200, encrypted, label: 'Second - database incremental 1'};
				button.data({pitr: 1, backup_timestamp: 100, entities: '/db=0/crypted='+(encrypted?1:0)+'/', showdata: 'First', restorepoints: [increment, baseline]}).trigger('click');
				const selector = jQuery('#backupplus_pitr_restore_point');
				jQuery('#backupplus-pitr-restore-user').val('stale-user');
				jQuery('#backupplus-pitr-restore-password').val('stale-password');
				selector.val(baseline.backup_id).trigger('change');
				const full = !jQuery('#backupplus_restore_form').data('pitr') && jQuery('#backupplus-pitr-restore-baseline').is(':visible') && !jQuery('#backupplus-pitr-restore-replay').is(':visible') && jQuery('#backupplus_restorer_dboptions').is(':visible');
				const credentials = jQuery('#backupplus-pitr-restore-user,#backupplus-pitr-restore-password').get().every(input => input.disabled && !input.value);
				const decryption = jQuery('.backupplus-restore-encryption').is(':visible') === encrypted;
				selector.val(increment.backup_id).trigger('change');
				const replay = jQuery('#backupplus_restore_form').data('pitr') && jQuery('#backupplus-pitr-restore-replay').is(':visible') && !jQuery('#backupplus-pitr-restore-user').prop('disabled') && !jQuery('#backupplus_restorer_dboptions').is(':visible');
				selector.val(baseline.backup_id).trigger('change');
				jQuery('.backupplus-restore--next-step').trigger('click');
				await new Promise(resolve => setTimeout(resolve, 50));
				cases.push({encrypted, full, credentials, decryption, replay, ordinary: backupplus_restore_stage === 3});
				jQuery('.backupplus-restore--next-step').trigger('click');
				cases[cases.length-1].submittedPoint = new URLSearchParams(jQuery('#backupplus_restorer_restore_options').val()).get('backupplus_pitr_restore_point') === baseline.backup_id;
				jQuery('.backupplus-restore--cancel').trigger('click');
				// A chain with only its full snapshot must open without asking for restore credentials.
				button.data('restorepoints', [baseline]).trigger('click');
				cases[cases.length-1].fullOnly = !jQuery('#backupplus_restore_form').data('pitr') && jQuery('#backupplus-pitr-restore-user').prop('disabled');
				jQuery('.backupplus-restore--cancel').trigger('click');
			}
			return {cases, requests};
		} finally {
			jQuery('.backupplus-restore--cancel').trigger('click');
			window.backupplus_send_command = send;
			window.backupplus_prepare_restore = prepare;
			jQuery.fx.off = animations;
			skipped_db_scan = skippedScan;
			jQuery('#backupplus_restore_form').off('submit.baseline_test');
			row.remove();
		}
	})()`), {cases: [false,true].map(encrypted => ({encrypted, full:true, credentials:true, decryption:true, replay:true, ordinary:true, submittedPoint:true, fullOnly:true})), requests:['whichdownloadsneeded','restore_alldownloaded','whichdownloadsneeded','restore_alldownloaded']}, 'PITR full snapshots use ordinary database restore without restore credentials, including encrypted and full-only chains');
	assert.deepEqual(await evaluate(`(async () => {
		backupplus_open_main_tab('backups');
		const root = jQuery('#backupplus-navtab-backups-content .backupplus_existing_backups tbody').first();
		const selected = root.find('.backuprowselected').removeClass('backuprowselected');
		const rows = jQuery('<tr class="backupplus_existing_backups_row backupplus_existing_backups_row_987654321" data-key="987654321" data-nonce="fixture"><td><div class="backupplus-remove" data-hasremote="1"><a class="backupplus-delete-link" data-key="987654321" data-nonce="fixture" data-hasremote="1" data-chain="Database fixture: Full + 2 incrementals">Delete</a></div></td></tr><tr class="backupplus_existing_backups_row backupplus_existing_backups_row_987654322" data-key="987654322" data-nonce="ordinary"><td><div class="backupplus-remove" data-hasremote="0"><a class="backupplus-delete-link" data-key="987654322" data-nonce="ordinary" data-hasremote="0">Delete</a></div></td></tr>').appendTo(root);
		const send=window.backupplus_send_command, alert=window.alert, changed=backupplus_settings_form_changed;
		const heading=jQuery('#backupplus-existing-backups-heading').html();
		const requests=[], alerts=[];
		try {
			window.alert=message=>alerts.push(message);
			window.backupplus_send_command=(command,params,done,options)=>{
				if(command!=='deleteset') return send(command,params,done,options);
				requests.push({timestamp:params.backup_timestamp,remote:!!params.delete_remote});
				done({result:'success',backup_local:7,backup_remote:0,backup_sets:2,deleted_timestamps:params.backup_timestamp,error_messages:'',set_message:'Removed',local_message:'Local',remote_message:'Remote'});
			};
			rows.first().find('a').trigger('click');
			const single=jQuery('#backupplus_delete_chain_warning').is(':visible') && jQuery('#backupplus_delete_chain_warning li').text()==='Database fixture: Full + 2 incrementals';
			jQuery('#backupplus-delete-modal').dialog('close');
			rows.addClass('backuprowselected');
			backupplus_deleteallselected();
			const mixed=jQuery('#backupplus_delete_question_plural').is(':visible') && jQuery('#backupplus_delete_chain_warning li').length===1 && jQuery('#backupplus_delete_timestamp').val()==='987654321,987654322';
			jQuery('#backupplus_delete_remote').prop('checked',false);
			backupplus_settings_form_changed=false;
			const modal=jQuery('#backupplus-delete-modal');
			modal.dialog('option','buttons')[backupplus_i18n.deletebutton].call(modal[0]);
			await new Promise(resolve=>setTimeout(resolve,1100));
			return {single,mixed,removed:!root.find('.backupplus_existing_backups_row_987654321,.backupplus_existing_backups_row_987654322').length,requests,summary:alerts.length===1 && !alerts[0].includes('wp backupplus')};
		} finally {
			jQuery('#backupplus-delete-modal').dialog('close');
			rows.remove(); selected.addClass('backuprowselected');
			window.backupplus_send_command=send; window.alert=alert;
			backupplus_settings_form_changed=changed;
			jQuery('#backupplus_delete_remote').prop('checked',true);
			jQuery('#backupplus-existing-backups-heading').html(heading);
			backupplus_open_main_tab('settings');
		}
	})()`), {single:true,mixed:true,removed:true,requests:[{timestamp:'987654321,987654322',remote:false}],summary:true}, 'PITR and mixed Delete use the existing chain confirmation, remote checkbox and normal completion flow');
	assert.deepEqual(await evaluate(`(() => {
		const ids = [...document.querySelectorAll('[id]')].map(element => element.id);
		return ids.filter((id, index) => id.trim() !== id || ids.indexOf(id) !== index);
	})()`), [], 'Admin controls must have unique IDs without surrounding whitespace');
	assert.deepEqual(await evaluate(`(() => {
		const original = window.backupplus_popuplog, calls = [];
		const panel = jQuery('<div><div><a class="backupplus-log-link" data-jobid="test-job">Log</a></div></div>').appendTo('body');
		window.backupplus_popuplog = id => calls.push(id);
		try {
			panel.find('a')[0].click();
			panel.html('<div><a class="backupplus-log-link" data-jobid="test-job" data-fileid="test-file">Log</a></div>');
			panel.find('a')[0].click();
			return calls;
		} finally {
			window.backupplus_popuplog = original;
			panel.remove();
		}
	})()`), ['test-job', 'test-file'], 'Refreshed log links open once and use the file ID when provided');
	for (const email of [true, false]) {
		await evaluate(`set_email_report_storage_interface(${email}); true`);
		assert.deepEqual(await evaluate(`[
			jQuery('#cb_email_storage_label').css('display') !== 'none',
			jQuery('#cb_not_email_storage_label').css('display') !== 'none',
			jQuery('#backupplus_email').prop('disabled')
		]`), [email, !email, email], 'Email reporting text and controls follow the storage selection');
	}
	await evaluate(`jQuery.fx.off = true; backupplus_open_main_tab('settings'); jQuery('.backupplus_add_exclude_item').first().trigger('click'); jQuery('.backupplus-exclude-link[data-panel="contain-clause"]').trigger('click'); true`);
	await wait(`jQuery('#backupplus_exclude_clause_field').is(':visible')`);
	await evaluate(`document.querySelector('label.contain-clause-sub-label').click(); true`);
	assert.equal(await evaluate(`document.activeElement.id`), 'backupplus_exclude_clause_field', 'The wildcard label focuses its own field');
	await evaluate(`jQuery('#backupplus_exclude_modal').dialog('close'); true`);
	assert.equal(await evaluate(`(() => {
		const files = document.querySelector('#backupplus_include_wpcore').closest('tr');
		const databases = document.querySelector('#backupplus-database-settings');
		const email = document.querySelector('#backupplus_report_row_no_addon');
		return !!(files.compareDocumentPosition(databases) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(databases.compareDocumentPosition(email) & Node.DOCUMENT_POSITION_FOLLOWING);
	})()`), true, 'Database connections follow file inclusion and precede Email');
	assert.equal(await evaluate(`!!document.querySelector('#backupplus_include_wpcore')`), true, 'Core opt-in checkbox is rendered with a valid ID');
	assert.equal(await evaluate(`!!document.querySelector('[name="backupplus_include_more_path"]')`), true, 'Additional paths editor is rendered');
	for (const entity of ['wpcore', 'more']) {
		await evaluate(`jQuery.fx.off = true; jQuery('#backupplus_include_${entity}').prop('checked', false).trigger('click'); true`);
		await wait(`jQuery('#backupplus_include_${entity}_exclude_container').is(':visible')`);
		await evaluate(`jQuery('#backupplus_include_${entity}_exclude_container .backupplus_add_exclude_item').trigger('click'); jQuery('.backupplus-exclude-link[data-panel="extension"]').trigger('click'); jQuery('#backupplus_exclude_extension_field').val('bptest'); jQuery('.backupplus-exclude-submit[data-panel="extension"]').trigger('click'); true`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bptest')`), true, entity + ' modal adds a rule to the submitted value');
		await evaluate(`(() => {
			const row = jQuery('#backupplus_include_${entity}_exclude_container .backupplus_exclude_entity_wrapper').last();
			row.find('.backupplus_exclude_entity_edit').trigger('click');
			row.find('input').val('ext:bpedited');
			row.find('.backupplus_exclude_entity_update').trigger('click');
		})()`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bpedited')`), true, entity + ' rule edits update the submitted value');
		await evaluate(`(() => {
			const confirm = window.confirm;
			window.confirm = () => true;
			jQuery('#backupplus_include_${entity}_exclude_container .backupplus_exclude_entity_delete').last().trigger('click');
			window.confirm = confirm;
		})()`);
		assert.equal(await evaluate(`jQuery('#backupplus_include_${entity}_exclude').val().split(',').includes('ext:bpedited')`), false, entity + ' rule deletion updates the submitted value');
		await evaluate(`jQuery('#backupplus_include_${entity}_exclude_container .backupplus_add_exclude_item').trigger('click'); jQuery('.backupplus-exclude-link[data-panel="file-dir"]').trigger('click'); true`);
		await wait(`jQuery('#backupplus_exclude_files_folders_jstree .jstree-anchor').length > 0`);
		await evaluate(`jQuery('#backupplus_exclude_modal').dialog('close'); true`);
		await evaluate(`jQuery('#backupplus_include_${entity}').trigger('click'); true`);
		await wait(`!jQuery('#backupplus_include_${entity}_exclude_container').is(':visible')`);
		await evaluate(`jQuery('#backupplus_include_${entity}').trigger('click'); true`);
	}
	if (process.env.BACKUPPLUS_FILES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus_include_wpcore').scrollIntoView(); window.scrollBy(0,-80); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_FILES_SCREENSHOT, Buffer.from(screenshot.data,'base64'));
	}
	assert.equal(await evaluate(`fetch(backupplus_sources.ajax, {method:'POST',body:new URLSearchParams({action:'backupplus_source_tables',nonce:'invalid'})}).then(r=>r.status)`), 403, 'Table discovery rejects invalid nonces');
	await evaluate(`jQuery('.bp-add-database').first().trigger('click'); jQuery('.bp-add-database').first().trigger('click'); true`);
	assert.equal(await evaluate(`new Set(jQuery('.bp-databases[data-manual="0"] .bp-connection').map(function(){return jQuery(this).data('id')}).get()).size`), await evaluate(`jQuery('.bp-databases[data-manual="0"] .bp-connection').length`), 'New database IDs are unique');
	const idBefore = await evaluate(`Number(jQuery('.bp-next-db-id').val())`);
	await evaluate(`jQuery('.bp-remove-database').last().trigger('click'); jQuery('.bp-add-database').first().trigger('click'); true`);
	assert.equal(await evaluate(`Number(jQuery('.bp-next-db-id').val())`), idBefore+1, 'Removed database IDs are not reused');
	assert.equal(await evaluate(`(() => {
		const card = document.querySelector('#backupplus-database-settings .bp-connection');
		const fields = [...card.querySelectorAll('[data-field]')];
		const boxes = fields.map(input => input.getBoundingClientRect());
		return boxes.every(box => Math.abs(box.left - boxes[0].left) < 1 && Math.abs(box.width - boxes[0].width) < 1) && fields.every(input => !!document.querySelector('label[for="'+input.id+'"]'));
	})()`), true, 'Desktop connection fields align and have associated labels');
	if (process.env.BACKUPPLUS_SOURCES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus-database-settings').scrollIntoView(); window.scrollBy(0,-60); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_SOURCES_SCREENSHOT, Buffer.from(screenshot.data,'base64'));
	}
	await call('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
	assert.equal(await evaluate(`['wpcore', 'more'].every(entity => {
		const panel = document.querySelector('#backupplus_include_' + entity + '_exclude_container');
		const bounds = panel.getBoundingClientRect();
		return bounds.left >= 0 && bounds.right <= innerWidth && [...panel.querySelectorAll('input:not([type="hidden"]), textarea, .backupplus_add_exclude_item')].every(input => input.getBoundingClientRect().right <= bounds.right);
	})`), true, 'Core and Additional files controls fit the mobile viewport');
	await evaluate(`jQuery('#backupplus-database-settings .bp-connection .bp-table-mode').first().val('selected').trigger('change'); true`);
	const mobileLayout = await evaluate(`(() => {
		const card = document.querySelector('#backupplus-database-settings .bp-connection');
		const bounds = card.getBoundingClientRect();
		return {width: innerWidth, columns: getComputedStyle(card.querySelector('.bp-connection-field')).gridTemplateColumns, overflow: [...card.querySelectorAll('[data-field], button, select')].filter(input => { const box = input.getBoundingClientRect(); return input.getClientRects().length && (box.left < bounds.left || box.right > bounds.right + 1); }).map(input => input.outerHTML)};
	})()`);
	assert.equal(mobileLayout.columns.split(' ').length, 1, JSON.stringify(mobileLayout));
	assert.deepEqual(mobileLayout.overflow, [], 'Mobile controls stay within their card');
	if (process.env.BACKUPPLUS_SOURCES_SCREENSHOT) {
		await evaluate(`document.querySelector('#backupplus-database-settings .bp-connection').scrollIntoView(); window.scrollBy(0,-60); true`);
		const screenshot = await call('Page.captureScreenshot', {format:'png'});
		await writeFile(process.env.BACKUPPLUS_SOURCES_SCREENSHOT.replace(/\.png$/, '-mobile.png'), Buffer.from(screenshot.data,'base64'));
	}
	await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
	const tables = await evaluate(`new Promise(resolve => jQuery.post(backupplus_sources.ajax,{action:'backupplus_source_tables',nonce:backupplus_sources.nonce}).done(resolve))`);
	assert.equal(tables.success, true, 'WordPress table discovery succeeds');
	assert(tables.data.tables.length > 0, 'Discovery lists actual database tables');
	await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table-mode').first().val('selected').trigger('change'); jQuery('#backupnow_database_moreoptions .bp-table').prop('checked',false); true`);
	assert.equal(await evaluate(`backupplus_database_override()`), null, 'Empty one-off selected lists cannot proceed');
	const one = tables.data.tables[0];
	await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table').filter(function(){return this.value===${JSON.stringify(one)}}).prop('checked',true); true`);
	assert.deepEqual((await evaluate(`backupplus_database_override()`)).wp.tables, [one], 'One-off table payload contains exact selected names');
	assert.equal(await evaluate(`jQuery('.bp-databases[data-manual="0"] .bp-table-mode').first().val()`), 'all', 'One-off changes leave saved-settings controls unchanged');
	await evaluate(`jQuery('#backupnow_database_moreoptions').show(); jQuery('#backupnow_database_moreoptions .bp-table-search').val('no-such-table').trigger('input'); true`);
	assert.equal(await evaluate(`jQuery('#backupnow_database_moreoptions .bp-table-list label:visible').length`), 0, 'Search filters table checkboxes');
	assert.equal(await evaluate(`!!document.querySelector('[name="backupplus_restorer_wpcore_includewpconfig"]')`), true, 'Restore wizard offers explicit wp-config replacement');
	console.log('Backup source browser checks passed.');
} finally {
	if (ws) ws.close();
	browser.kill('SIGTERM');
	await new Promise(resolve => browser.once('exit', resolve));
	await rm(directory, {recursive:true,force:true,maxRetries:10,retryDelay:100});
}
