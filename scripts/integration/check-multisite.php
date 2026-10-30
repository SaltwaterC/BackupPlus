<?php
/**
 * WordPress network support, on the sub-directory network of dev/test-sites.sh (backupplus-test-ms):
 *
 * - only super administrators manage BackupPlus, and the network's other sites have no BackupPlus state or commands;
 * - a network backup restores as a whole, or one site at a time without touching the others or the shared tables;
 * - a single-site backup, taken on the leased single test site, imports into a new site and over an existing one, with its
 *   users merged into the network's, its addresses replaced and its uploads in the site's directory.
 */
require __DIR__ . '/lib/support.php';

$runtime = getenv( 'PODMAN' ) ? getenv( 'PODMAN' ) : 'podman';
$network = getenv( 'BACKUPPLUS_TEST_MS_CONTAINER' ) ? getenv( 'BACKUPPLUS_TEST_MS_CONTAINER' ) : 'backupplus-test-ms-wordpress';
$single  = getenv( 'WP_TEST_CONTAINER' ) ? getenv( 'WP_TEST_CONTAINER' ) : 'backupplus-dev-wordpress';
$tag     = bin2hex( random_bytes( 3 ) );
$cleanup = array();

/** Run WP-CLI in a container; by default it must succeed, and its output is returned. */
function ms_wp( $container, $arguments, $must_succeed = true ) {
	global $runtime;
	$result = integration_run( array_merge( array( $runtime, 'exec', $container, 'wp', '--allow-root' ), $arguments ), '', null, 900 );
	if ( $must_succeed ) {
		integration_expect( 0 === $result['status'], 'wp ' . implode( ' ', $arguments ) . " failed:\n" . $result['stderr'] . substr( $result['stdout'], -2000 ) );
		return trim( $result['stdout'] );
	}
	return $result;
}

/** A WP-CLI command that must fail with a message containing $expected. */
function ms_refused( $container, $arguments, $expected ) {
	$result = ms_wp( $container, $arguments, false );
	integration_expect( 0 !== $result['status'] && false !== strpos( $result['stderr'] . $result['stdout'], $expected ), 'wp ' . implode( ' ', $arguments ) . ' must fail with "' . $expected . '"; got: ' . $result['stderr'] . substr( $result['stdout'], -1000 ) );
}

function ms_eval( $container, $code, $url = null ) {
	return ms_wp( $container, array_merge( $url ? array( '--url=' . $url ) : array(), array( 'eval', $code ) ) );
}

function ms_sh( $container, $script ) {
	global $runtime;
	$result = integration_run( array( $runtime, 'exec', $container, 'sh', '-c', $script ) );
	integration_expect( 0 === $result['status'], 'Shell command failed in ' . $container . ': ' . $script . "\n" . $result['stderr'] );
	return trim( $result['stdout'] );
}

function ms_file( $container, $path ) {
	return ms_sh( $container, 'cat ' . escapeshellarg( $path ) . ' 2>/dev/null || true' );
}

function ms_backups( $container ) {
	return ms_eval( $container, 'echo $GLOBALS["backupplus"]->backups_dir_location();' );
}

function ms_post_title( $container, $url, $id ) {
	return ms_wp( $container, array( '--url=' . $url, 'post', 'get', (string) $id, '--field=post_title' ) );
}

/** A site's address, as WordPress lists it (with a trailing slash). */
function ms_site_url( $container, $site ) {
	return ms_wp( $container, array( 'site', 'list', '--site__in=' . $site, '--field=url' ) );
}

