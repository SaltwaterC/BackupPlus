<?php
// mysqli, PDO and $wpdb methods that were not sinks.
function unlisted_sinks( $mysqli, $pdo, $wpdb, $t, $stmt ) {
	$mysqli->real_query( $t ); // expect
	$mysqli->multi_query( $t ); // expect
	$mysqli->send_query( $t ); // expect
	$pdo->exec( $t ); // expect
	mysqli_stmt_prepare( $stmt, $t ); // expect
	$wpdb->insert( $t, array( 'a' => 1 ) ); // expect
	$wpdb->update( $t, array( 'a' => 1 ), array( 'b' => 2 ) ); // expect
	$wpdb->delete( $t, array( 'a' => 1 ) ); // expect
	$wpdb->replace( $t, array( 'a' => 1 ) ); // expect
	$GLOBALS['wpdb']->delete( $t, array( 'a' => 1 ) ); // expect
	$wpdb->prepare( 'SELECT %s', $t ); // expect
}
