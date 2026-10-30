<?php

/** Result emails, Slack posts and ntfy notifications for finished backups. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Notifications {

	/**
	 * The jobdata is passed in instead of fetched, because the live jobdata may now differ from that which should be reported on (e.g. an incremental run was subsequently scheduled)
	 *
	 * @param String $final_message The final message to be sent
	 * @param Array  $jobdata       Full job data
	 */
	private function send_results_email( $final_message, $jobdata ) {

		$debug_mode = BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' );

		$sendmail_to = $this->just_one_email( BackupPlus_Options::get_backupplus_option( 'backupplus_email' ) );
		if ( is_string( $sendmail_to ) ) {
			$sendmail_to = array( $sendmail_to );
		}

		$backup_files = $jobdata['backup_files'];
		$backup_db    = $jobdata['backup_database'];

		if ( is_array( $backup_db ) ) {
			$backup_db = $backup_db['wp'];
		}
		if ( is_array( $backup_db ) ) {
			$backup_db = $backup_db['status'];
		}

		$backup_type   = ( 'backup' == $jobdata['job_type'] ) ? __( 'Full backup', 'backupplus' ) : __( 'Incremental', 'backupplus' );
		$backup_status = __( 'Successful', 'backupplus' );

		$was_aborted = ! empty( $jobdata['aborted'] );

		if ( $was_aborted ) {
			$backup_contains = __( 'The backup was aborted by the user', 'backupplus' );
			$backup_status   = __( 'Aborted', 'backupplus' );
		} elseif ( 'finished' == $backup_files && ( 'finished' == $backup_db || 'encrypted' == $backup_db ) && 'incremental' !== $jobdata['job_type'] ) {
			$backup_contains = __( 'Files and database', 'backupplus' ) . " ($backup_type)";
		} elseif ( 'finished' == $backup_files ) {
			$backup_contains  = ( 'begun' == $backup_db ) ? __( 'Files (database backup has not completed)', 'backupplus' ) : __( 'Files only (database was not part of this particular schedule)', 'backupplus' );
			$backup_contains .= " ($backup_type)";
		} elseif ( 'finished' == $backup_db || 'encrypted' == $backup_db ) {
			$backup_contains = ( 'begun' == $backup_files ) ? __( 'Database (files backup has not completed)', 'backupplus' ) : __( 'Database only (files were not part of this particular schedule)', 'backupplus' );
		} elseif ( 'begun' == $backup_db || 'begun' == $backup_files ) {
			$backup_contains = __( 'Incomplete', 'backupplus' );
			$backup_status   = __( 'Incomplete', 'backupplus' );
		} else {
			$this->log( 'Unknown/unexpected status: ' . serialize( $backup_files ) . '/' . serialize( $backup_db ) );
			$backup_contains = __( 'Unknown/unexpected error - please raise a support request', 'backupplus' );
			$backup_status   = __( 'Unsuccessful', 'backupplus' );
		}

		$append_log  = '';
		$attachments = array();

		$error_count = 0;

		if ( $this->error_count() > 0 ) {
			$append_log    .= __( 'Errors encountered:', 'backupplus' ) . "\r\n";
			$attachments[0] = $this->logfile_name;
			foreach ( $this->errors as $err ) {
				if ( is_wp_error( $err ) ) {
					foreach ( $err->get_error_messages() as $msg ) {
						$append_log .= '* ' . rtrim( $msg ) . "\r\n";
					}
				} elseif ( is_array( $err ) && 'error' == $err['level'] ) {
					$append_log .= '* ' . rtrim( $err['message'] ) . "\r\n";
				} elseif ( is_string( $err ) ) {
					$append_log .= '* ' . rtrim( $err ) . "\r\n";
				}
				++$error_count;
			}
			$append_log   .= "\r\n";
			$backup_status = __( 'Unsuccessful due to number of errors', 'backupplus' );
		}
		$warnings = ( isset( $jobdata['warnings'] ) ) ? $jobdata['warnings'] : array();
		if ( is_array( $warnings ) && count( $warnings ) > 0 ) {
			if ( 'finished' == $jobdata['jobstatus'] && 0 == $this->error_count() ) {
				$append_log .= __( 'Warnings encountered (note: this is for information; the backup has completed successfully)', 'backupplus' ) . "\r\n";
			} else {
				$append_log .= __( 'Warnings encountered:', 'backupplus' ) . "\r\n";
			}
			$attachments[0] = $this->logfile_name;
			foreach ( $warnings as $err ) {
				$append_log .= '* ' . rtrim( $err ) . "\r\n";
			}
			$append_log .= "\r\n";
			if ( 0 == $this->error_count() ) {
				$backup_status = __( 'Successful with warnings', 'backupplus' );
			}
		}

		if ( $debug_mode && '' != $this->logfile_name && ! in_array( $this->logfile_name, $attachments ) ) {
			$append_log    .= "\r\n" . __( 'The log file has been attached to this email.', 'backupplus' );
			$attachments[0] = $this->logfile_name;
		}

		// We have to use the action in order to set the MIME type on the attachment - by default, WordPress just puts application/octet-stream

		/* translators: %s: Blog name */
		$subject = apply_filters( 'backupplus_report_subject', sprintf( __( 'Backed up: %s', 'backupplus' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ) . ' (BackupPlus ' . $this->version . ') ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() ), 'Y-m-d H:i' ) . ' - ' . esc_html( $backup_status ), $error_count, count( $warnings ) );

		// The class_exists() check here is a micro-optimization to prevent a possible HTTP call whose results may be disregarded by the filter
		$extra_messages = apply_filters( 'backupplus_report_extramessages', array() );
		$extra_msg      = '';
		if ( is_array( $extra_messages ) ) {
			foreach ( $extra_messages as $msg ) {
				$extra_msg .= $msg['key'] . ': ' . $msg['val'] . "\r\n";
			}
		}

		foreach ( $this->remotestorage_extrainfo as $service => $message ) {
			if ( ! empty( $this->backup_methods[ $service ] ) ) {
				$extra_msg .= $this->backup_methods[ $service ] . ': ' . $message['plain'] . "\r\n";
			}
		}

		// Make it available to the filter
		$jobdata['remotestorage_extrainfo'] = $this->remotestorage_extrainfo;

		if ( ! class_exists( 'BackupPlus_Notices' ) ) {
			backupplus_try_include_file( 'includes/backupplus-notices.php', 'include_once' );
		}
		global $backupplus_notices;
		$ws_notice = $backupplus_notices->do_notice( false, 'report-plain', true );

		$body = apply_filters(
			'backupplus_report_body',
			__( 'Backup of:', 'backupplus' ) . ' ' . site_url() . "\r\n" .
			'BackupPlus ' . __( 'WordPress backup is complete', 'backupplus' ) . ".\r\n" .
			__( 'Backup contains:', 'backupplus' ) . " $backup_contains\r\n" .
			__( 'Latest status:', 'backupplus' ) . ' ' . $final_message . "\r\n" .
			$extra_msg .
			"\r\n" .
			$ws_notice . "\r\n" .
			$append_log,
			$final_message,
			$backup_contains,
			$this->errors,
			$warnings,
			$jobdata
		);

		$this->attachments = apply_filters( 'backupplus_report_attachments', $attachments );

		$attach_size  = 0;
		$unlink_files = array();

		foreach ( $this->attachments as $ind => $attach ) {
			if ( $attach == $this->logfile_name && filesize( $attach ) > 6 * 1048576 ) {

				$this->log( 'Log file is large (' . round( filesize( $attach ) / 1024, 1 ) . ' KB): will compress before e-mailing' );

				if ( ! $handle = fopen( $attach, 'r' ) ) {
					$this->log( 'Error: Failed to open log file for reading: ' . $attach );
				} else {
					if ( ! $whandle = gzopen( $attach . '.gz', 'w' ) ) {
						$this->log( 'Error: Failed to open log file for reading: ' . $attach . '.gz' );
					} else {
						while ( false !== ( $line = @stream_get_line( $handle, 131072, "\n" ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@gzwrite( $whandle, $line . "\n" );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						}
						fclose( $handle );
						gzclose( $whandle );
						$this->attachments[ $ind ] = $attach . '.gz';
						$unlink_files[]            = $attach . '.gz';
					}
				}
			}
			$attach_size += filesize( $this->attachments[ $ind ] );
		}

		foreach ( $sendmail_to as $ind => $mailto ) {

			// Reports can go to ntfy alone, in which case no address is configured.
			if ( ! is_string( $mailto ) || '' === trim( $mailto ) ) {
				continue;
			}

			if ( false === apply_filters( 'backupplus_report_sendto', true, $mailto, $error_count, count( $warnings ), $ind ) ) {
				continue;
			}

			foreach ( explode( ',', $mailto ) as $sendmail_addr ) {
				// if the address is a URL then instead of emailing it, POST it to slack
				if ( preg_match( '/^https?:\/\//i', $sendmail_addr ) ) {
					$this->log( "Sending to (URL) ('$backup_contains') report (attachments: " . count( $attachments ) . ', size: ' . round( $attach_size / 1024, 1 ) . ' KB) to: ' . substr( $sendmail_addr, 0, 5 ) . '...' );
					$this->post_results_slack( $subject, $body, trim( $sendmail_addr ), $this->file_nonce );
				} else {
					$this->log( "Sending email ('$backup_contains') report (attachments: " . count( $attachments ) . ', size: ' . round( $attach_size / 1024, 1 ) . ' KB) to: ' . substr( $sendmail_addr, 0, 5 ) . '...' );
					$headers = array();
					try {
						$headers[]               = 'X-BackupPlus-Backup-ID: ' . $this->nonce;
						$from_email              = apply_filters( 'backupplus_email_from_header', $this->get_email_from_header() );
						$from_name               = apply_filters( 'backupplus_email_from_name_header', $this->get_email_from_name_header() );
						$use_wp_from_name_filter = '' === $from_email;
						// Notice that we don't use the 'wp_mail_from' filter, but only the 'From:' header to set sender name and sender email address, the reason behind it is that some SMTP plugins override the "wp_mail()" function and they do anything they want inside their own "wp_mail()" function, including not to call the php_mailer filter nor the wp_mail_from and wp_mail_from_name filters, but since the function signature remain the same as the WP one, so they may evaluate and do something with the header parameter
						if ( ! $use_wp_from_name_filter ) {
							$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
						} else {
							add_filter( 'wp_mail_from_name', array( $this, 'get_email_from_name_header' ), 9 );
						}
						add_action( 'wp_mail_failed', array( $this, 'log_email_delivery_failure' ) );
						wp_mail( trim( $sendmail_addr ), $subject, $body, $headers, is_array( $this->attachments ) ? $this->attachments : array() );
						remove_action( 'wp_mail_failed', array( $this, 'log_email_delivery_failure' ) );
						if ( $use_wp_from_name_filter ) {
							remove_filter( 'wp_mail_from_name', array( $this, 'get_email_from_name_header' ), 9 );
						}
					} catch ( Exception $e ) {
						$this->log( 'Exception occurred when sending mail (' . get_class( $e ) . '): ' . $e->getMessage() );
					}
				}
			}
		}

		if ( BackupPlus_Ntfy::enabled() ) {
			$this->log( "Sending ntfy ('$backup_contains') report" );
			$this->post_results_ntfy( $subject, $body, $backup_status );
		}

		foreach ( $unlink_files as $file ) {
			@unlink( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		}
		do_action( 'backupplus_report_finished' );
	}

	/**
	 * Log the email delivery failure to the log file when a PHPMailer exception is caught
	 *
	 * @param WP_Error $error A WP_Error object with the PHPMailer\PHPMailer\Exception message, and an array containing the mail recipient, subject, message, headers, and attachments.
	 */
	public function log_email_delivery_failure( $error ) {
		$this->log( 'An error occurred when sending a backup report email and/or backup file(s) via email (' . $error->get_error_code() . '): ' . $error->get_error_message() );
	}

	/**
	 * Check whether the provided admin_email is under the same domain with the site, and use it as a sender email to increase the chance of an email being sent successfully (if appropriate)
	 *
	 * @return String The admin email address if it's found to be in same domain, an empty string otherwise
	 */
	public function get_email_from_header() {
		$sitename           = $this->get_site_name();
		$admin_email        = get_bloginfo( 'admin_email' );
		$admin_email_domain = preg_replace( '/^[^@]+@(.+)$/', '$1', $admin_email );
		if ( trim( strtolower( $sitename ) ) === trim( strtolower( $admin_email_domain ) ) ) {
			// assuming (non validating) that the email account of the admin email does exist, and the admin email is under the same domain as with the web domain and the domain exists and live as well
			return $admin_email;
		}
		return '';
	}

	/**
	 * Build sender name and use something authentic that represents the identity of the plugin and web domain
	 *
	 * @return String The sender name
	 */
	public function get_email_from_name_header() {
		/* translators: %s: Site name */
		return sprintf( __( 'BackupPlus on %s', 'backupplus' ), $this->get_site_name() );
	}

	/**
	 * Post backup report to slack instead of emailing if the address is a URL
	 *
	 * @param  string $header      report title
	 * @param  string $report_body report content
	 * @param  string $webhook_url url to post report
	 * @param  string $nval        backup log file nonce
	 * @return Void
	 */
	public function post_results_slack( $header, $report_body, $webhook_url, $nval ) {
		$findcontent = __( 'The log file has been attached to this email.', 'backupplus' );

		$report_body = str_replace( $findcontent, '', $report_body );
		$url         = BackupPlus_Options::admin_page_url() . "?page=backupplus&action=downloadlog&backupplus_backup_nonce=$nval";
		$response    = wp_remote_post(
			$webhook_url,
			array(
				'method'  => 'POST',
				'headers' => array(),
				'body'    => json_encode(
					array(
						'blocks' => array(
							array(
								'type' => 'header',
								'text' => array(
									'type'  => 'plain_text',
									'text'  => $header,
									'emoji' => true,
								),
							),
							array(
								'type' => 'section',
								'text' => array(
									'type' => 'mrkdwn',
									'text' => $report_body,
								),
							),
							array(
								'type'      => 'section',
								'text'      => array(
									'type' => 'mrkdwn',
									'text' => __( 'You can view the log by pressing the \'View log\' button.', 'backupplus' ),
								),
								'accessory' => array(
									'type'      => 'button',
									'text'      => array(
										'type'  => 'plain_text',
										'text'  => __( 'View log', 'backupplus' ),
										'emoji' => true,
									),
									'value'     => 'view_log_123',
									'url'       => $url,
									'action_id' => 'button-action',
								),
							),
						),
					)
				),
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code < 200 || $response_code >= 300 ) {
				$this->log( 'HTTP POST error : ' . $response_code . ' - ' . wp_remote_retrieve_response_message( $response ) );
			}
		} else {
			$this->log( 'HTTP POST error : ' . $response->get_error_code() . ' - ' . $response->get_error_message() );
		}
	}

	/**
	 * Publish the backup report to the configured ntfy topic
	 *
	 * @param  string $subject       report title
	 * @param  string $report_body   report content
	 * @param  string $backup_status the status the report was built with
	 * @return Void
	 */
	public function post_results_ntfy( $subject, $report_body, $backup_status ) {
		$report_body = trim( str_replace( __( 'The log file has been attached to this email.', 'backupplus' ), '', $report_body ) );
		if ( __( 'Successful', 'backupplus' ) === $backup_status ) {
			$priority = 3;
			$tags     = array( 'white_check_mark' );
		} elseif ( __( 'Successful with warnings', 'backupplus' ) === $backup_status ) {
			$priority = 3;
			$tags     = array( 'warning' );
		} else {
			$priority = 4;
			$tags     = array( 'x' );
		}
		BackupPlus_Ntfy::notify( $subject, $report_body, $priority, $tags, BackupPlus_Options::admin_page_url() . '?page=backupplus' );
	}

	public function just_one_email( $input, $required = false ) {
		$x = $this->just_one( $input, 'saveemails', ( empty( $input ) && false === $required ) ? '' : get_bloginfo( 'admin_email' ) );
		if ( is_array( $x ) ) {
			foreach ( $x as $ind => $val ) {
				if ( empty( $val ) ) {
					unset( $x[ $ind ] );
				}
			}
			if ( empty( $x ) ) {
				$x = '';
			}
		}
		return $x;
	}

	/**
	 * Sanitize the selected remote storage services: every one of them receives the backups.
	 *
	 * @param Array|String $input - the selected service identifiers
	 *
	 * @return Array|String - the unique identifiers, or 'none' when nothing is selected
	 */
	public function sanitize_services( $input ) {
		$services = array();
		foreach ( is_array( $input ) ? $input : explode( ',', (string) $input ) as $service ) {
			$service = is_string( $service ) ? trim( $service ) : '';
			if ( '' === $service || '0' === $service || 'none' === $service || ! preg_match( '/^[a-z0-9_-]+$/Di', $service ) ) {
				continue;
			}
			$services[ $service ] = $service;
		}
		return $services ? array_values( $services ) : 'none';
	}

	/**
	 * Filter the values down to just one (subject to being filtered)
	 *
	 * @param Array|String   $input  - input
	 * @param String         $filter - filter suffix to use
	 * @param Boolean|String $rinput - a 'preferred' value (unless false) if no filtering is done
	 *
	 * @return Array|String|Null - output, after filtering
	 */
	public function just_one( $input, $filter = 'savestorage', $rinput = false ) {
		$oinput = $input;
		if ( false === $rinput ) {
			$rinput = is_array( $input ) ? array_pop( $input ) : $input;
		}
		if ( is_string( $rinput ) && false !== strpos( $rinput, ',' ) ) {
			$rinput = substr( $rinput, 0, strpos( $rinput, ',' ) );
		}
		return apply_filters( 'BackupPlus_' . $filter, $rinput, $oinput );
	}
}
