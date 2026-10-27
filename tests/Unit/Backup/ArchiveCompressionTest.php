<?php
/** Native tar interoperability, streamed SQL and malformed archive handling. */
namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_Archive_Compression as Compression;
use BackupPlus_PITR_Process as Process;
use BackupPlus_Tar as Tar;
use BackupPlus_Zstd_Writer as Writer;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ArchiveCompressionTest extends PitrTestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/includes/class-tar.php';
		$this->assertTrue( (bool) Compression::enabled() );
	}

	public function test_tar_zstd_is_readable_by_native_tar_with_long_unicode_names_and_empty_entries(): void {
		$payload = random_bytes( 262144 );
		$source  = $this->directory . '/source';
		file_put_contents( $source, $payload );
		$name = 'uploads/' . str_repeat( 'long-directory/', 12 ) . 'é file.txt';
		$path = $this->directory . '/backup.tar';
		$tar  = new Tar();
		$this->assertTrue( $tar->open( $path ) );
		$tar->addEmptyDir( 'uploads/empty' );
		$tar->addFile( $source, $name );
		file_put_contents( $source, '' );
		$tar->addFile( $source, 'uploads/empty.txt' );
		$tar->close();
		$compressed = Compression::compress_file( $path );
		Process::run( array( '/usr/bin/tar', '--use-compress-program=/usr/bin/zstd', '-xOf', $compressed, $name ), $this->directory, null, $this->directory . '/native' );
		$this->assertSame( $payload, file_get_contents( $this->directory . '/native' ) );
		$this->assertTrue( $tar->open( $compressed ) );
		$this->assertSame( $payload, $tar->getFromName( $name ) );
		$this->assertSame( '', $tar->getFromName( 'uploads/empty.txt' ) );
		$this->assertSame( 'uploads/empty/', $tar->statIndex( 0 )['name'] );
		$tar->close();
		$this->assertSame( array(), glob( $this->directory . '/zstd-read-*' ) );
	}

	public function test_tar_append_and_replaced_manifest_survive_reopening(): void {
		$path   = $this->directory . '/append.tar';
		$source = $this->directory . '/manifest';
		file_put_contents( $source, 'first' );
		$tar = new Tar();
		$this->assertTrue( $tar->open( $path ) );
		$tar->addFile( $source, 'backupplus-manifest.json' );
		$tar->close();
		file_put_contents( $source, 'second manifest' );
		$this->assertTrue( $tar->open( $path ) );
		$tar->addFile( $source, 'backupplus-manifest.json' );
		$tar->close();
		$this->assertTrue( $tar->open( $path ) );
		$this->assertSame( 'second manifest', $tar->getFromName( 'backupplus-manifest.json' ) );
		$tar->close();
		Process::run( array( '/usr/bin/tar', '-tf', $path ), $this->directory, null, $this->directory . '/list' );
		$this->assertSame( 2, substr_count( file_get_contents( $this->directory . '/list' ), 'backupplus-manifest.json' ) );
	}

	public function test_sql_writer_publishes_one_zstandard_layer_and_preserves_seekable_sql_reads(): void {
		$path   = $this->directory . '/database.sql.zst';
		$sql    = "# test dump\nSELECT '" . str_repeat( 'repeated SQL value ', 10000 ) . "';\n";
		$writer = new Writer( $path );
		\BackupPlus_Archive_IO::write_all( $writer->handle, $sql );
		$this->assertFileDoesNotExist( $path );
		$writer->finish();
		unset( $writer );
		$this->assertTrue( Compression::is_zstd( $path ) );
		$stream = Compression::open_read( $path );
		$this->assertSame( $sql, stream_get_contents( $stream ) );
		$this->assertSame( 0, gzseek( $stream, 0 ) );
		$this->assertSame( "# test dump\n", gzgets( $stream ) );
		gzclose( $stream );
		$this->assertSame( array(), glob( $this->directory . '/*.tmp' ) );
	}

	public function test_interrupted_sql_writer_does_not_publish_partial_archives(): void {
		$path   = $this->directory . '/interrupted.sql.zst';
		$writer = new Writer( $path );
		fwrite( $writer->handle, 'incomplete SQL' );
		unset( $writer );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( array(), glob( $this->directory . '/*.tmp' ) );
	}

	public function test_tar_reader_accepts_native_pax_and_rejects_links_and_parent_paths(): void {
		file_put_contents( $this->directory . '/source', 'native data' );
		Process::run( array( '/usr/bin/tar', '--format=pax', '-cf', 'native.tar', 'source' ), $this->directory );
		$tar = new Tar();
		$this->assertTrue( $tar->open( $this->directory . '/native.tar' ), $tar->last_error );
		$this->assertSame( 'native data', $tar->getFromName( 'source' ) );
		$tar->close();
		symlink( 'source', $this->directory . '/link' );
		Process::run( array( '/usr/bin/tar', '-cf', 'links.tar', 'link' ), $this->directory );
		$this->assertFalse( $tar->open( $this->directory . '/links.tar' ) );
		Process::run( array( '/usr/bin/tar', '--transform=s|source|../outside|', '-cf', 'unsafe.tar', 'source' ), $this->directory );
		$this->assertFalse( $tar->open( $this->directory . '/unsafe.tar' ) );
	}

	public function test_tar_header_corruption_and_truncated_zstandard_are_rejected(): void {
		file_put_contents( $this->directory . '/source', str_repeat( 'a', 1024 ) );
		$path = $this->directory . '/source.tar';
		$tar  = new Tar();
		$this->assertTrue( $tar->open( $path ) );
		$tar->addFile( $this->directory . '/source', 'source' );
		$tar->close();
		$compressed = Compression::compress_file( $path );
		$handle     = fopen( $compressed, 'r+b' );
		ftruncate( $handle, filesize( $compressed ) - 1 );
		fclose( $handle );
		$this->assertFalse( $tar->open( $compressed ) );
		$this->assertSame( array(), glob( $this->directory . '/zstd-read-*' ) );
		$handle = fopen( $path, 'r+b' );
		fwrite( $handle, 'bad header' );
		fclose( $handle );
		$this->assertFalse( $tar->open( $path ) );
	}
}
