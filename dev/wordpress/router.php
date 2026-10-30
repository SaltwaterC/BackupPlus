<?php

$request_path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$request_path = is_string( $request_path ) ? rawurldecode( $request_path ) : '/';
// Private development configuration and repository metadata are never web assets.
if ( preg_match( '~(?:^|/)\.~', $request_path ) ) {
	http_response_code( 404 );
	exit;
}
$document_root = realpath( $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html' );
if ( false === $document_root ) {
	http_response_code( 500 );
	exit( 'WordPress document root is unavailable.' );
}

// The plugin is mounted outside the document root, so resolve its static files separately.
$plugin_prefix = '/wp-content/plugins/backupplus';
$plugin_root   = realpath( '/workspace/backupplus' );
if ( $plugin_root && str_starts_with( $request_path, $plugin_prefix ) ) {
	$plugin_relative_path = substr( $request_path, strlen( $plugin_prefix ) );
	if ( '' === $plugin_relative_path || '/' === $plugin_relative_path[0] ) {
		$plugin_file = realpath( $plugin_root . $plugin_relative_path );
		if ( $plugin_file && str_starts_with( $plugin_file, $plugin_root . DIRECTORY_SEPARATOR ) && is_file( $plugin_file ) ) {
			return false;
		}
	}
}

$requested_file = realpath( $document_root . $request_path );

// A sub-directory network's sites share WordPress's files: /site/wp-admin/... is /wp-admin/..., as WordPress's rewrite rules have it.
if ( getenv( 'WP_MULTISITE' ) && 'subdomain' !== getenv( 'WP_MULTISITE' ) && preg_match( '~^/[_0-9a-zA-Z-]+(/(?:wp-(?:content|admin|includes)(?:/.*)?|[^/]*\.php))$~', $request_path, $matches ) ) {
	$shared_file = realpath( $document_root . $matches[1] );
	if ( is_dir( (string) $shared_file ) && is_file( $shared_file . '/index.php' ) ) {
		$shared_file .= '/index.php';
	}
	if ( $shared_file && str_starts_with( $shared_file, $document_root . DIRECTORY_SEPARATOR ) && is_file( $shared_file ) ) {
		if ( '.php' !== substr( $shared_file, -4 ) ) {
			$types     = array(
				'css'   => 'text/css',
				'js'    => 'application/javascript',
				'svg'   => 'image/svg+xml',
				'png'   => 'image/png',
				'jpg'   => 'image/jpeg',
				'gif'   => 'image/gif',
				'woff2' => 'font/woff2',
			);
			$extension = strtolower( pathinfo( $shared_file, PATHINFO_EXTENSION ) );
			header( 'Content-Type: ' . ( $types[ $extension ] ?? ( mime_content_type( $shared_file ) ?: 'application/octet-stream' ) ) );
			readfile( $shared_file );
			exit;
		}
		$_SERVER['SCRIPT_FILENAME'] = $shared_file;
		chdir( dirname( $shared_file ) );
		require $shared_file;
		exit;
	}
}

if ( $document_root && $requested_file && str_starts_with( $requested_file, $document_root . DIRECTORY_SEPARATOR ) && ( is_file( $requested_file ) || is_dir( $requested_file ) ) ) {
	return false;
}

require $document_root . '/index.php';
