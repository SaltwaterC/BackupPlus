<?php
// Assembly that the BPS-19 follow-up review found the check missed.
function review_gaps( $link, $mysqli, $t, $value ) {
	$q = 'DELETE FROM ';
	$s = "$q$t"; // expect
	$u = sprintf( '%s%s', $q, $t ); // expect
	$parts   = array();
	$parts[] = 'DELETE FROM x WHERE id = 1';
	$w = implode( ' ', $parts ); // expect
	$x = preg_replace( '/T/', $t, 'DELETE FROM T' ); // expect
	$y = preg_replace( '/x/', 'y', $q ); // expect
	mysqli_execute_query( $link, $t ); // expect
	$mysqli->execute_query( $t ); // expect
	mysqli_options( $link, MYSQLI_INIT_COMMAND, $t ); // expect
	$mysqli->options( MYSQLI_INIT_COMMAND, $t ); // expect
}
