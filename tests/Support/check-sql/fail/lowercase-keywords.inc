<?php
// The keyword check had no /i flag.
function lowercase_keywords( $table, $id, $column ) {
	$a = 'delete from ' . $table; // expect
	$b = "select * from {$table}"; // expect
	$c = 'update ' . $table . ' set x = 1'; // expect
	$d = 'insert into ' . $table . ' values (1)'; // expect
	$e = 'Select ' . $column . ', id From ' . $table; // expect
	$f = 'drop table if exists ' . $table; // expect
}
