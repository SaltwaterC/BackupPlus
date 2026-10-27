<?php
/** Pure recovery-set accounting and selection. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
trait BackupPlus_Storage_Limits_Policy {
	/** One history row already contains every increment of its full file backup. */
	public static function artifacts( $set ) {
		global $backupplus;
		$files    = array();
		$entities = array_merge( array( 'db' ), array_keys( $backupplus->get_backupable_file_entities( true ) ) );
		foreach ( $set as $entity => $value ) {
			if ( ! in_array( $entity, $entities, true ) && ! BackupPlus_Backup_Sources::custom( $entity ) && ! preg_match( '/\Adb[0-9]+\z/D', $entity ) ) { continue; }
			foreach ( (array) $value as $index => $name ) {
				if ( ! is_string( $name ) || basename( $name ) !== $name ) { continue; }
				$key            = $entity . ( $index ? $index : '' ) . '-size';
				$files[ $name ] = array(
					'size'   => isset( $set[ $key ] ) ? $set[ $key ] : null,
					'source' => $entity,
				);
			}
		}
		if ( ! empty( $set['pitr']['downloads'] ) ) {
			foreach ( $set['pitr']['downloads'] as $file ) { $files[ $file['name'] ] = array(
				'size'   => $file['size'],
				'source' => 'db',
			); }
		}
		return $files;
	}

	/** Newer incomplete sets never replace an older restorable source. */
	public static function recovery_sets( $history, $inventory, $destination, $pitr = array() ) {
		$sets   = array();
		$newest = array();
		$batch  = class_exists( 'BackupPlus_Pre_Update_Backups' ) ? BackupPlus_Pre_Update_Backups::instance()->batch() : array();
		krsort( $history );
		foreach ( $history as $time => $set ) {
			$files   = self::artifacts( $set );
			$present = array_intersect_key( $files, $inventory );
			if ( ! $present && empty( $set['storage_deleting'][ $destination ] ) ) { continue; }
			$protected = ! empty( $set['always_keep'] ) || ( isset( $set['native'] ) && ! $set['native'] );
			$protected = $protected || ( ! empty( $batch['backup_time'] ) && (int) $batch['backup_time'] === (int) $time && in_array( $batch['state'], array( 'queued', 'backing_up', 'ready', 'updating' ), true ) );
			$complete  = ! isset( $set['storage_complete'] ) || $set['storage_complete'];
			$complete  = $complete && ( empty( $set['incremental'] ) || ! empty( $set['incremental']['complete'] ) ) && ( empty( $set['pre_update'] ) || ! empty( $set['pre_update']['complete'] ) );
			$complete  = $complete && empty( $set['storage_deleting'][ $destination ] ) && empty( $set['storage_removed'][ $destination ] );
			$id        = isset( $set['pitr']['chain_id'] ) ? $set['pitr']['chain_id'] : null;
			if ( $id ) {
				$chain     = isset( $pitr['chains'][ $id ] ) ? $pitr['chains'][ $id ] : array();
				$complete  = $complete && isset( $set['pitr']['state'] ) && 'complete' === $set['pitr']['state'] && ! empty( $chain );
				$protected = $protected || ! empty( $chain['imported'] ) || $id === ( isset( $pitr['active'] ) ? $pitr['active'] : null ) || $id === ( isset( $pitr['restore_chain'] ) ? $pitr['restore_chain'] : null ) || in_array( $id, isset( $pitr['protected_previous'] ) ? $pitr['protected_previous'] : array(), true ) || ( ! empty( $pitr['pending']['entry']['manifest']['chain_id'] ) && $id === $pitr['pending']['entry']['manifest']['chain_id'] );
				if ( ! $chain ) { $protected = true; }
			}
			$hard_protected = $protected || ( ! $complete && empty( $set['storage_deleting'][ $destination ] ) && empty( $set['storage_removed'][ $destination ] ) );
			$newest_sources = array();
			$sources        = array();
			foreach ( $files as $name => $info ) {
				$source = $info['source'];
				if ( ! isset( $sources[ $source ] ) ) { $sources[ $source ] = $complete; }
				if ( ! isset( $inventory[ $name ] ) || ( null !== $info['size'] && (int) $info['size'] !== $inventory[ $name ] ) || $inventory[ $name ] < 1 ) { $sources[ $source ] = false; }
			}
			foreach ( $sources as $source => $restorable ) {
				if ( ! $restorable ) { continue; }
				$coverage_id   = 'db' === $source ? 'wp' : substr( $source, 2 );
				$coverage      = isset( $set['database_coverage'][ $coverage_id ] ) && 0 === strpos( $source, 'db' ) ? $set['database_coverage'][ $coverage_id ] : array();
				$coverage_keys = empty( $coverage['partial'] ) ? array( $source ) : array();
				foreach ( isset( $coverage['tables'] ) ? $coverage['tables'] : array() as $table ) { $coverage_keys[] = $source . ':table:' . $table; }
				if ( ! $coverage_keys ) { $coverage_keys[] = $source . ':partial'; }
				foreach ( $coverage_keys as $coverage_key ) {
					if ( ! isset( $newest[ $coverage_key ] ) ) {
						$newest[ $coverage_key ]   = $time;
						$newest_sources[ $source ] = true;
						$protected                 = true;
					}
				}
			}

			// An incomplete set is not an eviction candidate: it may belong to an interrupted job.
			if ( ! $complete && empty( $set['storage_deleting'][ $destination ] ) && empty( $set['storage_removed'][ $destination ] ) ) { $protected = true; }
			$sets[ $time ] = array(
				'files'          => $present,
				'protected'      => $protected,
				'hard_protected' => $hard_protected,
				'newest_sources' => $newest_sources,
				'pitr'           => $id,
				'sources'        => array_keys( $sources ),
				'pre_update'     => ! empty( $set['pre_update'] ),
				'imported'       => isset( $set['native'] ) && ! $set['native'],
			);
		}
		ksort( $sets );
		return $sets;
	}

	/** Inclusive 80% and 100% boundaries; a jump emits only the highest newly reached level. */
	public static function alert_transition( $previous, $usage, $limit, $quota, $now ) {
		$level    = ! $limit ? 0 : ( $usage >= $limit ? 100 : ( $usage >= ceil( $limit * 0.8 ) ? 80 : 0 ) );
		$previous = array_merge(
			array(
				'level'         => 0,
				'critical_sent' => 0,
				'limit'         => $limit,
			),
			$previous
		);
		$old      = $previous['limit'] === $limit ? $previous['level'] : 0;
		$notify   = $level > $old || ( $quota && 100 === $level && $now - $previous['critical_sent'] >= DAY_IN_SECONDS );
		if ( $quota && 100 === $level && $previous['critical_sent'] && $now - $previous['critical_sent'] < DAY_IN_SECONDS ) { $notify = false; }
		if ( $notify && 100 === $level ) { $previous['critical_sent'] = $now; }
		$previous['level'] = $level;
		$previous['limit'] = $limit;
		return array(
			'state'  => $previous,
			'notify' => $notify ? $level : 0,
		);
	}
}
