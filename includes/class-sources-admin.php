<?php
/** Settings, one-off table choices, and restore mappings in the existing wizard. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
class BackupPlus_Sources_Admin {
	public static function register() {
		add_action( 'wp_ajax_backupplus_source_tables', array( __CLASS__, 'tables_ajax' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action(
			'backupplus_restore_form_wpcore',
			function () {
				echo '<label><input type="checkbox" name="backupplus_restorer_wpcore_includewpconfig" value="1"> ' . esc_html__( 'Replace wp-config.php', 'backupplus' ) . '</label>';
			}
		);
	}
	public static function assets() {
		wp_enqueue_style( 'backupplus-sources', BACKUPPLUS_URL . '/css/backupplus-sources.css', array( 'backupplus-admin-css' ), '1' );
		wp_enqueue_script( 'backupplus-sources', BACKUPPLUS_URL . '/js/backupplus-sources.js', array( 'jquery' ), '1', true );
		wp_localize_script(
			'backupplus-sources',
			'backupplus_sources',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'backupplus_source_tables' ),
				'error' => __( 'Could not discover tables. Check the connection details.', 'backupplus' ),
				'empty' => __( 'Choose at least one table in selected mode.', 'backupplus' ),
			)
		);
	}
	public static function tables_ajax() {
		check_ajax_referer( 'backupplus_source_tables', 'nonce' );
		if ( ! BackupPlus_Options::user_can_manage() ) { wp_send_json_error( 'Not authorized.', 403 ); }
		try {
			global $wpdb;
			$info = isset( $_POST['connection'] ) ? wp_unslash( $_POST['connection'] ) : null;
			if ( ! empty( $_POST['saved'] ) ) {
				$connections = BackupPlus_Backup_Sources::connections();
				$id          = absint( $_POST['saved'] );
				if ( ! isset( $connections[ $id ] ) ) { throw new RuntimeException( 'Unknown connection.' ); }
				$info = $connections[ $id ];
			}
			$db     = null === $info ? $wpdb : BackupPlus_Backup_Sources::connect( $info );
			$tables = $db->get_col( 'SHOW TABLES' );
			if ( $db->last_error ) { throw new RuntimeException( 'Could not discover tables.' ); }
			wp_send_json_success( array( 'tables' => $tables ) );
		} catch ( Exception $e ) { wp_send_json_error( 'Could not connect or discover tables. Check the connection details and permissions.' ); }
	}
	public static function table_selector( $id, $entry, $manual = false, $tables = array() ) {
		$name  = $manual ? '' : 'backupplus_database_selection[' . $id . ']';
		$entry = array_merge(
			array(
				'enabled' => true,
				'mode'    => 'all',
				'tables'  => array(),
			),
			$entry
		);
		echo '<fieldset class="bp-table-selector" data-database="' . esc_attr( $id ) . '" data-manual="' . ( $manual ? '1' : '0' ) . '"><div class="bp-table-controls">';
		echo '<input type="hidden" name="' . esc_attr( $name . '[enabled]' ) . '" value="0"><label><input class="bp-db-enabled" type="checkbox" name="' . esc_attr( $name . '[enabled]' ) . '" value="1" ' . checked( ! empty( $entry['enabled'] ), true, false ) . '> ' . esc_html__( 'Enabled', 'backupplus' ) . '</label> ';
		echo '<select class="bp-table-mode" aria-label="' . esc_attr__( 'Tables to back up', 'backupplus' ) . '" name="' . esc_attr( $name . '[mode]' ) . '"><option value="all" ' . selected( $entry['mode'], 'all', false ) . '>' . esc_html__( 'All eligible tables', 'backupplus' ) . '</option><option value="selected" ' . selected( $entry['mode'], 'selected', false ) . '>' . esc_html__( 'Only selected tables', 'backupplus' ) . '</option></select> ';
		echo '<button type="button" class="button bp-discover">' . esc_html__( 'Test connection / refresh tables', 'backupplus' ) . '</button></div><span class="bp-connection-status" role="status"></span>';
		echo '<div class="bp-table-choices"><p class="bp-table-tools"><input class="bp-table-search" type="search" aria-label="' . esc_attr__( 'Search tables', 'backupplus' ) . '" placeholder="' . esc_attr__( 'Search tables', 'backupplus' ) . '"> <button type="button" class="button bp-select-tables" data-check="1">' . esc_html__( 'Select all', 'backupplus' ) . '</button> <button type="button" class="button bp-select-tables" data-check="0">' . esc_html__( 'Deselect all', 'backupplus' ) . '</button></p><div class="bp-table-list">';
		foreach ( array_unique( array_merge( $tables, $entry['tables'] ) ) as $table ) {
			echo '<label class="bp-table-label"><input type="checkbox" class="bp-table" name="' . esc_attr( $name . '[tables][]' ) . '" value="' . esc_attr( $table ) . '" ' . checked( in_array( $table, $entry['tables'], true ), true, false ) . '> ' . esc_html( $table ) . '</label>';
		}
		echo '</div></div></fieldset>';
	}
	public static function database_settings( $manual = false ) {
		global $wpdb;
		$selection   = (array) BackupPlus_Options::get_backupplus_option( 'backupplus_database_selection', array() );
		$connections = BackupPlus_Backup_Sources::connections();
		echo '<div class="bp-databases" data-manual="' . ( $manual ? '1' : '0' ) . '"><div class="bp-wordpress-database"><h4>' . esc_html__( 'WordPress database', 'backupplus' ) . '</h4>';
		self::table_selector( 'wp', isset( $selection['wp'] ) ? $selection['wp'] : array(), $manual, $wpdb->get_col( 'SHOW TABLES' ) );
		if ( ! $manual ) {
			echo '<input type="hidden" name="backupplus_backupdb_nonwp" value="0"><label class="bp-non-wp-tables"><input type="checkbox" name="backupplus_backupdb_nonwp" value="1" ' . checked( BackupPlus_Options::get_backupplus_option( 'backupplus_backupdb_nonwp', false ), true, false ) . '> ' . esc_html__( 'Include non-WordPress tables in All eligible tables', 'backupplus' ) . '</label>';
		}
		echo '<p class="description bp-selection-help">' . esc_html__( 'Selected mode uses exact table names, including non-WordPress tables. Only the chosen tables are backed up; related tables are not added automatically.', 'backupplus' ) . '</p></div><div class="bp-external-connections">';
		foreach ( $connections as $id => $info ) { self::connection_editor( $id, $info, isset( $selection[ $id ] ) ? $selection[ $id ] : array( 'enabled' => $info['enabled'] ), $manual ); }
		echo '</div>';
		if ( ! $manual ) {
			$next = max(
				array_merge(
					array( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_database_next_id', 1 ) ),
					array_map(
						function ( $id ) {
							return $id + 1;
						},
						array_keys( $connections )
					)
				)
			);
			echo '<input type="hidden" name="backupplus_extradbs[_present]" value="1"><input class="bp-next-db-id" type="hidden" name="backupplus_database_next_id" value="' . esc_attr( $next ) . '"><button type="button" class="button bp-add-database">' . esc_html__( 'Add database connection', 'backupplus' ) . '</button><template class="bp-connection-template">';
			self::connection_editor( 'NEWID', array(), array(), false );
			echo '</template>';
		}
		echo '</div>';
	}
	public static function connection_editor( $id, $info, $entry, $manual ) {
		echo '<div class="bp-connection" data-id="' . esc_attr( $id ) . '"><div class="bp-connection-header"><h4>' . esc_html__( 'External database', 'backupplus' ) . ' ' . esc_html( $id ) . '</h4>';
		if ( ! $manual ) { echo '<button type="button" class="button bp-remove-database">' . esc_html__( 'Remove connection', 'backupplus' ) . '</button>'; }
		echo '</div>';
		if ( ! $manual ) {
			echo '<div class="bp-connection-fields">';
			foreach ( array(
				'host'   => __( 'Host', 'backupplus' ),
				'name'   => __( 'Database name', 'backupplus' ),
				'user'   => __( 'Username', 'backupplus' ),
				'pass'   => __( 'Password', 'backupplus' ),
				'prefix' => __( 'Eligible table prefix', 'backupplus' ),
			) as $field => $label ) {
				$input_id = 'bp-db-' . $id . '-' . $field;
				echo '<div class="bp-connection-field"><label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label><div><input id="' . esc_attr( $input_id ) . '" data-field="' . esc_attr( $field ) . '" type="' . ( 'pass' === $field ? 'password' : 'text' ) . '" name="backupplus_extradbs[' . esc_attr( $id ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( isset( $info[ $field ] ) ? $info[ $field ] : '' ) . '" autocomplete="off"';
				if ( 'host' === $field || 'prefix' === $field ) { echo ' aria-describedby="' . esc_attr( $input_id . '-help' ) . '"'; }
				echo '>';
				if ( 'host' === $field ) { echo '<p id="' . esc_attr( $input_id . '-help' ) . '" class="description">' . esc_html__( 'Include a port or socket if needed.', 'backupplus' ) . '</p>'; }
				if ( 'prefix' === $field ) { echo '<p id="' . esc_attr( $input_id . '-help' ) . '" class="description">' . esc_html__( 'Optional. Applies to All eligible tables.', 'backupplus' ) . '</p>'; }
				echo '</div></div>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html( $info['host'] . '/' . $info['name'] ) . '</p>';
			// Refresh saved connections server-side; credentials never enter the one-off form.
			echo '<input class="bp-saved-connection" type="hidden" value="' . esc_attr( $id ) . '">';
		}
		self::table_selector( $id, $entry, $manual );
		echo '</div>';
	}

	public static function file_settings() {
		echo '<p><input type="hidden" name="backupplus_include_more" value="0"><label><input type="checkbox" name="backupplus_include_more" value="1" ' . checked( BackupPlus_Options::get_backupplus_option( 'backupplus_include_more', false ), true, false ) . '> ' . esc_html__( 'Additional files', 'backupplus' ) . '</label></p><p>' . esc_html__( 'Absolute local file or directory paths, one per line. Directories restore by merging their contents.', 'backupplus' ) . '</p><textarea name="backupplus_include_more_path" rows="5" class="large-text">' . esc_textarea( implode( "\n", BackupPlus_Backup_Sources::paths( BackupPlus_Options::get_backupplus_option( 'backupplus_include_more_path', array() ) ) ) ) . '</textarea>';
		foreach ( array(
			'wpcore' => 'WordPress core exclusions',
			'more'   => 'Additional file exclusions',
		) as $entity => $label ) {
			echo '<p><label>' . esc_html( $label ) . ' <input class="large-text" name="backupplus_include_' . esc_attr( $entity ) . '_exclude" value="' . esc_attr( BackupPlus_Options::get_backupplus_option( 'backupplus_include_' . $entity . '_exclude', '' ) ) . '"></label></p>';
		}
	}
	public static function restore_fields( $id, $info ) {
		if ( BackupPlus_Backup_Sources::custom( $id ) ) {
			echo '<p><label>' . esc_html__( 'Absolute destination', 'backupplus' ) . ' <input class="large-text" name="backupplus_source_destinations[' . esc_attr( $id ) . ']" value="' . esc_attr( $info['path'] ) . '"></label></p>';
		} elseif ( preg_match( '/^db[1-9][0-9]*$/D', $id ) ) {
			echo '<p><label>' . esc_html__( 'Restore into existing database', 'backupplus' ) . ' <select name="backupplus_external_targets[' . esc_attr( $id ) . '][saved]"><option value="">' . esc_html__( 'Enter a connection below', 'backupplus' ) . '</option>';
			foreach ( BackupPlus_Backup_Sources::connections() as $key => $connection ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $key . ': ' . $connection['host'] . '/' . $connection['name'] ) . '</option>'; }
			echo '</select></label></p>';
			foreach ( array(
				'host' => 'Host',
				'name' => 'Existing database name',
				'user' => 'Username',
				'pass' => 'Password',
			) as $field => $label ) {
				echo '<p><label>' . esc_html( $label ) . ' <input autocomplete="off" type="' . ( 'pass' === $field ? 'password' : 'text' ) . '" name="backupplus_external_targets[' . esc_attr( $id ) . '][connection][' . esc_attr( $field ) . ']"></label></p>';
			}
		}
	}
}
