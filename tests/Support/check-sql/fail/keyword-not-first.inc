<?php
// Literals that do not start with a keyword.
function keyword_not_first( $t, $charset, $sql ) {
	$a = ' WHERE id = ' . $t; // expect
	$b = ' where id = ' . $t; // expect
	$c = '/*!40101 SET NAMES ' . $charset . ' */;'; // expect
	$d = array( '--batch', '--execute=' . $sql ); // expect
	$e = $t . ' ORDER BY id'; // expect
	$f = 'x' . $t . ' limit 10'; // expect
}
