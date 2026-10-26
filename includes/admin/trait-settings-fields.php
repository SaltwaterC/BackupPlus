<?php

/** Reusable settings form widgets and storage method rows. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Settings_Fields {

	/**
	 * Outputs html for a storage method using the parameters passed in, this version should be removed when all remote storages use the multi version
	 *
	 * @param String $method   a list of methods to be used when
	 * @param String $header   the table header content
	 * @param String $contents the table contents
	 */
	public function storagemethod_row( $method, $header, $contents ) {
		?>
			<tr class="backupplusmethod <?php echo esc_attr( $method ); ?>">
				<th><?php echo wp_kses_post( $header ); ?></th>
				<td><?php echo wp_kses_post( $contents ); ?></td>
			</tr>
		<?php
	}

	/**
	 * Outputs html for a storage method using the parameters passed in, this version of the method is compatible with multi storage options
	 *
	 * @param  string $classes  a list of classes to be used when
	 * @param  string $header   the table header content
	 * @param  string $contents the table contents
	 */
	public function storagemethod_row_multi( $classes, $header, $contents ) {
		?>
			<tr class="<?php echo esc_attr( $classes ); ?>">
				<th><?php echo wp_kses_post( $header ); ?></th>
				<td><?php echo wp_kses_post( $contents ); ?></td>
			</tr>
		<?php
	}

	/**
	 * Returns html for a storage method using the parameters passed in, this version of the method is compatible with multi storage options
	 * DEV NOTE: please don't use this method in a handlebars template, but write the HTML code directly in the template. Also, this method might be no longer available in future releases
	 *
	 * @param  string $classes  a list of classes to be used when
	 * @param  string $header   the table header content
	 * @param  string $contents the table contents
	 * @return string handlebars html template
	 */
	public function get_storagemethod_row_multi_configuration_template( $classes, $header, $contents ) {
		return '<tr class="' . esc_attr( $classes ) . '">
					<th>' . $header . '</th>
					<td>' . $contents . '</td>
				</tr>';
	}

	/**
	 * Directly output the settings form (suitable for the admin area)
	 *
	 * @param Array $options current options (passed on to the template)
	 */
	public function settings_formcontents( $options = array() ) {
		$this->include_template(
			'wp-admin/settings/form-contents.php',
			false,
			array(
				'options' => $options,
			)
		);
		$this->include_template( 'wp-admin/settings/exclude-modal.php', false );
	}

	/**
	 * Return or display the HTML for the files selector widget
	 *
	 * @param  String         $prefix                 Prefix for the ID
	 * @param  Boolean        $show_exclusion_options True or False for exclusion options
	 * @param  Boolean|String $include_more           $include_more can be (bool) or (string)"sometimes"
	 * @param  Boolean        $echo_instead_of_return Pass true to display instead of return.
	 *
	 * @return String
	 */
	public function files_selector_widgetry( $prefix = '', $show_exclusion_options = true, $include_more = true, $echo_instead_of_return = false ) {

		if ( ! $echo_instead_of_return ) {
			ob_start();
		}
		global $backupplus;
		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		if ( ! function_exists( 'get_mu_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$mu_plugins = get_mu_plugins();

		// The true (default value if non-existent) here has the effect of forcing a default of on.
		$include_more_paths = BackupPlus_Options::get_backupplus_option( 'backupplus_include_more_path' );
		foreach ( $backupable_entities as $key => $info ) {
			if ( $show_exclusion_options && BackupPlus_Backup_Sources::custom( $key ) ) { continue; }
			$included = ( BackupPlus_Backup_Sources::included( $key ) ) ? 'checked="checked"' : '';
			if ( in_array( $key, array( 'others', 'uploads', 'wpcore' ), true ) ) {

				$data_toggle_exclude_field = $show_exclusion_options ? 'data-toggle_exclude_field="' . $key . '"' : '';

				/* translators: %s: Server path. */
				echo '<label ' . ( ( 'others' == $key ) ? 'title="' . esc_attr( sprintf( __( 'Your wp-content directory server path: %s', 'backupplus' ), WP_CONTENT_DIR ) ) . '" ' : '' ) . ' for="' . esc_attr( $prefix . 'backupplus_include_' . $key ) . '" class="backupplus_checkbox"><input class="backupplus_include_entity" id="' . esc_attr( $prefix . 'backupplus_include_' . $key ) . '" ' . wp_kses( $data_toggle_exclude_field, array() ) . ' type="checkbox" name="backupplus_include_' . esc_attr( $key ) . '" value="1" ' . wp_kses( $included, array() ) . '> ' .
							esc_html( ( 'others' == $key ) ? __( 'Any other directories found inside wp-content', 'backupplus' ) : $info['description'] ) .
						'</label>';

				if ( $show_exclusion_options ) {
					$default_exclude = 'others' === $key ? BACKUPPLUS_DEFAULT_OTHERS_EXCLUDE : ( 'uploads' === $key ? BACKUPPLUS_DEFAULT_UPLOADS_EXCLUDE : '' );
					BackupPlus_Sources_Admin::file_exclusions( $key, $info['description'], $info['path'], $prefix, $default_exclude );
				}
			} else {

				if ( 'more' != $key || true === $include_more || ( 'sometimes' === $include_more && ! empty( $include_more_paths ) ) ) {

					$data_toggle_exclude_field = $show_exclusion_options ? 'data-toggle_exclude_field="' . $key . '"' : '';

					$force_disabled = '';
					if ( 'mu-plugins' == $key && ! $mu_plugins ) {
						$force_disabled       = 'data-force_disabled="1"';
						$info['description'] .= ' (' . __( 'none present', 'backupplus' ) . ')';
					}

					echo '<label for="' . esc_attr( $prefix . 'backupplus_include_' . $key ) . '" ' . ( ( isset( $info['htmltitle'] ) ) ? ' title="' . esc_attr( $info['htmltitle'] ) . '"' : '' ) . ' class="backupplus_checkbox"><input class="backupplus_include_entity" ' . wp_kses( $data_toggle_exclude_field, array() ) . ' id="' . esc_attr( $prefix . 'backupplus_include_' . $key ) . '" type="checkbox" name="' . esc_attr( 'backupplus_include_' . $key ) . '" value="1" ' . wp_kses( $included . ' ' . $force_disabled, array() ) . '>' . esc_html( $info['description'] );

					echo '</label>';
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped HTML.
					echo apply_filters( "backupplus_config_option_include_$key", '', $prefix, false );
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		}

		if ( $show_exclusion_options ) { BackupPlus_Sources_Admin::file_settings( $prefix ); }
		if ( ! $echo_instead_of_return ) {
			return ob_get_clean();
		}
	}

	/**
	 * Output or echo HTML for an error condition relating to a remote storage method
	 *
	 * @param String  $text       - the text of the message; this should already be escaped (no more is done)
	 * @param String  $extraclass - a CSS class for the resulting DOM node
	 * @param Integer $echo       - if set, then the results will be echoed as well as returned
	 *
	 * @return String - the results
	 */
	public function show_double_warning( $text, $extraclass = '', $echo = true ) {

		$ret  = "<div class=\"error backupplusmethod $extraclass\"><p>$text</p></div>";
		$ret .= "<div class=\"notice error below-h2\"><p>$text</p></div>";

		if ( $echo ) {
			echo wp_kses_post( $ret );
		}
		return $ret;
	}

	public function optionfilter_split_every( $value ) {
		return max( absint( $value ), BACKUPPLUS_SPLIT_MIN );
	}

	/**
	 * Check if curl exists; if not, print or return appropriate error messages
	 *
	 * @param String  $service                the service description (used only for user-visible messages - so, use the description)
	 * @param Boolean $has_fallback           set as true if the lack of Curl only affects the ability to connect over SSL
	 * @param String  $extraclass             an extra CSS class for any resulting message, passed on to show_double_warning()
	 * @param Boolean $echo_instead_of_return whether the result should be echoed or returned
	 * @return String                         any resulting message, if $echo_instead_of_return was set
	 */
	public function curl_check( $service, $has_fallback = false, $extraclass = '', $echo_instead_of_return = true ) {

		$ret = '';

		// Check requirements
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			$ret .= $this->show_double_warning(
				'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
				/* translators: 1: Service name, 2: Required module. */
				sprintf( __( 'Your web server\'s PHP installation does not included a <strong>required</strong> (for %1$s) module (%2$s).', 'backupplus' ), $service, 'Curl' ) . ' ' .
				__( "Please contact your web hosting provider's support and ask for them to enable it.", 'backupplus' ) . ' ',
				$extraclass,
				false
			);
		} else {
			$curl_version       = curl_version();
			$curl_ssl_supported = ( $curl_version['features'] & CURL_VERSION_SSL );
			if ( ! $curl_ssl_supported ) {
				if ( $has_fallback ) {
					$ret .= '<p><strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . __( "Your web server's PHP/Curl installation does not support https access.", 'backupplus' ) . ' ' .
							/* translators: %s: Service Name. */
							sprintf( __( 'Communications with %s will be unencrypted.', 'backupplus' ), $service )
								. ' ' . __( 'Ask your web host to install cURL with SSL support to enable encrypted communications.', 'backupplus' ) . '</p>';
				} else {
					$ret .= $this->show_double_warning(
						'<p><strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . __( "Your web server's PHP/Curl installation does not support https access.", 'backupplus' ) . ' '
								/* translators: %s: Service Name. */
								. sprintf( __( 'We cannot access %s without this support.', 'backupplus' ), $service )
								. ' ' . __( "Please contact your web hosting provider's support.", 'backupplus' ) . ' '
								/* translators: %s: Service Name. */
								. sprintf( __( '%s requires Curl+https.', 'backupplus' ), $service )
								. ' ' . __( 'Please do not file any support requests; there is no alternative.', 'backupplus' ) . '</p>',
						$extraclass,
						false
					);
				}
			} else {
				$ret .= '<p><em>'
							/* translators: %s: Service Name. */
							. sprintf( __( "Good news: Your site's communications with %s can be encrypted.", 'backupplus' ), $service )
							. ' ' . __( "If you see any errors to do with encryption, then look in the 'Expert Settings' for more help.", 'backupplus' )
						. '</em></p>';
			}
		}
		if ( $echo_instead_of_return ) {
			echo wp_kses_post( $ret );
		} else {
			return $ret;
		}
	}

	/**
	 * This function will get a list of remote storage methods with valid connection details and create a HTML list of checkboxes
	 *
	 * @return String - HTML checkbox list of remote storage methods with valid connection details
	 */
	private function backup_now_remote_message() {
		global $backupplus;

		$active_remote_storage_list = '';
		$active_remote_storages     = $this->get_active_remote_storages();

		foreach ( $active_remote_storages as $instance => $remote_storage ) {
			$method  = $remote_storage['method'];
			$label   = $remote_storage['label'];
			$checked = ! empty( $remote_storage['enabled'] ) ? 'checked="checked"' : '';

			if ( 'email' == $method ) {
				$active_remote_storage_list .= '<input class="backupplus_remote_service_entity" id="' . esc_attr( $method ) . 'backupplus_service" checked="checked" type="checkbox" name="backupplus_include_remote_service_' . esc_attr( $method ) . '" value=""> <label for="' . esc_attr( $method ) . 'backupplus_service">' . esc_html( $backupplus->backup_methods[ $method ] ) . '</label><br>';
			} else {
				$active_remote_storage_list .= '<input class="backupplus_remote_service_entity" id="' . esc_attr( $method ) . 'backupplus_service_' . esc_attr( $instance ) . '" ' . esc_attr( $checked ) . ' type="checkbox" name="backupplus_include_remote_service_' . esc_attr( $method ) . '" value="' . esc_attr( $instance ) . '"> <label for="' . esc_attr( $method ) . 'backupplus_service_' . esc_attr( $instance ) . '">' . esc_html( $label ) . '</label><br>';
			}
		}

		$service = $backupplus->just_one( BackupPlus_Options::get_backupplus_option( 'backupplus_service' ) );
		if ( is_string( $service ) ) {
			$service = array( $service );
		}
		if ( ! is_array( $service ) ) {
			$service = array();
		}

		$no_remote_configured = ( empty( $service ) || array( 'none' ) === $service || array( '' ) === $service ) ? true : false;

		if ( $no_remote_configured && empty( $active_remote_storage_list ) ) {
			return '<input type="checkbox" disabled="disabled" id="backupnow_includecloud"> <em>' .
			/* translators: %s: "settings" which is the name of a tab on which remote storage settings are configured */
			sprintf(
				__( "Backup won't be sent to any remote storage - none has been saved in the %s", 'backupplus' ),
				'<a href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&amp;tab=settings" id="backupplus_backupnow_gotosettings">' .
				__( 'settings', 'backupplus' )
			) . '</a></em>';
		}

		if ( empty( $active_remote_storage_list ) ) {
			$active_remote_storage_list = '<p>' . __( 'No remote storage locations with valid options found.', 'backupplus' ) . '</p>';
		}

		return '<input type="checkbox" id="backupnow_includecloud" checked="checked"> <label for="backupnow_includecloud">' . __( 'Send this backup to remote storage', 'backupplus' ) . '</label> (<a href="' . esc_url( BackupPlus::get_current_clean_url() ) . '" id="backupnow_includecloud_showmoreoptions">...</a>)<br><div id="backupnow_includecloud_moreoptions" class="backupplus-hidden" style="display:none;"><em>' . __( 'The following remote storage options are configured.', 'backupplus' ) . '</em><br>' . $active_remote_storage_list . '</div>';
	}

	/**
	 * This function will get a list of remote storage methods with valid connection details.
	 *
	 * @return array - List of configured remote storages.
	 */
	public function get_active_remote_storages() {
		global $backupplus;

		$active_instances = array();
		$services         = (array) $backupplus->just_one( $backupplus->get_canonical_service_list() );
		$all_services     = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( $services );

		foreach ( $all_services as $method => $sinfo ) {
			if ( 'email' == $method ) {
				$possible_emails = $backupplus->just_one_email( BackupPlus_Options::get_backupplus_option( 'backupplus_email' ) );
				if ( ! empty( $possible_emails ) ) {
					$active_instances['email'] = array(
						'label'   => $backupplus->backup_methods[ $method ],
						'method'  => $method,
						'enabled' => true, // We hardcode this as true -- legacy logic, its always checked.
					);
				}
			} elseif ( empty( $sinfo['object'] ) || empty( $sinfo['instance_settings'] ) || ! is_callable( array( $sinfo['object'], 'options_exist' ) ) ) {
				continue;
			}

			$instance_count = 1;
			foreach ( $sinfo['instance_settings'] as $instance => $opt ) {
				if ( $sinfo['object']->options_exist( $opt ) ) {
					$instance_count_label = ( 1 == $instance_count ) ? '' : ' (' . $instance_count . ')';
					$label                = empty( $opt['instance_label'] ) ? $sinfo['object']->get_description() . $instance_count_label : $opt['instance_label'];
					if ( ! isset( $opt['instance_enabled'] ) ) {
						$opt['instance_enabled'] = 1;
					}
					$active_instances[ $instance ] = array(
						'label'   => $label,
						'method'  => $method,
						'enabled' => ! empty( $opt['instance_enabled'] ),
					);
					++$instance_count;
				}
			}
		}

		return $active_instances;
	}
}
