<?php
// Statements assembled by string functions.
function builders( $conditions, $t, $values ) {
	$a = implode( ' AND ', $conditions ); // expect
	$b = join( ' OR ', $conditions ); // expect
	$c = strtr( 'DELETE FROM {t}', array( '{t}' => $t ) ); // expect
	$d = str_replace( '{t}', $t, 'DELETE FROM {t}' ); // expect
	$e = str_ireplace( '{t}', $t, 'delete from {t}' ); // expect
	$f = substr_replace( 'SELECT * FROM ', $t, 14, 0 ); // expect
	$g = vsprintf( 'SELECT * FROM %s', $values ); // expect
	$format = 'DELETE FROM %s';
	$h = sprintf( $format, $t ); // expect
	$i = sprintf( __( 'select * from %s' ), $t ); // expect
	$j = implode( ', ', array( 'SELECT id', $t ) ); // expect
}
