<?php
// Statement keywords missing from the list.
function missing_keywords( $v ) {
	$q = array();
	$q[] = 'WITH ' . $v; // expect
	$q[] = 'EXPLAIN ' . $v; // expect
	$q[] = 'DO ' . $v; // expect
	$q[] = 'LOAD DATA INFILE ' . $v; // expect
	$q[] = 'HANDLER ' . $v . ' OPEN'; // expect
	$q[] = 'PREPARE s FROM ' . $v; // expect
	$q[] = 'EXECUTE ' . $v; // expect
	$q[] = 'FLUSH ' . $v; // expect
	$q[] = 'OPTIMIZE TABLE ' . $v; // expect
	$q[] = 'ANALYZE TABLE ' . $v; // expect
	$q[] = 'BEGIN ' . $v; // expect
	$q[] = 'START ' . $v; // expect
	$q[] = 'XA ' . $v; // expect
	$q[] = 'with recursive cte as (' . $v; // expect
	$q[] = 'explain select ' . $v; // expect
	$q[] = 'load data local infile ' . $v; // expect
	$q[] = 'xa start ' . $v; // expect
}
