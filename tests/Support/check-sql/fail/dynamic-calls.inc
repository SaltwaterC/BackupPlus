<?php
// Calls whose method or function the check cannot see.
function dynamic_calls( $wpdb, $mysqli, $m, $t, $link ) {
	$wpdb->$m( $t ); // expect
	$wpdb->{$m}( $t ); // expect
	$mysqli->$m( $t ); // expect
	call_user_func( array( $wpdb, 'query' ), $t ); // expect
	call_user_func_array( array( $wpdb, $m ), array( $t ) ); // expect
	call_user_func( 'mysqli_query', $link, $t ); // expect
	$f = 'mysqli_query'; // expect
	$g = array( $GLOBALS['wpdb'], 'get_results' ); // expect
	$h = array( 'BackupPlus_SQL', 'fragment' ); // expect
	$i = 'wpdb::query'; // expect
	$j = array( $db, 'get_var' ); // expect
	$k = [ $this->wpdb_obj, 'query' ]; // expect
	BackupPlus_SQL::$m( $t ); // expect
}
