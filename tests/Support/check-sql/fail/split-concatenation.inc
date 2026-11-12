<?php
// The adjacency check only looked at a keyword literal directly beside ".", and script() took any variable.
function split_concatenation( $x, $t ) {
	$q  = 'DELETE FROM ';
	$q .= $t; // expect
	$x->script( $q ); // expect
	$x->sql_exec( $q, 1 ); // expect
}

function split_into_a_property( $t ) {
	$this->statement  = 'SELECT * FROM ';
	$this->statement .= $t; // expect
}