/** Fetch a page of the network from the host, whatever host name the site has (a sub-domain or a mapped domain). */
function ms_http( $url, $redirects = 5 ) {
	$parts   = parse_url( $url );
	$headers = array();
	$page    = @file_get_contents(
		'http://127.0.0.1:' . $parts['port'] . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
		false,
		stream_context_create(
			array(
				'http' => array(
					'header'          => 'Host: ' . $parts['host'] . ':' . $parts['port'],
					'follow_location' => 0,
					'ignore_errors'   => true,
				),
			)
		)
	);
	foreach ( function_exists( 'http_get_last_response_headers' ) ? (array) http_get_last_response_headers() : array() as $header ) {
		if ( $redirects > 0 && preg_match( '/^Location:\s*(\S+)/i', $header, $match ) ) {
			return ms_http( $match[1], $redirects - 1 );
		}
	}
	return false === $page ? '' : $page;
}

function ms_check( $condition, $message ) {
	integration_expect( $condition, $message );
	echo 'ok - ' . $message . "\n";
}

try {
	integration_expect( 0 === ms_wp( $network, array( 'core', 'is-installed', '--network' ), false )['status'], $network . ' must be a WordPress network: run dev/test-sites.sh' );
	$home      = ms_wp( $network, array( 'option', 'get', 'home' ) );
	$subdomain = '1' === ms_eval( $network, 'echo (int) is_subdomain_install();' );
	echo '# ' . ( $subdomain ? 'Sub-domain' : 'Sub-directory' ) . ' network at ' . $home . "\n";
	$content = ms_eval( $network, 'echo WP_CONTENT_DIR;' );
	$uploads = $content . '/uploads';

	// The network's own site for this run, and a site administrator who is not a super administrator
	$slug       = 'ms-site-' . $tag;
	$site       = (int) ms_wp( $network, array( 'site', 'create', '--slug=' . $slug, '--title=Multisite check', '--porcelain' ) );
	$cleanup[]  = array( 'site', $site );
	$site_url   = ms_site_url( $network, $site );
	$site_admin = 'mssiteadmin' . $tag;
	$cleanup[]  = array( 'user', $site_admin );
	ms_wp( $network, array( 'user', 'create', $site_admin, $site_admin . '@example.test', '--role=administrator' ) );

	// A site with a domain of its own, as Network Admin > Sites > Edit Site maps one
	$mapped     = (int) ms_wp( $network, array( 'site', 'create', '--slug=ms-mapped-' . $tag, '--title=Mapped check', '--porcelain' ) );
	$cleanup[]  = array( 'site', $mapped );
	$mapped_url = 'http://mapped' . $tag . '.example.test:' . parse_url( $home, PHP_URL_PORT ) . '/';
	ms_eval( $network, 'update_blog_details( ' . $mapped . ', array( "domain" => ' . var_export( parse_url( $mapped_url, PHP_URL_HOST ) . ':' . parse_url( $mapped_url, PHP_URL_PORT ), true ) . ', "path" => "/" ) ); switch_to_blog( ' . $mapped . ' ); update_option( "siteurl", ' . var_export( rtrim( $mapped_url, '/' ), true ) . ' ); update_option( "home", ' . var_export( rtrim( $mapped_url, '/' ), true ) . ' ); restore_current_blog();' );
	integration_expect( $mapped_url === ms_site_url( $network, $mapped ), 'The site has its mapped domain' );
	$mapped_post = (int) ms_wp( $network, array( '--url=' . $mapped_url, 'post', 'create', '--post_title=Mapped original ' . $tag, '--post_status=publish', '--porcelain' ) );

	// Network management: super administrators only, from the main site
	ms_check( '1|manage_network_options' === ms_eval( $network, 'wp_set_current_user( 1 ); echo (int) BackupPlus_Options::user_can_manage() . "|" . BackupPlus_Options::capability();' ), 'A super administrator manages BackupPlus with the manage_network_options capability' );
	ms_check( '0' === ms_eval( $network, 'wp_set_current_user( get_user_by( "login", ' . var_export( $site_admin, true ) . ' )->ID ); echo (int) BackupPlus_Options::user_can_manage();' ), 'An administrator of the main site who is not a super administrator cannot manage BackupPlus' );
	ms_check( false !== strpos( ms_eval( $network, 'echo BackupPlus_Options::admin_page_url();' ), '/wp-admin/network/settings.php' ), 'The settings page is in Network Admin' );

	// The other sites: no screens, events or options of their own, and the main site's configuration
	ms_eval( $network, 'BackupPlus_Options::update_backupplus_option( "backupplus_retain", 6 );' );
	ms_check( '0|6|0' === ms_eval( $network, 'echo (int) BackupPlus_Multisite::available() . "|" . BackupPlus_Options::get_backupplus_option( "backupplus_retain" ) . "|" . (int) wp_schedule_single_event( time() + 600, "backupplus_check_multisite" );', $site_url ), 'Another site has no BackupPlus screens or events, and reads the network configuration' );
	ms_check( '0' === ms_eval( $network, 'global $wpdb; echo (int) BackupPlus_SQL::wpdb()->value( "SELECT COUNT(*) FROM %i WHERE option_name LIKE ?", array( $wpdb->options ), array( "backupplus%" ) );', $site_url ), 'Requests to another site write no BackupPlus options there' );
	ms_refused( $network, array( '--url=' . $site_url, 'backupplus', 'list' ), 'from its main site' );

	// A network backup
	$main_post = (int) ms_wp( $network, array( 'post', 'create', '--post_title=Main original ' . $tag, '--post_status=publish', '--porcelain' ) );
	$site_post = (int) ms_wp( $network, array( '--url=' . $site_url, 'post', 'create', '--post_title=Site original ' . $tag, '--post_status=publish', '--porcelain' ) );
	$cleanup[] = array( 'post', $main_post );
	$main_file = $uploads . '/ms-check/' . $tag . '.txt';
	$site_file = $uploads . '/sites/' . $site . '/ms-check/' . $tag . '.txt';
	ms_sh( $network, 'mkdir -p ' . dirname( $main_file ) . ' ' . dirname( $site_file ) . ' && echo main-original > ' . $main_file . ' && echo site-original > ' . $site_file );
	$cleanup[]      = array( 'path', $uploads . '/ms-check' );
	$network_backup = (int) ms_wp( $network, array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-network-' . $tag, '--porcelain' ) );
	$cleanup[]      = array( 'backup', $network, $network_backup );

	$change = function ( $suffix ) use ( $network, $site_url, $main_post, $site_post, $main_file, $site_file, $mapped_url, $mapped_post ) {
		ms_wp( $network, array( 'post', 'update', (string) $main_post, '--post_title=Main ' . $suffix ) );
		ms_wp( $network, array( '--url=' . $site_url, 'post', 'update', (string) $site_post, '--post_title=Site ' . $suffix ) );
		ms_wp( $network, array( '--url=' . $mapped_url, 'post', 'update', (string) $mapped_post, '--post_title=Mapped ' . $suffix ) );
		ms_sh( $network, 'echo main-' . $suffix . ' > ' . $main_file . ' && echo site-' . $suffix . ' > ' . $site_file );
	};

	// One site of the network backup
	$change( 'changed' );
	ms_wp( $network, array( 'backupplus', 'restore', (string) $network_backup, '--site=' . $site, '--yes' ) );
	ms_check( 'Site original ' . $tag === ms_post_title( $network, $site_url, $site_post ) && 'site-original' === ms_file( $network, $site_file ), 'Restoring one site restores its tables and uploads' );
	ms_check( 'Main changed' === ms_post_title( $network, $home, $main_post ) && 'main-changed' === ms_file( $network, $main_file ), 'Restoring one site leaves the main site alone' );
	ms_check( 'site-changed' === ms_file( $network, $uploads . '-old/sites/' . $site . '/ms-check/' . $tag . '.txt' ), "The site's previous uploads are kept in uploads-old" );
	ms_check( 'Mapped changed' === ms_post_title( $network, $mapped_url, $mapped_post ), 'Restoring one site leaves the other sites alone' );
	ms_wp( $network, array( 'backupplus', 'restore', (string) $network_backup, '--site=' . $mapped, '--yes' ) );
	ms_check( 'Mapped original ' . $tag === ms_post_title( $network, $mapped_url, $mapped_post ) && $mapped_url === ms_site_url( $network, $mapped ), 'A site with a mapped domain is restored, and keeps its domain' );
	ms_check( false !== strpos( ms_http( $mapped_url . '?p=' . $mapped_post ), 'Mapped original ' . $tag ), 'The restored site with a mapped domain serves its content' );

	// The main site of the network backup
	$change( 'again' );
	$later     = 'mslater' . $tag;
	$cleanup[] = array( 'user', $later );
	ms_wp( $network, array( 'user', 'create', $later, $later . '@example.test' ) );
	ms_wp( $network, array( 'backupplus', 'restore', (string) $network_backup, '--site=1', '--yes' ) );
	ms_check( 'Main original ' . $tag === ms_post_title( $network, $home, $main_post ) && 'main-original' === ms_file( $network, $main_file ), 'Restoring the main site restores its tables and uploads' );
	ms_check( 'Site again' === ms_post_title( $network, $site_url, $site_post ) && 'site-again' === ms_file( $network, $site_file ), "Restoring the main site leaves the other sites' tables and uploads alone" );
	ms_check( '' !== ms_wp( $network, array( 'user', 'get', $later, '--field=ID' ) ), "Restoring one site leaves the network's users alone" );
	ms_check( '' === ms_sh( $network, 'ls -A ' . $uploads . '/.maintenance 2>/dev/null || true' ), 'The site leaves maintenance after the restore' );
	ms_check( 'main-again' === ms_file( $network, $uploads . '-old/ms-check/' . $tag . '.txt' ) && 'site-changed' === ms_file( $network, $uploads . '-old/sites/' . $site . '/ms-check/' . $tag . '.txt' ), "Restoring the main site keeps its previous uploads in uploads-old, beside the other sites' kept there" );

	// The whole network, which replaces the network tables (where the running restore keeps its job data) too
	$change( 'third' );
	$cleanup[] = array( 'old', ms_backups( $network ) . '/uploads-old' );
	ms_wp( $network, array( 'backupplus', 'restore', (string) $network_backup, '--yes' ) );
	ms_check( 'Main original ' . $tag === ms_post_title( $network, $home, $main_post ) && 'Site original ' . $tag === ms_post_title( $network, $site_url, $site_post ), 'Restoring the whole network restores every site' );
	ms_check( 0 !== ms_wp( $network, array( 'user', 'get', $later ), false )['status'], "Restoring the whole network restores the network's users" );
	// The backup's own lock was held when its options were dumped: the restore keeps the locks held now instead
	$after     = (int) ms_wp( $network, array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-after-' . $tag, '--porcelain' ) );
	$cleanup[] = array( 'backup', $network, $after );
	ms_check( $after > 0, 'A backup can start straight after a restore' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $network_backup, '--import-into=new', '--slug=nothing-' . $tag, '--yes' ), 'This is a backup of a network' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $network_backup, '--site=999999', '--yes' ), 'does not exist in this network' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $network_backup, '--site=' . $site, '--components=db,uploads,plugins', '--yes' ), 'Unknown, unavailable or incompatible restore components' );

	// A single-site backup, with a user the network does not have and the network's administrator
	$single_home  = ms_wp( $single, array( 'option', 'get', 'home' ) );
	$single_admin = ms_wp( $single, array( 'user', 'get', '1', '--field=user_login' ) );
	$author       = 'msauthor' . $tag;
	$author_id    = (int) ms_wp( $single, array( 'user', 'create', $author, $author . '@example.test', '--role=editor', '--user_pass=Imported-' . $tag, '--porcelain' ) );
	$single_post  = (int) ms_wp( $single, array( 'post', 'create', '--post_title=Imported ' . $tag, '--post_status=publish', '--post_author=' . $author_id, '--post_content=<img src="' . $single_home . '/wp-content/uploads/ms-import/' . $tag . '.txt">', '--porcelain' ) );
	$single_dir   = ms_eval( $single, 'echo WP_CONTENT_DIR;' ) . '/uploads/ms-import';
	ms_sh( $single, 'mkdir -p ' . $single_dir . ' && echo single-site > ' . $single_dir . '/' . $tag . '.txt' );
	$single_backup = (int) ms_wp( $single, array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-single-' . $tag, '--porcelain' ) );
	$files         = array_column( json_decode( ms_wp( $single, array( 'backupplus', 'restore', (string) $single_backup, '--dry-run', '--format=json' ) ), true ), 'file' );
	$transfer      = integration_temp_dir( 'backupplus-ms-' );
	$from          = ms_backups( $single );
	$to            = ms_backups( $network );
	foreach ( $files as $file ) {
		integration_expect( 0 === integration_run( array( $runtime, 'cp', $single . ':' . $from . '/' . $file, $transfer . '/' . $file ) )['status'], 'Copying ' . $file . ' from the single site failed' );
		integration_expect( 0 === integration_run( array( $runtime, 'cp', $transfer . '/' . $file, $network . ':' . $to . '/' . $file ) )['status'], 'Copying ' . $file . ' to the network failed' );
	}
	integration_remove_tree( $transfer );
	ms_wp( $single, array( 'backupplus', 'delete', (string) $single_backup, '--yes' ) );
	ms_wp( $single, array( 'post', 'delete', (string) $single_post, '--force' ) );
	ms_wp( $single, array( 'user', 'delete', (string) $author_id, '--yes' ) );
	ms_sh( $single, 'rm -rf ' . $single_dir );

	ms_wp( $network, array( 'backupplus', 'rescan' ) );
	$import = 0;
	foreach ( json_decode( ms_wp( $network, array( 'backupplus', 'list', '--format=json' ) ), true ) as $row ) {
		if ( 'ms-single-' . $tag === $row['label'] ) {
			$import = (int) $row['timestamp'];
		}
	}
	integration_expect( $import > 0, 'The single-site backup is in the network history after a rescan' );
	$cleanup[] = array( 'backup', $network, $import );

	// It cannot replace the network, a site of it as if it were a network backup, or the main site
	$before = ms_sh( $network, 'ls -R ' . $uploads . ' | md5sum' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $import, '--yes' ), 'This is a backup of a single site' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $import, '--site=' . $site, '--yes' ), 'This is a backup of a single site' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $import, '--import-into=1', '--yes' ), 'other than its main site' );
	ms_refused( $network, array( 'backupplus', 'restore', (string) $import, '--import-into=new', '--yes' ), 'Name the new site with --slug' );
	ms_check( ms_sh( $network, 'ls -R ' . $uploads . ' | md5sum' ) === $before, 'A refused restore changes no uploads' );

	// The network's settings are kept through an import, and one the network does not have stays unset (its default
	// selects every table; saved empty, it made every later backup fail)
	ms_wp( $network, array( 'option', 'delete', 'backupplus_database_selection' ), false );

	// Into a new site
	$new_slug  = 'ms-import-' . $tag;
	$cleanup[] = array( 'slug', $new_slug );
	$cleanup[] = array( 'user', $author );
	$existing  = ms_wp( $network, array( 'user', 'get', $single_admin, '--field=ID' ), false );
	ms_wp( $network, array( 'backupplus', 'restore', (string) $import, '--import-into=new', '--slug=' . $new_slug, '--yes' ) );
	$new_site = (int) ms_eval( $network, 'echo (int) get_id_from_blogname( ' . var_export( $new_slug, true ) . ' );' );
	$new_url  = $new_site ? rtrim( ms_site_url( $network, $new_site ), '/' ) : '';
	$expected = $subdomain ? parse_url( $home, PHP_URL_SCHEME ) . '://' . $new_slug . '.' . ms_host( $home ) : $home . '/' . $new_slug;
	ms_check( $new_site > 0 && $expected === $new_url && $new_url === ms_wp( $network, array( '--url=' . $new_url . '/', 'option', 'get', 'siteurl' ) ), 'Importing into a new site creates it with its own address' );
	$imported = json_decode( ms_wp( $network, array( '--url=' . $new_url . '/', 'post', 'list', '--post_type=post', '--title=Imported ' . $tag, '--fields=ID,post_author,post_content', '--format=json' ) ), true );
	$author   = ms_wp( $network, array( 'user', 'get', $author, '--field=ID' ) );
	ms_check( 1 === count( $imported ) && (int) $imported[0]['post_author'] === (int) $author, "The imported content belongs to the user's new network account" );
	$file_url = $new_url . '/wp-content/uploads/sites/' . $new_site . '/ms-import/' . $tag . '.txt';
	ms_check( false !== strpos( $imported[0]['post_content'], $file_url ) && false === strpos( $imported[0]['post_content'], $single_home ), "The backup's addresses are replaced with the site's own: " . $file_url );
	ms_check( 'single-site' === trim( ms_http( $file_url ) ), 'The imported uploads are served at their new address' );
	ms_check( 'single-site' === ms_file( $network, $uploads . '/sites/' . $new_site . '/ms-import/' . $tag . '.txt' ), "The backup's uploads are in the site's uploads directory" );
	ms_check( 0 === ms_wp( $network, array( 'user', 'check-password', $author, 'Imported-' . $tag ), false )['status'], 'An imported user keeps their password' );
	ms_check( 'editor' === ms_wp( $network, array( '--url=' . $new_url . '/', 'user', 'get', $author, '--field=roles' ) ), 'An imported user has their role on the site' );
	if ( 0 === $existing['status'] ) {
		ms_check( trim( $existing['stdout'] ) === ms_wp( $network, array( 'user', 'get', $single_admin, '--field=ID' ) ) && 'administrator' === ms_wp( $network, array( '--url=' . $new_url . '/', 'user', 'get', $single_admin, '--field=roles' ) ), "A user the network already has is merged with that account, and has their role from the backup on the site" );
	} else {
		$cleanup[] = array( 'user', $single_admin );
	}
	$users = ms_wp( $network, array( 'user', 'list', '--format=count' ) );
	ms_check( '0|0' === ms_eval( $network, 'global $wpdb; $p = $wpdb->get_blog_prefix( ' . $new_site . ' ); echo (int) BackupPlus_SQL::wpdb()->value( "SELECT COUNT(*) FROM %i WHERE option_name LIKE ?", array( $p . "options" ), array( "backupplus%" ) ) . "|" . (int) BackupPlus_SQL::wpdb()->value( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)", array(), array( $p . "users", $p . "usermeta" ) );' ), "The imported site has no BackupPlus settings or users tables of its own" );
	ms_check( false !== strpos( ms_http( $new_url . '/?p=' . (int) $imported[0]['ID'] ), 'Imported ' . $tag ), 'The imported site serves its content' );

	// Over the network's existing site
	ms_wp( $network, array( 'backupplus', 'restore', (string) $import, '--import-into=' . $site, '--yes' ) );
	ms_check( $site_url === ms_wp( $network, array( '--url=' . $site_url, 'option', 'get', 'siteurl' ) ) . '/', 'Importing over a site keeps its address' );
	ms_check( '0|1' === ms_wp( $network, array( '--url=' . $site_url, 'post', 'list', '--post_type=post', '--title=Site original ' . $tag, '--format=count' ) ) . '|' . ms_wp( $network, array( '--url=' . $site_url, 'post', 'list', '--post_type=post', '--title=Imported ' . $tag, '--format=count' ) ), "Importing over a site replaces its content with the backup's" );
	ms_check( 'single-site' === trim( ms_http( $site_url . 'wp-content/uploads/sites/' . $site . '/ms-import/' . $tag . '.txt' ) ), 'The site serves the imported uploads at its own address' );
	ms_check( $users === ms_wp( $network, array( 'user', 'list', '--format=count' ) ), 'Importing again merges every user with the account the first import created' );

	// Over a site with a mapped domain, whose addresses (and so its content's) are on its own domain
	ms_wp( $network, array( 'backupplus', 'restore', (string) $import, '--import-into=' . $mapped, '--yes' ) );
	ms_check( $mapped_url === ms_site_url( $network, $mapped ) && rtrim( $mapped_url, '/' ) === ms_wp( $network, array( '--url=' . $mapped_url, 'option', 'get', 'home' ) ), 'Importing over a site with a mapped domain keeps its domain' );
	$imported = json_decode( ms_wp( $network, array( '--url=' . $mapped_url, 'post', 'list', '--post_type=post', '--title=Imported ' . $tag, '--fields=ID,post_content', '--format=json' ) ), true );
	$file_url = $mapped_url . 'wp-content/uploads/sites/' . $mapped . '/ms-import/' . $tag . '.txt';
	ms_check( 1 === count( $imported ) && false !== strpos( $imported[0]['post_content'], $file_url ), "The imported content's addresses are on the mapped domain: " . $file_url );
	ms_check( false !== strpos( ms_http( $mapped_url . '?p=' . (int) $imported[0]['ID'] ), 'Imported ' . $tag ) && 'single-site' === trim( ms_http( $file_url ) ), 'The site with a mapped domain serves the imported content and uploads' );
	ms_check( 'main-original' === ms_file( $network, $main_file ) && 'Main original ' . $tag === ms_post_title( $network, $home, $main_post ), 'Importing leaves the main site alone' );
	ms_check( 0 !== ms_wp( $network, array( 'option', 'get', 'backupplus_database_selection' ), false )['status'] && '6' === ms_wp( $network, array( 'option', 'get', 'backupplus_retain' ) ), "Importing keeps the network's settings, and leaves those it does not have unset" );
	$last      = (int) ms_wp( $network, array( 'backupplus', 'backup', '--local', '--files=uploads', '--label=ms-last-' . $tag, '--porcelain' ) );
	$cleanup[] = array( 'backup', $network, $last );
	ms_check( $last > 0, 'The network backs up after an import' );

	echo "Multisite checks passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $item ) {
		try {
			switch ( $item[0] ) {
				case 'site':
					ms_wp( $network, array( 'site', 'delete', (string) $item[1], '--yes' ), false );
					break;
				case 'slug':
					ms_wp( $network, array( 'site', 'delete', '--slug=' . $item[1], '--yes' ), false );
					break;
				case 'user':
					ms_wp( $network, array( 'user', 'delete', $item[1], '--network', '--yes' ), false );
					break;
				case 'post':
					ms_wp( $network, array( 'post', 'delete', (string) $item[1], '--force' ), false );
					break;
				case 'backup':
					ms_wp( $item[1], array( 'backupplus', 'delete', (string) $item[2], '--yes' ), false );
					break;
				case 'old':
					ms_sh( $network, 'rm -rf ' . escapeshellarg( $item[1] ) );
					break;
				case 'path':
					ms_sh( $network, 'rm -rf ' . escapeshellarg( $item[1] ) . ' ' . escapeshellarg( $item[1] ) . '-old ' . escapeshellarg( dirname( $item[1] ) ) . '-old' );
					break;
			}
		} catch ( Throwable $error ) {
			fwrite( STDERR, 'Cleanup: ' . $error->getMessage() . "\n" );
		}
	}
}

function ms_host( $url ) {
	$host = parse_url( $url, PHP_URL_HOST );
	$port = parse_url( $url, PHP_URL_PORT );
	return $port ? $host . ':' . $port : $host;
}
