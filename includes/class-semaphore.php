<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
/**
 * Semaphore Lock Management
 * Adapted from WP Social under the GPL - thanks to Alex King (https://github.com/crowdfavorite/wp-social)
 */
class BackupPlus_Semaphore {

	/**
	 * Initializes the semaphore object.
	 *
	 * @static
	 * @return BackupPlus_Semaphore
	 */
	public static function factory() {
		return new self();
	}

	/**
	 * Lock Broke
	 *
	 * @var boolean
	 */
	protected $lock_broke = false;

	public $lock_name = 'lock';

	/**
	 * Attempts to start the lock. If the rename works, the lock is started.
	 *
	 * @return bool
	 */
	public function lock() {
		global $wpdb, $backupplus;

		$sql = BackupPlus_SQL::wpdb();
		// Attempt to set the lock
		$affected = $sql->execute( 'UPDATE %i SET option_name = ? WHERE option_name = ?', array( $wpdb->options ), array( 'backupplus_locked_' . $this->lock_name, 'backupplus_unlocked_' . $this->lock_name ) );

		if ( '0' == $affected && ! $this->stuck_check() ) {
			$backupplus->log( 'Semaphore lock (' . $this->lock_name . ', ' . $wpdb->options . ') failed (line ' . __LINE__ . ')' );
			return false;
		}

		// Check to see if all processes are complete
		$affected = $sql->execute( "UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = ? AND option_value = '0'", array( $wpdb->options ), array( 'backupplus_semaphore_' . $this->lock_name ) );

		if ( '1' != $affected ) {
			if ( ! $this->stuck_check() ) {
				$backupplus->log( 'Semaphore lock (' . $this->lock_name . ', ' . $wpdb->options . ') failed (line ' . __LINE__ . ')' );
				return false;
			}

			// Reset the semaphore to 1
			$sql->execute( "UPDATE %i SET option_value = '1' WHERE option_name = ?", array( $wpdb->options ), array( 'backupplus_semaphore_' . $this->lock_name ) );

			$backupplus->log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') reset to 1' );
		}

		// Set the lock time
		$sql->execute( 'UPDATE %i SET option_value = ? WHERE option_name = ?', array( $wpdb->options ), array( current_time( 'mysql', 1 ), 'backupplus_last_lock_time_' . $this->lock_name ) );
		$backupplus->log( 'Set semaphore last lock (' . $this->lock_name . ') time to ' . current_time( 'mysql', 1 ) );

		$backupplus->log( 'Semaphore lock (' . $this->lock_name . ') complete' );
		return true;
	}

	public static function ensure_semaphore_exists( $semaphore ) {
		// Make sure the options for semaphores exist
		global $wpdb, $backupplus;

		$sql     = BackupPlus_SQL::wpdb();
		$names   = array( 'backupplus_locked_' . $semaphore, 'backupplus_unlocked_' . $semaphore, 'backupplus_last_lock_time_' . $semaphore, 'backupplus_semaphore_' . $semaphore );
		$results = $sql->rows( 'SELECT option_id FROM %i WHERE option_name IN (?)', array( $wpdb->options ), array( $names ) );

		if ( ! is_array( $results ) || count( $results ) < 3 ) {

			if ( is_array( $results ) && count( $results ) > 0 ) {
				$backupplus->log( "Semaphore ($semaphore, " . $wpdb->options . ') in an impossible/broken state - fixing (' . count( $results ) . ')' );
			} else {
				$backupplus->log( "Semaphore ($semaphore, " . $wpdb->options . ') being initialised' );
			}

			$sql->execute( 'DELETE FROM %i WHERE option_name IN (?)', array( $wpdb->options ), array( $names ) );

			$sql->execute(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
				array( $wpdb->options ),
				array( 'backupplus_unlocked_' . $semaphore, '1', 'no', 'backupplus_last_lock_time_' . $semaphore, current_time( 'mysql', 1 ), 'no', 'backupplus_semaphore_' . $semaphore, '0', 'no' )
			);
		}
	}

	/**
	 * Increment the semaphore.
	 *
	 * @param  array $filters
	 * @return BackupPlus_Semaphore
	 */
	public function increment( array $filters = array() ) {
		global $wpdb, $backupplus;

		if ( count( $filters ) ) {
			// Loop through all of the filters and increment the semaphore
			foreach ( $filters as $priority ) {
				for ( $i = 0, $j = count( $priority ); $i < $j; ++$i ) {
					$this->increment();
				}
			}
		} else {
			BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = ?', array( $wpdb->options ), array( 'backupplus_semaphore_' . $this->lock_name ) );
			$backupplus->log( 'Incremented the semaphore (' . $this->lock_name . ') by 1' );
		}

		return $this;
	}

	/**
	 * Decrements the semaphore.
	 *
	 * @return void
	 */
	public function decrement() {
		global $wpdb, $backupplus;

		BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) - 1 WHERE option_name = ? AND CAST(option_value AS UNSIGNED) > 0', array( $wpdb->options ), array( 'backupplus_semaphore_' . $this->lock_name ) );
		$backupplus->log( 'Decremented the semaphore (' . $this->lock_name . ') by 1' );
	}

	/**
	 * Unlocks the process.
	 *
	 * @return bool
	 */
	public function unlock() {
		global $wpdb, $backupplus;

		// Decrement for the master process.
		$this->decrement();

		$result = BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET option_name = ? WHERE option_name = ?', array( $wpdb->options ), array( 'backupplus_unlocked_' . $this->lock_name, 'backupplus_locked_' . $this->lock_name ) );

		if ( '1' == $result ) {
			$backupplus->log( 'Semaphore (' . $this->lock_name . ') unlocked' );
			return true;
		}

		$backupplus->log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') still locked (' . $result . ')' );
		return false;
	}

	/**
	 * Attempts to jiggle the stuck lock loose.
	 *
	 * @return bool
	 */
	private function stuck_check() {
		global $wpdb, $backupplus;

		// Check to see if we already broke the lock.
		if ( $this->lock_broke ) {
			return true;
		}

		$current_time         = current_time( 'mysql', 1 );
		$three_minutes_before = gmdate( 'Y-m-d H:i:s', time() - ( defined( 'BACKUPPLUS_SEMAPHORE_LOCK_WAIT' ) ? BACKUPPLUS_SEMAPHORE_LOCK_WAIT : 180 ) );

		$affected = BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET option_value = ? WHERE option_name = ? AND option_value <= ?', array( $wpdb->options ), array( $current_time, 'backupplus_last_lock_time_' . $this->lock_name, $three_minutes_before ) );

		if ( '1' == $affected ) {
			$backupplus->log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') was stuck, set lock time to ' . $current_time );
			$this->lock_broke = true;
			return true;
		}

		// Check if lock is greater that 24 hours
		$last_lock_time = strtotime( BackupPlus_Options::get_backupplus_option( 'backupplus_last_lock_time_' . $this->lock_name, $current_time ) );
		$next_day       = strtotime( $current_time . ' +1 day' );
		if ( $last_lock_time > $next_day ) {
			$this->lock_broke = true;
			return true;
		}

		return false;
	}

	/**
	 * Remove a given lock from the options table in the database
	 *
	 * @return Boolean True if the lock has successfully been removed, false otherwise
	 */
	public function delete_lock() {
		global $wpdb;
		return (bool) BackupPlus_SQL::wpdb()->execute( 'DELETE FROM %i WHERE option_name = ?', array( $wpdb->options ), array( $this->lock_name ) );
	}
} // End BackupPlus_Semaphore
