<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Restoring into a WordPress network. Besides restoring a network backup as a whole, a super administrator can:
 *
 * - restore one site of a network backup ("site" mode): only that site's tables and its uploads directory are restored;
 *   what the network's sites share (users, network tables, plugins, themes, WordPress itself) is left alone;
 * - import a single-site backup as one site of the network ("import" mode), either a new site or a replacement for an
 *   existing one: its tables get that site's table prefix, its users are merged into the network's and its uploads go
 *   into the site's uploads directory.
 *
 * The choice is made in the restore options: backupplus_restore_ms_whichsites (a blog ID) for a site, and
 * backupplus_restore_ms_import ('new', with backupplus_restore_ms_import_slug, or a blog ID) for an import.
 */
class BackupPlus_Multisite_Restore {

	/** Tables shared by all the sites of a network, without the table prefix. */
	const NETWORK_TABLES = array( 'users', 'usermeta', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions' );

	/** Columns holding user IDs in the tables that every site has. */
	const USER_COLUMNS = array(
		'posts'    => 'post_author',
		'comments' => 'user_id',
		'links'    => 'link_owner',
	);

	private static $options = array();

	public static function register() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'backupplus_restorer_restore_options', array( __CLASS__, 'record_options' ) );
		add_action( 'backupplus_restore_all_downloaded_postscan', array( __CLASS__, 'postscan' ), 30, 7 );
		add_filter( 'backupplus_restore_this_site', array( __CLASS__, 'restore_this_site' ), 10, 2 );
		add_filter( 'backupplus_restore_this_table', array( __CLASS__, 'restore_this_table' ), 10, 5 );
		add_filter( 'backupplus_restore_final_table_prefix', array( __CLASS__, 'final_table_prefix' ), 10, 2 );
		add_filter( 'backupplus_restore_maintenance_mode', array( __CLASS__, 'maintenance_mode' ), 10, 2 );
		add_filter( 'backupplus_pre_restore_move_in', array( __CLASS__, 'move_in' ), 10, 7 );
		add_filter( 'backupplus_restore_existing_old_folder', array( __CLASS__, 'existing_old_folder' ), 10, 3 );
		add_filter( 'backupplus_restore_search_replace_targets', array( __CLASS__, 'search_replace_targets' ) );
		add_filter( 'backupplus_restore_search_replace_table', array( __CLASS__, 'search_replace_table' ), 10, 3 );
		add_action( 'backupplus_restored_db', array( __CLASS__, 'restored_db' ), 20, 2 );
	}

	public static function record_options( $options ) {
		self::$options = is_array( $options ) ? $options : array();
	}

	/**
	 * What the restore does to the network
	 *
	 * @param Array|Null $options - restore options; by default, those of the restore that is running
	 *
	 * @return String - 'network' (everything in the backup), 'site' (one site of a network backup) or 'import' (a single-site backup)
	 */
	public static function mode( $options = null ) {
		$options = null === $options ? self::$options : $options;
		if ( ! is_multisite() || ! is_array( $options ) ) {
			return 'network';
		}
		if ( ! empty( $options['backupplus_restore_ms_import'] ) ) {
			return 'import';
		}
		return empty( $options['backupplus_restore_ms_whichsites'] ) ? 'network' : 'site';
	}

	/** The blog ID that a site restore or an import writes to; 0 for a restore of the whole network. */
	public static function target( $options = null ) {
		$options = null === $options ? self::$options : $options;
		switch ( self::mode( $options ) ) {
			case 'site':
				return (int) $options['backupplus_restore_ms_whichsites'];
			case 'import':
				return 'new' === $options['backupplus_restore_ms_import'] ? 0 : (int) $options['backupplus_restore_ms_import'];
		}
		return 0;
	}

	/**
	 * Check the restore options before anything is restored, and create the site that a single-site backup is imported into.
	 *
	 * @param Array $entities   - the entities to restore, as keys
	 * @param Array $options    - restore options
	 * @param Array $backup_set - the backup set being restored
	 *
	 * @return Array|WP_Error - the restore options to use, or why the restore cannot go ahead
	 */
	public static function prepare( $entities, $options, $backup_set ) {
		if ( ! is_multisite() ) {
			return $options;
		}
		$mode = self::mode( $options );
		// History rebuilt from the archives alone does not record whether a backup is of a network; the database's header
		// does. When it cannot be read here (it is encrypted), the restorer refuses a mismatch once it reads the header.
		if ( ! isset( $backup_set['is_multisite'] ) && isset( $entities['db'] ) ) {
			$multisite = self::backup_is_multisite( $backup_set );
			if ( null !== $multisite ) {
				$backup_set['is_multisite'] = $multisite;
			}
		}
		$single_site_backup = isset( $backup_set['is_multisite'] ) && ! $backup_set['is_multisite'];

		if ( 'network' === $mode ) {
			if ( $single_site_backup && ( isset( $entities['db'] ) || isset( $entities['uploads'] ) ) ) {
				return new WP_Error( 'multisite_import_target', __( 'This is a backup of a single site. Choose the site of this network to import it into in the database restoration options.', 'backupplus' ) );
			}
			return $options;
		}

		$other = array_diff( array_keys( $entities ), array( 'db', 'uploads' ) );
		if ( $other ) {
			return new WP_Error(
				'multisite_site_entities',
				__( 'Only the database and the uploads can be restored into a single site of a network: plugins, themes, WordPress core and other content are shared by all its sites.', 'backupplus' ) . ' ' .
				/* translators: %s: Comma-separated list of components */
				sprintf( __( 'Deselect these components and start the restore again: %s', 'backupplus' ), implode( ', ', $other ) )
			);
		}

		if ( 'site' === $mode ) {
			if ( $single_site_backup ) {
				return new WP_Error( 'multisite_not_network_backup', __( 'This is a backup of a single site, not of a network.', 'backupplus' ) );
			}
			if ( ! get_site( self::target( $options ) ) ) {
				/* translators: %d: Site (blog) ID */
				return new WP_Error( 'multisite_no_site', sprintf( __( 'Site %d does not exist in this network. A single site can only be restored over an existing site with the same ID.', 'backupplus' ), self::target( $options ) ) );
			}
			return $options;
		}

		if ( isset( $backup_set['is_multisite'] ) && $backup_set['is_multisite'] ) {
			return new WP_Error( 'multisite_import_network', __( 'This is a backup of a network; choose one of its sites to restore instead of importing it.', 'backupplus' ) );
		}
		$new = 'new' === $options['backupplus_restore_ms_import'];
		if ( $new ) {
			$site = self::create_site( isset( $options['backupplus_restore_ms_import_slug'] ) ? $options['backupplus_restore_ms_import_slug'] : '' );
			if ( is_wp_error( $site ) ) {
				return $site;
			}
			$options['backupplus_restore_ms_import'] = $site;
		} else {
			$site = (int) $options['backupplus_restore_ms_import'];
			if ( ! get_site( $site ) || is_main_site( $site ) ) {
				return new WP_Error( 'multisite_import_site', __( 'A single-site backup can be imported into a new site, or over a site of this network other than its main site.', 'backupplus' ) );
			}
		}

		// The addresses of the site, for the search and replace in the imported tables. They are recorded before anything is
		// imported: a resumed restore finds the backup's addresses in the site's options. A new site's are those of its
		// domain and path: its tables may be left over from an earlier site with the same ID. An existing site keeps its
		// own, which may be a mapped domain.
		if ( empty( $options['backupplus_restore_ms_import_urls'] ) ) {
			switch_to_blog( $site );
			$uploads = untrailingslashit( wp_upload_dir( null, false )['baseurl'] );
			$details = get_site( $site );
			$address = wp_parse_url( network_home_url(), PHP_URL_SCHEME ) . '://' . $details->domain . untrailingslashit( $details->path );
			$siteurl = $new ? $address : untrailingslashit( get_option( 'siteurl' ) );
			$home    = $new ? $address : untrailingslashit( get_option( 'home' ) );
			restore_current_blog();
			// On the site itself, its content and uploads are under its own address (WP_CONTENT_URL follows the site URL),
			// unless the network sets a content URL of its own; this request is the main site's
			$network = untrailingslashit( content_url() );
			$content = self::without_scheme( $network ) === self::without_scheme( untrailingslashit( get_option( 'siteurl' ) ) . '/wp-content' ) ? $siteurl . '/wp-content' : $network;
			if ( 0 === strpos( self::without_scheme( $uploads ), self::without_scheme( $network ) . '/' ) ) {
				$uploads = $content . substr( self::without_scheme( $uploads ), strlen( self::without_scheme( $network ) ) );
			}
			$options['backupplus_restore_ms_import_urls'] = array(
				'siteurl' => $siteurl,
				'home'    => $home,
				'content' => $content,
				'uploads' => $uploads,
			);
			$options['backupplus_restore_ms_import_user'] = self::owner();
		}
		$options['backupplus_restorer_replacesiteurl'] = 1;
		return $options;
	}

	private static function without_scheme( $url ) {
		return preg_replace( '#^https?:#i', '', $url );
	}

	/**
	 * Read from a backup's database header whether it is a backup of a network
	 *
	 * @param Array $backup_set - the backup set
	 *
	 * @return Boolean|Null - null when the database is not available here unencrypted
	 */
	private static function backup_is_multisite( $backup_set ) {
		global $backupplus;
		if ( empty( $backup_set['db'] ) || empty( $backup_set['timestamp'] ) ) {
			return null;
		}
		$file = $backupplus->backups_dir_location() . '/' . ( is_array( $backup_set['db'] ) ? reset( $backup_set['db'] ) : $backup_set['db'] );
		if ( ! is_readable( $file ) || BackupPlus_Encryption::is_file_encrypted( $file ) ) {
			return null;
		}
		$info = $backupplus->analyse_db_file( $backup_set['timestamp'], array(), false, true );
		return isset( $info[3]['multisite'] ) ? (bool) $info[3]['multisite'] : null;
	}

	/**
	 * Create the site that a single-site backup is imported into
	 *
	 * @param String $slug - the site's sub-domain or path
	 *
	 * @return Integer|WP_Error - the new site's blog ID
	 */
	private static function create_site( $slug ) {
		// As Network Admin > Sites > Add New Site validates the address
		$slug    = strtolower( trim( (string) $slug ) );
		$network = get_network();
		$error   = __( 'The new site could not be created:', 'backupplus' ) . ' ';
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return new WP_Error( 'multisite_new_site', $error . __( 'Site addresses can only contain lowercase letters (a-z), numbers and hyphens.', 'backupplus' ) );
		}
		if ( ! is_subdomain_install() && in_array( $slug, get_subdirectory_reserved_names(), true ) ) {
			/* translators: %s: Site address */
			return new WP_Error( 'multisite_new_site', $error . sprintf( __( 'The address %s is reserved for use by WordPress functions.', 'backupplus' ), $slug ) );
		}
		$domain = is_subdomain_install() ? $slug . '.' . preg_replace( '|^www\.|', '', $network->domain ) : $network->domain;
		$path   = is_subdomain_install() ? $network->path : $network->path . $slug . '/';
		if ( domain_exists( $domain, $path, $network->id ) ) {
			/* translators: %s: Site address */
			return new WP_Error( 'multisite_new_site', $error . sprintf( __( 'The site %s already exists.', 'backupplus' ), $domain . $path ) );
		}
		$site = wpmu_create_blog( $domain, $path, $slug, self::owner(), array( 'public' => 1 ), $network->id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		global $backupplus;
		/* translators: 1: Blog ID, 2: Site address */
		$backupplus->log( sprintf( __( 'Created site %1$d (%2$s) to import the backup into', 'backupplus' ), $site, $domain . $path ), 'notice-restore' );
		return (int) $site;
	}

	/** The super administrator running the restore, or (from WP-CLI) the network's first one: owns the new site and orphaned content. */
	private static function owner() {
		$user = get_current_user_id();
		if ( $user && is_super_admin( $user ) ) {
			return $user;
		}
		$admins = get_super_admins();
		$admin  = $admins ? get_user_by( 'login', reset( $admins ) ) : false;
		return $admin ? (int) $admin->ID : 0;
	}

	/** Restore one site's tables: those of other sites are left alone. */
	public static function restore_this_site( $restore, $site_id ) {
		return 'network' === self::mode() ? $restore : (int) $site_id === self::target();
	}

	/**
	 * Restore one site's tables: those of other sites, the network's shared tables and tables without the WordPress table
	 * prefix are left alone.
	 *
	 * @param Boolean     $restore    - whether to restore the table
	 * @param String      $unprefixed - the table's name without the backup's table prefix
	 * @param Array       $options    - restore options
	 * @param String|Null $table      - the table's name in the backup
	 * @param String|Null $prefix     - the backup's table prefix
	 *
	 * @return Boolean
	 */
	public static function restore_this_table( $restore, $unprefixed, $options = array(), $table = null, $prefix = null ) {
		$mode = self::mode( $options );
		if ( ! $restore || 'network' === $mode ) {
			return $restore;
		}
		if ( null !== $table && null !== $prefix && 0 !== strpos( $table, $prefix ) ) {
			return false;
		}
		if ( 'import' === $mode ) {
			return true;
		}
		$site = self::target( $options );
		if ( preg_match( '/^(\d+)_/', $unprefixed, $matches ) ) {
			return (int) $matches[1] === $site && ! is_main_site( $site );
		}
		return is_main_site( $site ) && ! in_array( $unprefixed, self::NETWORK_TABLES, true );
	}

	/** An imported backup's tables become the tables of the site it is imported into. */
	public static function final_table_prefix( $prefix, $options = array() ) {
		if ( 'import' !== self::mode( $options ) || ! self::target( $options ) ) {
			return $prefix;
		}
		global $wpdb;
		return $wpdb->get_blog_prefix( self::target( $options ) );
	}

	/**
	 * Only the site being restored goes into maintenance; see BackupPlus::backupplus_single_site_maintenance_init()
	 *
	 * @param Boolean $network - whether to put the whole network into maintenance
	 * @param Boolean $active  - whether to enter, or to leave, maintenance
	 *
	 * @return Boolean
	 */
	public static function maintenance_mode( $network, $active ) {
		if ( 'network' === self::mode() || ! self::target() ) {
			return $network;
		}
		switch_to_blog( self::target() );
		$uploads = wp_upload_dir( null, false );
		restore_current_blog();
		$file = trailingslashit( $uploads['basedir'] ) . '.maintenance';
		if ( $active ) {
			wp_mkdir_p( $uploads['basedir'] );
			file_put_contents( $file, (string) time() );
		} elseif ( file_exists( $file ) ) {
			unlink( $file );
		}
		return false;
	}

	/**
	 * Restore the uploads of the one site being restored or imported into: its directory of the network's uploads, which for
	 * the main site is the uploads directory itself, without the other sites' directory (sites/).
	 *
	 * @param Boolean|String      $done              - from earlier filters
	 * @param String              $type              - the entity
	 * @param String              $working_dir       - the unpacked archive (WP_Filesystem path)
	 * @param Array               $info              - the entity's information
	 * @param Array               $backup_set        - the backup set
	 * @param BackupPlus_Restorer $restorer          - the restorer
	 * @param String              $wp_filesystem_dir - the network's uploads directory (WP_Filesystem path)
	 *
	 * @return Boolean|String|WP_Error - true, when the uploads were restored here
	 */
	public static function move_in( $done, $type, $working_dir, $info, $backup_set, $restorer, $wp_filesystem_dir ) {
		if ( 'uploads' !== $type || false !== $done || 'network' === self::mode() || ! self::target() ) {
			return $done;
		}
		global $wp_filesystem, $backupplus;
		$site    = self::target();
		$uploads = untrailingslashit( $wp_filesystem_dir );
		$source  = $restorer->get_first_directory( $working_dir, array( basename( isset( $info['path'] ) ? $info['path'] : 'uploads' ), 'uploads' ) );

		if ( 'site' === self::mode() && ! is_main_site( $site ) ) {
			$from = false === $source ? false : $source . '/sites/' . $site;
			$to   = $uploads . '/sites/' . $site;
		} else {
			$from = $source;
			$to   = 'import' === self::mode() ? $uploads . '/sites/' . $site : $uploads;
		}
		$old = self::old_uploads( $uploads );
		// The network's other sites' uploads are never replaced or moved
		$keep = array( 'sites', '.maintenance' );

		// On the first archive of the set, the site's current uploads are moved aside (into uploads-old), as for a whole restore
		if ( ! $backupplus->jobdata_get( 'multisite_uploads_moved' ) ) {
			$replaced = self::old_entries( $old );
			if ( $replaced ) {
				$backupplus->log_e( '%s: This directory already exists, and will be replaced', $old );
			}
			foreach ( $replaced as $entry ) {
				$wp_filesystem->delete( $entry, true );
			}
			if ( $wp_filesystem->is_dir( $to ) ) {
				$made = self::mkdir( $old );
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				$moved = $restorer->move_backup_in( $to, trailingslashit( $old ), BackupPlus_Restorer::MOVEIN_COPY_IN_CONTENTS, $keep, 'uploads', false, false, true );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
			}
			$backupplus->jobdata_set( 'multisite_uploads_moved', true );
		}

		if ( false !== $from && $wp_filesystem->is_dir( $from ) ) {
			$made = self::mkdir( $to );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$moved = $restorer->move_backup_in( $from, trailingslashit( $to ), BackupPlus_Restorer::MOVEIN_COPY_IN_CONTENTS, $keep, 'uploads' );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
			if ( ! $moved ) {
				return new WP_Error( 'new_move_failed', __( 'Could not move the new files into place. Check your wp-content/upgrade folder.', 'backupplus' ) );
			}
		} else {
			$backupplus->log( 'uploads: this archive has no uploads for the site being restored' );
		}

		// Leave the unpacked archive empty: the rest of it belongs to other sites. An incremental chain's manifest describes
		// the whole uploads directory, so it is not used to delete files here.
		foreach ( array_keys( (array) $wp_filesystem->dirlist( $working_dir, true, false ) ) as $entry ) {
			$wp_filesystem->delete( trailingslashit( $working_dir ) . $entry, true );
		}
		return true;
	}

	/**
	 * The previous uploads that a restore from the dashboard would replace, which must be deleted first ("Delete old folders"):
	 * those of the site being restored, not those kept by restores of the network or of its other sites.
	 *
	 * @param String|Boolean $existing          - the network's old uploads folder, if it exists
	 * @param String         $type              - the entity
	 * @param String         $wp_filesystem_dir - the network's uploads directory (WP_Filesystem path)
	 *
	 * @return String|Boolean - the folder, or false
	 */
	public static function existing_old_folder( $existing, $type, $wp_filesystem_dir ) {
		if ( 'uploads' !== $type || 'network' === self::mode() ) {
			return $existing;
		}
		// A new site has no previous uploads
		if ( ! self::target() ) {
			return false;
		}
		$old = self::old_uploads( untrailingslashit( $wp_filesystem_dir ) );
		return self::old_entries( $old ) ? $old : false;
	}

	/** Where a restore into one site keeps that site's previous uploads: the main site's are in uploads-old, beside the other sites' in uploads-old/sites. */
	private static function old_uploads( $uploads ) {
		$site = self::target();
		return 'import' === self::mode() || ! is_main_site( $site ) ? $uploads . '-old/sites/' . $site : $uploads . '-old';
	}

	/** The site's previous uploads in its old folder (WP_Filesystem paths), without the other sites' when it is the main site's. */
	private static function old_entries( $old ) {
		global $wp_filesystem;
		$list    = $wp_filesystem->exists( $old ) ? $wp_filesystem->dirlist( $old, true, false ) : false;
		$entries = is_array( $list ) ? array_keys( $list ) : array();
		if ( 'site' === self::mode() && is_main_site( self::target() ) ) {
			$entries = array_diff( $entries, array( 'sites' ) );
		}
		return array_map(
			function ( $entry ) use ( $old ) {
				return $old . '/' . $entry;
			},
			array_values( $entries )
		);
	}

	/** Create a directory and its missing parents (WP_Filesystem path). */
	private static function mkdir( $directory ) {
		global $wp_filesystem;
		$missing = array();
		for ( $path = untrailingslashit( $directory ); ! $wp_filesystem->is_dir( $path ); $path = dirname( $path ) ) {
			if ( dirname( $path ) === $path ) {
				break;
			}
			$missing[] = $path;
		}
		foreach ( array_reverse( $missing ) as $path ) {
			if ( ! $wp_filesystem->mkdir( $path, FS_CHMOD_DIR ) ) {
				return new WP_Error( 'mkdir_failed', __( 'Failed to create directory', 'backupplus' ) . ' (' . $path . ')' );
			}
		}
		return true;
	}

	/** An imported site's addresses replace the backup's, rather than the network's. */
	public static function search_replace_targets( $targets ) {
		if ( 'import' !== self::mode() || empty( self::$options['backupplus_restore_ms_import_urls'] ) ) {
			return $targets;
		}
		return array_merge( $targets, self::$options['backupplus_restore_ms_import_urls'] );
	}

	/** When one site of a network is restored, the search and replace leaves the other sites' tables alone. */
	public static function search_replace_table( $replace, $table, $prefix ) {
		if ( 'site' !== self::mode() || 0 !== strpos( $table, $prefix ) ) {
			return $replace;
		}
		return $replace && self::restore_this_table( true, substr( $table, strlen( $prefix ) ), self::$options );
	}

	/**
	 * After an import's tables are restored: merge its users into the network's, and remove what belonged to the
	 * single site's own installation.
	 *
	 * @param Array  $info   - information about the restored database
	 * @param String $prefix - the table prefix that was restored into
	 */
	public static function restored_db( $info, $prefix ) {
		if ( 'import' !== self::mode() || ! self::target() ) {
			return;
		}
		global $wpdb, $backupplus;
		$site = self::target();
		if ( $wpdb->get_blog_prefix( $site ) !== $prefix ) {
			return;
		}
		$sql = BackupPlus_SQL::wpdb();

		self::merge_users( $prefix, $site );

		// The imported site uses the network's BackupPlus configuration and the network's uploads directory for sites
		$options = $prefix . 'options';
		$sql->execute( 'DELETE FROM %i WHERE option_name LIKE ? OR option_name LIKE ?', array( $options ), array( $wpdb->esc_like( 'backupplus_' ) . '%', $wpdb->esc_like( 'updraft_' ) . '%' ) );
		$sql->execute( "DELETE FROM %i WHERE option_name IN ('upload_path', 'upload_url_path')", array( $options ) );
		// Whatever the search and replace found, the site's address is the network's
		$urls = self::$options['backupplus_restore_ms_import_urls'];
		$sql->execute( "UPDATE %i SET option_value = ? WHERE option_name = 'siteurl'", array( $options ), array( $urls['siteurl'] ) );
		$sql->execute( "UPDATE %i SET option_value = ? WHERE option_name = 'home'", array( $options ), array( $urls['home'] ) );

		clean_blog_cache( $site );
		wp_cache_flush();
		/* translators: %d: Blog ID */
		$backupplus->log( sprintf( __( 'The backup was imported into site %d.', 'backupplus' ), $site ), 'notice-restore' );
	}

	/**
	 * Merge the users of an imported backup, restored into the site's own users and usermeta tables, into the network's.
	 * A user whose login or email address already exists in the network is that user: the account is not changed, but gets
	 * the role it had on the imported site. The others are added with their passwords. User IDs in the site's tables are
	 * then changed to the network's IDs.
	 *
	 * @param String  $prefix - the site's table prefix
	 * @param Integer $site   - the site's blog ID
	 */
	private static function merge_users( $prefix, $site ) {
		global $wpdb, $backupplus;
		$sql        = BackupPlus_SQL::wpdb();
		$users      = $prefix . 'users';
		$usermeta   = $prefix . 'usermeta';
		$site_keys  = $wpdb->esc_like( $prefix ) . '%';
		$other_keys = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$map        = array();
		$added      = 0;

		$rows = self::table_exists( $users ) ? $sql->rows( 'SELECT ID, user_login, user_email FROM %i ORDER BY ID', array( $users ), array(), ARRAY_A ) : array();
		foreach ( (array) $rows as $row ) {
			$existing = get_user_by( 'login', $row['user_login'] );
			if ( ! $existing && '' !== $row['user_email'] ) {
				$existing = get_user_by( 'email', $row['user_email'] );
			}
			if ( $existing ) {
				$user = (int) $existing->ID;
				// Only the user's settings for this site, such as its role, come from the backup
				$sql->execute( 'DELETE FROM %i WHERE user_id = ? AND meta_key LIKE ?', array( $wpdb->usermeta ), array( $user, $site_keys ) );
				$sql->execute( 'INSERT INTO %i (user_id, meta_key, meta_value) SELECT ?, meta_key, meta_value FROM %i WHERE user_id = ? AND meta_key LIKE ?', array( $wpdb->usermeta, $usermeta ), array( $user, (int) $row['ID'], $site_keys ) );
			} else {
				if ( false === $sql->execute( 'INSERT INTO %i (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) SELECT user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name FROM %i WHERE ID = ?', array( $wpdb->users, $users ), array( (int) $row['ID'] ) ) ) {
					/* translators: 1: User login, 2: Database error */
					$backupplus->log( sprintf( __( 'The user %1$s could not be added: %2$s', 'backupplus' ), $row['user_login'], $sql->last_error() ), 'warning-restore' );
					continue;
				}
				$user = $sql->insert_id();
				// Never another site's or the network's settings, such as a role on another site, nor the old logins
				$sql->execute( 'INSERT INTO %i (user_id, meta_key, meta_value) SELECT ?, meta_key, meta_value FROM %i WHERE user_id = ? AND meta_key <> ? AND (meta_key LIKE ? OR meta_key NOT LIKE ?)', array( $wpdb->usermeta, $usermeta ), array( $user, (int) $row['ID'], 'session_tokens', $site_keys, $other_keys ) );
				update_user_meta( $user, 'primary_blog', $site );
				++$added;
			}
			$map[ (int) $row['ID'] ] = $user;
			clean_user_cache( $user );
		}

		$fallback = isset( self::$options['backupplus_restore_ms_import_user'] ) ? (int) self::$options['backupplus_restore_ms_import_user'] : 0;
		$offset   = 1 + max( (int) $sql->value( 'SELECT MAX(ID) FROM %i', array( $wpdb->users ) ), $map ? max( array_keys( $map ) ) : 0 );
		foreach ( self::USER_COLUMNS as $table => $column ) {
			$table = $prefix . $table;
			if ( self::table_exists( $table ) ) {
				$offset = max( $offset, 1 + (int) $sql->value( 'SELECT MAX(%i) FROM %i', array( $column, $table ) ) );
			}
		}
		foreach ( self::USER_COLUMNS as $table => $column ) {
			$table = $prefix . $table;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			// Move the backup's IDs out of the way first, so that no ID is changed twice
			$sql->execute( 'UPDATE %i SET %i = %i + ? WHERE %i > 0', array( $table, $column, $column, $column ), array( $offset ) );
			foreach ( $map as $old => $new ) {
				$sql->execute( 'UPDATE %i SET %i = ? WHERE %i = ?', array( $table, $column, $column ), array( $new, $old + $offset ) );
			}
			// Content of users who are not in the backup
			$sql->execute( 'UPDATE %i SET %i = ? WHERE %i >= ?', array( $table, $column, $column ), array( $fallback, $offset ) );
		}

		$sql->execute( 'DROP TABLE IF EXISTS %i', array( $users ) );
		$sql->execute( 'DROP TABLE IF EXISTS %i', array( $usermeta ) );
		/* translators: 1: Number of users, 2: Number of new users */
		$backupplus->log( sprintf( __( 'Users of the imported site: %1$d, of whom %2$d were added to the network; the others already existed.', 'backupplus' ), count( $map ), $added ), 'notice-restore' );
		if ( $map ) {
			$backupplus->log( 'Imported user IDs (backup => network): ' . wp_json_encode( $map ) );
		}
	}

	private static function table_exists( $table ) {
		return $table === BackupPlus_SQL::wpdb()->value( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array(), array( $table ) );
	}

	/**
	 * The choices for restoring into a network, added to the database restoration options once the backup has been checked
	 *
	 * @param Array   $backups   - backup history
	 * @param Integer $timestamp - the backup being restored
	 * @param Array   $elements  - the entities being restored, as keys
	 * @param Array   $info      - information about the backup (in-out)
	 * @param Array   $mess      - messages (in-out)
	 * @param Array   $warn      - warnings (in-out)
	 * @param Array   $err       - errors (in-out)
	 */
	public static function postscan( $backups, $timestamp, $elements, &$info, &$mess, &$warn, &$err ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Action parameters.
		$sites_in_backup = isset( $info['multisite_sites'] ) && is_array( $info['multisite_sites'] ) ? $info['multisite_sites'] : array();
		unset( $info['multisite_sites'] );
		if ( ! isset( $info['multisite'] ) ) {
			return;
		}
		$html = '';
		if ( $info['multisite'] && isset( $elements['db'] ) ) {
			$network = BackupPlus_Multisite::sites();
			$html   .= '<p><label for="backupplus_restore_ms_whichsites"><strong>' . esc_html__( 'Restore:', 'backupplus' ) . '</strong></label> <select name="backupplus_restore_ms_whichsites" id="backupplus_restore_ms_whichsites">';
			$html   .= '<option value="0" selected="selected">' . esc_html__( 'The entire network (all its sites)', 'backupplus' ) . '</option>';
			foreach ( $sites_in_backup ? $sites_in_backup : array_map( '__return_empty_string', $network ) as $id => $url ) {
				$label = '' === $url && isset( $network[ $id ] ) ? $network[ $id ]['url'] : $url;
				/* translators: 1: Blog ID, 2: Site address */
				$label = sprintf( __( 'Only site %1$d: %2$s', 'backupplus' ), $id, $label );
				if ( ! isset( $network[ $id ] ) ) {
					$label .= ' ' . __( '(not in this network)', 'backupplus' );
				}
				$html .= '<option value="' . (int) $id . '"' . ( isset( $network[ $id ] ) ? '' : ' disabled="disabled"' ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></p><p><em>' . esc_html__( 'A single site is restored from its database tables and, if selected, its uploads. The users, the network settings, the plugins, the themes and WordPress itself are shared by the whole network and are left as they are; only the database and uploads components can be restored.', 'backupplus' ) . '</em></p>';
		} elseif ( ! $info['multisite'] ) {
			$other = array_diff( array_keys( $elements ), array( 'db', 'uploads' ) );
			if ( ! isset( $elements['db'] ) ) {
				$err[] = esc_html__( 'This is a backup of a single site. To import it into this network, include its database.', 'backupplus' );
				return;
			}
			if ( $other ) {
				/* translators: %s: Comma-separated list of components */
				$err[] = esc_html( sprintf( __( 'This is a backup of a single site, which can be imported into this network from its database and uploads. Plugins, themes and other content are shared by all the network\'s sites; deselect them: %s', 'backupplus' ), implode( ', ', $other ) ) );
				return;
			}
			$network = get_network();
			$html   .= '<p><label for="backupplus_restore_ms_import"><strong>' . esc_html__( 'Import this single-site backup into:', 'backupplus' ) . '</strong></label> <select name="backupplus_restore_ms_import" id="backupplus_restore_ms_import">';
			$html   .= '<option value="new" selected="selected">' . esc_html__( 'A new site of this network', 'backupplus' ) . '</option>';
			foreach ( BackupPlus_Multisite::sites() as $id => $site ) {
				if ( ! is_main_site( $id ) ) {
					/* translators: 1: Blog ID, 2: Site address */
					$html .= '<option value="' . (int) $id . '">' . esc_html( sprintf( __( 'Replace site %1$d: %2$s', 'backupplus' ), $id, $site['url'] ) ) . '</option>';
				}
			}
			$html .= '</select></p><p><label for="backupplus_restore_ms_import_slug">' . esc_html__( 'Address of the new site:', 'backupplus' ) . '</label> ';
			if ( is_subdomain_install() ) {
				$html .= '<input type="text" name="backupplus_restore_ms_import_slug" id="backupplus_restore_ms_import_slug" pattern="[a-z0-9-]+" autocomplete="off">.' . esc_html( preg_replace( '|^www\.|', '', $network->domain ) );
			} else {
				$html .= esc_html( $network->domain . $network->path ) . '<input type="text" name="backupplus_restore_ms_import_slug" id="backupplus_restore_ms_import_slug" pattern="[a-z0-9-]+" autocomplete="off">';
			}
			$html  .= '</p><p><em>' . esc_html__( 'The site\'s tables and uploads become those of the chosen site, and its addresses are replaced with the site\'s. Its users are added to the network; a user whose login or email address already exists in the network is merged with that account, which keeps its password. Plugins and themes are not imported: the site uses those of the network.', 'backupplus' ) . '</em></p>';
			$warn[] = esc_html__( 'You are importing a single-site backup into this network. Choose the site to import it into in the database restoration options below.', 'backupplus' );
		}
		if ( '' !== $html ) {
			$html          = '<div class="udp-notice below-h2 backupplus-restore-option" id="backupplus_restore_multisite">' . $html . '</div>';
			$info['addui'] = empty( $info['addui'] ) ? $html : $html . $info['addui'];
		}
	}
}
