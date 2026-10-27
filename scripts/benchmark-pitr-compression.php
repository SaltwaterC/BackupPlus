<?php
/** Compare gzip and the production Zstandard codec on a raw log without publishing/changing backups. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) || ! is_file( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php scripts/benchmark-pitr-compression.php /path/to/raw-binlog\n" );
	exit( 1 );
}
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-pitr-process.php';
require_once dirname( __DIR__ ) . '/includes/class-pitr-compression.php';
if ( ! BackupPlus_PITR_Compression::zstd_binary() ) { fwrite( STDERR, "Install zstd to run this benchmark.\n" );
	exit( 1 ); }
$directory = BackupPlus_PITR_Process::directory( sys_get_temp_dir() . '/backupplus-codec-benchmark-' . bin2hex( random_bytes( 12 ) ) );
$input     = realpath( $argv[1] );
try {
	$source_hash = hash_file( 'sha256', $input );
	$size        = filesize( $input );
	$gzip        = $directory . '/log.gz';
	BackupPlus_PITR_Process::private_file( $gzip );
	$start = microtime( true );
	$in    = fopen( $input, 'rb' );
	$out   = gzopen( $gzip, 'wb6' );
	try {
		while ( ! feof( $in ) ) {
			$data = fread( $in, 1048576 );
			if ( false === $data ) { throw new RuntimeException( 'Could not read benchmark source.' ); }
			$offset = 0;
			while ( $offset < strlen( $data ) ) {
				$written = gzwrite( $out, substr( $data, $offset ) );
				if ( ! $written ) { throw new RuntimeException( 'Could not write benchmark archive.' ); }
				$offset += $written;
			}
		}
	} finally { fclose( $in );
		gzclose( $out ); }
	$gzip_seconds = microtime( true ) - $start;
	$compressed   = $directory . '/log.zst';
	$start        = microtime( true );
	BackupPlus_PITR_Compression::compress_zstd( $input, $compressed, $directory );
	$zstd_seconds = microtime( true ) - $start;
	$unpacked     = $directory . '/raw';
	$start        = microtime( true );
	BackupPlus_PITR_Compression::decompress_zstd( $compressed, $unpacked, $directory, $size );
	$decode_seconds = microtime( true ) - $start;
	if ( filesize( $input ) !== $size || ! hash_equals( $source_hash, hash_file( 'sha256', $input ) ) || ! hash_equals( $source_hash, hash_file( 'sha256', $unpacked ) ) ) { throw new RuntimeException( 'Source changed or lossless round-trip verification failed.' ); }
	echo json_encode(
		array(
			'source_bytes'             => $size,
			'gzip_bytes'               => filesize( $gzip ),
			'gzip_seconds'             => round( $gzip_seconds, 3 ),
			'zstd_bytes'               => filesize( $compressed ),
			'zstd_seconds'             => round( $zstd_seconds, 3 ),
			'zstd_decode_seconds'      => round( $decode_seconds, 3 ),
			'size_reduction_factor'    => round( filesize( $gzip ) / filesize( $compressed ), 2 ),
			'roundtrip_sha256_matches' => true,
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
} finally {
	foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $directory );
}
