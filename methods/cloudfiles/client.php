<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

// SDK uses namespacing - requires PHP 5.3 (actually the SDK states its requirements as 5.3.3)
use OpenCloud\Rackspace;

// New SDK - https://github.com/rackspace/php-opencloud and http://docs.rackspace.com/sdks/guide/content/php.html
// Uploading: https://github.com/rackspace/php-opencloud/blob/master/docs/userguide/ObjectStore/Storage/Object.md

backupplus_try_include_file( 'methods/cloudfiles/base.php', 'require_once' );

class BackupPlus_BackupModule_cloudfiles_opencloudsdk extends BackupPlus_BackupModule_cloudfiles_base {

	/**
	 * Cloudfiles available regions.
	 *
	 * @var array
	 */
	private $regions;

	/**
	 * Input and option field mappings with default values and supported contexts.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array(
		'authurl' => array(
			'default_value'                   => 'https://auth.api.rackspacecloud.com',
			'template_property_input_mapping' => 'account',
			'contexts'                        => array( 'option', 'input' ),
		),
		'region'  => array(
			'default_value' => null,
			'contexts'      => array( 'option', 'input' ),
		),
		'user'    => array(
			'default_value'                   => '',
			'template_property_input_mapping' => 'username',
			'contexts'                        => array( 'option', 'input' ),
		),
		'apikey'  => array(
			'default_value' => '',
			'contexts'      => array( 'option', 'input' ),
		),
		'path'    => array(
			'default_value'                   => '',
			'template_property_input_mapping' => 'container',
			'contexts'                        => array( 'option', 'input' ),
		),
	);

	public function __construct() {
		parent::__construct( 'cloudfiles', 'Cloud Files', 'Rackspace Cloud Files', '/images/rackspacecloud-logo.png' );

		$this->regions = array(
			'DFW' => __( 'Dallas (DFW) (default)', 'backupplus' ),
			'SYD' => __( 'Sydney (SYD)', 'backupplus' ),
			'ORD' => __( 'Chicago (ORD)', 'backupplus' ),
			'IAD' => __( 'Northern Virginia (IAD)', 'backupplus' ),
			'HKG' => __( 'Hong Kong (HKG)', 'backupplus' ),
			'LON' => __( 'London (LON)', 'backupplus' ),
		);
	}

	public function get_client() {
		return $this->client;
	}

	public function get_openstack_service( $opts, $useservercerts = false, $disablesslverify = null ) {

		$user    = $opts['user'];
		$apikey  = $opts['apikey'];
		$authurl = $opts['authurl'];
		$region  = ( ! empty( $opts['region'] ) ) ? $opts['region'] : null;

		backupplus_try_include_file( 'vendor/autoload.php', 'include_once' );

		// The new authentication APIs don't match the values we were storing before
		$new_authurl = ( 'https://lon.auth.api.rackspacecloud.com' == $authurl || 'uk' == $authurl ) ? Rackspace::UK_IDENTITY_ENDPOINT : Rackspace::US_IDENTITY_ENDPOINT;

		if ( null === $disablesslverify ) {
			$disablesslverify = BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' );
		}

		if ( empty( $user ) || empty( $apikey ) ) {
			throw new Exception( __( 'Authorisation failed (check your credentials)', 'backupplus' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}
		$this->log( 'authentication URL: ' . $new_authurl );

		$client       = new Rackspace(
			$new_authurl,
			array(
				'username' => $user,
				'apiKey'   => $apikey,
			)
		);
		$this->client = $client;

		if ( $disablesslverify ) {
			$client->setSslVerification( false );
		} else {
			if ( $useservercerts ) {
				$client->setConfig( array( $client::SSL_CERT_AUTHORITY, 'system' ) );
			} else {
				$client->setSslVerification( BACKUPPLUS_DIR . '/includes/cacert.pem', true, 2 );
			}
		}

		return $client->objectStoreService( 'cloudFiles', $region );
	}

	/**
	 * Get partial templates of the Rackspace Cloud Files remote storage.
	 *
	 * @return Array an associative array keyed by name of the partial templates
	 */
	public function get_partial_templates() {
		$partial_templates = array();
		ob_start();
		?>
		{{api_key_setting_default_label}}
		<?php
		$partial_templates['backupplus_cloudfiles_apikeysetting'] = apply_filters( 'backupplus_cloudfiles_apikeysetting', ob_get_clean() );
		return wp_parse_args( apply_filters( 'backupplus_' . $this->get_id() . '_partial_templates', $partial_templates ), parent::get_partial_templates() );
	}

