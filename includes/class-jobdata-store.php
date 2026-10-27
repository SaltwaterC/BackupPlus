<?php
/** Immutable archive-list snapshots keep progress writes independent of chain length. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_Jobdata_Store {
	const MARKER = '__backupplus_jobpart_v1';
	const PREFIX = 'backupplus_jobpart_';
	const FIELDS = array( 'previous_backup_files_array', 'backup_files_array' );

	public static function register() {
		add_action( 'delete_site_option', array( __CLASS__, 'deleted' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'deleted' ) );
		add_action( 'backupplus_clean_temporary_files', array( __CLASS__, 'collect_orphans' ) );
	}
	private static function prefix( $job ) {
		return self::PREFIX . substr( hash( 'sha256', $job ), 0, 32 ) . '_'; }

	/** Write immutable versions first, then let the caller atomically publish their references. */
	public static function pack( $job, $data, $previous ) {
		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_array( $data[ $field ] ) ) { continue; }
			$raw = serialize( $data[ $field ] );
			if ( strlen( $raw ) < 4096 || strlen( $raw ) > 67108864 || ! function_exists( 'gzdeflate' ) ) { continue; }
			$hash      = hash( 'sha256', $raw );
			$reference = array( self::MARKER => $hash );
			if ( ! isset( $previous[ $field ] ) || $previous[ $field ] !== $reference ) {
				$name   = self::prefix( $job ) . $hash;
				$stored = get_site_option( $name, false );
				if ( false === $stored ) {
					$compressed = gzdeflate( $raw, 6 );
					if ( false === $compressed ) { throw new RuntimeException( 'Could not encode the backup archive-list checkpoint.' ); }
					$stored = array(
						'job'        => $job,
						'created_at' => time(),
						'length'     => strlen( $raw ),
						'data'       => base64_encode( $compressed ),
					);
					update_site_option( $name, $stored );
					if ( get_site_option( $name, false ) !== $stored ) { throw new RuntimeException( 'Could not persist the backup archive-list checkpoint.' ); }
				}
				self::decode( $job, $hash, $stored );
			}
			$data[ $field ] = $reference;
		}
		return $data;
	}

	public static function unpack( $job, $data ) {
		if ( ! is_array( $data ) ) { return $data; }
		foreach ( self::FIELDS as $field ) {
			if ( isset( $data[ $field ][ self::MARKER ] ) ) {
				$hash = $data[ $field ][ self::MARKER ];
				if ( ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) ) { throw new RuntimeException( 'Invalid backup archive-list checkpoint reference.' ); }
				$data[ $field ] = self::decode( $job, $hash, get_site_option( self::prefix( $job ) . $hash, false ) );
			}
		}
		return $data;
	}

	private static function decode( $job, $hash, $stored ) {
		if ( ! is_array( $stored ) || ! isset( $stored['job'], $stored['length'], $stored['data'] ) || $job !== $stored['job'] || ! is_int( $stored['length'] ) || $stored['length'] < 1 || $stored['length'] > 67108864 || ! is_string( $stored['data'] ) ) { throw new RuntimeException( 'The backup archive-list checkpoint is unavailable.' ); }
		$compressed = base64_decode( $stored['data'], true );
		$raw        = false === $compressed ? false : @gzinflate( $compressed, $stored['length'] );
		if ( false === $raw || strlen( $raw ) !== $stored['length'] || ! hash_equals( $hash, hash( 'sha256', $raw ) ) ) { throw new RuntimeException( 'The backup archive-list checkpoint failed verification.' ); }
		$value = unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || serialize( $value ) !== $raw ) { throw new RuntimeException( 'Invalid backup archive-list checkpoint.' ); }
		return $value;
	}

	private static function names( $prefix, $network = null ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		return (array) ( is_multisite()
			? BackupPlus_SQL::wpdb()->column( 'SELECT meta_key FROM %i WHERE meta_key LIKE ? AND site_id = ?', array( $wpdb->sitemeta ), array( $like, null === $network ? (int) get_current_network_id() : (int) $network ) )
			: BackupPlus_SQL::wpdb()->column( 'SELECT option_name FROM %i WHERE option_name LIKE ?', array( $wpdb->options ), array( $like ) ) );
	}

	/** A deleted job no longer needs any checkpoint versions; other jobs retain theirs. */
	public static function deleted( $option, $network = null ) {
		$prefix = 'backupplus_jobdata_';
		if ( 0 !== strpos( $option, $prefix ) || strlen( $option ) <= strlen( $prefix ) ) { return; }
		$parts = self::prefix( substr( $option, strlen( $prefix ) ) );
		foreach ( self::names( $parts, $network ) as $name ) {
			if ( 0 !== strpos( $name, $parts ) ) { continue; }
			if ( null !== $network && is_multisite() ) { delete_network_option( $network, $name ); }
			else { delete_site_option( $name ); }
		}
	}

	/** Failed first writes can leave unreferenced parts without a parent job to expire. */
	public static function collect_orphans() {
		foreach ( self::names( self::PREFIX ) as $name ) {
			$part = get_site_option( $name, false );
			if ( ! is_array( $part ) || ! isset( $part['job'], $part['created_at'] ) || ! is_string( $part['job'] ) || 0 !== strpos( $name, self::prefix( $part['job'] ) ) || $part['created_at'] > time() - 86400 ) { continue; }
			if ( false === get_site_option( 'backupplus_jobdata_' . $part['job'], false ) ) { delete_site_option( $name ); }
		}
	}
}
