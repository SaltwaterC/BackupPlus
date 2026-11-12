<?php
// Interpolated strings, heredocs and constants that hold SQL.
const PURGE = 'DELETE FROM ';
define( 'SELECTION', 'SELECT * FROM ' );

class Interpolation {
	const STATEMENT = 'TRUNCATE TABLE ';

	public function run( $t, $x ) {
		$a = "DELETE FROM $t"; // expect
		$b = "DELETE FROM {$t} WHERE id = {$x->id}"; // expect
		$c = /* expect */ <<<SQL
SELECT * FROM $t
SQL;
		$d = PURGE . $t; // expect
		$e = SELECTION . $t; // expect
		$f = self::STATEMENT . $t; // expect
		$g = static::STATEMENT . $t; // expect
		return "insert into {$t} values (1)"; // expect
	}
}
