<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}


// Search/replace code adapted in according with the licence from https://github.com/interconnectit/Search-Replace-DB

// phpcs:ignore
class BackupPlus_Restore_Search_Replace {

	private $is_migration;

	private $restored_blogs = false;

	private $restored_sites = false;

	private $wpdb_obj = false;

	protected $restore_options = array();

	private $page_size = 5000;

	private $old_abspath = '';

	/**
	 * Whether to use $wpdb or not
	 *
	 * @var Bool
	 */
	private $use_wpdb;

	/**
	 * Table prefix
	 *
	 * @var String
	 */
	private $base_prefix;

	/**
	 * The database handle object or false boolean
	 *
	 * @var Mixed
	 */
	private $mysql_dbh;

	/**
	 * Whether the object is using mysqli or not
	 *
	 * @var Boolean
	 */
	private $use_mysqli;

	/**
	 * Variable to store migration progress
	 *
	 * @var Array
	 */
	private $report;

	/**
	 * The site url
	 *
	 * @var String
	 */
	protected $siteurl;

	/**
	 * The old site url
	 *
	 * @var String
	 */
	private $old_siteurl;

	/**
	 * The home url
	 *
	 * @var String
	 */
	protected $home;

	/**
	 * The old home url
	 *
	 * @var String
	 */
	private $old_home;

	/**
	 * The wp-content url
	 *
	 * @var String
	 */
	protected $content;

	/**
	 * The old wp-content url
	 *
	 * @var String
	 */
	protected $old_content;

	/**
	 * The uploads url
	 *
	 * @var String
	 */
	protected $uploads;

	/**
	 * The old uploads url
	 *
	 * @var String
	 */
	protected $old_uploads;

	/**
	 * Tables for search and replace operation
	 *
	 * @var Array
	 */
	private $which_tables;

	/**
	 * Tables that have been searched and replaced
	 *
	 * @var Array
	 */
	private $tables_replaced;

	/**
	 * Sites array of multisite
	 *
	 * @var Array
	 */
	private $original_sites;

	/**
	 * Constructor, called during BackupPlus initialization.
	 */
	public function __construct() {
		add_action( 'backupplus_restored_db', array( $this, 'backupplus_restored_db' ), 10, 2 );
		add_action( 'backupplus_restored_db_table', array( $this, 'backupplus_restored_db_table' ), 10, 3 );
		add_action( 'backupplus_restore_db_pre', array( $this, 'backupplus_restore_db_pre' ) );
		add_action( 'backupplus_restore_db_record_old_siteurl', array( $this, 'backupplus_restore_db_record_old_siteurl' ) );
		add_action( 'backupplus_restore_db_record_old_home', array( $this, 'backupplus_restore_db_record_old_home' ) );
		add_action( 'backupplus_restore_db_record_old_content', array( $this, 'backupplus_restore_db_record_old_content' ) );
		add_action( 'backupplus_restore_db_record_old_uploads', array( $this, 'backupplus_restore_db_record_old_uploads' ) );
		add_action( 'backupplus_restore_db_record_old_abspath', array( $this, 'backupplus_restore_db_record_old_abspath' ) );
		add_action( 'backupplus_restored_plugins_one', array( $this, 'restored_plugins_one' ) );
		add_action( 'backupplus_debugtools_dashboard', array( $this, 'debugtools_dashboard' ), 30 );
		add_action( 'backupplus_adminaction_searchreplace', array( $this, 'adminaction_searchreplace' ) );
		add_action( 'backupplus_creating_table', array( $this, 'backupplus_creating_table' ), 10, 1 );
		// Displaying notices after migration if migrated url exists in .htaccess file
		add_action( 'all_admin_notices', array( $this, 'migration_admin_notices' ) );

		add_filter( 'backupplus_dbscan_urlchange', array( $this, 'dbscan_urlchange' ), 10, 3 );
		add_filter( 'backupplus_https_to_http_additional_warning', array( $this, 'https_to_http_additional_warning' ), 10, 1 );
		add_filter( 'backupplus_http_to_https_additional_warning', array( $this, 'http_to_https_additional_warning' ), 10, 1 );
		add_filter( 'backupplus_dbscan_urlchange_www_append_warning', array( $this, 'dbscan_urlchange_www_append_warning' ), 10, 1 );

		add_filter( 'backupplus_restorecachefiles', array( $this, 'restorecachefiles' ), 10, 2 );
		add_filter( 'backupplus_restored_plugins', array( $this, 'restored_plugins' ) );

		// Both MU and normal site
		// Actions/filters that need UD to be fully loaded before we can consider adding them
		add_action( 'plugins_loaded', array( $this, 'lite_plugins_loaded' ) );
	}

	public function lite_plugins_loaded() {
		global $backupplus;

		// Register restore search-and-replace hooks on supported WordPress versions.
		if ( is_a( $backupplus, 'BackupPlus' ) && method_exists( $backupplus, 'get_wordpress_version' ) && version_compare( $backupplus->get_wordpress_version(), '3.5', '>=' ) ) {
			if ( version_compare( PHP_VERSION, '5.3', '>=' ) ) {
				add_filter( 'backupplus_restore_all_downloaded_postscan', array( $this, 'lite_restore_all_downloaded_postscan' ), 20, 7 );
			}
			// Both MU and normal site
			add_action( 'backupplus_restorer_restore_options', array( $this, 'restorer_restore_options' ) );
		}
	}

	public function restorer_restore_options( $restore_options ) {
		$this->restore_options = $restore_options;
	}

