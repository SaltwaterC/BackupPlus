<?php

/** Status, AJAX, log download, notices and the classic update screens. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Pre_Update_Backups_Interface {

	/** Safe public state; logs use their own authenticated, batch-bound endpoint. */
	public function status( $batch ) {
		global $backupplus;
		try {
			$job = $backupplus->jobdata_getarray( $batch['job'] );
		} catch ( Throwable $error ) {
			$job = array();
		}
		$stage    = isset( $job['jobstatus'] ) ? $job['jobstatus'] : '';
		$progress = array(
			'begun'          => 5,
			'filescreating'  => 15,
			'filescreated'   => 40,
			'dbcreating'     => 45,
			'dbcreated'      => 60,
			'clouduploading' => 70,
			'pruning'        => 95,
			'finished'       => 100,
		);
		return array(
			'id'       => $batch['id'],
			'state'    => $batch['state'],
			'progress' => in_array( $batch['state'], array( 'ready', 'complete' ), true ) ? 100 : ( isset( $progress[ $stage ] ) ? $progress[ $stage ] : ( 'queued' === $batch['state'] ? 0 : 10 ) ),
			'message'  => in_array( $batch['state'], array( 'failed', 'cancelled', 'expired' ), true ) ? __( 'The backup did not complete. Updates remain paused. Review the log, then retry.', 'backupplus' ) : __( 'Backing up the database and site files, including remote uploads. Keep this screen open; updates will continue automatically.', 'backupplus' ),
			'log'      => wp_nonce_url( admin_url( 'admin-post.php?action=backupplus_pre_update_log&batch=' . rawurlencode( $batch['id'] ) ), 'backupplus_pre_update_log_' . $batch['id'] ),
		);
	}

	public function ajax() {
		check_ajax_referer( 'backupplus_pre_update', 'nonce' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Pre-update backups are disabled. Reload the update screen.', 'backupplus' ) ), 409 );
		}
		if ( 'prepare' === $operation ) {
			$keys   = isset( $_POST['targets'] ) && is_array( $_POST['targets'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['targets'] ) ) : array();
			$result = $this->queue_batch( $keys );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
			}
			wp_send_json_success( $this->status( $result ) );
		}
		if ( ! $this->lock() ) {
			wp_send_json_error( array( 'message' => $this->error()->get_error_message() ), 409 );
		}
		$result = null;
		try {
			$batch = $this->batch();
			$id    = isset( $_POST['batch'] ) && is_string( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
			if ( ! $id || empty( $batch['id'] ) || $batch['id'] !== $id || ! $this->can_manage( $batch ) ) {
				$result = $this->error( 'backupplus_forbidden' );
			} elseif ( 'cancel' === $operation && empty( $batch['request'] ) ) {
				$batch['state'] = 'cancelled';
				wp_clear_scheduled_hook( 'backupplus_pre_update_run', array( $id ) );
				wp_clear_scheduled_hook( 'backupplus_pre_update_continue', array( $id ) );
				$this->save( $batch );
			} elseif ( 'retry' === $operation && in_array( $batch['state'], array( 'failed', 'cancelled', 'expired' ), true ) ) {
				$offers = $this->offers();
				foreach ( $batch['targets'] as $key => $target ) {
					if ( ! isset( $offers[ $key ] ) || $offers[ $key ]['version'] !== $target['version'] || $offers[ $key ]['packages'] !== $target['packages'] ) {
						$result = $this->error( 'backupplus_update_changed' );
					}
				}
				if ( ! $result ) {
					$batch['state']     = 'queued';
					$batch['job']       = substr( md5( bin2hex( random_bytes( 16 ) ) ), 0, 12 );
					$batch['completed'] = 0;
					$batch['created']   = time();
					$batch['remaining'] = array_keys( $batch['targets'] );
					$this->save( $batch );
					wp_schedule_single_event( time(), 'backupplus_pre_update_run', array( $id ) );
				}
			} elseif ( 'status' !== $operation ) {
				$result = $this->error();
			}
			if ( ! $result && 'status' === $operation ) {
				if ( $batch['completed'] && time() >= $batch['completed'] + HOUR_IN_SECONDS && ( empty( $batch['request'] ) || time() >= ( isset( $batch['request_started'] ) ? $batch['request_started'] : $batch['completed'] ) + HOUR_IN_SECONDS ) && in_array( $batch['state'], array( 'ready', 'updating' ), true ) ) {
					$batch['state']           = 'expired';
					$batch['request']         = '';
					$batch['request_started'] = 0;
					$this->save( $batch );
				} elseif ( ( 'backing_up' === $batch['state'] && $this->stalled( $batch ) ) || ( 'ready' === $batch['state'] && ! $this->available( $batch ) ) ) {
					// A killed worker must not leave the interface waiting forever.
					$batch['state'] = 'failed';
					$this->save( $batch );
				}
			}
		} finally {
			$this->unlock();
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		spawn_cron();
		wp_send_json_success( $this->status( $batch ) );
	}

	private function stalled( $batch ) {
		global $backupplus;
		try {
			$job = $backupplus->jobdata_getarray( $batch['job'] );
		} catch ( Throwable $error ) {
			return true;
		}
		$last = $batch['created'];
		foreach ( isset( $job['runs_started'] ) ? $job['runs_started'] : array() as $run => $start ) {
			$last = max( $last, $start + ( isset( $job['run_times'][ $run ] ) ? $job['run_times'][ $run ] : 0 ) );
		}
		$log = $backupplus->backups_dir_location() . '/log.' . $batch['job'] . '.txt';
		if ( is_file( $log ) ) {
			$last = max( $last, filemtime( $log ) );
		}
		$scheduled = false;
		foreach ( _get_cron_array() as $events ) {
			foreach ( isset( $events['backupplus_backup_resume'] ) ? $events['backupplus_backup_resume'] : array() as $event ) {
				if ( isset( $event['args'][1] ) && $batch['job'] === $event['args'][1] ) {
					$scheduled = true;
				}
			}
		}
		return time() > $last + ( $scheduled ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	}

	public function download_log() {
		global $backupplus;
		$batch = $this->batch();
		$id    = isset( $_GET['batch'] ) && is_string( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		check_admin_referer( 'backupplus_pre_update_log_' . $id );
		if ( ! $id || empty( $batch['id'] ) || $id !== $batch['id'] || ! $this->can_manage( $batch ) ) {
			wp_die( esc_html__( 'You cannot access this backup log.', 'backupplus' ), '', array( 'response' => 403 ) );
		}
		$path = $backupplus->backups_dir_location() . '/log.' . $batch['job'] . '.txt';
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The log is not available yet.', 'backupplus' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="pre-update-backup.log"' );
		readfile( $path );
		exit;
	}

	public function recovery_notice() {
		$batch = $this->batch();
		if ( self::enabled() && ! empty( $batch['mode'] ) && 'automatic' === $batch['mode'] && in_array( $batch['state'], array( 'queued', 'backing_up', 'ready', 'failed', 'cancelled', 'expired' ), true ) && $this->can_manage( $batch ) ) {
			echo '<div id="backupplus-pre-update-recovery" data-batch="' . esc_attr( $batch['id'] ) . '"></div>';
		}
	}

	public function enqueue() {
		if ( ! self::enabled() || ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) && ! current_user_can( 'update_core' ) ) ) {
			return;
		}
		wp_enqueue_script( 'backupplus-pre-update', BACKUPPLUS_URL . '/js/pre-update-backups.js', wp_script_is( 'updates', 'registered' ) ? array( 'jquery', 'updates' ) : array( 'jquery' ), '3.0.0', true );
		$classic_links = array();
		if ( version_compare( get_bloginfo( 'version' ), '4.6', '<' ) ) {
			foreach ( $this->offers() as $key => $offer ) {
				if ( 'core' !== $offer['type'] ) {
					$action                = 'upgrade-' . $offer['type'];
					$classic_links[ $key ] = html_entity_decode( wp_nonce_url( self_admin_url( 'update.php?action=' . $action . '&' . $offer['type'] . '=' . rawurlencode( $offer['id'] ) ), $action . '_' . $offer['id'] ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		wp_localize_script(
			'backupplus-pre-update',
			'backupplusPreUpdate',
			array(
				'url'          => admin_url( 'admin-ajax.php' ),
				'classicLinks' => $classic_links,
				'nonce'        => wp_create_nonce( 'backupplus_pre_update' ),
				'title'        => __( 'Pre-update backup', 'backupplus' ),
				'retry'        => __( 'Retry backup', 'backupplus' ),
				'cancel'       => __( 'Cancel updates', 'backupplus' ),
				'log'          => __( 'Download backup log', 'backupplus' ),
				'error'        => __( 'The backup could not be verified. Updates remain paused. Retry when the connection is available.', 'backupplus' ),
			)
		);
	}

	/** Classic and iframe update screens stop before maintenance mode and retain native nonce checks. */
	public function classic() {
		global $pagenow;
		if ( ! self::enabled() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		$routes = array(
			'upgrade-plugin'         => 'plugin',
			'update-selected'        => 'plugin',
			'upgrade-theme'          => 'theme',
			'update-selected-themes' => 'theme',
			'do-plugin-upgrade'      => 'plugin',
			'do-theme-upgrade'       => 'theme',
			'do-core-upgrade'        => 'core',
			'do-core-reinstall'      => 'core',
		);
		if ( ! isset( $routes[ $action ] ) ) {
			return;
		}
		$type = $routes[ $action ];
		if ( 'core' === $type && empty( $_POST['upgrade'] ) ) {
			return;
		}
		if ( ! current_user_can( 'core' === $type ? 'update_core' : 'update_' . $type . 's' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform these updates.', 'backupplus' ), '', array( 'response' => 403 ) );
		}
		$single = in_array( $action, array( 'upgrade-plugin', 'upgrade-theme' ), true );
		$ids    = array();
		if ( $single ) {
			$ids = isset( $_REQUEST[ $type ] ) && is_string( $_REQUEST[ $type ] ) ? array( wp_unslash( $_REQUEST[ $type ] ) ) : array();
		} elseif ( 'core' !== $type ) {
			$plural = $type . 's';
			$ids    = isset( $_GET[ $plural ] ) && is_string( $_GET[ $plural ] ) ? explode( ',', wp_unslash( $_GET[ $plural ] ) ) : ( isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ? wp_unslash( $_POST['checked'] ) : array() );
		}
		$native_nonce = 'update-core.php' === $pagenow ? 'upgrade-core' : ( $single ? $action . '_' . reset( $ids ) : 'bulk-update-' . $type . 's' );
		check_admin_referer( $native_nonce );
		$keys = array();
		if ( 'core' === $type ) {
			$version = isset( $_POST['version'] ) && is_string( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '';
			$locale  = isset( $_POST['locale'] ) && is_string( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : get_locale();
			$keys[]  = 'core:' . $version . ':' . $locale;
		} else {
			foreach ( $ids as $id ) {
				if ( is_string( $id ) ) {
					$keys[] = $type . ':' . urldecode( $id );
				}
			}
		}
		if ( ! $keys ) {
			return;
		}
		$batch = $this->batch();
		$token = isset( $_REQUEST['backupplus_batch'] ) && is_string( $_REQUEST['backupplus_batch'] ) ? wp_unslash( $_REQUEST['backupplus_batch'] ) : '';
		if ( $token && ! empty( $batch['id'] ) && $token === $batch['id'] && $this->can_manage( $batch ) && 'ready' === $batch['state'] && time() < $batch['completed'] + HOUR_IN_SECONDS && ! array_diff( $keys, $batch['remaining'] ) ) {
			return;
		}
		// Render a native admin page; only original update fields are carried into the resume form.
		$title = __( 'Pre-update backup', 'backupplus' );
		$this->enqueue();
		require ABSPATH . 'wp-admin/admin-header.php';
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><div id="backupplus-pre-update-classic" data-targets="' . esc_attr( json_encode( $keys ) ) . '"></div>';
		$resume = array(
			'action'   => $action,
			'_wpnonce' => wp_create_nonce( $native_nonce ),
		);
		$path   = $pagenow;
		if ( 'core' === $type ) {
			$resume['upgrade'] = '1';
			$resume['version'] = $version;
			$resume['locale']  = $locale;
		} elseif ( 'update-core.php' === $pagenow ) {
			// The native core screen otherwise creates another iframe and loses the batch identity.
			$path               = 'update.php';
			$resume['action']   = 'plugin' === $type ? 'update-selected' : 'update-selected-themes';
			$resume['_wpnonce'] = wp_create_nonce( 'bulk-update-' . $type . 's' );
			$resume['checked']  = $ids;
		} elseif ( $single ) {
			$resume[ $type ] = reset( $ids );
		} else {
			$resume['checked'] = $ids;
		}
		echo '<form id="backupplus-pre-update-resume" method="post" action="' . esc_url( self_admin_url( $path . '?action=' . $resume['action'] ) ) . '">';
		foreach ( $resume as $name => $value ) {
			foreach ( (array) $value as $entry ) {
				echo '<input type="hidden" name="' . esc_attr( $name . ( is_array( $value ) ? '[]' : '' ) ) . '" value="' . esc_attr( $entry ) . '">';
			}
		}
		echo '</form><noscript>' . esc_html__( 'Enable JavaScript to prepare a backup and continue these updates.', 'backupplus' ) . '</noscript></div>';
		require ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}
}
