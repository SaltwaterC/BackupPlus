<?php
/**
 * BPS-06 in real WordPress: the backup uploader and the archive decrypter uploader store a file only under a validated backup
 * log or archive name. A name with a PHP suffix is refused before anything is written, and the multipart file name that the
 * client sends never reaches the file system, whatever the validated name is.
 *
 * The uploads are real multipart requests to admin-ajax.php from a disposable administrator; a must-use plugin, active only
 * for requests that carry this run's secret header, points the backup directory at a disposable directory.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-upload-names.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function upload_names_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

$gate  = bin2hex( random_bytes( 12 ) );
$label = 'backupplus_upload_names_' . $gate;
// A folder directly in wp-content: the backup directory may not be inside uploads (BPS-07), and the web server must reach it.
$directory = WP_CONTENT_DIR . '/' . $label;
mkdir( $directory, 0700 );
$mu_file = WPMU_PLUGIN_DIR . '/' . $label . '.php';
$user    = wp_insert_user(
	array(
		'user_login' => $label,
		'user_pass'  => wp_generate_password( 40 ),
		'role'       => 'administrator',
	)
);
upload_names_expect( ! is_wp_error( $user ), 'A disposable administrator must be created' );
$original_user    = get_current_user_id();
$original_cookies = $_COOKIE;
$cleanup          = function () use ( $directory, $mu_file, $user ) {
	if ( is_file( $mu_file ) ) {
		unlink( $mu_file );
	}
	if ( is_dir( $directory ) ) {
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink( $directory . '/' . $entry );
			}
		}
		rmdir( $directory );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( get_userdata( $user ) ) {
		wp_delete_user( $user );
	}
};
register_shutdown_function( $cleanup );
file_put_contents(
	$mu_file,
	'<?php if ( ! isset( $_SERVER["HTTP_X_BACKUPPLUS_UPLOAD_TEST"] ) || ! hash_equals( ' . var_export( $gate, true ) . ', $_SERVER["HTTP_X_BACKUPPLUS_UPLOAD_TEST"] ) ) { return; }' . "\n"
	. 'add_filter( "pre_option_backupplus_dir", function () { return ' . var_export( $directory, true ) . '; }, 100 );' . "\n"
);

try {
	wp_set_current_user( $user );
	$session                     = WP_Session_Tokens::get_instance( $user )->create( time() + 600 );
	$logged_in                   = wp_generate_auth_cookie( $user, time() + 600, 'logged_in', $session );
	$auth                        = wp_generate_auth_cookie( $user, time() + 600, 'auth', $session );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	$nonce                       = wp_create_nonce( 'backupplus-uploader' );
	$ajax                        = admin_url( 'admin-ajax.php' );

	/**
	 * Upload one part as plupload does: form fields, then the file part with its own (client-chosen) file name.
	 *
	 * @return String - the response body
	 */
	$upload        = function ( $action, array $fields, $file_name, $contents ) use ( $ajax, $nonce, $gate, $auth, $logged_in ) {
		$boundary = 'BackupPlusTest' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( array(
			'action'      => $action,
			'_ajax_nonce' => $nonce,
		) + $fields as $field => $value ) {
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . "\"\r\n\r\n" . $value . "\r\n";
		}
		$body    .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"async-upload\"; filename=\"" . $file_name . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $contents . "\r\n--" . $boundary . "--\r\n";
		$response = wp_remote_post(
			$ajax,
			array(
				'headers'     => array(
					'X-BackupPlus-Upload-Test' => $gate,
					'Cookie'                   => AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in ),
					'Content-Type'             => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'        => $body,
				'redirection' => 0,
				'timeout'     => 60,
			)
		);
		upload_names_expect( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'The upload endpoint must answer' );
		return wp_remote_retrieve_body( $response );
	};
	$backup_upload = function ( array $fields, $file_name, $contents ) use ( $upload ) {
		$result = json_decode( $upload( 'plupload_action', $fields, $file_name, $contents ), true );
		upload_names_expect( is_array( $result ), 'The backup uploader must answer with JSON' );
		return $result;
	};
	// The files a test left in the backup directory, apart from the protection files that the plugin writes there
	$stored = function () use ( $directory ) {
		$names = array_values( array_diff( scandir( $directory ), array( '.', '..', 'index.html', '.htaccess', 'web.config' ) ) );
		sort( $names );
		return $names;
	};
	$reset  = function () use ( $directory, $stored ) {
		foreach ( $stored() as $name ) {
			unlink( $directory . '/' . $name );
		}
	};
	$php    = '<?php echo "BPS-06";';
	$log    = 'log.abcdef012345.txt';
	$valid  = 'backup_2024-01-01-0000_site_abcdef012345-db.gz';
	$crypt  = 'backup_2024-01-01-0000_site_abcdef012345-db.gz.crypt';
	$finder = 'log.abcdef012345.txt.php';

	// The finding's chunked upload: every chunk of a log name with a PHP suffix is refused, and nothing is stored
	foreach ( array( 0, 1 ) as $chunk ) {
		$result = $backup_upload(
			array(
				'name'   => $finder,
				'chunks' => 2,
				'chunk'  => $chunk,
			),
			'blob',
			$php
		);
		upload_names_expect( ! empty( $result['e'] ) && empty( $result['m'] ), 'Every chunk of ' . $finder . ' must be refused' );
	}
	upload_names_expect( array() === $stored(), 'A refused chunked upload must store nothing' );

	// The same name in one part, and a PHP name that comes only from the multipart file name
	foreach ( array(
		array( array( 'name' => $finder ), $finder ),
		array( array(), 'shell.php' ),
		array( array( 'name' => $valid . '.php' ), $valid . '.php' ),
		array( array( 'name' => 'backup_2024-01-01-0000_x.php.y_abcdef012345-db.gz' ), 'x.gz' ),
	) as $case ) {
		$result = $backup_upload( $case[0], $case[1], $php );
		upload_names_expect( ! empty( $result['e'] ), 'The upload of ' . $case[1] . ' must be refused' );
		upload_names_expect( array() === $stored(), 'The refused upload of ' . $case[1] . ' must store nothing' );
	}

	// Invalid chunk numbers are refused before anything is stored
	$result = $backup_upload(
		array(
			'name'   => $valid,
			'chunks' => 2,
			'chunk'  => 2,
		),
		'blob',
		'x'
	);
	upload_names_expect( ! empty( $result['e'] ) && array() === $stored(), 'A chunk beyond the last one must be refused' );

	// A valid name with a mismatched multipart file name is stored under the valid name only
	foreach ( array( $valid, $log ) as $name ) {
		$result = $backup_upload( array( 'name' => $name ), 'shell.php', $php );
		upload_names_expect( empty( $result['e'] ) && $name === $result['m'], $name . ' must be accepted: ' . wp_json_encode( $result ) );
		upload_names_expect( array( $name ) === $stored() && $php === file_get_contents( $directory . '/' . $name ), 'The upload must be stored as ' . $name . ' and as nothing else: ' . implode( ', ', $stored() ) );
		$reset();
	}

	// A chunked upload of a valid name still works, with plupload's multipart file name of each chunk
	foreach ( array( 'first chunk|', 'second chunk' ) as $chunk => $contents ) {
		$result = $backup_upload(
			array(
				'name'   => $valid,
				'chunks' => 2,
				'chunk'  => $chunk,
			),
			'blob',
			$contents
		);
		upload_names_expect( empty( $result['e'] ), 'Chunk ' . $chunk . ' of a valid upload must be accepted: ' . wp_json_encode( $result ) );
	}
	upload_names_expect( array( $valid ) === $stored() && 'first chunk|second chunk' === file_get_contents( $directory . '/' . $valid ), 'Chunks must be joined into the validated name only' );
	$reset();

	// The archive decrypter uploader follows the same rules
	foreach ( array( array( 'name' => $finder ), array() ) as $fields ) {
		$response = $upload( 'plupload_action2', $fields, 'shell.php', $php );
		upload_names_expect( 0 === strpos( $response, 'ERROR:' ) && array() === $stored(), 'The decrypter uploader must refuse a PHP name: ' . $response );
	}
	$response = $upload( 'plupload_action2', array( 'name' => $crypt ), 'shell.php', $php );
	upload_names_expect( 'OK:' . $crypt === $response, 'The decrypter uploader must accept an encrypted archive name: ' . $response );
	upload_names_expect( array( $crypt ) === $stored(), 'The decrypter uploader must store the validated name only: ' . implode( ', ', $stored() ) );
	$reset();

	echo "PASS: uploads refuse PHP names before storing anything and store accepted files only under the validated name\n";
} finally {
	wp_set_current_user( $original_user );
	$_COOKIE = $original_cookies;
	$cleanup();
}
