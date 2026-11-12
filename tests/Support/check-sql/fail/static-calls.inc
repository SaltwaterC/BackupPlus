<?php
// Fully qualified, self::, static:: and parent:: calls, and static calls on database classes.
function fully_qualified( $t, $link ) {
	\BackupPlus_SQL::fragment( $t ); // expect
	\BackupPlus_SQL::render( 'DROP TABLE ' . $t ); // expect
	\mysqli_query( $link, $t ); // expect
	\mysqli_real_query( $link, $t ); // expect
	BackupPlus_Database_Utility::query( $t ); // expect
}

class BackupPlus_SQL_Wrapper extends BackupPlus_SQL {
	public function run( $t ) {
		self::fragment( $t ); // expect
		static::render( $t ); // expect
		parent::render( $t ); // expect
	}
}

class Example_Database {
	public static function lookup( $t ) {
		return self::rows( $t ); // expect
	}
}
