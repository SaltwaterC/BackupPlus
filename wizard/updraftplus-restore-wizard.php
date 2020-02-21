<?php // phpcs:ignore Generic.PHP.RequireStrictTypes.MissingDeclaration

	$action = !empty($_POST['ACTION']) ? $_POST['ACTION'] : 'NO_ACTION';
	
	switch ($action) {
		case 'CHECK_BACKUP':
			check_backup();
			response('success', 'Backup set found and is ok');
			break;

		case 'DOWNLOAD_WP':
			$version = get_wordpress_version();
			download_wordpress($version);
			response('success', "Downloaded version {$version} of Wordpress");
			break;

		case 'EXTRACT_WP':
			extract_wordpress();
			response('success', 'WordPress has been extracted');
			break;

		case 'CONFIG_WP':
			config_wordpress();
			response('success', 'WordPress has been configured');
			break;

		case 'DOWNLOAD_UDP':
			if (find_local_updraftplus()) {
				response('success', "Found UpdraftPlus in plugins backup");
			} else {
				$version = get_updraftplus_version();
				download_updraftplus($version);
				response('success', "Downloaded version {$version} of UpdraftPlus");
			}
			break;

		case 'EXTRACT_UDP':
			extract_updraftplus();
			response('success', 'UpdraftPlus has been extracted');
			break;

		case 'ACTIVATE_PLUGINS':
			install_plugins();
			response('success', 'Plugins have been activated');
			break;

		case 'MOVE_BACKUPS':
			load_wordpress();
			move_backups();
			response('success', 'Moved backups to backup folder');
			break;

		case 'BUILD_BACKUP_HISTORY':
			load_wordpress();
			build_backup_history();
			response('success', 'Built the backup history');
			break;

		case 'CONFIG_RESTORE':
			load_wordpress();
			$data = prepare_restore();
			response('success', $data);
			break;

		case 'DO_RESTORE':
			load_wordpress();
			load_filters();
			ob_start();
			restore_backup();
			ob_end_clean();
			response('success', 'Restore complete');
			break;

		case 'MOVE_WP':
			move_wordpress();
			response('success', 'Successfully moved the wordpress install to root');
			break;

		case 'CLEANUP':
			clean(ROOT);
			response('success', 'Clean up complete');
			break;

		case 'NO_ACTION':
			// We don't want to error here as this may be called when the page first loads e.g before we even send the POST
			break;
		default:
			response('error', "Invalid ACTION: {$action}");
			break;
	}
