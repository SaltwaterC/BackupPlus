<?php

$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$request_path = is_string($request_path) ? rawurldecode($request_path) : '/';
$document_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html');
if (false === $document_root) {
	http_response_code(500);
	exit('WordPress document root is unavailable.');
}

// The plugin is mounted outside the document root, so resolve its static files separately.
$plugin_prefix = '/wp-content/plugins/backupplus';
$plugin_root = realpath('/workspace/backupplus');
if ($plugin_root && str_starts_with($request_path, $plugin_prefix)) {
	$plugin_relative_path = substr($request_path, strlen($plugin_prefix));
	if ('' === $plugin_relative_path || '/' === $plugin_relative_path[0]) {
		$plugin_file = realpath($plugin_root . $plugin_relative_path);
		if ($plugin_file && str_starts_with($plugin_file, $plugin_root . DIRECTORY_SEPARATOR) && is_file($plugin_file)) {
			return false;
		}
	}
}

$requested_file = realpath($document_root . $request_path);


if ($document_root && $requested_file && str_starts_with($requested_file, $document_root . DIRECTORY_SEPARATOR) && (is_file($requested_file) || is_dir($requested_file))) {
	return false;
}

require $document_root . '/index.php';
