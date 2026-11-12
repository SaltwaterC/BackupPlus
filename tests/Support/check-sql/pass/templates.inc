<?php
// Constant templates, rendered statements and dump executors.
function templates( $sql, $t, $id, $statement, $dump_line, $mysqli ) {
	$sql->rows( 'SELECT * FROM %i WHERE id = ?', array( $t ), array( $id ) );
	$sql->execute( $id ? 'DELETE FROM %i' : 'TRUNCATE TABLE %i', array( $t ) );
	$fragment = BackupPlus_SQL::fragment( '%i = ?', array( 'a' ), array( $id ) );
	$sql->execute( 'UPDATE %i SET %l', array( $t, array( $fragment ) ) );
	$sql->script( $dump_line );
	$sql->script( BackupPlus_SQL::render( 'DROP TABLE IF EXISTS %i', array( $t ) ) );
	$this->sql_exec( $statement, 1, '', false );
	$sql->value( "SELECT option_value FROM %i WHERE option_name = 'siteurl'", array( $t ) );
	// A dump writer appends the dump's own text to a rendered head.
	$head = BackupPlus_SQL::render( 'INSERT INTO %i VALUES ', array( $t ) );
	echo $head . $dump_line . ";\n";
	echo BackupPlus_SQL::render( "/*!40101 SET NAMES %i */;\n", array( $t ) );
	if ( $fragment instanceof BackupPlus_SQL_Fragment ) {
		return $fragment;
	}
}

function typed( BackupPlus_SQL_Fragment $fragment, ?BackupPlus_SQL_Fragment $other = null ): BackupPlus_SQL_Fragment {
	return $fragment;
}

class Example_Client {
	/** A sink-named wrapper hands its own parameter on. */
	public function query( $sql ) {
		$this->run( array( '--batch', '--execute=' . $sql ) );
		return mysqli_query( $this->link, $sql );
	}
}
