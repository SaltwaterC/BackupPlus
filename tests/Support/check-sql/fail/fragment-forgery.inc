<?php
// A fragment that BackupPlus_SQL::fragment() did not compile.
function fragment_forgery( $t ) {
	$a = new BackupPlus_SQL_Fragment( $t, array() ); // expect
	$b = new \BackupPlus_SQL_Fragment( $t, array() ); // expect
	$class = 'BackupPlus_SQL_Fragment'; // expect
	$c     = new $class( $t, array() );
	$d     = new ReflectionClass( BackupPlus_SQL_Fragment::class ); // expect
	$e     = unserialize( 'O:23:"BackupPlus_SQL_Fragment":0:{}' ); // expect
	$f     = "backupplus_sql_fragment"; // expect
}
