<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

if ( ! class_exists( 'BackupPlus_Restorer_Skin' ) ) {
	backupplus_try_include_file( 'includes/backupplus-restorer-skin.php', 'require_once' );
}
if ( ! class_exists( 'BackupPlus_Search_Replace' ) ) {
	backupplus_try_include_file( 'includes/class-search-replace.php', 'require_once' );
}

require_once __DIR__ . '/includes/restorer/trait-database-connection.php';
require_once __DIR__ . '/includes/restorer/trait-run.php';
require_once __DIR__ . '/includes/restorer/trait-unpack.php';
require_once __DIR__ . '/includes/restorer/trait-file-restore.php';
require_once __DIR__ . '/includes/restorer/trait-entity-restore.php';
require_once __DIR__ . '/includes/restorer/trait-permissions.php';
require_once __DIR__ . '/includes/restorer/trait-caches.php';
require_once __DIR__ . '/includes/restorer/trait-site-state.php';
require_once __DIR__ . '/includes/restorer/trait-database-restore.php';
require_once __DIR__ . '/includes/restorer/trait-database-tables.php';
require_once __DIR__ . '/includes/restorer/trait-database-sql.php';
require_once __DIR__ . '/includes/class-restore-inventory.php';

class BackupPlus_Restorer {
	use BackupPlus_Restorer_Database_Connection;
	use BackupPlus_Restorer_Run;
	use BackupPlus_Restorer_Unpack;
	use BackupPlus_Restorer_File_Restore;
	use BackupPlus_Restorer_Entity_Restore;
	use BackupPlus_Restorer_Permissions;
	use BackupPlus_Restorer_Caches;
	use BackupPlus_Restorer_Site_State;
	use BackupPlus_Restorer_Database_Restore;
	use BackupPlus_Restorer_Database_Tables;
	use BackupPlus_Restorer_Database_Sql;

	// This just stores the result of is_multisite()
	private $is_multisite;

	// This is just used so far for detecting whether we're on the second run for an entity or not.
	public $been_restored = array();

	private $tables_been_dropped = array();

	private $delete_archives_upon_restoration = false;

	private $errors;

	private $created_by_version = false;

	// This one can be set externally, if the information is available
	public $ud_backup_is_multisite = -1;

	public $ud_multisite_selective_restore = false;

	/**
	 * Information about the backup set being restored
	 *
	 * @var Array|Null
	 */
	private $ud_backup_set;

	private $ud_foreign_working_dir;

	private $ud_foreign_package;

	public $ud_foreign;

	private $ud_extract_count;

	private $ud_working_dir;

	private $ud_extract_dir;

	private $ud_made_dirs;

	// store restored table names
	public $restored_table_names = array();

	public $is_dummy_db_restore = false;

	// The default of false means "use the global $wpdb"
	private $wpdb_obj = false;

	private $mysql_dbh = false;

	private $use_mysqli = false;

	private $line_last_logged = 0;

	private $our_siteurl;

	private $configuration_bundle;

	/**
	 * User inputted restore options, available to restore extensions.
	 *
	 * @var array
	 */
	public $restore_options;

	private $restore_this_site = array();

	private $restore_this_table = array();

	private $restoring_table = '';

	private $line = 0;

	private $statements_run = 0;

	private $use_wpdb = null;

	private $import_table_prefix = null;

	private $final_import_table_prefix = null;

	private $disable_atomic_on_current_table = false;

	private $table_engine = '';

	private $table_name = '';

	private $new_table_name = '';

	private $original_table_name = '';

	private $tables_created = 0;

	private $view_names = array();

	private $continuation_data;

	private $current_index = 0;

	private $current_type = '';

	private $previous_table_name = '';

	private $include_unspecified_tables = false;

	private $include_unspecified_plugins = false;

	private $include_unspecified_themes = false;

	private $tables_to_restore = array();

	private $validate_restored_theme = false;

	private $plugins_to_restore = array();

	private $themes_to_restore = array();

	private $stored_routine_supported = null;

	private $tables_to_skip = array();

