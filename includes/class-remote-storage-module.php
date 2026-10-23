<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
require_once __DIR__ . '/class-remote-storage-http.php';

/** Provider-neutral integration between file clients and the backup/settings APIs. */
abstract class BackupPlus_Remote_Storage_Module extends BackupPlus_BackupModule {
	protected $fields       = array();
	protected $instructions = '';
	abstract protected function create_client( $options );
	public function get_supported_features() {
		return array( 'multi_options', 'config_templates', 'multi_storage', 'conditional_logic' ); }
	public function get_default_options() {
		$result = array();
		foreach ( $this->fields as $key => $field ) {
			$result[ $key ] = $field[1];
		}
		return $result;
	}
	public function get_input_option_mappings( $context = '' ) {
		$result = array();
		foreach ( $this->fields as $key => $field ) {
			$result[ $key ] = array(
				'default_value' => $field[1],
				'contexts'      => array( 'input', 'option' ),
			);
		}
		return $result;
	}
	protected function connect( $options = null ) {
		global $backupplus;
		if ( null === $options && $this->get_storage() ) {
			return $this->get_storage();
		}
		$saved   = null === $options;
		$options = $saved ? $this->get_options() : wp_parse_args( $options, $this->get_default_options() );
		foreach ( array( 'nossl', 'disableverify', 'useservercerts' ) as $key ) {
			if ( ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $saved && method_exists( $backupplus, 'get_job_option' ) ? $backupplus->get_job_option( 'backupplus_ssl_' . $key ) : BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_' . $key, false );
			}
		}
		$client = $this->create_client( $options );
		if ( $saved ) {
			$this->set_storage( $client );
		}
		return $client;
	}
	public function backup( $backup_array ) {
		global $backupplus;
		$success = true;
		foreach ( $backup_array as $file ) {
			try {
				if ( ! BackupPlus_Remote_Storage_Client::valid_filename( $file ) ) {
					throw new RuntimeException( 'Invalid backup filename.' );
				}
				$source = $backupplus->backups_dir_location() . '/' . $file;
				clearstatcache( true, $source );
				if ( ! is_file( $source ) || ! is_readable( $source ) ) {
					throw new RuntimeException( 'Cannot read the local archive.' );
				}
				$last_percent = -1;
				$this->connect()->upload(
					$file,
					$source,
					function ( $sent ) use ( $backupplus, $file, $source, &$last_percent ) {
						$size    = filesize( $source );
						$percent = $size ? min( 100, (int) floor( 100 * $sent / $size ) ) : 100;
						if ( $percent > $last_percent ) {
							$backupplus->record_uploaded_chunk( $percent, $file, $this->get_id() );
						}
						$last_percent = $percent;
					}
				);
				$backupplus->uploaded_file( $file );
			} catch ( Exception $error ) {
				$this->log( $error->getMessage(), 'error' );
				$success = false; }
		}
		return $success;
	}
	public function listfiles( $match = 'backup_' ) {
		if ( ! $this->options_exist( $this->get_options() ) ) {
			return new WP_Error( 'no_settings', __( 'No storage settings were found.', 'backupplus' ) );
		}
		try {
			return array_values(
				array_filter(
					$this->connect()->listing(),
					function ( $entry ) use ( $match ) {
						return false !== strpos( $entry['name'], $match );
					}
				)
			);
		} catch ( Exception $error ) {
			return new WP_Error( 'storage_list_failed', $error->getMessage() ); }
	}
	public function download( $file ) {
		global $backupplus;
		try {
			if ( ! BackupPlus_Remote_Storage_Client::valid_filename( $file ) ) {
				throw new RuntimeException( 'Invalid backup filename.' );
			}
			return $this->connect()->download( $file, $backupplus->backups_dir_location() . '/' . $file );
		} catch ( Exception $error ) {
			$this->log( $error->getMessage(), 'error' );
			return false; }
	}
	public function delete( $files, $data = false, $sizeinfo = array() ) {
		$success = true;
		foreach ( (array) $files as $file ) {
			try {
				$this->connect()->delete( $file ); } catch ( Exception $error ) {
				$this->log( $error->getMessage(), 'error' );
				$success = false; }
		}
		return $success;
	}
	public function credentials_test( $posted_settings ) {
		$this->set_connection_status( false );
		$local  = null;
		$client = null;
		$name   = 'backupplus-test-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
		try {
			$client = $this->connect( $posted_settings );
			$client->listing();
			$local = wp_tempnam( $name );
			if ( ! $local ) {
				throw new RuntimeException( 'Cannot create a local test file.' );
			}
			$contents = random_bytes( 64 );
			if ( false === file_put_contents( $local, $contents ) ) {
				throw new RuntimeException( 'Cannot write the local test file.' );
			}
			$client->upload( $name, $local );
			$client->download( $name, $local );
			if ( $contents !== file_get_contents( $local ) ) {
				throw new RuntimeException( 'The storage test file contents did not match.' );
			}
			$client->delete( $name );
			$client = null;
			$this->set_connection_status( true );
			echo esc_html( __( 'Success: Verified listing, upload, download and deletion.', 'backupplus' ) );
		} catch ( Exception $error ) {
			echo esc_html( __( 'Failure:', 'backupplus' ) . ' ' . $error->getMessage() ); } finally {
			if ( $client && $local ) {
				try {
					$client->delete( $name ); } catch ( Exception $ignored ) {
					}
			}
			if ( $local && file_exists( $local ) ) {
				unlink( $local );
			}
			}
	}
	public function options_filter( $settings ) {
		return $settings; }
	public function get_template_properties() {
		$properties = $this->get_persistent_variables_and_methods();
		foreach ( $this->fields as $key => $field ) {
			$properties[ 'input_' . $key . '_label' ] = $field[0];
			$properties[ 'input_' . $key . '_type' ]  = $field[2] ?? 'text';
			if ( 'select' === ( $field[2] ?? '' ) ) {
				$properties[ 'input_' . $key . '_option_labels' ] = $field[3];
			}
		}
		return $properties;
	}
	public function get_configuration_template() {
		$html = '<tr class="{{get_template_css_classes true}}"><td colspan="2">' . esc_html( $this->instructions ) . '</td></tr>';
		foreach ( $this->fields as $key => $field ) {
			$html      .= '<tr class="{{get_template_css_classes true}}"><th>{{input_' . $key . '_label}}:</th><td>';
			$attributes = ' data-backupplus_settings_test="' . $key . '" id="{{get_template_input_attribute_value "id" "' . $key . '"}}" name="{{get_template_input_attribute_value "name" "' . $key . '"}}" autocomplete="off"';
			if ( 'select' === ( $field[2] ?? '' ) ) {
				$html .= '<select' . $attributes . '>{{#each input_' . $key . '_option_labels}}<option value="{{@key}}" {{#ifeq @key ../' . $key . '}}selected="selected"{{/ifeq}}>{{this}}</option>{{/each}}</select>';
			} elseif ( 'textarea' === ( $field[2] ?? '' ) ) {
				$html .= '<textarea class="backupplus_input--wide" rows="5"' . $attributes . '>{{' . $key . '}}</textarea>';
			} else {
				$html .= '<input class="backupplus_input--wide" type="{{input_' . $key . '_type}}" value="{{' . $key . '}}"' . $attributes . ' />';
			}
			$html .= '</td></tr>';
		}
		return $html . '{{{get_template_test_button_html "' . $this->get_description() . '"}}}';
	}
}