	/**
	 * This method overrides the parent method and lists the supported features of this remote storage option.
	 *
	 * @return Array - an array of supported features (any features not
	 * mentioned are assumed to not be supported)
	 */
	public function get_supported_features() {
		// This options format is handled via only accessing options via $this->get_options()
		return array( 'multi_options', 'config_templates', 'multi_storage', 'conditional_logic' );
	}

	/**
	 * This gives the partial template string to the settings page for the CloudFiles  settings.
	 *
	 * @return String - the partial template, ready for substitutions to be carried out
	 */
	public function get_configuration_middlesection_template() {
		global $backupplus;

		$classes      = $this->get_css_classes();
		$template_str = '
		<tr class=""' . $classes . '>
			<th title="' . __( 'Accounts created at rackspacecloud.com are US accounts; accounts created at rackspace.co.uk are UK accounts.', 'backupplus' ) . '">' . __( 'US or UK-based Rackspace Account', 'backupplus' ) . ':</th>
			<td>
				<select data-backupplus_settings_test="authurl" ' . $this->output_settings_field_name_and_id( 'authurl', true ) . ' title="' . __( 'Accounts created at rackspacecloud.com are US-accounts; accounts created at rackspace.co.uk are UK-based', 'backupplus' ) . '">
					<option {{#ifeq "https://auth.api.rackspacecloud.com" authurl}}selected="selected"{{/ifeq}} value="https://auth.api.rackspacecloud.com">' . __( 'US (default)', 'backupplus' ) . '</option>
					<option {{#ifeq "https://lon.auth.api.rackspacecloud.com" authurl}}selected="selected"{{/ifeq}} value="https://lon.auth.api.rackspacecloud.com">' . __( 'UK', 'backupplus' ) . '</option>
				</select>
			</td>
		</tr>
		<tr class=""' . $classes . '>
			<th>' . __( 'Cloud Files Storage Region', 'backupplus' ) . ':</th>
			<td>
				<select data-backupplus_settings_test="region" ' . $this->output_settings_field_name_and_id( 'region', true ) . '>
					{{#each regions as |desc reg|}}
						<option {{#ifeq ../region reg}}selected="selected"{{/ifeq}} value="{{reg}}">{{desc}}</option>
					{{/each}}
				</select>
			</td>
		</tr>
		<tr class=""' . $classes . '>
			<th>' . __( 'Cloud Files Username', 'backupplus' ) . ':</th>
			<td><input data-backupplus_settings_test="user" type="text" autocomplete="off" class="backupplus_input--wide "' . $this->output_settings_field_name_and_id( 'user', true ) . ' value="{{user}}" />
			<div style="clear:both;">
				' . apply_filters( 'backupplus_cloudfiles_apikeysetting', '' ) . '
			</div>
			</td>
		</tr>
		<tr class=""' . $classes . '>
			<th>' . __( 'Cloud Files API Key', 'backupplus' ) . ':</th>
			<td><input data-backupplus_settings_test="apikey" type="' . apply_filters( 'backupplus_admin_secret_field_type', 'password' ) . '" autocomplete="off" class="backupplus_input--wide "' . $this->output_settings_field_name_and_id( 'apikey', true ) . ' value="{{apikey}}" />
			</td>
		</tr>
		<tr class=""' . $classes . '>
			<th>' . apply_filters( 'backupplus_cloudfiles_location_description', __( 'Cloud Files Container', 'backupplus' ) ) . ':</th>
			<td><input data-backupplus_settings_test="path" type="text" class="backupplus_input--wide "' . $this->output_settings_field_name_and_id( 'path', true ) . ' value="{{path}}" /></td>
		</tr>';
		return $template_str;
	}

	/**
	 * Modifies handerbar template options
	 *
	 * @param array $opts handerbar template options
	 * @return Array - Modified handerbar template options
	 */
	public function transform_options_for_template( $opts ) {
		$opts['regions'] = $this->regions;
		$opts['region']  = ( empty( $opts['region'] ) ) ? 'DFW' : $opts['region'];
		if ( isset( $opts['apikey'] ) ) {
			$opts['apikey'] = trim( $opts['apikey'] );
		}
		$opts['authurl'] = ! empty( $opts['authurl'] ) ? $opts['authurl'] : '';
		return $opts;
	}

	/**
	 * Perform a test of user-supplied credentials, and echo the result
	 *
	 * @param Array $posted_settings - settings to test
	 */
	public function credentials_test( $posted_settings ) {

		if ( empty( $posted_settings['apikey'] ) ) {
			/* translators: %s: API key */
			echo esc_html(
				sprintf(
				/* translators: %s: API key */
					__( 'Failure: No %s was given.', 'backupplus' ),
					__( 'API key', 'backupplus' )
				)
			);
			return;
		}

		if ( empty( $posted_settings['user'] ) ) {
			/* translators: %s: Username */
			echo esc_html(
				sprintf(
				/* translators: %s: Username */
					__( 'Failure: No %s was given.', 'backupplus' ),
					__( 'Username', 'backupplus' )
				)
			);
			return;
		}

		$opts = array(
			'user'    => $posted_settings['user'],
			'apikey'  => $posted_settings['apikey'],
			'authurl' => $posted_settings['authurl'],
			'region'  => ( empty( $posted_settings['region'] ) ) ? null : $posted_settings['region'],
		);

		$this->credentials_test_go( $opts, $posted_settings['path'], $posted_settings['useservercerts'], $posted_settings['disableverify'] );
	}

	/**
	 * Check whether options have been set up by the user, or not
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist( $opts ) {
		if ( is_array( $opts ) && isset( $opts['user'] ) && '' != $opts['user'] && ! empty( $opts['apikey'] ) ) {
			return true;
		}
		return false;
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
				{{#if storage_image_url}}
					<img alt="{{storage_long_description}}" src="{{storage_image_url}}">
				{{/if}}
				<br>
				{{{mb_substr_existence_label}}}
				{{{json_last_error_existence_label}}}
				{{{curl_existence_label}}}
				<br>
				<p>{{{rackspace_text_description}}}</p>
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
		<tr class="{{get_template_css_classes true}}">
			<th title="{{input_account_title}}">{{input_account_label}}:</th>
			<td>
				<select data-backupplus_settings_test="authurl" class="udc-wd-600" id="{{get_template_input_attribute_value "id" "authurl"}}" name="{{get_template_input_attribute_value "name" "authurl"}}" title="{{input_account_title}}">
					{{#each input_account_option_labels}}
						<option {{#ifeq ../authurl @key}}selected="selected"{{/ifeq}} value="{{@key}}">{{this}}</option>
					{{/each}}
				</select>
			</td>
		</tr>
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_region_label}}:</th>
			<td>
				<select data-backupplus_settings_test="region" class="udc-wd-600" id="{{get_template_input_attribute_value "id" "region"}}" name="{{get_template_input_attribute_value "name" "region"}}">
					{{#each regions as |desc reg|}}
						<option {{#ifeq ../region reg}}selected="selected"{{/ifeq}} value="{{reg}}">{{desc}}</option>
					{{/each}}
				</select>
			</td>
		</tr>
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_username_label}}:</th>
			<td><input data-backupplus_settings_test="user" type="text" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "user"}}" name="{{get_template_input_attribute_value "name" "user"}}" value="{{user}}" />
			<div style="clear:both;">
				{{#> backupplus_cloudfiles_apikeysetting}}
				{{/backupplus_cloudfiles_apikeysetting}}
			</div>
			</td>
		</tr>
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_apikey_label}}:</th>
			<td><input data-backupplus_settings_test="apikey" type="{{input_apikey_type}}" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "apikey"}}" name="{{get_template_input_attribute_value "name" "apikey"}}" value="{{apikey}}" />
			</td>
		</tr>
		<tr class="{{get_template_css_classes true}}">
			<th>{{input_container_label}}:</th>
			<td><input data-backupplus_settings_test="path" type="text" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "path"}}" name="{{get_template_input_attribute_value "name" "path"}}" value="{{path}}" /></td>
		</tr>';
		{{{get_template_test_button_html "Rackspace Cloud Files"}}}
		<?php
		return ob_get_clean();
	}

	/**
	 * Retrieve a list of template properties by taking all the persistent variables and methods of the parent class and combining them with the ones that are unique to this module, also the necessary HTML element attributes and texts which are also unique only to this backup module
	 * NOTE: Please sanitise all strings that are required to be shown as HTML content on the frontend side (i.e. wp_kses()), or any other technique to prevent XSS attacks that could come via WP hooks
	 *
	 * @return Array an associative array keyed by names that describe themselves as they are
	 */
	public function get_template_properties() {
		global $backupplus, $backupplus_admin;

		$mb_substr_existence_label = $json_last_error_existence_label = '';

		if ( ! apply_filters( 'backupplus_openstack_mbsubstr_exists', function_exists( 'mb_substr' ) ) ) {
			$mb_substr_existence_label = wp_kses(
				$backupplus_admin->show_double_warning(
					'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
					/* translators: %s: Missing PHP module */
					sprintf( __( 'Your web server\'s PHP installation does not include a required module (%s).', 'backupplus' ), 'mbstring' ) . ' ' .
					__( 'Please contact your web hosting provider\'s support.', 'backupplus' ) . ' ' .
					/* translators: 1: Module description, 2: Required module */
					sprintf( __( 'BackupPlus\'s %1$s module <strong>requires</strong> %2$s.', 'backupplus' ), $this->desc, 'mbstring' ) . ' ' .
					__( 'Please do not file any support requests; there is no alternative.', 'backupplus' ),
					$this->method,
					false
				),
				$this->allowed_html_for_content_sanitisation()
			);
		}

		if ( ! apply_filters( 'backupplus_rackspace_jsonlasterror_exists', function_exists( 'json_last_error' ) ) ) {
			$json_last_error_existence_label = wp_kses(
				$backupplus_admin->show_double_warning(
					'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
					/* translators: %s: Module description */
					sprintf( __( 'Your web server\'s PHP installation does not include a required module (%s).', 'backupplus' ), 'json' ) . ' ' .
					__( 'Please contact your web hosting provider\'s support.', 'backupplus' ) . ' ' .
					/* translators: 1: Module description, 2: Required module */
					sprintf( __( 'BackupPlus\'s %1$s module <strong>requires</strong> %2$s.', 'backupplus' ), 'Cloud Files', 'json' ) . ' ' .
					__( 'Please do not file any support requests; there is no alternative.', 'backupplus' ),
					'cloudfiles',
					false
				),
				$this->allowed_html_for_content_sanitisation()
			);
		}

		$properties = array(
			'storage_image_url'               => ! empty( $this->img_url ) ? BACKUPPLUS_URL . $this->img_url : '',
			'storage_long_description'        => $this->long_desc,
			'api_key_setting_default_label'   => '',
			'mb_substr_existence_label'       => $mb_substr_existence_label,
			'json_last_error_existence_label' => $json_last_error_existence_label,
			'curl_existence_label'            => wp_kses( $backupplus_admin->curl_check( $this->long_desc, false, $this->method . '', false ), $this->allowed_html_for_content_sanitisation() ),
			'rackspace_text_description'      => wp_kses(
				sprintf(
					/* translators: 1: API key URL, 2: instructions URL */
					__( 'Get your API key <a href="%1$s" target="_blank">from your Rackspace Cloud console</a> (<a href="%2$s" target="_blank">read instructions here</a>), then pick a container name to use for storage.', 'backupplus' ),
					'https://mycloud.rackspace.com/',
					'https://docs.rackspace.com/support/how-to/set-up-an-api-key-cloud-office-control-panel'
				) . ' ' . __( 'This container will be created for you if it does not already exist.', 'backupplus' ),
				$this->allowed_html_for_content_sanitisation()
			),
			'input_account_label'             => __( 'US or UK-based Rackspace Account', 'backupplus' ),
			'input_account_title'             => __( 'Accounts created at rackspacecloud.com are US accounts; accounts created at rackspace.co.uk are UK accounts.', 'backupplus' ),
			'input_account_option_labels'     => array(
				'https://auth.api.rackspacecloud.com'     => __( 'US (default)', 'backupplus' ),
				'https://lon.auth.api.rackspacecloud.com' => __( 'UK', 'backupplus' ),
			),
			'input_region_label'              => __( 'Cloud Files Storage Region', 'backupplus' ),
			'input_region_option_labels'      => $this->regions,
			'input_username_label'            => __( 'Cloud Files Username', 'backupplus' ),
			'input_username_placeholder'      => __( 'Enter your username', 'backupplus' ),
			'input_apikey_label'              => __( 'Cloud Files API Key', 'backupplus' ),
			'input_apikey_type'               => apply_filters( 'backupplus_admin_secret_field_type', 'password' ),
			'input_apikey_placeholder'        => __( 'Paste your key here', 'backupplus' ),
			'input_container_label'           => wp_kses( apply_filters( 'backupplus_cloudfiles_location_description', __( 'Cloud Files Container', 'backupplus' ) ), $this->allowed_html_for_content_sanitisation() ),
			'input_container_placeholder'     => __( 'Enter your container name', 'backupplus' ),
			/* translators: %s: Backup method */
			'input_test_label'                => sprintf( __( 'Test %s Settings', 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ),
		);
		return wp_parse_args( apply_filters( 'backupplus_' . $this->get_id() . '_template_properties', array() ), wp_parse_args( $properties, $this->get_persistent_variables_and_methods() ) );
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
		$prefix = 'input_' . $option['template_property_input_mapping'] . '_';

		if ( empty( $field['tooltip'] ) && isset( $template_properties[ $prefix . 'title' ] ) ) {
			$field['tooltip'] = array( 'text' => $template_properties[ $prefix . 'title' ] );
		}

		return $field;
	}
}
