<?php

/** The settings page templates and option transforms for the Google Drive storage. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_Settings_Templates {

	/**
	 * Retrieve a list of template properties by taking all the persistent variables and methods of the parent class and combining them with the ones that are unique to this module, also the necessary HTML element attributes and texts which are also unique only to this backup module
	 * NOTE: Please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses()), or any other technique to prevent XSS attacks that could come via WP hooks
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		global $backupplus;
		$properties = array(
			'storage_image_url'                      => BACKUPPLUS_URL . '/images/googledrive_logo.png',
			'storage_image_title'                    => __( 'Google Drive', 'backupplus' ),
			'input_clientid_label'                   => __( 'Google Drive', 'backupplus' ) . ' ' . __( 'Client ID', 'backupplus' ),
			'input_clientid_title'                   => __( 'If Google later shows you the message "invalid_client", then you did not enter a valid client ID here.', 'backupplus' ),
			'input_secret_label'                     => __( 'Google Drive', 'backupplus' ) . ' ' . __( 'Client Secret', 'backupplus' ),
			'input_secret_type'                      => apply_filters( 'backupplus_admin_secret_field_type', 'password' ),
			'input_folder_label'                     => __( 'Google Drive', 'backupplus' ) . ' ' . __( 'Folder', 'backupplus' ),
			'clientid_and_secret_instruction_label1' => __( 'Create a Google OAuth client and enable the Google Drive API. The steps below are for experienced users.', 'backupplus' ),
			'clientid_and_secret_instruction_label2' => __( 'Follow this link to your Google API Console, and there activate the Drive API and create a Client ID in the API Access section.', 'backupplus' ),
			'clientid_and_secret_instruction_label3' => __( "Select 'Web Application' as the application type.", 'backupplus' ),
			'clientid_and_secret_instruction_label4' => __( 'You must add the following as the authorised redirect URI (under "More Options") when asked', 'backupplus' ),
			'clientid_and_secret_instruction_label5' => BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth',
			'clientid_and_secret_instruction_label6' => __( 'N.B. If you install BackupPlus on several WordPress sites, then you cannot re-use your project; you must create a new one from your Google API console for each site.', 'backupplus' ),
			'id_number_instruction_label'            => wp_kses( __( '<strong>This is NOT a folder name</strong>.', 'backupplus' ) . ' ' . __( 'It is an ID number internal to Google Drive', 'backupplus' ), $this->allowed_html_for_content_sanitisation() ),
			'custom_folder_name_label'               => __( 'BackupPlus uses the configured Google Drive folder for backups.', 'backupplus' ),
			'privacy_policy_label'                   => esc_html__( 'BackupPlus transfers backup archives to the Google Drive account you authorize.', 'backupplus' ),
			'authentication_label'                   => __( 'Authenticate with Google', 'backupplus' ),
			/* translators: %s: Storage provider name */
			'authentication_label2'                  => wp_kses( sprintf( __( "<strong>After</strong> you have saved your settings (by clicking 'Save Changes' below), then come back here once and follow this link to complete authentication with %s.", 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ), $this->allowed_html_for_content_sanitisation() ),
			/* translators: %s: Service name */
			'authentication_link_text'               => sprintf( __( 'Sign in with %s', 'backupplus' ), 'Google' ),
			'already_authenticated_label'            => __( "<strong>(You appear to be already authenticated,</strong> though you can authenticate again to refresh your access if you've had a problem).", 'backupplus' ),
			'deauthentication_nonce'                 => wp_create_nonce( $this->get_id() . '_deauth_nonce' ),
			/* translators: %s: Storage provider name */
			'deauthentication_link_text'             => sprintf( __( 'Follow this link to remove these settings for %s.', 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ),
			'deauthorise_use_master_label'           => __( 'To de-authorize BackupPlus (all sites) from accessing your Google Drive, follow this link to your Google account settings.', 'backupplus' ),
			'account_name_label'                     => __( "Account holder's name", 'backupplus' ),
			'input_folder_placeholder'               => __( 'Enter your folder name', 'backupplus' ),
			'input_folder_title'                     => __( 'The folder in your Google Drive where the backups will be stored.', 'backupplus' ),
		);
		if ( preg_match( '#^(https?://(\d+)\.(\d+)\.(\d+)\.(\d+))/#i', apply_filters( 'backupplus_gdrive_admin_page_url', BackupPlus_Options::admin_page_url() ), $matches ) ) {
			$properties['ip_address'] = $matches[1];
		}
		if ( isset( $properties['ip_address'] ) ) {
			$properties['unallowed_direct_ip_address'] = wp_kses(
				sprintf(
					/* translators: 1: Storage provider name */
					__( '%1$s does not allow authorisation of sites hosted on direct IP addresses.', 'backupplus' ) . ' ' .
					/* translators: 1: Site address, 2: Storage provider name */
					__( 'You will need to change your site\'s address (%1$s) before you can use %2$s for storage.', 'backupplus' ),
					__( 'Google Drive', 'backupplus' ),
					$matches[1]
				),
				$this->allowed_html_for_content_sanitisation()
			);
		}
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
					<img src="{{storage_image_url}}" alt="{{storage_image_title}}">
					{{#unless use_master}}
					<br>
					{{#if ip_address}}
						{{!-- This is advisory - so the fact it doesn't match IPv6 addresses isn't important --}}
						<p><strong>{{{unallowed_direct_ip_address}}}</strong></p>
					{{else}}
						{{!-- If we are not using the master app then show them the instructions for Client ID and Secret --}}
						<p><strong>{{clientid_and_secret_instruction_label1}}</strong></p>
						<p><a href="https://console.developers.google.com" target="_blank">{{clientid_and_secret_instruction_label2}}</a> {{clientid_and_secret_instruction_label3}}</p>
						<p>{{clientid_and_secret_instruction_label4}}: <kbd>{{clientid_and_secret_instruction_label5}}</kbd> {{clientid_and_secret_instruction_label6}}</p>
					{{/if}}
					{{/unless}}
					<p>
						{{{privacy_policy_label}}}
					</p>
				</td>
			</tr>
		<?php
	}

	/**
	 * Get the configuration template
	 *
	 * @return String - the template, ready for substitutions to be carried out
	 */
	public function get_configuration_template() {
		ob_start();
		?>
			{{#unless use_master}}
				<tr class="{{get_template_css_classes true}}">
					<th>{{input_clientid_label}}:</th>
					<td><input type="text" autocomplete="off" class="backupplus_input--wide" id="{{get_template_input_attribute_value "id" "clientid"}}" name="{{get_template_input_attribute_value "name" "clientid"}}" value="{{clientid}}" /><br><em>{{input_clientid_title}}</em></td>
				</tr>
				<tr class="{{get_template_css_classes true}}">
					<th>{{input_secret_label}}:</th>
					<td><input type="{{input_secret_type}}" class="backupplus_input--wide" id="{{get_template_input_attribute_value "id" "secret"}}" name="{{get_template_input_attribute_value "name" "secret"}}" value="{{secret}}" /></td>
				</tr>
			{{/unless}}
			{{#if is_google_enhanced_addon}}
				{{#> gdrive_additional_configuration_top}} {{/gdrive_additional_configuration_top}}
			{{else}}
				{{#if parentid}}
				<tr class="{{get_template_css_classes true}}">
					<th>{{input_folder_label}}:</th>
					<td>
						<input type="hidden" id="{{get_template_input_attribute_value "id" '["parentid","id"]'}}" name="{{get_template_input_attribute_value "name" '["parentid","id"]'}}" value="{{parentid_str}}">
						<input type="text" title="{{parentid_str}}" readonly="readonly" class="backupplus_input--wide" value="{{showparent}}">
						{{#if is_id_number_instruction}}
							<em>{{{id_number_instruction_label}}}</em>
						{{else}}
							<input type="hidden" id="{{get_template_input_attribute_value "id" '["parentid","name"]'}}" name="{{get_template_input_attribute_value "name" '["parentid","name"]'}}" value="{{parentid.name}}">
						{{/if}}
				{{else}}
					<tr class="{{get_template_css_classes true}}">
						<th>{{input_folder_label}}:</th>
						<td>
							<input type="text" readonly="readonly" class="backupplus_input--wide" id="{{get_template_input_attribute_value "id" "folder"}}" name="{{get_template_input_attribute_value "name" "folder"}}" value="BackupPlus" />
				{{/if}}
							<br>
							<em>
{{custom_folder_name_label}}
							</em>
						</td>
					</tr>
			{{/if}}
			<tr class="{{get_template_css_classes true}}">
				<th>{{authentication_label}}:</th>
				<td>
					{{#if is_authenticate_with_google}}
						<p>
							{{{already_authenticated_label}}}
							<a class="backupplus_deauthlink" href="{{admin_page_url}}?action=backupplusmethod-{{method_id}}-auth&page=backupplus&backupplus_{{method_id}}auth=deauth&nonce={{deauthentication_nonce}}&backupplus_instance={{instance_id}}" data-instance_id="{{instance_id}}" data-remote_method="{{method_id}}">{{deauthentication_link_text}}</a>
						</p>
						{{#if use_master}}
							<p><a target="_blank" href="https://myaccount.google.com/permissions">{{deauthorise_use_master_label}}</a></p>
						{{/if}}
					{{/if}}
					{{#if is_ownername_display}}
						{{#if owneremail}}
						<br>
							{{account_name_label}}: {{ownername}} ({{owneremail}})
						{{else}}
						<br>
							{{account_name_label}}: {{ownername}}
						{{/if}}
					{{/if}}
					<p>
						{{{authentication_label2}}}
					</p>
					<br>
					<a data-pretext="{{{authentication_label2}}}" class="button-ud-google backupplus_authlink" href="{{admin_page_url}}?&action=backupplusmethod-{{method_id}}-auth&page=backupplus&backupplus_{{method_id}}auth=doit&nonce={{storage_auth_nonce}}&backupplus_instance={{instance_id}}" data-instance_id="{{instance_id}}" data-remote_method="{{method_id}}">{{authentication_link_text}}</a>
				</td>
			</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get partial templates associated to the corresponding backup module (remote storage object)
	 *
	 * @return Array an associative array keyed by names of the partial template
	 */
	public function get_partial_templates() {
		return wp_parse_args( apply_filters( 'backupplus_' . $this->get_id() . '_partial_templates', array() ), parent::get_partial_templates() );
	}

	/**
	 * Modifies handerbar template options
	 *
	 * @param array $opts
	 * @return Array - Modified handerbar template options
	 */
	public function transform_options_for_template( $opts ) {
		$opts['use_master']               = $this->use_master( $opts );
		$opts['is_google_enhanced_addon'] = false;
		if ( isset( $opts['parentid'] ) ) {
			$opts['parentid_str']             = ( is_array( $opts['parentid'] ) ) ? $opts['parentid']['id'] : $opts['parentid'];
			$opts['showparent']               = ( is_array( $opts['parentid'] ) && ! empty( $opts['parentid']['name'] ) ) ? $opts['parentid']['name'] : $opts['parentid_str'];
			$opts['is_id_number_instruction'] = ( ! empty( $opts['parentid'] ) && ( ! is_array( $opts['parentid'] ) || empty( $opts['parentid']['name'] ) ) );
		}
		$opts['is_authenticate_with_google'] = ( ! empty( $opts['token'] ) || ! empty( $opts['user_id'] ) );
		$opts['is_ownername_display']        = ( ( ! empty( $opts['token'] ) || ! empty( $opts['user_id'] ) ) && ! empty( $opts['ownername'] ) );
		$opts                                = apply_filters( 'backupplus_options_googledrive_options', $opts );
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
			'expires_in',
			'tmp_access_token',
			'token',
			'user_id',
		);
	}

	/**
	 * This function will build and return the authentication link
	 *
	 * @param String $instance_id - the instance id
	 * @param String $text        - the link text
	 *
	 * @return String - the authentication link
	 */
	public function build_authentication_link( $instance_id, $text ) {

		$id = $this->get_id();

		/* translators: %s: Service name */
		return '<p>' . $text . '</p><br><a data-pretext="' . $text . '" class="button-ud-google backupplus_authlink" href="' . BackupPlus_Options::admin_page_url() . '?&action=backupplusmethod-' . $id . '-auth&page=backupplus&backupplus_' . $id . 'auth=doit&nonce=' . wp_create_nonce( 'storage_auth_nonce' ) . '&backupplus_instance=' . esc_attr( $instance_id ) . '" data-instance_id="' . esc_attr( $instance_id ) . '" data-remote_method="' . $id . '">' . sprintf( __( 'Sign in with %s', 'backupplus' ), 'Google' ) . '</a>';
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
	public function configure_field_from_legacy( $field, $template_properties, $field_name, $option ) {
		if ( 'folder' === $field_name ) {
			return array();
		}

		$prefix = 'input_' . $option['template_property_input_mapping'] . '_';

		if ( empty( $field['tooltip'] ) && isset( $template_properties[ $prefix . 'title' ] ) ) {
			$field['tooltip'] = array( 'text' => $template_properties[ $prefix . 'title' ] );
		}

		return $field;
	}
}
