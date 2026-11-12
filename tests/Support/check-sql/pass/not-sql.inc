<?php
// Text that mentions SQL words but is not SQL.
function not_sql( $file, $type, $boundary, $ssh, $fields, $name, $identifier, $wpdb ) {
	$log   = 'Delete ' . $file . ' from the backup directory';
	$log  .= ' - ' . $name;
	$mime  = str_replace( '; charset=', ';charset=', $type );
	$body  = "Content-Type: application/json; charset=UTF-8\r\n";
	$body .= "--$boundary\r\n";
	$ssh->exec( 'cd ' . escapeshellarg( $file ) . ' && ls' );
	$use   = 'Unknown field. Use ' . implode( ', ', $fields ) . '.';
	$match = preg_match( '/^\s*CREATE TRIGGER\s+(' . $identifier . ')\s+ON\s+/i', $name );
	$regex = '~\AALTER\s+EVENT\s+' . preg_quote( $name, '~' ) . '\s*;?\z~i';
	$attr  = 'data-charset=' . $name;
	$hooks = array( __CLASS__, 'render' );
	$pair  = array( 'key', 'value' );
	$check = function_exists( 'mysqli_query' );
	$ok    = is_callable( array( $wpdb, 'db_version' ) );
	$args  = array( $wpdb->esc_like( 'backupplus_' ) . '%', $name );
	$err   = sprintf( __( 'An error occurred on the first %s command - aborting run' ), 'INSERT (options)' );
	$http  = 'GET' . "\n\n" . $name;
	return $ssh;
}
