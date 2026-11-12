<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/**
 * The secret that lets the restore page continue a restore after the database restore has logged the administrator out (BPS-11).
 *
 * backupplus_ajaxrestore_continue is open to unauthenticated requests, because restoring the users table ends the session that
 * started the restore. The job ID is not a secret: it names log files and archives. The restore page instead receives a token of
 * 32 random bytes, issued only to a manager. The site keeps the SHA-256 hash of the token with the job ID and an expiry time, so
 * the stored value cannot be replayed, and a continuation is accepted only for the restore in progress, before the expiry. A new
 * token replaces the previous one; the "Unfinished restoration" notice issues one when a manager continues a restore.
 *
 * The option is a site option like backupplus_restore_in_progress, and is carried over a database restore with it.
 */
class BackupPlus_Restore_Continuation {

	const OPTION = 'backupplus_restore_continuation';

	/** The same period as the "Unfinished restoration" notice (check_restore_progress()). */
	const LIFETIME = 86400;

	const TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/D';

	const JOB_PATTERN = '/\A[0-9a-f]+\z/D';

	/**
	 * Issue a token for continuing the given restore job, replacing any earlier one.
	 *
	 * @param String $job_id - the restore job ID
	 *
	 * @return String - the token, 64 hexadecimal characters; only its hash is stored
	 */
	public static function issue( $job_id ) {
		if ( ! is_string( $job_id ) || ! preg_match( self::JOB_PATTERN, $job_id ) ) {
			throw new InvalidArgumentException( 'Invalid restore job ID.' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		update_site_option(
			self::OPTION,
			array(
				'job_id'  => $job_id,
				'hash'    => hash( 'sha256', $token ),
				'expires' => time() + self::LIFETIME,
			)
		);
		return $token;
	}

	/**
	 * Whether a continuation request may resume the given job: it is the restore in progress and the token is the one issued for
	 * it, before its expiry.
	 *
	 * @param Mixed $job_id - the job ID from the request
	 * @param Mixed $token  - the token from the request
	 *
	 * @return Boolean
	 */
	public static function verify( $job_id, $token ) {
		if ( ! is_string( $job_id ) || ! preg_match( self::JOB_PATTERN, $job_id ) || ! is_string( $token ) || ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return false;
		}
		$in_progress = get_site_option( 'backupplus_restore_in_progress' );
		$stored      = get_site_option( self::OPTION );
		if ( ! is_string( $in_progress ) || '' === $in_progress || ! is_array( $stored ) || ! isset( $stored['job_id'], $stored['hash'], $stored['expires'] ) || ! is_string( $stored['job_id'] ) || ! is_string( $stored['hash'] ) ) {
			return false;
		}
		if ( time() >= (int) $stored['expires'] ) {
			return false;
		}
		// Every comparison runs, so that the time taken does not say which one failed
		$matches = hash_equals( $in_progress, $job_id );
		$matches = hash_equals( $stored['job_id'], $job_id ) && $matches;
		$matches = hash_equals( $stored['hash'], hash( 'sha256', $token ) ) && $matches;
		return $matches;
	}

	/**
	 * Remove the token, when the restore it was issued for is finished or dismissed.
	 *
	 * @param String|null $job_id - remove it only if it was issued for this job; null removes any token
	 */
	public static function clear( $job_id = null ) {
		if ( null !== $job_id ) {
			$stored = get_site_option( self::OPTION );
			if ( ! is_array( $stored ) || ! isset( $stored['job_id'] ) || $stored['job_id'] !== $job_id ) {
				return;
			}
		}
		delete_site_option( self::OPTION );
	}
}
