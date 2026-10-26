<?php

/** The settings page templates and option transforms for the Dropbox storage. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Dropbox_Settings_Templates {

	/**
	 * Retrieve a list of template properties by taking all the persistent variables and methods of the parent class and combining them with the ones that are unique to this module, also the necessary HTML element attributes and texts which are also unique only to this backup module
	 * NOTE: Please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses())
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		global $backupplus, $backupplus_admin;
		$partial_templates = $this->get_partial_templates();
		$properties        = array(
			'storage_image_url'                       => BACKUPPLUS_URL . '/images/dropbox-logo.png',
			/* translators: %s: Service name */
			'storage_image_description'               => sprintf( __( '%s logo', 'backupplus' ), 'Dropbox' ),
			'curl_existence_label'                    => wp_kses( $backupplus_admin->curl_check( $backupplus->backup_methods[ $this->get_id() ], true, $this->get_id() . '', false ), $this->allowed_html_for_content_sanitisation() ),
			'app_authorisation_policy_label'          => esc_html__( 'BackupPlus uses Dropbox authorization to store backups in the Dropbox account you select.', 'backupplus' ),
			'sub_folders_instruction_label1'          => __( 'Need to use sub-folders?', 'backupplus' ),
			/* translators: %s: Backup folder path */
			'sub_folders_instruction_label2'          => sprintf( __( 'Backups are saved in %s.', 'backupplus' ), 'apps/BackupPlus' ),
			/* translators: 1: Opening link tag, 2: Closing link tag */
			'sub_folders_instruction_label3'          => __( 'BackupPlus uses the configured Dropbox app folder for backups.', 'backupplus' ),
			'input_authenticate_with_label'           => sprintf(
				/* translators: %s: Service name */
				__( 'Authenticate with %s', 'backupplus' ),
				__( 'Dropbox', 'backupplus' )
			),
			'already_authenticated_label'             => __( '(You are already authenticated).', 'backupplus' ),
			/* translators: %s: Service name */
			'authentication_link_text'                => wp_kses( sprintf( __( "<strong>After</strong> you have saved your settings (by clicking 'Save Changes' below), then come back here and follow this link to complete authentication with %s.", 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ), $this->allowed_html_for_content_sanitisation() ),
			/* translators: %s: Service name */
			'deauthentication_link_text'              => sprintf( __( 'Follow this link to remove these settings for %s.', 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ),
			'authentication_label'                    => __( 'Ensure you are logged into the correct account before continuing.', 'backupplus' ),
			'authorised_redirect_uri_label'           => __( 'You must add the following as the authorised redirect URI in your Dropbox console (under "API Settings") when asked', 'backupplus' ),
			'input_app_key_label'                     => __( 'Your Dropbox App Key', 'backupplus' ),
			'input_app_secret_label'                  => __( 'Your Dropbox App Secret', 'backupplus' ),
			'partial_templates_contain_input_element' => isset( $partial_templates['dropbox_additional_configuration_top'] ) && preg_match( '/<input(?:>|[^>]+>)/i', $partial_templates['dropbox_additional_configuration_top'] ),
			'deauthentication_nonce'                  => wp_create_nonce( $this->get_id() . '_deauth_nonce' ),
			'input_folder_label'                      => __( 'Store at', 'backupplus' ),
			'input_folder_prefix'                     => 'apps/UpdraftPlus.Com/',
		);
		return wp_parse_args( apply_filters( 'backupplus_' . $this->get_id() . '_template_properties', array() ), wp_parse_args( $properties, $this->get_persistent_variables_and_methods() ) );
	}

	/**
	 * Get the pre configuration template
	 *
	 * @return String - the template
	 */
	public function get_pre_configuration_template() {
		?>
			<tr class="{{get_template_css_classes false}} {{method_id}}_pre_config_container">
				<td colspan="2">
					<img alt="{{storage_image_description}}" src="{{storage_image_url}}">
					<br>
					<p>
					{{{curl_existence_label}}}
					</p>
					<p>
					{{{app_authorisation_policy_label}}}
					</p>
				</td>
			</tr>
		<?php
	}

	/**
	 * Get remote storage partial templates, the partial template is recognised by its name. To find out a name of partial template, look for the partial call syntax in the template, it's enclosed by double curly braces (i.e. {{> partial_template_name }})
	 *
	 * @return Array an associative array keyed by name of the partial templates
	 */
	public function get_partial_templates() {
		$partial_templates = array();
		ob_start();
		?>
			<tr class="{{get_template_css_classes true}}">
				<td colspan="2"><strong>{{sub_folders_instruction_label1}}</strong> {{sub_folders_instruction_label2}} {{{sub_folders_instruction_label3}}}</td>
			</tr>
		<?php
		$partial_templates['dropbox_additional_configuration_top'] = ob_get_clean();
		return wp_parse_args( apply_filters( 'backupplus_' . $this->get_id() . '_partial_templates', $partial_templates ), parent::get_partial_templates() );
	}

	/**
	 * Get the configuration template
	 *
	 * @return String - the template, ready for substitutions to be carried out
	 */
	public function get_configuration_template() {
		ob_start();
		?>
			{{#> dropbox_additional_configuration_top}}
			{{/dropbox_additional_configuration_top}}
			<tr class="{{get_template_css_classes true}}">
				<th>{{input_authenticate_with_label}}:</th>
				<td>
					{{#if is_authenticated}}
					<p>
						<strong>{{already_authenticated_label}}</strong>
						<a class="backupplus_deauthlink" href="{{admin_page_url}}?action=backupplusmethod-{{method_id}}-auth&page=backupplus&backupplus_{{method_id}}auth=deauth&nonce={{deauthentication_nonce}}&backupplus_instance={{instance_id}}" data-instance_id="{{instance_id}}" data-remote_method="{{method_id}}">{{deauthentication_link_text}}</a>
					</p>
					{{/if}}
					{{#if ownername_sentence}}
						<br/>
						{{ownername_sentence}}
					{{/if}}
					<p>
					{{authentication_label}} <a class="backupplus_authlink" href="{{admin_page_url}}?&action=backupplusmethod-{{method_id}}-auth&page=backupplus&backupplus_{{method_id}}auth=doit&nonce={{storage_auth_nonce}}&backupplus_instance={{instance_id}}" data-instance_id="{{instance_id}}" data-remote_method="{{method_id}}">{{{authentication_link_text}}}</a>
					</p>
				</td>
			</tr>
			{{!-- Legacy: only show this next setting to old users who had a setting stored --}}
			{{#if old_user_settings}}
				<tr class="{{get_template_css_classes true}}">
					<th></th>
					<td>
						<p>{{authorised_redirect_uri_label}}: <kbd>{{admin_page_url}}?page=backupplus&action=backupplusmethod-dropbox-auth</kbd></p>
					</td>
				</tr>
				<tr class="{{get_template_css_classes true}}">
					<th>{{input_app_key_label}}:</th>
					<td><input type="text" autocomplete="off" style="width:332px" id="{{get_template_input_attribute_value "id" "appkey"}}" name="{{get_template_input_attribute_value "name" "appkey"}}" value="{{appkey}}" /></td>
				</tr>
				<tr class="{{get_template_css_classes true}}">
					<th>{{input_app_secret_label}}:</th>
					<td><input type="text" style="width:332px" id="{{get_template_input_attribute_value "id" "secret"}}" name="{{get_template_input_attribute_value "name" "secret"}}" value="{{secret}}" /></td>
				</tr>
			{{else}}
				{{#unless partial_templates_contain_input_element}}
				{{!-- We need to make sure that it is not the case that the module has no settings whatsoever - this can result in the module being effectively invisible. --}}
				<input type="hidden" id="{{get_template_input_attribute_value "id" "dummy-nosave"}}" name="{{get_template_input_attribute_value "name" "dummy-nosave"}}" value="0">
				{{/unless}}
			{{/if}}
		<?php
		return ob_get_clean();
	}

	/**
	 * Generates ownername with email
	 *
	 * @param array $opts
	 * @return String - Ownername with email
	 */
	private function generate_ownername_with_email( $opts ) {
		$ownername_with_email = '';

		if ( ! empty( $opts['ownername'] ) ) {
			$ownername_with_email = $opts['ownername'];
		}

		if ( ! empty( $opts['email'] ) ) {
			if ( ! empty( $ownername_with_email ) ) {
				$ownername_with_email = $ownername_with_email . ' (' . $opts['email'] . ')';
			} else {
				$ownername_with_email = $opts['email'];
			}
		}

		return $ownername_with_email;
	}

	/**
	 * Modifies handerbar template options
	 *
	 * @param array $opts
	 * @return Array - Modified handerbar template options
	 */
	public function transform_options_for_template( $opts ) {
		if ( ! empty( $opts['tk_access_token'] ) ) {
			$opts['ownername']    = empty( $opts['ownername'] ) ? '' : $opts['ownername'];
			$opts['email']        = empty( $opts['email'] ) ? '' : $opts['email'];
			$ownername_with_email = $this->generate_ownername_with_email( $opts );

			if ( $ownername_with_email ) {
				/* translators: %s: Account holder's name with email */
				$opts['ownername_sentence'] = sprintf( __( "Account holder's name: %s.", 'backupplus' ), $ownername_with_email ) . ' ';
			}
			$opts['is_authenticated'] = true;
		}
		$opts['old_user_settings'] = ( ! empty( $opts['appkey'] ) || ( defined( 'BACKUPPLUS_CUSTOM_DROPBOX_APP' ) && BACKUPPLUS_CUSTOM_DROPBOX_APP ) );
		if ( $opts['old_user_settings'] ) {
			$opts['appkey'] = empty( $opts['appkey'] ) ? '' : $opts['appkey'];
			$opts['secret'] = empty( $opts['secret'] ) ? '' : $opts['secret'];
		}
		$opts = apply_filters( 'backupplus_options_dropbox_options', $opts );
		return $opts;
	}

	/**
	 * Gives settings keys which values should not passed to handlebarsjs context.
	 * The settings stored in UD in the database sometimes also include internal information that it would be best not to send to the front-end (so that it can't be stolen by a man-in-the-middle attacker)
	 *
	 * @return Array - Settings array keys which should be filtered
	 */
	public function filter_frontend_settings_keys() {
		return array(
			'CSRF',
			'code',
			'ownername',
			'tk_access_token',
		);
	}

	/**
	 * Customize generated field data using legacy mapping values.
	 *
	 * Used by transform_template_properties_to_fields_structure()
	 * to allow child classes to adjust the generated field structure
	 * based on legacy data and field mapping requirements.
	 *
	 * @param array  $field               Field data.
	 * @param array  $template_properties Template properties.
	 * @param string $field_name          Field name.
	 * @param array  $option              Field mapping option.
	 *
	 * @return array
	 */
	public function configure_field_from_legacy( $field, $template_properties, $field_name, $option ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Variable is not being used yet
		if ( 'folder' === $field_name ) {
			$field = array();
		}

		return $field;
	}
}