	/**
	 * Disable W3TC and WP Super Cache, etc.
	 */
	public function restored_plugins() {
		if ( true !== $this->is_migration ) {
			return;
		}
		global $backupplus;
		$active_plugins = $backupplus->unserialize( $backupplus->option_filter_get( 'active_plugins' ) );
		if ( ! is_array( $active_plugins ) ) {
			return;
		}
		$disable_plugins = array(
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'W3 Super Cache',
			'quick-cache/quick-cache.php'         => 'Quick Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		);
		foreach ( $disable_plugins as $slug => $desc ) {
			// in_array is case sensitive
			// if (in_array($slug, $active_plugins)) {
			if ( preg_grep( '#' . $slug . '#i', $active_plugins ) ) {
				unset( $active_plugins[ $slug ] );

				$backupplus->log( 'Disabled this plugin: %s: re-activate it manually when you are ready.', $desc );
				/* translators: %s: Plugin name or description */
				$backupplus->log( sprintf( __( 'Disabled this plugin: %s: re-activate it manually when you are ready.', 'backupplus' ), $desc ), 'notice-restore' );

			}
		}
		update_option( 'active_plugins', $active_plugins );
	}

	public function restorecachefiles( $val, $file ) {
		// On a migration, we don't want to add cache files if they do not already exist (because usually they won't work until re-installed)
		if ( true !== $this->is_migration || false == $val ) {
			return $val;
		}
		$val = is_file( WP_CONTENT_DIR . '/' . $file ) ? $val : false;
		if ( false == $val ) {
			global $backupplus;
			$backupplus->log_e( '%s: Skipping cache file (does not already exist)', $file );
		}
		return $val;
	}

	public function adminaction_searchreplace( $options = array() ) {

		global $backupplus_restorer;

		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			echo esc_html(
				/* translators: %s: PHP version */
				sprintf( __( "The search and replace feature isn't suitable for PHP %s users.", 'backupplus' ), PHP_VERSION ) . ' ' .
				__( 'To take advantage of this feature, please upgrade your PHP version to at least 5.3.', 'backupplus' )
			) . '<br>';
			echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus' ) . '">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;
		}

		$options = wp_parse_args(
			$options,
			array(
				'show_return_link' => true,
				'show_heading'     => true,
			)
		);

		$keyword_to_search        = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'search', '' );
		$keyword_replacement_text = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'replace', '' );

		if ( ! empty( $options['show_heading'] ) ) {
			echo '<h2>' . esc_html__( 'Search / replace database', 'backupplus' ) . '</h2>';
		}
		echo '<strong>' . esc_html__( 'Search for', 'backupplus' ) . ':</strong> ' . esc_html( stripslashes( $keyword_to_search ) ) . '<br>';
		echo '<strong>' . esc_html__( 'Replace with', 'backupplus' ) . ':</strong> ' . esc_html( stripslashes( $keyword_replacement_text ) ) . '<br>';
		$pagesize           = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'pagesize' );
		$this->page_size    = ( empty( $pagesize ) || ! is_numeric( $pagesize ) ) ? 5000 : (int) $pagesize;
		$post_which_tables  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'whichtables', '' );
		$this->which_tables = empty( $post_which_tables ) ? '' : explode( ',', ( stripslashes( $post_which_tables ) ) );
		if ( empty( $keyword_to_search ) ) {
			echo sprintf(
				/* translators: %s: Missing parameter name */
				esc_html__( 'Failure: No %s was given.', 'backupplus' ),
				esc_html__( 'search term', 'backupplus' )
			) . '<br>';

			if ( ! empty( $options['show_return_link'] ) ) {
				echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			}

			return;
		}

		if ( empty( $backupplus_restorer ) || ! is_a( $backupplus_restorer, 'BackupPlus_Restorer' ) ) {
			// Needed for the BackupPlus_WPDB class and BackupPlus_Restorer::sql_exec() method
			backupplus_try_include_file( 'restorer.php', 'include_once' );
			$backupplus_restorer = new BackupPlus_Restorer( null, null, true );
			add_filter( 'backupplus_logline', array( $backupplus_restorer, 'backupplus_logline' ), 10, 5 );
			$backupplus_restorer->search_replace_obj->backupplus_restore_db_pre();
		}
		$this->backupplus_restore_db_pre();
		$this->tables_replaced = array();
		$this->backupplus_restored_db_dosearchreplace( stripslashes( $keyword_to_search ), stripslashes( $keyword_replacement_text ), $this->base_prefix, false );
		if ( ! empty( $options['show_return_link'] ) ) {
			echo '<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus Configuration', 'backupplus' ) . '</a>';
		}
	}

	/**
	 * This method will check if the newly created table has already been created before, if it has then we should mark it to be search and replaced again.
	 *
	 * @param  String $table - the name of the newly created table
	 */
	public function backupplus_creating_table( $table ) {
		global $backupplus;

		if ( ! empty( $this->tables_replaced[ $table ] ) && $this->tables_replaced[ $table ] ) {
			$this->tables_replaced[ $table ] = false;
			$backupplus->log( 'Warning: This database table has already been created once, now marking it to be search and replaced again - will try to continue but if errors are encountered then check that the backup is correct.', 'notice-restore' );
		}
	}

	public function debugtools_dashboard() {
		global $backupplus_admin;
		?>
		<div class="advanced_tools search_replace">
			<h3><?php esc_html_e( 'Search / replace database', 'backupplus' ); ?></h3>
			<?php if ( version_compare( PHP_VERSION, '5.3', '<' ) ) { ?>
				<p><em>
					<?php
					echo esc_html(
						/* translators: %s: PHP version */
						sprintf( __( 'This feature isn\'t suitable for PHP %s users.', 'backupplus' ), PHP_VERSION ) . ' ' .
						__( 'To take advantage of this feature, please upgrade your PHP version to at least 5.3.', 'backupplus' )
					);
					?>
				</em></p>
			<?php } else { ?>
				<p><em><?php esc_html_e( 'This can easily destroy your site; so, use it with care!', 'backupplus' ); ?></em></p>
				<form id="search_replace_form" method="post" onsubmit="return(confirm('<?php echo esc_js( __( 'A search/replace cannot be undone - are you sure you want to do this?', 'backupplus' ) ); ?>'))">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>">
				<input type="hidden" name="action" value="backupplus_broadcastaction">
				<input type="hidden" name="subaction" value="backupplus_adminaction_searchreplace">
				<table>
				<?php
					$backupplus_admin->settings_debugrow( '<label for="search">' . esc_html__( 'Search for', 'backupplus' ) . '</label>:', '<input id="search" type="text" name="search" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="replace">' . esc_html__( 'Replace with', 'backupplus' ) . '</label>:', '<input id="replace" type="text" name="replace" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="pagesize">' . esc_html__( 'Rows per batch', 'backupplus' ) . '</label>:', '<input id="pagesize" type="number" min="1" step="1" name="pagesize" value="5000" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '<label for="whichtables">' . esc_html__( 'These tables only', 'backupplus' ) . '</label>:', '<input id="whichtables" type="text" name="whichtables" title="' . esc_attr__( 'Enter a comma-separated list; otherwise, leave blank for all tables.', 'backupplus' ) . '" value="" style="width:380px;">' );
					$backupplus_admin->settings_debugrow( '', '<input class="button-primary search_and_replace" type="submit" value="' . esc_attr__( 'Go', 'backupplus' ) . '">' );
				?>
				</table>
			</form>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * WordPress filter backupplus_dbscan_urlchange
	 *
		 * @param String $output          - the unfiltered migration warning
	 * @param String $old_siteurl     - the old site URL
	 * @param Array  $restore_options - restoration options
	 *
	 * @return String - filtered
	 */
	public function dbscan_urlchange( $output, $old_siteurl, $restore_options ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		/* translators: %s: Old site URL */
		$msg = sprintf( __( 'This looks like a migration (the backup is from a site with a different address/URL, %s).', 'backupplus' ), htmlspecialchars( $old_siteurl ) );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			/* translators: %s: PHP version */
			$msg .= ' ' . sprintf( __( 'However, the search and replace feature is not suitable for the PHP version (%s) your server is running on.', 'backupplus' ), PHP_VERSION );
			$msg .= ' ' . __( 'This restoration can search and replace your database if you upgrade your PHP version to at least 5.3.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_https_to_http_additional_warning
	 *
	 * @param String $output - Filter input
	 *
	 * @return String - filtered
	 */
	public function https_to_http_additional_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		$msg = ' ' . __( 'This restoration will work if you still have an SSL certificate (i.e. can use https) to access the site.', 'backupplus' );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$msg .= ' ' . __( 'Otherwise, you will want to upgrade your PHP version to at least 5.3 and take advantage of the search and replace feature.', 'backupplus' );
		} else {
			$msg .= ' ' . __( 'Otherwise, you will want to use below search and replace to search/replace the site address so that the site can be visited without https.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_http_to_https_additional_warning
	 *
	 * @param String $output - Filter input
	 *
	 * @return String - filtered
	 */
	public function http_to_https_additional_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		$msg = ' ' . __( 'As long as your web hosting allows http (i.e. non-SSL access) or will forward requests to https (which is almost always the case), this is no problem.', 'backupplus' );
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$msg .= ' ' . __( 'If that is not yet set up, then you should set it up, or upgrade your PHP version to at least 5.3 in order to access the search and replace feature, allowing for the automatic replacement of non-HTTPS links.', 'backupplus' );
		} else {
			$msg .= ' ' . __( 'If that is not yet set up, then you should set it up, or use below search and replace so that the non-https links are automatically replaced.', 'backupplus' );
		}
		return $msg;
	}

	/**
	 * WordPress filter backupplus_dbscan_urlchange_www_append_warning
	 *
	 * @param String $output - the unfiltered output (free plugin gives empty string)
	 *
	 * @return String - filtered
	 */
	public function dbscan_urlchange_www_append_warning( $output ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			/* translators: %s: PHP version */
			return sprintf( __( "This restoration can't use the search and replace feature as it's not suitable for the PHP version (%s) your server is running on.", 'backupplus' ), PHP_VERSION ) . ' ' .
			__( 'To take advantage of the feature, please upgrade your PHP version to at least 5.3.', 'backupplus' );
		} else {
			return __( 'you will want to use the search and replace option below to update the site address in the database.', 'backupplus' );
		}
	}

	public function restored_plugins_one( $plugin ) {
		global $backupplus;
		$backupplus->log( __( 'Processed plugin:', 'backupplus' ) . ' ' . $plugin, 'notice-restore' );
		$backupplus->log( "Processed plugin: $plugin" );
	}

	/**
	 * WordPress action backupplus_restore_all_downloaded_postscan called during the restore process.
	 *
	 * The last four parameters can be edited in-place.
	 *
	 * @param Array   $backups   - list of backups
	 * @param Integer $timestamp - the timestamp (epoch time) of the backup being restored
	 * @param Array   $elements  - elements being restored (as the keys of the array)
	 * @param Array   $info      - information about the backup being restored
	 * @param Array   $mess      - array of informational-level messages
	 * @param Array   $warn      - array of warning-level messages
	 * @param Array   $err       - array of error-level messages
	 */
	public function lite_restore_all_downloaded_postscan( $backups, $timestamp, $elements, &$info, &$mess, &$warn, &$err ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if ( is_array( $info ) && isset( $info['migration'] ) && true === $info['migration'] ) {
			if ( empty( $info['addui'] ) ) {
				$info['addui'] = '';
			}
			$info['addui'] .= '<div id="backupplus_restorer_dboptions" class="udp-notice before-h2 backupplus-restore-option backupplus-hidden">';
			$info['addui'] .= '<h4>' . __( 'Database restoration options:', 'backupplus' ) . '</h4>';
			$info['addui'] .= '<input name="backupplus_restorer_replacesiteurl" id="backupplus_restorer_replacesiteurl" type="checkbox" value="1" checked><label for="backupplus_restorer_replacesiteurl" title="' .
			/* translators: %s: Current site URL */
			sprintf( __( 'All references to the site location in the database will be replaced with your current site URL, which is: %s', 'backupplus' ), htmlspecialchars( untrailingslashit( site_url() ) ) ) . '"> ' .
			__( 'Search and replace site location in the database', 'backupplus' ) . '</label>';
			$info['addui'] .= '</div>';
		}
	}

	public function backupplus_restore_db_record_old_siteurl( $old_siteurl ) {
		// Only record once
		if ( ! empty( $this->old_siteurl ) ) {
			return;
		}
		$this->old_siteurl = $old_siteurl;
	}

	public function backupplus_restore_db_record_old_home( $old_home ) {
		// Only record once
		if ( ! empty( $this->old_home ) ) {
			return;
		}
		$this->old_home = $old_home;
	}

	public function backupplus_restore_db_record_old_content( $old_content ) {
		// Only record once
		if ( ! empty( $this->old_content ) ) {
			return;
		}
		$this->old_content = $old_content;
	}

	public function backupplus_restore_db_record_old_uploads( $old_uploads ) {
		// Only record once
		if ( ! empty( $this->old_uploads ) ) {
			return;
		}
		$this->old_uploads = $old_uploads;
	}

	/**
	 * This function is called via a filter it saves the passed in old abspath value from restorer.php to a class variable for later use
	 *
	 * @param String $old_abspath - the old abspath
	 *
	 * @return void
	 */
	public function backupplus_restore_db_record_old_abspath( $old_abspath ) {
		if ( '' !== $this->old_abspath ) {
			return;
		}
		$this->old_abspath = $old_abspath;
	}

	public function backupplus_restore_db_pre() {

		global $wpdb, $backupplus, $backupplus_restorer;

		$this->siteurl  = untrailingslashit( site_url() );
		$this->home     = untrailingslashit( home_url() );
		$this->content  = untrailingslashit( content_url() );
		$this->use_wpdb = $backupplus_restorer->use_wpdb();

		$this->base_prefix = $backupplus->get_table_prefix( false );

		$mysql_dbh  = false;
		$use_mysqli = false;

		if ( ! $this->use_wpdb ) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = $backupplus_restorer->get_db_object();
			// Was that successful?
			if ( ! $wpdb_obj->is_mysql || ! $wpdb_obj->ready ) {
				$this->use_wpdb = true;
			} else {
				$this->wpdb_obj = $wpdb_obj;
				$mysql_dbh      = $wpdb_obj->backupplus_get_database_handle();
				$use_mysqli     = $wpdb_obj->backupplus_use_mysqli();
			}
		}

		$this->mysql_dbh  = $mysql_dbh;
		$this->use_mysqli = $use_mysqli;

		if ( true == $this->use_wpdb ) {
			$backupplus->log_e( 'Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)' );
		}

		if ( is_multisite() ) {
			$sites = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i', array( $this->base_prefix . 'site' ), array(), ARRAY_N );
			if ( is_array( $sites ) ) {
				$nsites = array();
				foreach ( $sites as $site ) {
					$nsites[ $site[0] ] = array( $site[1], $site[2] );
				}
				$this->original_sites = $nsites;
			}
		}

		$this->report = array(
			'tables'    => 0,
			'rows'      => 0,
			'change'    => 0,
			'updates'   => 0,
			'timetaken' => 0,
			'errors'    => array(),
		);
	}

	public function backupplus_restored_db_table( $table, $import_table_prefix, $engine = '' ) {

		global $backupplus, $backupplus_restorer;

		// Anything else to do?
		if ( empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) || version_compare( PHP_VERSION, '5.3', '<' ) ) {
			return;
		}

		// Can only do something if the old siteurl is known
		$old_siteurl = isset( $this->old_siteurl ) ? $this->old_siteurl : '';
		$old_home    = isset( $this->old_home ) ? $this->old_home : '';
		$old_content = isset( $this->old_content ) ? $this->old_content : $old_siteurl . '/wp-content';
		// This wasn't stored in the backup header until 1.11.20. It's usually $old_content.'/uploads', but there's no need to force that, as on a default setup, the search/replace is caught by the content replace anyway
		$old_uploads = isset( $this->old_uploads ) ? $this->old_uploads : false;
		if ( ! $old_home && ! $old_siteurl ) {
			return;
		}

		$old_abspath = $this->old_abspath;

		if ( empty( $this->tables_replaced ) ) {
			$this->tables_replaced = array();
		}

		// Already done?
		if ( ! empty( $this->tables_replaced[ $table ] ) ) {
			return;
		}

		// If not done already, then search & replace this table, + record that it is done
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$stripped_table = substr( $table, strlen( $import_table_prefix ) );
		// Remove multisite site number prefix, if relevant
		if ( is_multisite() && preg_match( '/^(\d+)_(.*)$/', $stripped_table, $matches ) ) {
			$stripped_table = $matches[2];
		}

		// Skip tables that do not need URL replacement and are large enough to risk timeouts on hosts that enforce them.
		// The term_relationships table contains 3 columns, all integers. Therefore, we can skip it. It can easily get big, so this is a good time-saver.
		$skip_tables = array( 'slim_stats', 'statpress', 'term_relationships', 'icl_languages_translations', 'icl_string_positions', 'icl_string_translations', 'icl_strings', 'redirection_logs', 'Counterize', 'Counterize_UserAgents', 'Counterize_Referers', 'adrotate_stats', 'login_security_solution_fail', 'wfHits', 'wfhits', 'wbz404_logs', 'wbz404_redirects', 'wfFileMods', 'wffilemods', 'tts_trafficstats', 'tts_referrer_stats', 'dmsguestbook', 'relevanssi', 'wponlinebackup_generations', 'svisitor_stat', 'simple_feed_stats', 'itsec_log', 'rp_tags', 'woocommerce_order_items', 'relevanssi_log', 'blc_instances', 'wysija_email_user_stat', 'woocommerce_sessions', 'et_bloom_stats', 'redirection_404', 'lbakut_activity_log', 'stream_meta', 'wfBlockedIPLog', 'wfblockediplog', 'page_visit_history', 'strack_st', 'eum_logs' );

		if ( in_array( $stripped_table, $skip_tables ) ) {
			$this->tables_replaced[ $table ] = true;
			$backupplus->log_e( 'Skipping this table: data in this table (%s) should not be search/replaced', $table );
			return;
		}

		if ( 'ARCHIVE' == $engine ) {
			$this->tables_replaced[ $table ] = true;
			$backupplus->log_e( 'Skipping this table: this table (%s) should not be search/replaced, as it uses the %s engine', $table, $engine );
			return;
		}

		// Blogs table on multisite doesn't contain the full URL
		if ( is_multisite() && ( $table == $this->base_prefix . 'blogs' || $table == $this->base_prefix . 'site' ) && ( preg_match( '#^https?://([^/]+)#i', $this->home, $matches ) || preg_match( '#^https?://([^/]+)#i', $this->siteurl, $matches ) ) && ( preg_match( '#^https?://([^/]+)#i', $old_home, $omatches ) || preg_match( '#^https?://([^/]+)#i', $old_siteurl, $omatches ) ) ) {
			$from_array = strtolower( $omatches[1] );
			$to_array   = strtolower( $matches[1] );
			$backupplus->log_e( 'Replacing in blogs/site table: from: %s to: %s', htmlspecialchars( $from_array ), htmlspecialchars( $to_array ) );
			$try_site_blog_replace = true;
		} else {

			list($from_array, $to_array) = $this->build_searchreplace_array( $old_siteurl, $old_home, $old_content, $old_uploads, $old_abspath );

			// This block is for multisite installs, to do the search/replace of each site's URL individually. We want to try to do it here for efficiency - i.e. so that we don't have to double-pass tables
			if ( ! empty( $this->restored_blogs ) && preg_match( '/^(\d+)_(.*)$/', substr( $table, strlen( $import_table_prefix ) ), $tmatches ) && ( preg_match( '#^((https?://)([^/]+))#i', $this->home, $matches ) || preg_match( '#^((https?://)([^/]+))#i', $this->siteurl, $matches ) ) && ( preg_match( '#^((https?://)([^/]+))#i', $old_home, $omatches ) || preg_match( '#^((https?://)([^/]+))#i', $old_siteurl, $omatches ) ) ) {
				$old_home_domain = strtolower( $omatches[3] );
				$new_home_domain = strtolower( $matches[3] );
				$blog_id         = $tmatches[1];
				if ( $old_home_domain == $this->restored_blogs[1]['domain'] && isset( $this->restored_blogs[ $blog_id ] ) ) {
					$bdom  = $this->restored_blogs[ $blog_id ]['domain'];
					$bpath = $this->restored_blogs[ $blog_id ]['path'];
					$sblog = $omatches[2] . $bdom . untrailingslashit( $bpath );
					$rblog = $omatches[2] . str_replace( $old_home_domain, $new_home_domain, $bdom ) . untrailingslashit( $bpath );
					if ( ! in_array( $sblog, $from_array ) ) {
						$from_array[] = $sblog;
						$to_array[]   = $rblog;
					}
				}
			}
		}

		// The search/replace parameters are allowed to be either strings or arrays
		$report = $backupplus_restorer->search_replace_obj->icit_srdb_replacer( $from_array, $to_array, array( $table => $stripped_table ), 5000 );

		// If we just replaced either the blogs or site table, then populate our records of what is *now* (i.e. post-restore) in them
		if ( ! empty( $try_site_blog_replace ) ) {
			if ( $table == $this->base_prefix . 'blogs' ) {
				$blogs = BackupPlus_SQL::wpdb()->rows( 'SELECT blog_id, domain, path, site_id FROM %i', array( $table ), array(), ARRAY_N );
				if ( is_array( $blogs ) ) {
					$nblogs = array();
					foreach ( $blogs as $blog ) {
						$nblogs[ $blog[0] ] = array(
							'domain'  => $blog[1],
							'path'    => $blog[2],
							'site_id' => $blog[3],
						);
					}
					$this->restored_blogs = $nblogs;
				}
			} elseif ( $table == $this->base_prefix . 'site' ) {
				$sites = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i ORDER BY id ASC', array( $table ), array(), ARRAY_N );
				if ( is_array( $sites ) ) {
					$nsites = array();
					foreach ( $sites as $site ) {
						$nsites[ $site[0] ] = array( $site[1], $site[2] );
					}
					$this->restored_sites = $nsites;
				}
			}
			if ( ! empty( $this->restored_sites ) && ! empty( $this->restored_blogs ) && ! empty( $this->original_sites ) ) {
				// Adjust paths
				// Domain, path
				$any_site_changes = false;
				foreach ( $this->original_sites as $oid => $osite ) {
					if ( empty( $this->restored_sites[ $oid ] ) ) {
						continue;
					}
					$rsite = $this->restored_sites[ $oid ];
					// Task: 1) Replace the site path with the previous site path 2) Replace all the blog path prefixes from the same blog
					if ( $rsite[1] != $osite[1] ) {
						$any_site_changes                = true;
						$sitepath                        = $osite[1];
						$this->restored_sites[ $oid ][1] = $sitepath;
						foreach ( $this->restored_blogs as $blog_id => $blog ) {
							// From this site?
							if ( $blog['site_id'] != $oid ) {
								continue;
							}
							// Replace the prefix according to the change in prefix for the site
							$this->restored_blogs[ $blog_id ] = array(
								'domain'  => $blog['domain'],
								'path'    => $sitepath . substr( $blog['path'], strlen( $rsite[1] ) ),
								'site_id' => $oid,
							);
						}
					}
				}
				if ( $any_site_changes ) {
					$backupplus->log_e( 'Adjusting multisite paths' );
					foreach ( $this->restored_sites as $site_id => $osite ) {
						BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET path = ? WHERE id = ?', array( $this->base_prefix . 'site' ), array( $osite[1], (int) $site_id ) );
					}
					foreach ( $this->restored_blogs as $blog_id => $blog ) {
						BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET path = ? WHERE blog_id = ?', array( $this->base_prefix . 'blogs' ), array( $blog['path'], (int) $blog_id ) );
					}
				}
			}
		}

		// Output any errors encountered during the db work.
		if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) {
			$backupplus->log( __( 'Error:', 'backupplus' ), 'warning-restore', 'restore-db-error' );
			$processed_errors = array();
			foreach ( $report['errors'] as $error ) {
				if ( in_array( $error, $processed_errors ) ) {
					continue;
				}
				$processed_errors[] = $error;
				$num                = count( array_keys( $report['errors'], $error ) );
				$err_string         = $error;
				if ( $num > 1 ) {
					$err_string .= ' (x' . $num . ')';
				}
				$backupplus->log( $err_string, 'warning-restore' );
			}
		}

		if ( false == $report ) {
			$backupplus->log(
				sprintf(
				/* translators: %s: Operation name */
					__( 'Failed: the %s operation was not able to start.', 'backupplus' ),
					__( 'search and replace', 'backupplus' )
				),
				'warning-restore'
			);
		} elseif ( ! is_array( $report ) ) {
			$backupplus->log(
				sprintf(
				/* translators: %s: Operation name */
					__( 'Failed: we did not understand the result returned by the %s operation.', 'backupplus' ),
					__( 'search and replace', 'backupplus' )
				),
				'warning-restore'
			);
		} else {

			$this->tables_replaced[ $table ] = true;

			// Calc the time taken.
			foreach ( array( 'tables', 'rows', 'change', 'updates' ) as $key ) {
				$this->report[ $key ] += $report[ $key ];
			}
			$this->report['timetaken'] += $report['end'] - $report['start'];
		}
	}

	/**
	 * Displays admin notice if .htaccess have any old migrated site reference.
	 */
	public function migration_admin_notices() {

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}

		$backupplus_migrated_site_domain = get_site_option( 'backupplus_migrated_site_domain', false );
		if ( $backupplus_migrated_site_domain ) {
			$htaccess_file_path                   = ABSPATH . '.htaccess';
			$htaccess_file_reference_line_num_arr = array();
			if ( file_exists( $htaccess_file_path ) && is_file( $htaccess_file_path ) ) {
				$current_site_domain = rtrim( str_ireplace( array( 'http://', 'https://' ), '', get_home_url() ), '/' );
				$htaccess_file_lines = file( $htaccess_file_path );
				if ( false !== $htaccess_file_lines ) {
					foreach ( $htaccess_file_lines as $num => $line ) {
						$migrated_site_domain_pos = stripos( $line, $backupplus_migrated_site_domain );
						if ( false !== $migrated_site_domain_pos && stripos( $line, $current_site_domain ) !== $migrated_site_domain_pos ) {
							$htaccess_file_reference_line_num_arr[] = $num + 1;
						}
					}
				}
			}
			$count_old_site_references = count( $htaccess_file_reference_line_num_arr );
			if ( $count_old_site_references > 0 ) {
				?>
				<div class="notice error backupplus-migration-notice is-dismissible"><p>
						<?php
						printf(
							'<strong>' . esc_html__( 'Warning', 'backupplus' ) . ':</strong> ' . esc_html(
							/* translators: %s: Line number(s) in .htaccess file */
								_n( 'Your .htaccess has an old site reference on line number %s. You should remove it manually.', 'Your .htaccess has an old site references on line numbers %s. You should remove them manually.', $count_old_site_references, 'backupplus' )
							),
							esc_html( implode( ', ', $htaccess_file_reference_line_num_arr ) )
						);
						?>
					</p>
				</div>
				<?php
				add_action( 'admin_footer', array( $this, 'dismiss_notice_for_old_site_references' ) );
			} else {
				delete_site_option( 'backupplus_migrated_site_domain' );
			}
		}
	}

	/**
	 * Builds from supplied parameters and $this->(siteurl,home,content,uploads,abspath)
	 *
	 * @param String         $old_siteurl - the old site url
	 * @param String         $old_home    - the old home url
	 * @param Boolean|String $old_content - the old content url
	 * @param Boolean|String $old_uploads - the old upload url
	 * @param String         $old_abspath - the old abspath
	 *
	 * @return Array - itself containing two arrays, with corresponding 'search' and 'replace' items.
	 */
	private function build_searchreplace_array( $old_siteurl, $old_home, $old_content = false, $old_uploads = false, $old_abspath = '' ) {

		// The uploads parameter, if === false, should be ignored - it is only intended to be used in the special case of single-into-multisite imports (only in that case with $this->uploads get set)
		if ( false === $old_content && false === $old_uploads ) {
			$old_content = $old_siteurl . '/wp-content';
		}
		$from_array = array();
		$to_array   = array();

		if ( ! empty( $old_siteurl ) && $old_siteurl == $old_home ) {
			$from_array[] = $old_home;
			// Used to be site until Sep 2016, but that is wrong. Most likely it was the best possibility before the upload URL was also recorded/known.
			$to_array[] = $this->home;
		} elseif ( ! empty( $old_home ) && strpos( $old_siteurl, $old_home ) === 0 ) {
			// strpos: haystack, needle - i.e. old_home is a (proper, since they were not ==) substring of old_siteurl
			$from_array[] = $old_siteurl;
			$to_array[]   = $this->siteurl;
			$from_array[] = $old_home;
			$to_array[]   = $this->home;
			// If the source home URL is also a proper substring of the destination site URL, then this should be skipped
			if ( $old_home != $this->siteurl && strpos( $this->siteurl, $old_home ) === 0 ) {
				// Not pretty, but the only solution that can cope with content in posts that contains references to both site and home URLs in this case. This extra search URL un-does the adding of an unnecessary duplicate portion to site URLs in the case that is detected here.
				$from_array[] = $this->home . substr( $this->home, strlen( $old_home ) );
				$to_array[]   = $this->home;
			}
		} elseif ( ! empty( $old_siteurl ) && strpos( $old_home, $old_siteurl ) === 0 ) {
			// old_siteurl is a substring of old_home (weird!)
			$from_array[] = $old_home;
			$to_array[]   = $this->home;
			$from_array[] = $old_siteurl;
			$to_array[]   = $this->siteurl;
		} else {
			// neither contains the other
			if ( ! empty( $old_siteurl ) ) {
				$from_array[] = $old_siteurl;
				$to_array[]   = $this->siteurl;
			}
			if ( ! empty( $old_home ) ) {
				$from_array[] = $old_home;
				$to_array[]   = $this->home;
			}
		}
		// We now have a minimal array based on the site_url and home settings
		// The case we need to detect is: (site_url is a prefix of content_url and new_site_url is a prefix of new_content_url and the remains are the same.
		// We do [0] of the existing array, to handle the weird case where old_siteurl is a substring of old_home (i.e. we get the shortest possible match)
		// We will want to do the content URLs first, since they are likely to be longest
		if ( empty( $old_content ) || empty( $this->content ) || ( ! empty( $from_array ) && 0 === strpos( $old_content, $from_array[0] ) && 0 === strpos( $this->content, $to_array[0] ) && substr( $old_content, strlen( $from_array[0] ) ) === substr( $this->content, strlen( $to_array[0] ) ) ) ) {
			// OK - nothing to do - is already covered
		} else {
			// Search/replace needed
			array_unshift( $from_array, $old_content );
			array_unshift( $to_array, $this->content );
		}
		if ( empty( $old_uploads ) || empty( $this->uploads ) || ( ! empty( $from_array ) && 0 === strpos( $old_uploads, $from_array[0] ) && 0 === strpos( $this->uploads, $to_array[0] ) && substr( $old_uploads, strlen( $from_array[0] ) ) === substr( $this->uploads, strlen( $to_array[0] ) ) ) ) {
			// OK - nothing to do - is already covered or no data is present
		} else {
			// Search/replace needed
			array_unshift( $from_array, $old_uploads );
			array_unshift( $to_array, $this->uploads );
		}

		$schemes  = array( 'http', 'https' );
		$prefixes = array( 'www.', '' );
		// Add the opposite http version so that sites with mixed links are caught
		foreach ( $from_array as $key => $value ) {
			if ( preg_match( '#^https?://(?:www\.)?(.+)#i', $value, $matches ) ) {
				foreach ( $schemes as $scheme ) {
					foreach ( $prefixes as $prefix ) {
						if ( ! in_array( $scheme . '://' . $prefix . $matches[1], $from_array ) ) {
							$from_array[] = $scheme . '://' . $prefix . $matches[1];
							$to_array[]   = $to_array[ $key ];
						}
					}
				}
			}
		}

		if ( rtrim( $old_abspath, '/' ) !== '' ) {
			$from_array[] = rtrim( $old_abspath, '/' );
			$to_array[]   = rtrim( ABSPATH, '/' );
		}

		return array( $from_array, $to_array );
	}

	public function backupplus_restored_db( $info, $import_table_prefix ) {

		global $backupplus;

		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$unsupported_search_replace_msg = 'The search and replace operation was disregarded due to the PHP version being unsupported (' . PHP_VERSION . ')';
			$backupplus->log_e( $unsupported_search_replace_msg );
			return;
		}
		$backupplus->log( 'Begin search and replace (backupplus_restored_db)' );
		$backupplus->log( __( 'Database: search and replace site URL', 'backupplus' ), 'database-replace-site-url' );

		if ( empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) ) {
			$backupplus->log_e( 'This option was not selected.' );
			return;
		}

		$replace_this_siteurl = isset( $this->old_siteurl ) ? $this->old_siteurl : '';

		// Don't call site_url() - the result may/will have been cached.
		$options_table      = 'options';
		$options_table_name = $this->base_prefix . $options_table;

		$db_siteurl_thissite = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='siteurl'", array( $options_table_name ) );

		$db_home_thissite = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='home'", array( $options_table_name ) );

		if ( ! $replace_this_siteurl ) {
			$replace_this_siteurl = $db_siteurl_thissite;
		}

		$replace_this_home = isset( $this->old_home ) ? $this->old_home : '';
		if ( ! $replace_this_home ) {
			$replace_this_home = $db_home_thissite;
		}

		$replace_this_content = isset( $this->old_content ) ? $this->old_content : '';
		if ( ! $replace_this_content ) {
			$replace_this_content = $replace_this_siteurl . '/wp-content';
		}

		$replace_this_uploads = isset( $this->old_uploads ) ? $this->old_uploads : false;

		$replace_this_abspath = $this->old_abspath;

		// Sanity checks
		if ( empty( $replace_this_siteurl ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_siteurl', $this->siteurl ), 'warning-restore' );
			return;
		}
		if ( empty( $replace_this_home ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_home', $this->home ), 'warning-restore' );
			return;
		}
		if ( empty( $replace_this_content ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_content_url', $this->content ), 'warning-restore' );
			return;
		}

		if ( empty( $this->siteurl ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_siteurl', $replace_this_siteurl ), 'warning-restore' );
			return;
		}
		if ( empty( $this->home ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_home', $replace_this_home ), 'warning-restore' );
			return;
		}
		// Only complain about the empty content parameter if it's not the case where we use the uploads parameter instead
		if ( empty( $this->content ) && empty( $this->uploads ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_contenturl', $replace_this_content ), 'warning-restore' );
			return;
		}

		if ( $replace_this_siteurl == $this->siteurl && $replace_this_home == $this->home && $replace_this_content == $this->content ) {
			$this->is_migration = false;
			/* translators: %s: Site URL */
			$backupplus->log( sprintf( __( 'Nothing to do: the site URL is already: %s', 'backupplus' ), $this->siteurl ), 'notice-restore' );
			return;
		}

		$this->is_migration = true;

		do_action( 'backupplus_restored_db_is_migration' );

		// Detect situation where the database's siteurl in the header differs from that actual row data in the options table. This can occur if the options table was being over-ridden by a constant. In that case, the search/replace will have failed to set the option table's siteurl; and the result will be that that siteurl is hence wrong, leading to site breakage. The solution is to re-set it.
		// $info['expected_oldsiteurl'] is from the db.gz file header
		if ( isset( $info['expected_oldsiteurl'] ) && $info['expected_oldsiteurl'] != $db_siteurl_thissite && $db_siteurl_thissite != $this->siteurl ) {
			/* translators: 1: Actual database site URL, 2: Expected site URL */
			$backupplus->log_e( sprintf( __( 'Warning: the database\'s site URL (%1$s) is different to what we expected (%2$s)', 'backupplus' ), $db_siteurl_thissite, $info['expected_oldsiteurl'] ) );
			// Here, we change only the site URL entry; we don't run a full search/replace based on it. In theory, if someone developed using two different URLs, then this might be needed.
			if ( ! empty( $this->base_prefix ) && ! empty( $this->siteurl ) ) {
				BackupPlus_SQL::wpdb()->execute( "UPDATE %i SET option_value = ? WHERE option_name='siteurl'", array( $options_table_name ), array( $this->siteurl ) );
			}
		}

		if ( isset( $info['expected_oldhome'] ) && $info['expected_oldhome'] != $db_home_thissite && $db_home_thissite != $this->home ) {
			/* translators: 1: Actual database home URL, 2: Expected home URL */
			$backupplus->log_e( sprintf( __( 'Warning: the database\'s home URL (%1$s) is different to what we expected (%2$s)', 'backupplus' ), $db_home_thissite, $info['expected_oldhome'] ) );
			if ( ! empty( $this->base_prefix ) && ! empty( $this->home ) ) {
				BackupPlus_SQL::wpdb()->execute( "UPDATE %i SET option_value = ? WHERE option_name='home'", array( $options_table_name ), array( $this->home ) );
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		list($from_array, $to_array) = $this->build_searchreplace_array( $replace_this_siteurl, $replace_this_home, $replace_this_content, $replace_this_uploads, $replace_this_abspath );

		foreach ( $from_array as $ind => $from_url ) {
			$backupplus->log_e( 'Database search and replace: replace %s in backup dump with %s', $from_url, $to_array[ $ind ] );
		}

		return $this->backupplus_restored_db_dosearchreplace( $from_array, $to_array, $import_table_prefix );
	}

	private function backupplus_restored_db_dosearchreplace( $from_array, $to_array, $import_table_prefix, $examine_siteurls = true ) {

		global $backupplus, $wpdb, $backupplus_restorer;

		// Now, get an array of tables and then send it off to $backupplus_restorer->search_replace_obj->icit_srdb_replacer()
		// Code modified from searchreplacedb2.php version 2.1.0 from http://www.davidcoveney.com

		// Do we have any tables and if so build the all tables array
		$tables = array();

		// We use $wpdb for non-performance-sensitive operations (e.g. one-time calls)
		$tables_mysql = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		$is_multisite = is_multisite();
		if ( $examine_siteurls && $is_multisite ) {
			$sites  = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i ORDER BY id ASC', array( $import_table_prefix . 'site' ), array(), ARRAY_N );
			$nsites = array();
			foreach ( (array) $sites as $site ) {
				$nsites[ $site[0] ] = array(
					'dom'  => $site[1],
					'path' => $site[2],
				);
			}
			$blogs  = BackupPlus_SQL::wpdb()->rows( 'SELECT blog_id, domain, path, site_id FROM %i ORDER BY blog_id ASC', array( $import_table_prefix . 'blogs' ), array(), ARRAY_N );
			$nblogs = array();
			foreach ( (array) $blogs as $blog ) {
				$nblogs[ $blog[0] ] = array(
					'dom'     => $blog[1],
					'path'    => $blog[2],
					'site_id' => $blog[3],
				);
			}
		}

		if ( ! $tables_mysql ) {
			$backupplus->log( __( 'Error:', 'backupplus' ) . ' ' . __( 'Could not get list of tables', 'backupplus' ), 'warning-restore' );
			$backupplus->log( 'Could not get list of tables' );
			$backupplus_restorer->search_replace_obj->print_error( 'SHOW TABLES' );
			return false;
		} else {
			// Run through the array - each element a numerically-indexed array

			$multisite_processed_sites = array();

			foreach ( $tables_mysql as $table ) {

				// Type equality is necessary, as we don't want to match false
				// "Warning: strpos(): Empty delimiter" means that the second parameter is a zero-length string
				if ( 0 === strpos( $table[0], $import_table_prefix ) ) {
					$tablename = $table[0];

					$stripped_table = substr( $tablename, strlen( $import_table_prefix ) );
					// Remove multisite site number prefix, if relevant
					if ( is_multisite() && preg_match( '/^(\d+)_(.*)$/', $stripped_table, $matches ) ) {
						$stripped_table = $matches[2];
					}

					if ( ! empty( $this->which_tables ) && is_array( $this->which_tables ) ) {
						if ( ! in_array( $tablename, $this->which_tables ) ) {
							$backupplus->log( __( 'Search and replacing table:', 'backupplus' ) . ': ' . __( 'skipped (not in list)', 'backupplus' ), 'notice-restore', 'restore-skipped-' . $tablename );
							continue;
						}
					}

					$still_needs_doing = empty( $this->tables_replaced[ $tablename ] );

					// Looking for site tables on multisite
					if ( $examine_siteurls && $is_multisite && ! empty( $this->restored_blogs ) && preg_match( '/^(\d+)_(.*)$/', substr( $tablename, strlen( $import_table_prefix ) ), $tmatches ) && is_numeric( $tmatches[1] ) && ! empty( $this->restored_blogs[ $tmatches[1] ] ) && ! empty( $nblogs[ $tmatches[1] ] ) && ( preg_match( '#^((https?://)([^/]+))#i', $this->home, $matches ) || preg_match( '#^((https?://)([^/]+))#i', $this->siteurl, $matches ) ) ) {
						// If the database file was not created by UD, then it may be out of order. Specifically, the 'blogs' table might have come *after* the tables for the individual sites. As a result, the tables for those sites may not have been fully searched + replaced... so we need to check that.
						// What are we expecting the site_url to be?
						$blog_id = $tmatches[1];
						if ( empty( $multisite_processed_sites[ $blog_id ] ) ) {
							$multisite_processed_sites[ $blog_id ] = true;
							$site_url_current                      = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='siteurl'", array( $import_table_prefix . $blog_id . '_options' ) );
							if ( is_string( $site_url_current ) ) {
								$bpath = $this->restored_blogs[ $blog_id ]['path'];
								// Jan 2016: This line is old, and removes the main site's path, if present, from the front of this site's path - but why? I suspect it was so that images could be referenced directly without help from .htaccess - perhaps from when media used to be differently organised?
								// $bpathroot = $this->restored_blogs[1]['path'];
								// if (substr($bpath, 0, strlen($bpathroot)) == $bpathroot) $bpath = substr($bpath, strlen($bpathroot)-1);

								$proto = $matches[2];

								$site_url_target = $proto . $nblogs[ $blog_id ]['dom'] . untrailingslashit( $bpath );
								if ( $site_url_target != $site_url_current ) {
									$backupplus->log( "Site url ($site_url_current) for this blog (blog_id=$blog_id) did not match the expected value ($site_url_target) - replacing" );
									$multisite_processed_sites[ $blog_id ] = 1;
									$still_needs_doing                     = true;
									$from_array[]                          = $site_url_current;
									$to_array[]                            = $site_url_target;
								}
							}
						} elseif ( ! $still_needs_doing && 1 === $multisite_processed_sites[ $blog_id ] ) {
							$still_needs_doing = true;
						}
					}

					if ( $still_needs_doing ) {
						$tables[ $tablename ] = $stripped_table;
					} else {
						$backupplus->log( sprintf( __( 'Search and replacing table:', 'backupplus' ) ) . ' ' . $tablename . ': ' . __( 'already done', 'backupplus' ), 'notice-restore', 'restore-table-already-done-' . $tablename );
						$backupplus->log( 'Search and replacing table: ' . $tablename . ': already done' );
					}
				}
			}
		}

		$final_report = $this->report;

		if ( ! empty( $tables ) ) {

			$report = $backupplus_restorer->search_replace_obj->icit_srdb_replacer( $from_array, $to_array, $tables, $this->page_size );

			// Output any errors encountered during the db work.
			if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) {

				$backupplus->log( __( 'Error:', 'backupplus' ), 'warning-restore', 'db-replace-error' );

				$processed_errors = array();
				foreach ( $report['errors'] as $error ) {
					if ( in_array( $error, $processed_errors ) ) {
						continue;
					}
					$processed_errors[] = $error;
					$num                = count( array_keys( $report['errors'], $error ) );
					$error_msg          = $error;
					if ( $num > 1 ) {
						$error_msg .= ' (x' . $num . ')';
					}
					$backupplus->log( $error_msg, 'warning-restore' );
				}
			}

			if ( false == $report ) {
				/* translators: %s: Operation name */
				$backupplus->log( sprintf( __( 'Failed: the %s operation was not able to start.', 'backupplus' ), 'search and replace' ), 'warning-notice' );
			} elseif ( ! is_array( $report ) ) {
				/* translators: %s: Operation name */
				$backupplus->log( sprintf( __( 'Failed: we did not understand the result returned by the %s operation.', 'backupplus' ), 'search and replace' ), 'warning-notice' );
			}

			// Calc the time taken.
			foreach ( array( 'tables', 'rows', 'change', 'updates' ) as $key ) {
				$final_report[ $key ] += $report[ $key ];
			}
			$final_report['timetaken'] += $report['end'] - $report['start'];
			foreach ( $report['errors'] as $error ) {
				$final_report['errors'][] = $error;
			}
		}

		$backupplus->log( __( 'Tables examined:', 'backupplus' ) . ' ' . $final_report['tables'], 'notice-restore', 'restore-tables-examined' );
		$backupplus->log( __( 'Rows examined:', 'backupplus' ) . ' ' . $final_report['rows'], 'notice-restore', 'restore-rows-examined' );
		$backupplus->log( __( 'Changes made:', 'backupplus' ) . ' ' . $final_report['change'], 'notice-restore', 'restore-changes-made' );
		$backupplus->log( __( 'SQL update commands run:', 'backupplus' ) . ' ' . $final_report['updates'], 'notice-restore', 'restore-sql-commands-run' );
		$backupplus->log( __( 'Errors:', 'backupplus' ) . ' ' . count( $final_report['errors'] ), 'notice-restore', 'restore-tables-errors' );
		$backupplus->log( __( 'Time taken (seconds):', 'backupplus' ) . ' ' . round( $final_report['timetaken'], 3 ), 'notice-restore', 'restore-tables-time-taken' );

		// Keep the source site URL so the admin notice can check for stale references in .htaccess after the restore-time replacement.
		if ( ! empty( $this->old_siteurl ) ) {
			update_site_option( 'backupplus_migrated_site_domain', rtrim( str_ireplace( array( 'http://', 'https://' ), '', $this->old_siteurl ), '/' ) );
		}
	}

	/**
	 * Add js for dismiss migration old site references notice
	 *
	 * @return void
	 */
	public function dismiss_notice_for_old_site_references() {
		global $pagenow;
		$page = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'page' );
		if ( BackupPlus_Options::admin_page() != $pagenow || 'backupplus' != $page ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $pagenow is a WP global variable and has been early defined
			$GLOBALS['backupplus_admin']->admin_enqueue_scripts();
			?>
			<script>
			var backupplus_credentialtest_nonce='<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>';
			</script>		
			<?php
		}
		?>
		<script>
		jQuery(function($) {
			$('.backupplus-migration-notice').on('click', '.notice-dismiss', function() {
				backupplus_send_command('dismiss_migration_notice_for_old_site_reference');
			});
		});
		</script>
		<?php
	}
}