	private $plugins_to_skip = array();

	private $themes_to_skip = array();

	public $search_replace_obj = null;

	// Constants for use with the move_backup_in method
	// These can't be arbitrarily changed; there is legacy code doing bitwise operations and numerical comparisons, and possibly legacy code still using the values directly.
	const MOVEIN_OVERWRITE_NO_BACKUP     = 0;
	const MOVEIN_MAKE_BACKUP_OF_EXISTING = 1;
	const MOVEIN_DO_NOTHING_IF_EXISTING  = 2;
	const MOVEIN_COPY_IN_CONTENTS        = 3;

	private $wp_upgrader;

	public $skin = null;

	public $strings = array();

	private $generated_columns = array();

	private $supported_generated_column_engines = array();

	private $generated_columns_exist_in_the_statement = array();

	private $printed_new_table_prefix = false;

	private $old_siteurl = '';

	private $old_home = '';

	private $old_content = '';

	private $old_uploads = '';

	private $old_table_prefix = null;

	/**
	 * Old site information from the database backup file, available to restore extensions.
	 *
	 * @var array
	 */
	public $old_siteinfo = array();

	private $old_abspath = '';

	/**
	 * BackupPlus plugin slug of the backup site.  It is public because read in multisite addon.
	 *
	 * @var String
	 */
	public $old_backupplus_plugin_slug = '';

	private $pre_restore_updatedir_writable;

	/**
	 * A WP_Filesystem path
	 *
	 * @var String
	 */
	private $abspath;

	/**
	 * Entries indicate forbidden SQL permissions which restore code should thus not attempt to use ('create', 'drop', 'lock', rename', 'triggers')
	 *
	 * @var Array
	 */
	private $db_permissons_forbidden = array();

	private $prior_upload_path;

	private $insert_statements_run;

	private $start_time;

	private $last_error;

	private $last_error_no;

	private $max_allowed_packet;

	private $set_names;

