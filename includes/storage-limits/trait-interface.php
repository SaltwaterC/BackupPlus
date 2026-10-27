<?php
/** Settings and administrator status display. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
trait BackupPlus_Storage_Limits_Interface {
	public static function settings_html() {
		$limits = self::limits();
		$html   = '<tr><th scope="row">' . esc_html__( 'Storage limits', 'backupplus' ) . '</th><td><div class="backupplus-storage-limits-fields"><input type="hidden" name="backupplus_storage_limits[_unit]" value="GB"><table class="widefat" id="backupplus-storage-limits"><thead><tr><th>' . esc_html__( 'Destination', 'backupplus' ) . '</th><th>' . esc_html__( 'Disk alert threshold (GB)', 'backupplus' ) . '</th><th>' . esc_html__( 'Disk quota (GB)', 'backupplus' ) . '</th><th>' . esc_html__( 'Old backups are removed by', 'backupplus' ) . '</th></tr></thead><tbody>';
		foreach ( self::destinations() as $key => $destination ) {
			$service = $destination['service'];
			$id      = $destination['instance'];
			$html   .= '<tr><th scope="row">' . esc_html( self::label( $service, $id ) ) . '</th>';
			foreach ( array( 'alert', 'quota' ) as $field ) {
				$value = empty( $limits[ $service ][ $id ][ $field ] ) ? '' : $limits[ $service ][ $id ][ $field ] / self::GB;
				$name  = self::OPTION . '[' . $service . '][' . ( '' === $id ? '__default' : $id ) . '][' . $field . ']';
				$html .= '<td><input type="number" min="0" step="any" aria-label="' . esc_attr( self::label( $service, $id ) . ' ' . ( 'alert' === $field ? __( 'Disk alert threshold (GB)', 'backupplus' ) : __( 'Disk quota (GB)', 'backupplus' ) ) ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td>';
			}
			$by_count = sprintf(
				/* translators: 1: Number of file backup chains kept, 2: Number of database backup chains kept */
				__( 'The Keep counts: the newest %1$d file and %2$d database chains', 'backupplus' ),
				max( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ) ),
				max( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ) ) )
			);
			$by_quota = __( 'The quota: the oldest chains go first when it is exceeded', 'backupplus' );
			$html    .= '<td class="backupplus-storage-limits-retention" data-by-count="' . esc_attr( $by_count ) . '" data-by-quota="' . esc_attr( $by_quota ) . '">' . esc_html( empty( $limits[ $service ][ $id ]['quota'] ) ? $by_count : $by_quota ) . '</td></tr>';
		}
		return $html . '</tbody></table><p>' . esc_html__( 'Archive sizes in GB (1 GB = 1,000,000,000 bytes). Blank or zero disables a limit. Each destination is trimmed on its own: one with a quota is trimmed by size instead of by the Keep counts above, one without a quota keeps using the Keep counts. Either way the newest restorable chain of every source is kept. A local quota keeps local copies after upload. Save new remote destinations first to configure their limits here.', 'backupplus' ) . '</p><p>' . esc_html__( 'Independent notifications at 80% and 100% go to the site administrator and appear in BackupPlus. Protected backups can exceed a quota; backups continue. Quota critical emails are limited to one per day.', 'backupplus' ) . '</p></div></td></tr>';
	}

	public static function summary_html() {
		if ( ! self::enabled() ) { return ''; }
		$status = get_option( self::STATUS, array() );
		$limits = self::limits();
		$html   = '<div id="backupplus-storage-status" class="postbox"><h3>' . esc_html__( 'Storage usage and alerts', 'backupplus' ) . '</h3>';
		foreach ( self::destinations() as $key => $destination ) {
			$config = isset( $limits[ $destination['service'] ][ $destination['instance'] ] ) ? $limits[ $destination['service'] ][ $destination['instance'] ] : array(
				'alert' => 0,
				'quota' => 0,
			);
			if ( ! $config['alert'] && ! $config['quota'] ) { continue; }
			$entry    = isset( $status[ $key ] ) ? $status[ $key ] : array();
			$level    = max( isset( $entry['alert']['level'] ) ? $entry['alert']['level'] : 0, isset( $entry['quota']['level'] ) ? $entry['quota']['level'] : 0 );
			$severity = 100 === $level ? __( 'Critical', 'backupplus' ) : ( 80 === $level ? __( 'Warning', 'backupplus' ) : __( 'OK', 'backupplus' ) );
			$usage    = isset( $entry['usage'] ) ? number_format_i18n( $entry['usage'] / self::GB, 3 ) . ' GB' : __( 'Unavailable', 'backupplus' );
			$html    .= '<p><strong>' . esc_html( self::label( $destination['service'], $destination['instance'] ) ) . '</strong>: ' . esc_html( $usage . ' — ' . $severity );
			if ( empty( $entry['available'] ) ) { $html .= ' (' . esc_html__( 'measurement unavailable/stale', 'backupplus' ) . ')'; }
			foreach ( array(
				'alert' => __( 'Alert', 'backupplus' ),
				'quota' => __( 'Quota', 'backupplus' ),
			) as $kind => $label ) {
				if ( $config[ $kind ] ) { $html .= ' · ' . esc_html( $label . ': ' . number_format_i18n( $config[ $kind ] / self::GB, 3 ) . ' GB' ); }
			}
			if ( ! empty( $entry['measured'] ) ) { $html .= '<br>' . esc_html__( 'Last measurement:', 'backupplus' ) . ' ' . esc_html( wp_date( 'Y-m-d H:i:s', $entry['measured'] ) ); }
			if ( ! empty( $entry['outcome'] ) ) { $html .= '<br>' . esc_html( $entry['outcome'] ); }
			if ( ! empty( $entry['notification'] ) ) { $html .= '<br>' . esc_html__( 'Last alert:', 'backupplus' ) . ' ' . esc_html( $entry['notification'] ); }
			$html .= '</p>';
		}
		return $html . '</div>';
	}

	public static function file_available( $set, $file, $key ) {
		return empty( $set['storage_removed'][ $key ] ) && empty( $set['storage_deleting'][ $key ] ) && empty( $set['storage_deleted_files'][ $key ][ $file ] ) && empty( $set['storage_delete_queue'][ $key ][ $file ] );
	}

	/** Presentation only: retain the durable history and inventories for other copies and retries. */
	public static function visible_history( $history ) {
		global $backupplus;
		foreach ( $history as $time => &$set ) {
			if ( empty( $set['storage_removed'] ) && empty( $set['storage_deleted_files'] ) && empty( $set['storage_deleting'] ) ) { continue; }
			$files   = self::artifacts( $set );
			$sources = array();
			foreach ( $files as $file => $info ) {
				$available = is_file( $backupplus->backups_dir_location() . '/' . $file ) && empty( $set['storage_deleting']['local:'] );
				foreach ( isset( $set['service'] ) ? (array) $set['service'] : array() as $service ) {
					if ( ! $service || 'none' === $service ) { continue; }
					$ids = isset( $set['service_instance_ids'][ $service ] ) ? $set['service_instance_ids'][ $service ] : array( '' );
					foreach ( $ids as $id ) { if ( self::file_available( $set, $file, $service . ':' . $id ) ) { $available = true; }
					}
				}
				$source             = $info['source'];
				$sources[ $source ] = ( ! isset( $sources[ $source ] ) || $sources[ $source ] ) && $available;
			}
			foreach ( $sources as $source => $available ) { if ( ! $available ) { unset( $set[ $source ] ); }
			}
			if ( ! in_array( true, $sources, true ) ) { unset( $history[ $time ] ); }
		}
		unset( $set );
		return $history;
	}

	/** Hide destinations being removed from restore discovery without discarding shared history. */
	public static function discovery( $history ) {
		foreach ( $history as &$set ) {
			if ( isset( $set['storage_original_destinations'] ) ) {
				$set = array_replace( $set, $set['storage_original_destinations'] );
			} elseif ( ! empty( $set['storage_removed'] ) || ! empty( $set['storage_deleting'] ) ) {
				$set['storage_original_destinations'] = array_intersect_key( $set, array_flip( array( 'service', 'service_instance_ids' ) ) );
			}
			$removed = array_merge( isset( $set['storage_removed'] ) ? $set['storage_removed'] : array(), isset( $set['storage_deleting'] ) ? $set['storage_deleting'] : array() );
			foreach ( $removed as $key => $unused ) {
				list( $service, $id ) = explode( ':', $key, 2 );
				if ( 'local' === $service ) { continue; }
				if ( isset( $set['service_instance_ids'][ $service ] ) ) {
					$set['service_instance_ids'][ $service ] = array_values(
						array_filter(
							$set['service_instance_ids'][ $service ],
							function ( $instance ) use ( $id ) {
								return (string) $instance !== $id;
							}
						)
					);
					if ( $set['service_instance_ids'][ $service ] ) { continue; }
				}
				$set['service'] = array_values( array_diff( (array) $set['service'], array( $service ) ) );
			}
		}
		unset( $set );
		return $history;
	}
}
