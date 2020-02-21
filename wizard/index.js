var actions = {
	CHECK_BACKUP: 'CHECK_BACKUP',
	DOWNLOAD_WP: 'DOWNLOAD_WP',
	EXTRACT_WP: 'EXTRACT_WP',
	CONFIG_WP: 'CONFIG_WP',
	DOWNLOAD_UDP: 'DOWNLOAD_UDP',
	EXTRACT_UDP: 'EXTRACT_UDP',
	ACTIVATE_PLUGINS: 'ACTIVATE_PLUGINS',
	MOVE_BACKUPS: 'MOVE_BACKUPS',
	BUILD_BACKUP_HISTORY: 'BUILD_BACKUP_HISTORY',
	CONFIG_RESTORE: 'CONFIG_RESTORE',
	DO_RESTORE: 'DO_RESTORE',
	MOVE_WP: 'MOVE_WP',
	CLEANUP: 'CLEANUP',
}

var app = new Vue({
	el: '#app',
	data: {
		host: '',
		name: '',
		username: '',
		password: '',
		decryption: '',
		steps_done: 0,
		progress: 0,
		loading: false,
		state: {
			accessError: false,
			authError: false,
			databaseError: false,
			error: false
		},
		log: []
	},
	methods: {
		verify: function(event) {

			this.loading = true;
			this.log = [];
			this.state = {
				accessError: false,
				authError: false,
				databaseError: false,
				error: false
			};

			restore_site(this.host, this.username, this.password, this.name, this.decryption).then(function() {
				this.loading = false;
			}.bind(this), function() {
				this.loading = false;
			}.bind(this));
		},
		getProgress: function () {
			this.steps_done++;
			this.progress = Math.round(this.steps_done / Object.keys(actions).length * 100);
		}
	}
})

/**
 * This function will perform ajax requests and handle the responses.
 *
 * @param {*} formData
 */
function ajax(formData){
	return new Promise(function(resolve, reject) {
		fetch('updraftplus-restore-wizard.php', {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function(response) {
			return response.json();
		})
		.then(function(response) {
			app.log = app.log.concat(response.log);

			if (response.state === 'success' || response.state === 'importing') {
				app.getProgress();
				resolve(response);
			} else {
				var type = response.state;
				app.state[type] = response.info;
				reject(response);
			}
		})
		.catch(function (error) {
			console.log(error);
		})
	});
}

/**
 * This function will take the passed in data and extract the information then add it to the form data object passed in.
 *
 * @param {object} formData
 * @param {object} data
 * @param {string} parentKey
 */
function buildFormData(formData, data, parentKey) {
	if (data && typeof data === 'object' && !(data instanceof Date) && !(data instanceof File)) {
		Object.keys(data).forEach(function (key) {
			buildFormData(formData, data[key], parentKey ? parentKey + '[' + key + ']' : key);
		});
	} else {
		var value = data == null ? '' : data;

		formData.append(parentKey, value);
	}
}

/**
 * This function will send a ajax request to kick off the check backup stage.
 *
 * @param {string} decryption - the decryption phrase
 */
function check_backup(decryption) {
	app.log.push('Verifiying Backup set');

	var formData = new FormData();
	formData.append('ACTION', actions.CHECK_BACKUP);
	formData.append('updraft_encryptionphrase', decryption);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the download WordPress stage.
 */
function download_wordpress() {
	app.log.push('Downloading Wordpress');

	var formData = new FormData();
	formData.append('ACTION', actions.DOWNLOAD_WP);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the extract WordPress stage.
 */
function extract_wordpress() {
	app.log.push('Extracting Wordpress');

	var formData = new FormData();
	formData.append('ACTION', actions.EXTRACT_WP);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the config WordPress stage.
 *
 * @param {string} host     - the database hostname
 * @param {string} username - the database username
 * @param {string} password - the database password
 * @param {string} name     - the database name
 */
function config_wordpress(host, username, password, name) {
	app.log.push('Configuring Wordpress');

	var formData = new FormData();
	formData.append('ACTION', actions.CONFIG_WP);
	formData.append('host', host);
	formData.append('username', username);
	formData.append('password', password);
	formData.append('name', name);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the download UpdraftPlus stage.
 */
function download_updraftplus() {
	app.log.push('Downloading UpdraftPlus')

	var formData = new FormData();
	formData.append('ACTION', actions.DOWNLOAD_UDP);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the extract UpdraftPlus stage.
 */
function extract_updraftplus() {
	app.log.push('Extracting UpdraftPlus');

	var formData = new FormData();
	formData.append('ACTION', actions.EXTRACT_UDP);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the activate plugins stage.
 */
function activate_plugins() {
	app.log.push('Activating plugins');

	var formData = new FormData();
	formData.append('ACTION', actions.ACTIVATE_PLUGINS);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the move backups stage.
 */
function moving_backups() {
	app.log.push('Moving backups');

	var formData = new FormData();
	formData.append('ACTION', actions.MOVE_BACKUPS);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the build backup history stage.
 */
function build_backup_history() {
	app.log.push('Building backup history');

	var formData = new FormData();
	formData.append('ACTION', actions.BUILD_BACKUP_HISTORY);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the config restore stage.
 */
function config_restore() {
	app.log.push('Configure the restore');

	var formData = new FormData();
	formData.append('ACTION', actions.CONFIG_RESTORE);
	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the do restore stage.
 *
 * @param {array}  data       - the restore options array
 * @param {string} decryption - the decryption phrase
 */
function do_restore(data, decryption) {
	app.log.push('Running the restore');

	var formData = new FormData();
	formData.append('ACTION', actions.DO_RESTORE);
	
	if ('' != decryption) {
		formData.append('updraft_encryptionphrase', decryption);
	}
	
	buildFormData(formData, data);

	return ajax(formData);
}

/**
 * This function will send a ajax request to kick off the move WordPress stage.
 */
function move_wordpress() {
	app.log.push('Moving Wordpress');

	var formData = new FormData();
	formData.append('ACTION', actions.MOVE_WP);
	return ajax(formData);
};

/**
 * This function will send a ajax request to kick off the cleanup stage.
 */
function cleanup() {
	app.log.push('Cleaning up');

	var formData = new FormData();
	formData.append('ACTION', actions.CLEANUP);
	return ajax(formData);
}

/**
 * This function will start the UpdraftPlus restore wizard process.
 *
 * @param {string} host       - the database hostname
 * @param {string} username   - the database username
 * @param {string} password   - the database password
 * @param {string} name       - the database name
 * @param {string} decryption - the decryption phrase
 */
function restore_site(host, username, password, name, decryption) {
	return new Promise(function(resolve, reject) {
		check_backup(decryption).then(function() {
			download_wordpress().then(function() {
				extract_wordpress().then(function() {
					config_wordpress(host, username, password, name).then(function () {
						download_updraftplus().then(function () {
							extract_updraftplus().then(function () {
								activate_plugins().then(function () {
									moving_backups().then(function () {
										build_backup_history().then(function () {
											config_restore().then(function (data) {
												do_restore(data.info, decryption).then(function () {
													move_wordpress().then(function () {
														cleanup().then(function () {
															alert("Restore complete");
															window.location.href = "index.php";
														}, reject)
													}, reject)
												}, reject)
											}, reject)
										}, reject)
									}, reject)
								}, reject)
							}, reject)
						}, reject)
					}, reject)
				}, reject)
			}, reject)
		}, reject)
	})
}