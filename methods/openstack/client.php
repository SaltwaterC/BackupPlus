<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

// SDK uses namespacing - requires PHP 5.3 (actually the SDK states its requirements as 5.3.3)
// @codingStandardsIgnoreLine
use OpenCloud\OpenStack;

backupplus_try_include_file( 'methods/openstack/base.php', 'require_once' );

class BackupPlus_BackupModule_openstack extends BackupPlus_BackupModule_openstack_base {

	/**
	 * Input and option field mappings with default values and supported contexts.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array(
		'authurl'  => array(
			'default_value'                   => '',
			'template_property_input_mapping' => 'authentication_uri',
			'contexts'                        => array( 'option', 'input' ),
		),
		'tenant'   => array(
			'default_value' => '',
			'contexts'      => array( 'option', 'input' ),
		),
		'region'   => array(
			'default_value' => '',
			'contexts'      => array( 'option', 'input' ),
		),
		'user'     => array(
			'default_value'                   => '',
			'template_property_input_mapping' => 'username',
			'contexts'                        => array( 'option', 'input' ),
		),
		'password' => array(
			'default_value' => '',
			'contexts'      => array( 'option', 'input' ),
		),
		'path'     => array(
			'default_value'                   => '',
			'template_property_input_mapping' => 'container',
			'contexts'                        => array( 'option', 'input' ),
		),
	);

	public function __construct() {
		// 4th parameter is a relative (to BACKUPPLUS_DIR) logo URL, which should begin with /, should we get approved for use of the OpenStack logo in future (have requested info)
		parent::__construct( 'openstack', 'OpenStack', 'OpenStack (Swift)', '' );
	}

	/**
	 * Get Openstack service
	 *
	 * @param  String  $opts             THis contains: 'tenant', 'user', 'password', 'authurl', (optional) 'region'
	 * @param  Boolean $useservercerts   User server certificates
	 * @param  String  $disablesslverify Check to disable SSL Verify
	 * @return Array
	 */
	public function get_openstack_service( $opts, $useservercerts = false, $disablesslverify = null ) {

		// 'tenant', 'user', 'password', 'authurl', 'path', (optional) 'region'
		extract( $opts );

		if ( null === $disablesslverify ) {
			$disablesslverify = BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' );
		}

		if ( empty( $user ) || empty( $password ) || empty( $authurl ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $user, $password and $authurl being extracted in extract() line 29
			throw new Exception( __( 'Authorisation failed (check your credentials)', 'backupplus' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message to be escaped when caught and printed.
		}

		backupplus_try_include_file( 'vendor/autoload.php', 'include_once' );
		global $backupplus;
		$backupplus->log( 'OpenStack authentication URL: ' . $authurl );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $authurl being extracted in extract() line 29

		$client       = new OpenStack(
			$authurl,
			array(// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $authurl being extracted in extract() line 29
			'username'   => $user, // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $user being extracted in extract() line 29
			'password'   => $password, // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $password being extracted in extract() line 29
			'tenantName' => $tenant, // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $tenant being extracted in extract() line 29
			)
		);
		$this->client = $client;

		if ( $disablesslverify ) {
			$client->setSslVerification( false );
		} else {
			if ( $useservercerts ) {
				$client->setConfig( array( $client::SSL_CERT_AUTHORITY => false ) );
			} else {
				$client->setSslVerification( BACKUPPLUS_DIR . '/includes/cacert.pem', true, 2 );
			}
		}

		$client->authenticate();

		if ( empty( $region ) ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- The variable is defined below.
			$catalog = $client->getCatalog();
			if ( ! empty( $catalog ) ) {
				$items = $catalog->getItems();
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						$name = $item->getName();
						$type = $item->getType();
						if ( 'swift' != $name || 'object-store' != $type ) {
							continue;
						}
						$eps = $item->getEndpoints();
						if ( ! is_array( $eps ) ) {
							continue;
						}
						foreach ( $eps as $ep ) {
							if ( is_object( $ep ) && ! empty( $ep->region ) ) {
								$region = $ep->region;
							}
						}
					}
				}
			}
		}

		$this->region = $region;

		return $client->objectStoreService( 'swift', $region );
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

	public function credentials_test( $posted_settings ) {

		if ( empty( $posted_settings['user'] ) ) {
			/* translators: %s: Username */
			echo esc_html( sprintf( __( 'Failure: No %s was given.', 'backupplus' ), __( 'username', 'backupplus' ) ) );
			return;
		}

		if ( empty( $posted_settings['password'] ) ) {
			/* translators: %s: Password */
			echo esc_html( sprintf( __( 'Failure: No %s was given.', 'backupplus' ), __( 'password', 'backupplus' ) ) );
			return;
		}

		if ( empty( $posted_settings['tenant'] ) ) {
			/* translators: %s: Tenant (used in OpenStack storage) */
			echo esc_html( sprintf( __( 'Failure: No %s was given.', 'backupplus' ), _x( 'tenant', '"tenant" is a term used with OpenStack storage - Google for "OpenStack tenant" to get more help on its meaning', 'backupplus' ) ) );
			return;
		}

		if ( empty( $posted_settings['authurl'] ) ) {
			/* translators: %s: Authentication URI */
			echo esc_html( sprintf( __( 'Failure: No %s was given.', 'backupplus' ), __( 'authentication URI', 'backupplus' ) ) );
			return;
		}

		$opts = array(
			'user'     => $posted_settings['user'],
			'password' => $posted_settings['password'],
			'authurl'  => $posted_settings['authurl'],
			'tenant'   => $posted_settings['tenant'],
			'region'   => empty( $posted_settings['region'] ) ? '' : $posted_settings['region'],
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
		if ( is_array( $opts ) && $opts['user'] && '' !== $opts['user'] && ! empty( $opts['authurl'] ) ) {
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
				{{{curl_existence_label}}}
				<br>
				<p>{{openstack_text_description}}</p>
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
			<th>{{input_authentication_uri_label}}:</th>
			<td><input title="{{input_authentication_uri_title}}" data-backupplus_settings_test="authurl" type="text" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "authurl"}}" name="{{get_template_input_attribute_value "name" "authurl"}}" value="{{authurl}}" />
			<br>
			<em>{{input_authentication_uri_title}}</em>
			</td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th><a href="{{input_tenant_link_url}}" title="{{input_tenant_link_title}}" target="_blank">{{input_tenant_label}}</a>:</th>
			<td><input data-backupplus_settings_test="tenant" type="text" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "tenant"}}" name="{{get_template_input_attribute_value "name" "tenant"}}" value="{{tenant}}" />
			</td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th>{{input_region_label}}:</th>
			<td><input title="{{input_region_title}}" data-backupplus_settings_test="region" type="text" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "region"}}" name="{{get_template_input_attribute_value "name" "region"}}" value="{{region}}" />
			<br>
			<em>{{input_region_title}}</em>
			</td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th>{{input_username_label}}:</th>
			<td><input data-backupplus_settings_test="user" type="text" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "user"}}" name="{{get_template_input_attribute_value "name" "user"}}" value="{{user}}" />
			</td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th>{{input_password_label}}:</th>
			<td><input data-backupplus_settings_test="password" type="{{input_password_type}}" autocomplete="off" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "password"}}" name="{{get_template_input_attribute_value "name" "password"}}" value="{{password}}" />
			</td>
		</tr>

		<tr class="{{get_template_css_classes true}}">
			<th>{{input_container_label}}:</th>
			<td><input data-backupplus_settings_test="path" type="text" class="backupplus_input--wide udc-wd-600" id="{{get_template_input_attribute_value "id" "path"}}" name="{{get_template_input_attribute_value "name" "path"}}" value="{{path}}" /></td>
		</tr>
		{{{get_template_test_button_html "OpenStack (Swift)"}}}
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

		$mb_substr_existence_label = '';
		if ( ! apply_filters( 'backupplus_openstack_mbsubstr_exists', function_exists( 'mb_substr' ) ) ) {
			$mb_substr_existence_label = wp_kses(
				$backupplus_admin->show_double_warning(
					'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
					/* translators: %s: Required module name */
					sprintf( __( 'Your web server\'s PHP installation does not include a required module (%s).', 'backupplus' ), 'mbstring' ) . ' ' .
					__( 'Please contact your web hosting provider\'s support.', 'backupplus' ) . ' ' .
					/* translators: 1: Module name, 2: Required module name */
					sprintf( __( 'BackupPlus\'s %1$s module <strong>requires</strong> %2$s.', 'backupplus' ), $this->desc, 'mbstring' ) . ' ' .
					__( 'Please do not file any support requests; there is no alternative.', 'backupplus' ),
					$this->method,
					false
				),
				$this->allowed_html_for_content_sanitisation()
			);
		}

		$properties = array(
			'storage_image_url'                    => ! empty( $this->img_url ) ? BACKUPPLUS_URL . $this->img_url : '',
			'storage_long_description'             => $this->long_desc,
			'mb_substr_existence_label'            => $mb_substr_existence_label,
			'curl_existence_label'                 => wp_kses( $backupplus_admin->curl_check( $this->long_desc, false, $this->method . '', false ), $this->allowed_html_for_content_sanitisation() ),
			'openstack_text_description'           => __( 'Get your access credentials from your OpenStack Swift provider, and then pick a container name to use for storage.', 'backupplus' ) . ' ' . __( 'This container will be created for you if it does not already exist.', 'backupplus' ),
			'input_authentication_uri_label'       => __( 'Authentication URI', 'backupplus' ),
			'input_authentication_uri_title'       => _x( 'This needs to be a v2 (Keystone) authentication URI; v1 (Swauth) is not supported.', 'Keystone and swauth are technical terms which cannot be translated', 'backupplus' ),
			'input_authentication_uri_placeholder' => __( 'Example: https://keystone.example.com/v2.0', 'backupplus' ),
			'input_tenant_label'                   => __( 'Tenant', 'backupplus' ),
			'input_tenant_link_url'                => 'https://docs.openstack.org/openstack-ops/content/projects_users.html',
			'input_tenant_link_title'              => __( 'Follow this link for more information', 'backupplus' ),
			'input_tenant_placeholder'             => __( 'Example: my-project', 'backupplus' ),
			'input_region_label'                   => __( 'Region', 'backupplus' ),
			'input_region_title'                   => __( 'Leave this blank, and a default will be chosen.', 'backupplus' ),
			'input_region_placeholder'             => __( 'Enter a region', 'backupplus' ),
			'input_username_label'                 => __( 'Username', 'backupplus' ),
			'input_username_placeholder'           => __( 'Enter your username', 'backupplus' ),
			'input_password_label'                 => __( 'Password', 'backupplus' ),
			'input_password_type'                  => apply_filters( 'backupplus_admin_secret_field_type', 'password' ),
			'input_container_label'                => __( 'Container', 'backupplus' ),
			'input_container_placeholder'          => __( 'Example: backups', 'backupplus' ),
			/* translators: %s: Remote storage method */
			'input_test_label'                     => sprintf( __( 'Test %s Settings', 'backupplus' ), $backupplus->backup_methods[ $this->get_id() ] ),
		);
		return wp_parse_args( $properties, $this->get_persistent_variables_and_methods() );
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
