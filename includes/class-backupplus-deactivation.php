<?php
if (!defined('ABSPATH')) die('No direct access allowed');

/**
 * Handles the BackupPlus deactivation popup modal.
 */
class BackupPlus_Deactivation {
		
	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('init', array($this, 'init_deinstall_dialog'));
	}

	/**
	 * Initialize the deactivation popup on admin plugins page or for AJAX requests
	 * Sets up the hooks required for initializing the deactivation dialog
	 */
	public function init_deinstall_dialog() {
		global $pagenow;
		if ((defined('WP_AJAX') && WP_AJAX) || 'admin-ajax.php' == $pagenow) {
			add_action('backupplus_deinstall', array($this, 'handle_user_choice'));
			$this->instantiate_deinstall_dialog_object();
		} elseif ('plugins.php' == $pagenow) {
			add_action('admin_init', array($this, 'instantiate_deinstall_dialog_object'));
			add_action('backupplus_admin_enqueue_scripts', array($this, 'enqueue_dialog_scripts'));
			add_action('admin_footer', array($this, 'enqueue_admin_common_scripts'));
			add_action('admin_print_footer_scripts', array($this, 'add_footer_inline_script'));
		}
	}

	/**
	 * Print additional javascript in the footer part of the page
	 */
	public function add_footer_inline_script() {
		if (!BackupPlus_Options::user_can_manage()) {
			return;
		}
		echo "<script type='text/javascript'>\n";
		// in free version on plugins.php page, since the deinstall dialog requires admin-common-js, the backupplus_credentialtest_nonce variable should be declared because it's going to be used by the backupplus_send_command for background operations, if not declared it will produce JS error in the browser's console; auto-backup does this too.
		echo "var backupplus_credentialtest_nonce = backupplus_credentialtest_nonce || '".esc_js(wp_create_nonce('backupplus-credentialtest-nonce'))."';";
		echo "\n</script>";
	}

	/**
	 * Enqueue our admin common scripts/styles
	 */
	public function enqueue_admin_common_scripts() {
		global $backupplus_admin;
		$backupplus_admin->admin_enqueue_scripts();
	}

	/**
	 * Enqueue deactivation dialog scripts
	 */
	public function enqueue_dialog_scripts() {
		global $backupplus;
		$enqueue_version = $backupplus->use_unminified_scripts() ? $backupplus->version.'.'.time() : $backupplus->version;
		$backupplus_min_or_not = $backupplus->get_backupplus_file_version();
		wp_enqueue_style('backupplus-deactivation-popup', BACKUPPLUS_URL.'/css/backupplus-deactivation'.$backupplus_min_or_not.'.css', array('backupplus-jquery-ui'), $enqueue_version);
		wp_enqueue_script('backupplus-deactivation-js', BACKUPPLUS_URL.'/js/backupplus-deactivation'.$backupplus_min_or_not.'.js', array('jquery'), $enqueue_version);
		wp_localize_script('backupplus-deactivation-js', 'backupplus_deinstall_dialog', array(
			'deactivate' => esc_html__('Deactivate', 'backupplus'),
			'remove' => esc_html__('Remove and deactivate', 'backupplus'),
		));
	}

	/**
	 * Instantiate a deactivation dialog object with preconfigured settings
	 */
	public function instantiate_deinstall_dialog_object() {

		if (!class_exists('Updraft_Deinstall_Dialog_v1')) {
			backupplus_try_include_file(
				'vendor/team-updraft/common-libs/src/updraft-plugin-deinstall-dialog/class-updraft-deinstall-dialog.php',
				'include_once'
			);
		}

		if (class_exists('Updraft_Deinstall_Dialog_v1')) {
			new Updraft_Deinstall_Dialog_v1(array(
				'script_handler'   => 'backupplus',
				'plugin_slug'      => BACKUPPLUS_PLUGIN_SLUG,
				'dialog_title'     => esc_html__('Before you deactivate...', 'backupplus'),
				'deactivate_label' => esc_html__('Deactivate', 'backupplus'),
				'cancel_label'     => esc_html__('Cancel', 'backupplus'),
				'template_file'    => BACKUPPLUS_DIR . '/templates/deactivation-popup-modal.php',
				'show_on_network_admin' => true,
				'show_on_subsites' => false,
				'custom_css' => true,
			));
		}
	}

	/**
	 * Handle the AJAX request to save the user’s deactivation choice.
	 * The capability check is handled by the dialog library.
	 */
	public function handle_user_choice() {

		$choice = BackupPlus_Manipulation_Functions::fetch_superglobal('post', 'backupplus_deinstall_option', 'no', true, null, 'sanitize_text_field');
		update_site_option('backupplus_deinstall_option', $choice);
	}
}
