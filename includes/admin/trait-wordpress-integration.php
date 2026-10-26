<?php

/** Hooks into WordPress core screens: admin bar, plugin list, heartbeat, kses and email download links. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_WordPress_Integration {

	/**
	 * Sets up what is needed to allow an in-page backup to be run. Will enqueue scripts and output appropriate HTML (so, should be run when at a suitable place). Not intended for use on the BackupPlus settings page.
	 *
	 * @param string   $title    Text to use for the title of the modal
	 * @param callable $callback Callable function to output the contents of the backupplus_inpage_prebackup element - i.e. what shows in the modal before a backup begins.
	 */
	public function add_backup_scaffolding( $title, $callback ) {
		$this->admin_enqueue_scripts();
		?>
		<script>
		// TODO: This is not the best way.
		var backupplus_credentialtest_nonce='<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>';
		</script>
		<div id="backupplus-poplog">
			<pre id="backupplus-poplog-content" style="white-space: pre-wrap;"></pre>
		</div>
		
		<div id="backupplus-backupnow-inpage-modal" title="BackupPlus - <?php echo esc_attr( $title ); ?>">

			<div id="backupplus_inpage_prebackup" style="float:left; clear:both;">
				<?php call_user_func( $callback ); ?>
			</div>

			<div id="backupplus_inpage_backup">

				<h2><?php echo esc_html( $title ); ?></h2>

				<div id="backupplus_backup_started" class="updated" style="display:none; max-width: 560px; font-size:100%; line-height: 100%; padding:6px; clear:left;"></div>

				<?php $this->render_active_jobs_and_log_table( true, false ); ?>

			</div>

		</div>
		<?php
	}

	/**
	 * Runs upon the WP action wp_before_admin_bar_render
	 */
	public function wp_before_admin_bar_render() {
		global $wp_admin_bar;

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		if ( defined( 'BACKUPPLUS_ADMINBAR_DISABLE' ) && BACKUPPLUS_ADMINBAR_DISABLE ) {
			return;
		}

		if ( false == apply_filters( 'backupplus_settings_page_render', true ) ) {
			return;
		}

		$option_location = BackupPlus_Options::admin_page_url();

		$args = array(
			'id'    => 'backupplus_admin_node',
			'title' => apply_filters( 'backupplus_admin_node_title', 'BackupPlus' ),
		);
		$wp_admin_bar->add_node( $args );

		$args = array(
			'id'     => 'backupplus_admin_node_status',
			'title'  => str_ireplace( 'Back Up', 'Backup', __( 'Backup', 'backupplus' ) ) . ' / ' . __( 'Restore', 'backupplus' ),
			'parent' => 'backupplus_admin_node',
			'href'   => $option_location . '?page=backupplus&tab=backups',
		);
		$wp_admin_bar->add_node( $args );

		$args = array(
			'id'     => 'backupplus_admin_node_settings',
			'title'  => __( 'Settings', 'backupplus' ),
			'parent' => 'backupplus_admin_node',
			'href'   => $option_location . '?page=backupplus&tab=settings',
		);
		$wp_admin_bar->add_node( $args );

		$args = array(
			'id'     => 'backupplus_admin_node_expert_content',
			'title'  => __( 'Advanced Tools', 'backupplus' ),
			'parent' => 'backupplus_admin_node',
			'href'   => $option_location . '?page=backupplus&tab=expert',
		);
		$wp_admin_bar->add_node( $args );
	}

	/**
	 * Check if available disk space is at least the specified number of bytes
	 *
	 * @param Integer $space - number of bytes
	 *
	 * @return Integer|Boolean - true or false to indicate if available; of -1 if the result is unknown
	 */
	private function disk_space_check( $space ) {
		// Allow checking by some other means (user request)
		if ( null !== ( $filtered_result = apply_filters( 'backupplus_disk_space_check', null, $space ) ) ) {
			return $filtered_result;
		}
		global $backupplus;
		$backupplus_dir  = $backupplus->backups_dir_location();
		$disk_free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( $backupplus_dir ) : false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( false == $disk_free_space ) {
			return -1;
		}
		return ( $disk_free_space > $space ) ? true : false;
	}

	/**
	 * Adds the settings link under the plugin on the plugin screen.
	 *
	 * @param  Array  $links Set of links for the plugin, before being filtered
	 * @param  String $file  File name (relative to the plugin directory)
	 * @return Array filtered results
	 */
	public function plugin_action_links( $links, $file ) {
		global $backupplus;
		if ( is_array( $links ) && BACKUPPLUS_PLUGIN_SLUG == $file ) {
			$settings_link = '<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus" class="js-backupplus-settings">' . __( 'Settings', 'backupplus' ) . '</a>';
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	/**
	 * Change the author link under the plugin's description on the plugin screen.
	 *
	 * @param  array  $plugin_meta - An array of the plugin's metadata
	 * @param  string $plugin_file - Path to the plugin file relative to the plugins directory
	 * @param  array  $plugin_data - An array of plugin data
	 * @return array - filtered plugin's metadata
	 */
	public function change_plugin_author_link( $plugin_meta, $plugin_file, $plugin_data ) {
		if ( BACKUPPLUS_PLUGIN_SLUG === $plugin_file ) {
			/* translators: %s: Original plugin authors. */
			$plugin_meta[1] = sprintf( __( 'By %s', 'backupplus' ), $plugin_data['Author'] );
		}
		return $plugin_meta;
	}

	/**
	 * Receive Heartbeat data and respond.
	 *
	 * Processes data received via a Heartbeat request, and returns additional data to pass back to the front end.
	 *
	 * @param array $response - Heartbeat response data to pass back to front end.
	 * @param array $data     - Data received from the front end (unslashed).
	 */
	public function process_status_in_heartbeat( $response, $data ) {

		if ( ! BackupPlus_Options::user_can_manage() || ! is_array( $response ) || empty( $data['backupplus'] ) ) {
			return $response;
		}

		try {
			$response['backupplus'] = $this->get_activejobs_list( BackupPlus_Manipulation_Functions::wp_unslash( $data['backupplus'] ) );
		} catch ( Exception $e ) {
			$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during get active job list. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			$response['backupplus'] = array(
				'fatal_error'         => true,
				'fatal_error_message' => $log_message,
			);
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
			$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during get active job list. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			$response['backupplus'] = array(
				'fatal_error'         => true,
				'fatal_error_message' => $log_message,
			);
		}

		if ( isset( $data['backupplus']['backupplus_credentialtest_nonce'] ) ) {
			if ( ! wp_verify_nonce( $data['backupplus']['backupplus_credentialtest_nonce'], 'backupplus-credentialtest-nonce' ) ) {
				$response['backupplus']['backupplus_credentialtest_nonce'] = wp_create_nonce( 'backupplus-credentialtest-nonce' );
			}
		}

		$response['backupplus']['time_now'] = get_date_from_gmt( gmdate( 'Y-m-d H:i:s' ), 'D, F j, Y H:i' );

		return $response;
	}

	/**
	 * Find out if the current request is a backup download request, and proceed with the download if it is
	 */
	public function maybe_download_backup_from_email() {
		global $pagenow;
		if ( BackupPlus_Options::user_can_manage() && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) && BackupPlus_Options::admin_page() === $pagenow && isset( $_REQUEST['page'] ) && 'backupplus' === $_REQUEST['page'] && isset( $_REQUEST['action'] ) && 'backupplus_download_backup' === $_REQUEST['action'] ) {
			$findex            = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'findex' );
			$findexes          = empty( $findex ) ? array( 0 ) : $findex;
			$request_timestamp = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'timestamp' );
			$timestamp         = empty( $request_timestamp ) ? '' : $request_timestamp;
			$request_nonce     = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'nonce' );
			$nonce             = empty( $request_nonce ) ? '' : $request_nonce;
			$request_type      = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'type' );
			$type              = empty( $request_type ) ? '' : $request_type;
			if ( empty( $timestamp ) || empty( $nonce ) || empty( $type ) ) {
				wp_die( esc_html__( 'The download link is broken, you may have clicked the link from untrusted source', 'backupplus' ), '', array( 'back_link' => true ) );
			}
			$backup_history = BackupPlus_Backup_History::get_history();
			if ( ! isset( $backup_history[ $timestamp ]['nonce'] ) || $backup_history[ $timestamp ]['nonce'] !== $nonce ) {
				wp_die( esc_html__( 'The download link is broken or the backup file is no longer available', 'backupplus' ), '', array( 'back_link' => true ) );
			}
			$this->do_backupplus_download_backup( $findexes, $type, $timestamp, 2, false, '' );
			exit; // we don't need anything else but an exit
		}
	}

	/**
	 * Allow HTML input elements on the unfinished restoration dialog ensuring the HTML form element doesn't get stripped during the call of wp_kses_post
	 *
	 * @param Array $allowed_html Allowed HTML elements
	 * @return Array The filtered allowed HTML elements
	 */
	public function kses_allow_input_tags_on_unfinished_restoration_dialog( $allowed_html ) {
		if ( ! isset( $allowed_html['input'] ) ) {
			$allowed_html['input'] = array();
		}
		$allowed_html['input']['type']  = true;
		$allowed_html['input']['name']  = true;
		$allowed_html['input']['value'] = true;
		$allowed_html['input']['id']    = true;
		return $allowed_html;
	}

	/**
	 * Adds the 'display' property to the list of allowed CSS styles for wp_kses.
	 *
	 * This function is typically used with the `safe_style_css` filter to ensure
	 * that the `display` property is not stripped when sanitizing inline styles
	 * using wp_kses or related functions.
	 *
	 * @param array $css The array of currently allowed CSS properties.
	 * @return array The modified array of allowed CSS properties including 'display'.
	 */
	public function kses_allow_style_display( $css ) {
		$css[] = 'display';
		return $css;
	}

	/**
	 * Returns an array of allowed HTML tags and their attributes for sanitization purposes.
	 *
	 * This function defines a set of HTML tags and attributes that are considered safe
	 * and can be used in specific contexts. The attributes are defined for each tag
	 * to ensure only the intended attributes are allowed.
	 *
	 * @return array<string, array<string, bool>> An associative array where the keys are HTML tag names
	 *                                            and the values are arrays of allowed attributes for
	 *                                            each tag. The attribute arrays use attribute names
	 *                                            as keys and `true` as the value to indicate the attribute
	 *                                            is allowed.
	 */
	public function kses_allow_tags() {
		return array(
			'div'    => array(
				'id'    => true,
				'class' => true,
				'style' => true,
			),
			'h3'     => array(
				'style' => true,
			),
			'input'  => array(
				'id'           => true,
				'class'        => true,
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'checked'      => true,
				'style'        => true,
				'min'          => true,
				'step'         => true,
				'title'        => true,
				'id'           => true,
				'placeholder'  => true,
				'disabled'     => true,
				'readonly'     => true,
				'required'     => true,
				'maxlength'    => true,
				'max'          => true,
				'pattern'      => true,
				'size'         => true,
				'data-*'       => true,
				'tabindex'     => true,
				'autocomplete' => true,
				'autofocus'    => true,
				'width'        => true,
				'height'       => true,
			),
			'select' => array(
				'id'    => true,
				'class' => true,
				'name'  => true,
				'value' => true,
				'style' => true,
			),
			'option' => array(
				'value'    => true,
				'selected' => true,
			),
			'label'  => array(
				'id'    => true,
				'class' => true,
				'style' => true,
				'for'   => true,
			),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'a'      => array(
				'id'               => true,
				'class'            => true,
				'style'            => true,
				'href'             => true,
				'target'           => true,
				'onclick'          => true,
				'data-type'        => true,
				'data-incremental' => true,
			),
			'button' => array(
				'id'               => true,
				'class'            => true,
				'type'             => true,
				'style'            => true,
				'onclick'          => true,
				'disabled'         => true,
				'title'            => true,
				'data-incremental' => true,
			),
			'span'   => array(
				'id'    => true,
				'class' => true,
				'style' => true,
			),
			'img'    => array(
				'id'     => true,
				'class'  => true,
				'style'  => true,
				'src'    => true,
				'width'  => true,
				'height' => true,
				'alt'    => true,
			),
			'p'      => array(
				'id'    => true,
				'class' => true,
				'style' => true,
			),
		);
	}
}