	/**
	 * Constructor
	 *
	 * @param WP_Upgrader_Skin|Null $skin              - an upgrader skin
	 * @param Array|Null            $backup_set        - the backup set to restore
	 * @param Boolean               $short_init        - whether just to do a minimal initialisation
	 * @param Array                 $restore_options   - options to guide the restoration
	 * @param Array|Null            $continuation_data - continuation data; the jobdata of the job thus far (but only a few properties are used - including second_loop_entities; $restore_options will have come from there too if relevant, but that is passed in here separately); the 'last_index_*' entries also indicate unzipping progress
	 */
	public function __construct( $skin = null, $backup_set = null, $short_init = false, $restore_options = array(), $continuation_data = null ) {

		$this->our_siteurl = untrailingslashit( site_url() );

		$this->continuation_data = $continuation_data;

		$this->setup_database_objects();

		$this->search_replace_obj = new BackupPlus_Search_Replace();

		if ( $short_init ) {
			return;
		}

		// If backupplus_incremental_restore_point is equal to -1 then this is either not a incremental restore or we are going to restore up to the latest increment, so there is no need to prune the backup set of any unwanted backup archives.
		if ( isset( $restore_options['backupplus_incremental_restore_point'] ) && $restore_options['backupplus_incremental_restore_point'] > 0 ) {
			$restore_point = $restore_options['backupplus_incremental_restore_point'];
			foreach ( $backup_set['incremental_sets'] as $increment_timestamp => $entities ) {
				if ( $increment_timestamp > $restore_point ) {
					foreach ( $entities as $entity => $backups ) {
						foreach ( $backups as $key => $value ) {
							unset( $backup_set[ $entity ][ $key ] );
						}
					}
				}
			}
		}

		// if backupplus_include_more_path is set then, the user has chosen where they want these backup files to be restored as either UD did not know where to restore them or the original location is not found on disk any more
		if ( isset( $restore_options['backupplus_include_more_path'] ) ) {
			if ( ! isset( $backup_set['morefiles_linked_indexes'] ) || ! isset( $backup_set['morefiles_more_locations'] ) ) {
				$backup_set['morefiles_more_locations'] = $restore_options['backupplus_include_more_path'];
				foreach ( $restore_options['backupplus_include_more_path'] as $key => $path ) {
					$backup_set['morefiles_linked_indexes'][] = $key;
				}
			} else {
				foreach ( $restore_options['backupplus_include_more_path'] as $key => $path ) {
					$backup_set['morefiles_more_locations'][ $key ] = $path;
				}
			}

			if ( isset( $restore_options['backupplus_include_more_index'] ) ) {
				// unset any backups the user has chosen not to restore
				foreach ( array_keys( $backup_set['more'] ) as $key ) {
					if ( ! in_array( $key, $restore_options['backupplus_include_more_index'] ) ) {
						unset( $backup_set['more'][ $key ] );
					}
				}
			}
		}

		if ( isset( $restore_options['include_unspecified_tables'] ) ) {
			$this->include_unspecified_tables = $restore_options['include_unspecified_tables'];
		}
		if ( isset( $restore_options['tables_to_restore'] ) ) {
			$this->tables_to_restore = $restore_options['tables_to_restore'];
		}
		if ( isset( $restore_options['tables_to_skip'] ) ) {
			$this->tables_to_skip = $restore_options['tables_to_skip'];
		}

		if ( isset( $restore_options['include_unspecified_plugins'] ) ) {
			$this->include_unspecified_plugins = $restore_options['include_unspecified_plugins'];
		}
		if ( isset( $restore_options['plugins_to_restore'] ) ) {
			$this->plugins_to_restore = $restore_options['plugins_to_restore'];
		}
		if ( isset( $restore_options['plugins_to_skip'] ) ) {
			$this->plugins_to_skip = (array) $restore_options['plugins_to_skip'];
		}

		if ( isset( $restore_options['include_unspecified_themes'] ) ) {
			$this->include_unspecified_themes = $restore_options['include_unspecified_themes'];
		}
		if ( isset( $restore_options['themes_to_restore'] ) ) {
			$this->themes_to_restore = $restore_options['themes_to_restore'];
		}
		if ( isset( $restore_options['themes_to_skip'] ) ) {
			$this->themes_to_skip = (array) $restore_options['themes_to_skip'];
		}

		// Restore in the most helpful order
		uksort( $backup_set, array( 'BackupPlus_Manipulation_Functions', 'sort_restoration_entities' ) );

		$this->ud_backup_set = $backup_set;

		add_filter( 'backupplus_logline', array( $this, 'backupplus_logline' ), 10, 5 );
		add_action( 'backupplus_restored_db_table', array( $this, 'backupplus_restored_db_table' ), 10, 3 );

		do_action( 'backupplus_restorer_restore_options', $restore_options );
		$this->ud_multisite_selective_restore = ( is_array( $restore_options ) && ! empty( $restore_options['backupplus_restore_ms_whichsites'] ) && $restore_options['backupplus_restore_ms_whichsites'] > 0 ) ? $restore_options['backupplus_restore_ms_whichsites'] : false;
		$this->restore_options                = $restore_options;

		$this->ud_foreign = empty( $backup_set['meta_foreign'] ) ? false : $backup_set['meta_foreign'];
		if ( isset( $backup_set['is_multisite'] ) ) {
			$this->ud_backup_is_multisite = $backup_set['is_multisite'];
		}
		if ( isset( $backup_set['created_by_version'] ) ) {
			$this->created_by_version = $backup_set['created_by_version'];
		}

		$this->backup_strings();

		$this->is_multisite = is_multisite();

		backupplus_try_include_file( 'includes/class-database-utility.php', 'require_once' );

		if ( ! class_exists( 'WP_Upgrader' ) ) {
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		$this->skin        = $skin;
		$this->wp_upgrader = new WP_Upgrader( $skin );
		$this->wp_upgrader->init();
	}
}

require_once __DIR__ . '/includes/restorer/class-wp-filesystem-direct.php';
require_once __DIR__ . '/includes/restorer/class-elementor-plugin.php';
require_once __DIR__ . '/includes/restorer/class-wpdb.php';
