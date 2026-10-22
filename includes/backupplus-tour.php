<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
/**
 * Class BackupPlus_Tour
 *
 * Adds the guided tour when activating the plugin for the first time.
 */
class BackupPlus_Tour {

	/**
	 * The class instance
	 *
	 * @var object
	 */
	protected static $instance;

	/**
	 * Get the instance
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * __construct
	 */
	private function __construct() {
	}

	/**
	 * Sets up the notices, security and loads assets for the admin page
	 */
	public function init() {
		// Add plugin action link
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );

		// only init and load assets if the tour hasn't been canceled
		$backupplus_tour = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'backupplus_tour' );
		if ( isset( $backupplus_tour ) && 0 === (int) $backupplus_tour ) {
			$this->set_tour_status( array( 'current_step' => 'start' ) );
			return;
		}

		// if backups already exist and
		if ( $this->backupplus_was_already_installed() && ! isset( $backupplus_tour ) ) {
			return;
		}

		// if 'Take tour' link was used, reset tour
		if ( isset( $backupplus_tour ) && 1 === (int) $backupplus_tour ) {
			$this->reset_tour_status();
		}

		if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_tour_cancelled_on' ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'load_tour' ) );
		}
	}

	/**
	 * Loads in tour assets
	 *
	 * @param string $hook - current page
	 */
	public function load_tour( $hook ) {

		$pages = array( 'settings_page_backupplus', 'plugins.php' );

		if ( ! in_array( $hook, $pages ) ) {
			return;
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		global $backupplus;

		$script_suffix         = $backupplus->use_unminified_scripts() ? '' : '.min';
		$backupplus_min_or_not = $backupplus->get_backupplus_file_version();
		wp_enqueue_script( 'backupplus-tether-js', trailingslashit( BACKUPPLUS_URL ) . 'includes/tether/tether' . $script_suffix . '.js', $backupplus->version, true );
		wp_enqueue_script( 'backupplus-shepherd-js', trailingslashit( BACKUPPLUS_URL ) . 'includes/tether-shepherd/shepherd' . $script_suffix . '.js', array( 'backupplus-tether-js' ), $backupplus->version, true );
		wp_enqueue_style( 'backupplus-shepherd-css', trailingslashit( BACKUPPLUS_URL ) . 'css/tether-shepherd/shepherd-theme-arrows-plain-buttons' . $script_suffix . '.css', false, $backupplus->version );
		wp_enqueue_style( 'backupplus-tour-css', trailingslashit( BACKUPPLUS_URL ) . 'css/backupplus-tour' . $backupplus_min_or_not . '.css', false, $backupplus->version );
		wp_register_script( 'backupplus-tour-js', trailingslashit( BACKUPPLUS_URL ) . 'js/tour.js', array( 'backupplus-tether-js' ), $backupplus->version, true );

		$tour_data = array(
			'nonce'                   => wp_create_nonce( 'backupplus-credentialtest-nonce' ),
			'show_tab_on_load'        => '#backupplus-navtab-status',
			'next'                    => __( 'Next', 'backupplus' ),
			'back'                    => __( 'Back', 'backupplus' ),
			'skip'                    => __( 'Skip this step', 'backupplus' ),
			'end_tour'                => __( 'End tour', 'backupplus' ),
			'close'                   => __( 'Close', 'backupplus' ),
			'plugins_page'            => array(
				'title'  => __( 'BackupPlus settings', 'backupplus' ),
				'text'   => '<strong>' . __( 'Welcome to BackupPlus', 'backupplus' ) . '</strong>, ' . __( 'the world’s most trusted backup plugin!', 'backupplus' ),
				'button' => array(
					'url'  => BackupPlus_Options::admin_page_url() . '?page=backupplus',
					'text' => __( 'Press here to start!', 'backupplus' ),
				),
			),
			'backup_now'              => array(
				'title' => __( 'Your first backup', 'backupplus' ),
				'text'  => sprintf(
					__( 'To make a simple backup to your server, press this button.', 'backupplus' ) . ' ' .
					/* translators: 1: HTML <a> and <strong> opening tag 2: HTML </a> and </strong> closing tag*/
					__( 'Or to setup regular backups and remote storage, go to %1$s settings %2$s', 'backupplus' ),
					'<strong><a href="#settings" class="js--go-to-settings">',
					'</a></strong>'
				),
			),
			'backup_options'          => array(
				'title' => __( 'Manual backup options', 'backupplus' ),
				'text'  => __( 'Select what you want to backup', 'backupplus' ),
			),
			'backup_now_btn'          => array(
				'title'    => __( 'Creating your first backup', 'backupplus' ),
				'text'     => __( 'Press here to run a manual backup.', 'backupplus' ) . '<br>' .
					/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
					sprintf( _x( 'But to avoid server-wide threats backup regularly to remote cloud storage in %1$s settings %2$s', 'Backup warning with link', 'backupplus' ), '<strong><a href="#settings" class="js--go-to-settings">', '</a></strong>' ),
				'btn_text' => __( 'Go to settings', 'backupplus' ),
			),
			'backup_now_btn_success'  => array(
				'title'    => __( 'Creating your first backup', 'backupplus' ),
				'text'     => __( 'Congratulations! Your first backup is running.', 'backupplus' ) . '<br>' .
					/* translators: 1: Opening strong tag, 2: Closing strong tag */
					sprintf( _x( 'But to avoid server-wide threats backup regularly to remote cloud storage in %1$s settings %2$s', 'Backup recommendation', 'backupplus' ), '<strong>', '</strong>' ),
				'btn_text' => __( 'Go to settings', 'backupplus' ),
			),
			'settings_timing'         => array(
				'title' => __( 'Choose your backup schedule', 'backupplus' ),
				'text'  => __( 'Choose the schedule that you want your backups to run on.', 'backupplus' ),
			),
			'settings_remote_storage' => array(
				'title' => __( 'Remote storage', 'backupplus' ),
				'text'  => __( 'Now select a remote storage destination to protect against server-wide threats.', 'backupplus' ) . ' ' . __( 'If not, your backups remain on the same server as your site.', 'backupplus' ),
			),
			'settings_more'           => array(
				'title' => __( 'More settings', 'backupplus' ),
				'text'  => __( 'Look through the other settings here, making any changes you’d like.', 'backupplus' ),
			),
			'auto_backup'             => array(
				'title'   => __( 'Pre-update backups', 'backupplus' ),
				'text'    => __( 'Check this box to enable automatic backups whenever a plugin, theme, or the WordPress core is updated.', 'backupplus' ),
				'enabled' => true,
			),
			'settings_save'           => array(
				'title' => __( 'Save', 'backupplus' ),
				'text'  => __( 'Press here to save your settings.', 'backupplus' ),
			),
			'settings_saved'          => array(
				'title' => __( 'Save', 'backupplus' ),
				'text'  => __( 'Congratulations, your settings have successfully been saved.', 'backupplus' ),
			),
		);
		$tab       = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'tab' );
		if ( isset( $tab ) ) {
			$tour_data['show_tab_on_load'] = '#backupplus-navtab-' . esc_attr( sanitize_text_field( $tab ) );
		}

		wp_localize_script( 'backupplus-tour-js', 'backupplus_tour_i18n', $tour_data );
		wp_enqueue_script( 'backupplus-tour-js' );
	}

	/**
	 * Removes the tour status so the tour can be seen again
	 *
	 * @return string|WP_Error not visible by the user
	 */
	public function reset_tour_status() {

		// If the option isn't set, the tour hasn't been cancelled
		if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_tour_cancelled_on' ) ) {
			// string not visible by the user
			return 'The tour is still active. Everything should be ok.';
		}

		$result = BackupPlus_Options::delete_backupplus_option( 'backupplus_tour_cancelled_on' );
		// strings not visible by the user
		return $result ? 'The tour status was successfully reset' : new WP_Error( 'update_failed', 'The attempt to update the tour option failed.', array( 'status' => 409 ) );
	}

	/**
	 * Updates the stored value for which step the tour ended on
	 *
	 * @param object $request - the http $_REQUEST obj
	 * @return bool
	 */
	public function set_tour_status( $request ) {
		if ( ! isset( $request['current_step'] ) ) {
			return false;
		}
		return BackupPlus_Options::update_backupplus_option( 'backupplus_tour_cancelled_on', $request['current_step'] );
	}

	/**
	 * Adds the Tour link under the plugin on the plugin screen.
	 *
	 * @param  Array  $links Set of links for the plugin, before being filtered
	 * @param  String $file  File name (relative to the plugin directory)
	 * @return Array filtered results
	 */
	public function plugin_action_links( $links, $file ) {
		if ( is_array( $links ) && BACKUPPLUS_PLUGIN_SLUG === $file ) {
			$links['backupplus_tour'] = '<a href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&backupplus_tour=1" class="js-backupplus-tour">' . __( 'Take Tour', 'backupplus' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Checks if UDP was newly installed.
	 *
	 * Checks if there are backups, and if there are more than 1,
	 * checks if the folder is older than 1 day old
	 *
	 * @return bool
	 */
	public function backupplus_was_already_installed() {
		// If backups already exist
		$backup_history = BackupPlus_Backup_History::get_history();

		// No backup history
		if ( ! $backup_history ) {
			return false;
		}
		if ( is_array( $backup_history ) && 0 === count( $backup_history ) ) {
			return false;
		}
		// If there is at least 1 backup, we check if the folder is older than 1 day old
		if ( 0 < count( $backup_history ) ) {
			$backups_timestamps = array_keys( $backup_history );
			$last_backlup_age   = time() - end( $backups_timestamps );
			if ( DAY_IN_SECONDS < $last_backlup_age ) {
				// the oldest backup is older than 1 day old, so it's likely that UDP was already installed, and the backups aren't a product of the user testing while doing the tour.
				return true;
			}
		}
		return false;
	}
}

// add_action('admin_init', array(BackupPlus_Tour::get_instance(), 'init'))"";
