<?php
// Look-alikes of the review gaps that are not assembly.
function review_gaps_pass( $link, $mysqli, $sql_line, $name ) {
	$sql_line = preg_replace( '/^(\s*insert\s\s*into)(.+)$/is', 'insert ignore into$2', $sql_line );
	$message  = sprintf( 'Table %s could not be read', $name );
	$labels   = array();
	$labels[] = 'plain words';
	$joined   = implode( ', ', $labels );
	mysqli_options( $link, MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
	$mysqli->options( MYSQLI_INIT_COMMAND, 'SET SESSION sql_log_bin = 0' );
	$greeting = "Hello $name";
}
