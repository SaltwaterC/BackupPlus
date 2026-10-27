<?php

/** The provider settings UI: template properties, field names and ids, templates and the settings field structure. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_BackupModule_Templates {

	/**
	 * Retrieve persistent class variables and/or methods (the ones that don't get changed during runtime) and transform them into a list of template properties
	 *
	 * @return Array an associative array keyed by names of the corresponding variables/methods, the keys might not exactly be the same with the name of the variables/methods
	 */
	protected function get_persistent_variables_and_methods() {
		global $backupplus;
		return array(
			'css_class'                              => 'backupplusmethod',
			'is_multi_options_feature_supported'     => $this->supports_feature( 'multi_options' ),
			'is_config_templates_feature_supported'  => $this->supports_feature( 'config_templates' ),
			'is_conditional_logic_feature_supported' => $this->supports_feature( 'conditional_logic' ),
			'is_multi_servers_feature_supported'     => $this->supports_feature( 'multi_servers' ),
			'method_id'                              => $this->get_id(),
			'_instance_id'                           => $this->_instance_id,
			'method_display_name'                    => $backupplus->backup_methods[ $this->get_id() ],
			/* translators: %s: Storage method name. */
			'input_test_label'                       => sprintf( __( 'Test %s Settings', 'backupplus' ), $this->get_description() ),
			'admin_page_url'                         => BackupPlus_Options::admin_page_url(),
			'storage_auth_nonce'                     => wp_create_nonce( 'storage_auth_nonce' ),
			'input_select_folder_label'              => __( 'Select existing folder', 'backupplus' ),
			'input_confirm_label'                    => __( 'Confirm', 'backupplus' ),
			'input_cancel_label'                     => __( 'Cancel', 'backupplus' ),
			'hostname_error_label'                   => __( 'Error:', 'backupplus' ) . ' ' . __( 'A host name cannot be an URL or contain a slash.', 'backupplus' ),
		);
	}

	/**
	 * Get all persistent variables and methods across the modules (this could mean the child including its parent), also the necessary required HTML element attributes and texts which are unique to each child
	 * NOTE: Since this method would normally be over-ridden by the child, please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses())
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		return array();
	}

	/**
	 * List all allowed HTML tags for content sanitisation
	 *
	 * @return Array an associatve array keyed by name of the allowed HTML tags
	 */
	protected function allowed_html_for_content_sanitisation() {
		return array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
			),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'p'      => array(),
			'div'    => array(
				'class' => array(),
			),
			'kbd'    => array(),
		);
	}

	/**
	 * Get partial templates associated to the corresponding backup module (remote storage object)
	 * N.B. This method would normally be over-ridden by the child.
	 *
	 * @return Array an associative array keyed by name of the partial templates
	 */
	public function get_partial_templates() {
		return array();
	}

	/**
	 * This method should only be called if the feature 'multi storage' is supported. In that case, it returns a template with information about the remote storage. The code below is a placeholder, and methods supporting the feature should always over-ride it.
	 *
	 * @return String - HTML template
	 */
	public function get_pre_configuration_template() {
		return $this->get_id() . ': called, but not implemented in the child class (coding error)';
	}

	/**
	 * This method should only be called if the feature 'config templates' is supported. In that case, it returns a template with appropriate placeholders for specific settings. The code below is a placeholder, and methods supporting the feature should always over-ride it.
	 *
	 * @return String - HTML template
	 */
	public function get_configuration_template() {
		return $this->get_id() . ': called, but not implemented in the child class (coding error)';
	}

	/**
	 * Outputs id and name fields, as if currently within an input tag
	 *
	 * This assumes standardised options handling (i.e. that the options array is backupplus_(method-id))
	 *
	 * @param Array|String $field                  - the field identifiers
	 * @param Boolean      $return_instead_of_echo - tells the method if it should return the output or echo it to page
	 */
	public function output_settings_field_name_and_id( $field, $return_instead_of_echo = false ) {

		$method_id = $this->get_id();

		$instance_id = $this->supports_feature( 'config_templates' ) ? '{{instance_id}}' : $this->_instance_id;

		$id   = '';
		$name = '';

		if ( is_array( $field ) ) {
			foreach ( $field as $value ) {
				$id   .= '_' . $value;
				$name .= '[' . $value . ']';
			}
		} else {
			$id  .= '_' . $field;
			$name = '[' . $field . ']';
		}

		$output = "id=\"backupplus_{$method_id}{$id}_{$instance_id}\" name=\"backupplus_{$method_id}[settings][{$instance_id}]{$name}\" ";

		if ( $return_instead_of_echo ) {
			return $output;
		} else {
			echo wp_kses_post( $output );
		}
	}

	/**
	 * Get the CSS ID
	 *
	 * @param String $field - the field identifier to return a CSS ID for
	 *
	 * @return String
	 */
	public function get_css_id( $field ) {
		$method_id   = $this->get_id();
		$instance_id = $this->supports_feature( 'config_templates' ) ? '{{instance_id}}' : $this->_instance_id;
		return "backupplus_{$method_id}_{$field}_{$instance_id}";
	}

	/**
	 * Get handlebarsjs template
	 * This deals with any boiler-plate, prior to calling config_print()
	 *
	 * @uses self::config_print()
	 * @uses self::get_configuration_template()
	 *
	 * return handlebarsjs template or html
	 */
	public function get_template() {
		ob_start();
		// Allow methods to not use this hidden field, if they do not output any settings (to prevent their saved settings being over-written by just this hidden field)
		if ( $this->print_shared_settings_fields() ) {
			?>
			<tr class="<?php echo esc_attr( $this->get_css_classes() ); ?>">
				<input type="hidden" name="backupplus_<?php echo esc_attr( $this->get_id() ); ?>[version]" value="1">
			</tr>
			<?php
		}

		if ( $this->supports_feature( 'config_templates' ) ) {
			?>
			{{#if first_instance}}
			<?php

				$this->get_pre_configuration_template();

			?>
			{{/if}}
			<?php
			do_action( 'backupplus_config_print_before_storage', $this->get_id(), $this );
			do_action( 'backupplus_config_print_add_conditional_logic', $this->get_id(), $this );
			if ( $this->supports_feature( 'multi_storage' ) ) {
				$this->print_instance_header_template();
			}

			$template  = ob_get_clean();
			$template .= $this->get_configuration_template();
		} else {
			do_action( 'backupplus_config_print_before_storage', $this->get_id(), $this );
			do_action( 'backupplus_config_print_add_conditional_logic', $this->get_id(), $this );
			// N.B. These are mutually exclusive: config_print() is not used if config_templates is supported. So, even during transition, the BackupPlus_BackupModule instance only needs to support one of the two, not both.
			$this->config_print();
			$template = ob_get_clean();
		}
		return $template;
	}

	/**
	 * Prints the row that names a destination and lets it be disabled or removed, so that several destinations of the same type can be told apart.
	 */
	private function print_instance_header_template() {
		$method = $this->get_id();
		$field  = 'backupplus_' . $method . '[settings][{{instance_id}}]';
		$label  = 'backupplus_' . $method . '_instance_label_{{instance_id}}';
		$toggle = 'backupplus_' . $method . '_instance_enabled_{{instance_id}}';
		?>
		<tr class="<?php echo esc_attr( $this->get_css_classes() . ' ' . $method . '_backupplus_remote_storage_border' ); ?>">
			<th><label for="<?php echo esc_attr( $label ); ?>"><?php esc_html_e( 'Destination name', 'backupplus' ); ?>:</label></th>
			<td>
				<input type="text" class="backupplus_input--wide" id="<?php echo esc_attr( $label ); ?>" name="<?php echo esc_attr( $field ); ?>[instance_label]" value="{{instance_label}}" maxlength="80" autocomplete="off">
				<p class="backupplus_instance_controls">
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>[instance_enabled]" value="0">
					<input type="checkbox" class="backupplus_instance_toggle" id="<?php echo esc_attr( $toggle ); ?>" name="<?php echo esc_attr( $field ); ?>[instance_enabled]" value="1" {{#if instance_enabled}}checked="checked"{{/if}}>
					<label for="<?php echo esc_attr( $toggle ); ?>">{{#if instance_enabled}}<?php esc_html_e( 'Currently enabled', 'backupplus' ); ?>{{else}}<?php esc_html_e( 'Currently disabled', 'backupplus' ); ?>{{/if}}</label>
					<a href="#" class="backupplus_delete_instance" data-method="<?php echo esc_attr( $method ); ?>" data-instance_id="{{instance_id}}"><?php esc_html_e( 'Remove this destination', 'backupplus' ); ?></a>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Modifies handerbar template options. Other child class can extend it.
	 *
	 * @param array $opts
	 * @return Array - Modified handerbar template options
	 */
	public function transform_options_for_template( $opts ) {
		return $opts;
	}

	/**
	 * Gives settings keys which values should not passed to handlebarsjs context.
	 * The settings stored in UD in the database sometimes also include internal information that it would be best not to send to the front-end (so that it can't be stolen by a man-in-the-middle attacker)
	 *
	 * @return Array - Settings array keys which should be filtered
	 */
	public function filter_frontend_settings_keys() {
		return array();
	}

	/**
	 * Over-ride this to allow methods to not use the hidden version field, if they do not output any settings (to prevent their saved settings being over-written by just this hidden field
	 *
	 * @return [boolean] - return true to output the version field or false to not output the field
	 */
	public function print_shared_settings_fields() {
		return true;
	}

	/**
	 * Prints out the configuration section for a particular module. This is now (Sep 2017) considered deprecated; things are being ported over to get_configuration_template(), indicated via the feature 'config_templates'.
	 */
	public function config_print() {
		echo esc_html( $this->get_id() ) . ': module neither declares config_templates support, nor has a config_print() method (coding bug)';
	}

	/**
	 * Returns a space-separated list of CSS classes suitable for rows in the configuration section
	 *
	 * @param Boolean $include_instance - a boolean value to indicate if we want to include the instance_id in the css class, we may not want to include the instance if it's for a UI element that we don't want to be removed along with other UI elements that do include a instance id.
	 *
	 * @returns String - the list of CSS classes
	 */
	public function get_css_classes( $include_instance = true ) {
		$classes = 'backupplusmethod ' . $this->get_id();
		if ( ! $include_instance ) {
			return $classes;
		}
		if ( $this->supports_feature( 'multi_options' ) ) {
			if ( $this->supports_feature( 'config_templates' ) ) {
				$classes .= ' ' . $this->get_id() . '-{{instance_id}}';
			} else {
				$classes .= ' ' . $this->get_id() . '-' . $this->_instance_id;
			}
		}
		return $classes;
	}

	/**
	 *
	 * Returns HTML for a row for a test button
	 *
	 * @param String $title - The text to be used in the button
	 *
	 * @returns String - The HTML to be inserted into the settings page
	 */
	protected function get_test_button_html( $title ) {
		ob_start();
		$instance_id = $this->supports_feature( 'config_templates' ) ? '{{instance_id}}' : $this->_instance_id;
		?>
		<tr class="<?php echo esc_attr( $this->get_css_classes() ); ?>">
			<th></th>
			<?php /* translators: %s: Remote storage method */ ?>
			<td><p><button id="backupplus-<?php echo esc_attr( $this->get_id() ); ?>-test-<?php echo esc_attr( $instance_id ); ?>" type="button" class="button-primary backupplus-test-button backupplus-<?php echo esc_attr( $this->get_id() ); ?>-test" data-instance_id="<?php echo esc_attr( $instance_id ); ?>" data-method="<?php echo esc_attr( $this->get_id() ); ?>" data-method_label="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( sprintf( __( 'Test %s Settings', 'backupplus' ), $title ) ); ?></button></p></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * This method will output any needed js for the JSTree.
	 *
	 * @return void
	 */
	public function admin_footer_jstree() {
		static $script_output = array(); // Static array to store script output status.

		$id = $this->get_id();

		// Check if the script has already been output for this ID.
		if ( isset( $script_output[ $id ] ) ) {
			return;
		}

		// wp_add_inline_script() is available on WP 4.5+
		if ( function_exists( 'wp_add_inline_script' ) ) {
			wp_add_inline_script( 'backupplus-admin-common', 'var js_tree_' . esc_js( $id ) . " = new backupplus_js_tree('" . esc_js( $id ) . "'); js_tree_" . esc_js( $id ) . '.init();', 'after' );
		} elseif ( wp_script_is( 'backupplus-admin-common', 'done' ) ) {
			"'''"
			?>
			<script>
				var js_tree_<?php echo esc_js( $id ); ?> = new backupplus_js_tree('<?php echo esc_js( $id ); ?>');
				js_tree_<?php echo esc_js( $id ); ?>.init();
			</script>
			<?php
		}
		// Mark the script as output for this ID.
		$script_output[ $id ] = true;
	}

	/**
	 * Transform template properties into field array structures.
	 *
	 * Builds an array of field definitions based on the template properties
	 * and configured fields. Supports text, password, and dropdown field
	 * types, including placeholders, tooltips, and options.
	 *
	 * This method is already onboarding-oriented; it processes all properties in the template that were added using the naming convention starting with the 'input_' prefix.
	 * Format: "input_{fieldName}_{attribute}"
	 * fieldName or inputName refers to data added in the $input_option_field_mappings class variable in each remote storage module
	 * attribute is essential metadata accompanying a field, defining its characteristics or behaviour. The list of supported attributes:
	 * id, default, type, label, placeholder, prefix, tooltip, option_labels
	 *
	 * NOTE: Not all variables in the template properties consistently follow the naming convention/format, so mapping might be necessary to avoid introducing new translation strings and/or redundancies.
	 *
	 * @return array List of field definitions.
	 */
	public function transform_template_properties_to_fields_structure() {
		$fields                           = array();
		$template_property_input_mappings = $this->get_input_option_mappings( 'input' );

		if ( empty( $template_property_input_mappings ) ) {
			return $fields;
		}

		$template_properties = $this->get_template_properties();

		foreach ( $template_property_input_mappings as $field_name => $option ) {
			$field = array(
				'id'      => $field_name,
				'default' => $option['default_value'],
				'type'    => 'text',
			);

			if ( ! isset( $option['template_property_input_mapping'] ) ) {
				$option['template_property_input_mapping'] = $field_name;
			}
			$prefix = 'input_' . $option['template_property_input_mapping'] . '_';

			if ( isset( $template_properties[ $prefix . 'type' ] ) ) {
				$field['type'] = $template_properties[ $prefix . 'type' ];
			}

			switch ( $field['type'] ) {
				case 'password':
					$field['show_forgot_password'] = false;
					break;
				case 'number':
					if ( isset( $template_properties[ $prefix . 'min_value' ] ) ) {
						$field['min'] = $template_properties[ $prefix . 'min_value' ];
					}
					if ( isset( $template_properties[ $prefix . 'max_value' ] ) ) {
						$field['max'] = $template_properties[ $prefix . 'max_value' ];
					}
					break;
			}

			if ( isset( $template_properties[ $prefix . 'label' ] ) ) {
				$field['label'] = $template_properties[ $prefix . 'label' ];
			}

			if ( isset( $template_properties[ $prefix . 'placeholder' ] ) ) {
				$field['placeholder'] = $template_properties[ $prefix . 'placeholder' ];
			}

			if ( isset( $template_properties[ $prefix . 'prefix' ] ) ) {
				$field['prefix'] = $template_properties[ $prefix . 'prefix' ];
			}

			if ( isset( $template_properties[ $prefix . 'tooltip' ] ) ) {
				$field['tooltip'] = array( 'text' => wp_kses_post( $template_properties[ $prefix . 'tooltip' ] ) );
			}

			if ( isset( $template_properties[ $prefix . 'option_labels' ] ) ) {
				$field['type'] = 'dropdown';
				$options       = array();
				foreach ( $template_properties[ $prefix . 'option_labels' ] as $key => $value ) {
					$options[] = array(
						'value' => $key,
						'label' => $value,
					);
				}
				$field['options'] = $options;
			}

			if ( method_exists( $this, 'configure_field_from_legacy' ) ) {
				$field = $this->configure_field_from_legacy( $field, $template_properties, $field_name, $option );
			}

			if ( $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}
}
