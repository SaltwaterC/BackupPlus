// Handlebars helpers and rendering of the remote storage templates.

jQuery(function($) {

	jQuery('#backupplus-hidethis').remove();
	
	/*
		* A Handlebarsjs helper function that is used to compare
		* two values if they are equal. Please refer to the example below.
		* Assuming "comment_status" contains the value of "spam".
		*
		* @param {mixed} a The first value to compare
		* @param {mixed} b The second value to compare
		*
		* @example
		* // returns "<span>I am spam!</span>", otherwise "<span>I am not a spam!</span>"
		* {{#ifeq "spam" comment_status}}
		*      <span>I am spam!</span>
		* {{else}}
		*      <span>I am not a spam!</span>
		* {{/ifeq}}
		*
		* @return {string}
	*/

	Handlebars.registerHelper('ifeq', function (a, b, opts) {
		if ('string' !== typeof a && 'undefined' !== typeof a && null !== a) a = a.toString();
		if ('string' !== typeof b && 'undefined' !== typeof b && null !== b) b = b.toString();
		if (a === b) {
			return opts.fn(this);
		} else {
			return opts.inverse(this);
		}
	});

	/*
		* Handlebars helper function to replace all password chars into asterisk char
		*
		* @param {string} password Required. The plain-text password
		*
		* @return {string}
	*/
	Handlebars.registerHelper('maskPassword', function(password) {
		return password.replace(/./gi,'*');
	});

	/*
		 * Handlebars helper function that wraps javascript encodeURIComponent so that it could encode the following characters: , / ? : @ & = + $ #
		 *
		 * @param {string} uri Required. The URI to be encoded
	 */
	Handlebars.registerHelper('encodeURIComponent', function(uri) {
		return encodeURIComponent(uri);
	});

	/**
	 * Handlebars helper function to compare two values using a specified operator
	 *
	 * @see https://stackoverflow.com/questions/8853396/logical-operator-in-a-handlebars-js-if-conditional#answer-16315366
	 *
	 * @param {mixed} v1 the first value to compare
	 * @param {mixed} v2 the second value to compare
	 *
	 * @return {boolean} true if the first value matched against the second value, false otherwise
	 */
	Handlebars.registerHelper('ifCond', function(v1, operator, v2, options) {
		switch (operator) {
			case '==':
				return (v1 == v2) ? options.fn(this) : options.inverse(this);
			case '===':
				return (v1 === v2) ? options.fn(this) : options.inverse(this);
			case '!=':
				return (v1 != v2) ? options.fn(this) : options.inverse(this);
			case '!==':
				return (v1 !== v2) ? options.fn(this) : options.inverse(this);
			case '<':
				return (v1 < v2) ? options.fn(this) : options.inverse(this);
			case '<=':
				return (v1 <= v2) ? options.fn(this) : options.inverse(this);
			case '>':
				return (v1 > v2) ? options.fn(this) : options.inverse(this);
			case '>=':
				return (v1 >= v2) ? options.fn(this) : options.inverse(this);
			case '&&':
				return (v1 && v2) ? options.fn(this) : options.inverse(this);
			case '||':
				return (v1 || v2) ? options.fn(this) : options.inverse(this);
			case 'typeof':
				return (v1 === typeof v2) ? options.fn(this) : options.inverse(this);
			case 'not_typeof':
				return (v1 !== typeof v2) ? options.fn(this) : options.inverse(this);
			default:
				return options.inverse(this);
		}
	});

	/**
	 * Handlebars helper function for looping through a block of code a specified number of times
	 *
	 * @param {mixed} from the start value
	 * @param {mixed} to   the end value where the loop will stop
	 * @param {mixed} incr the increment number
	 *
	 * @return {mixed} the current processing number
	 */
	Handlebars.registerHelper('for', function(from, to, incr, block) {
		var accum = '';
		for (var i = from; i < to; i += incr)
			accum += block.fn(i);
		return accum;
	});

	/**
	 * Assign value into a variable
	 *
	 * @param {string} name the variable name
	 * @param {mixed}  val  the value
	 */
	Handlebars.registerHelper('set_var', function(name, val, options) {
		if (!options.data.root) {
			options.data.root = {};
		}
		options.data.root[name] = val;
	});

	/**
	 * Get length of an array/object
	 *
	 * @param {mixed} object the object
	 */
	Handlebars.registerHelper('get_length', function(object) {
		if ("undefined" !== typeof object && false === object instanceof Array) {
			return Object.keys(object).length;
		} else if (true === object instanceof Array) {
			return object.length;
		} else {
			return 0;
		}
	});

	/**
	 * Return a space-separated list of CSS classes suitable for rows in the configuration section
	 *
	 * @see BackupPlus_BackupModule::get_css_classes()
	 *
	 * @param {boolean} include_instance a boolean value to indicate if we want to include the instance_id in the css class, we may not want to include the instance if it's for a UI element that we don't want to be removed along with other UI elements that do include a instance id
	 * @return {string} the list of CSS classes
	 */
	Handlebars.registerHelper('get_template_css_classes', function(include_instance, options) {
		var css_classes = options.data.root.css_class + ' ' + options.data.root.method_id;
		if (!include_instance || !options.data.root['is_multi_options_feature_supported']) return css_classes;
		if (options.data.root['is_config_templates_feature_supported']) {
			css_classes += ' ' + options.data.root.method_id + '-' + options.data.root.instance_id;
		} else {
			css_classes += ' ' + options.data.root.method_id + '-' + options.data.root._instance_id;
		}
		return css_classes;
	});

	/**
	 * Output the value of an id or name attribute, as if currently within an input tag
	 * This assumes standardised options handling (i.e. that the options array is backupplus_(method-id))
	 *
	 * @see BackupPlus_BackupModule::output_settings_field_name_and_id()
	 *
	 * @param {string} input_attribute The attribute of an input tag
	 * @param {mixed}  fields          the field identifiers
	 * @return {string} a specific value to the given input attribute
	 */
	Handlebars.registerHelper('get_template_input_attribute_value', function(input_attribute, fields, options) {
		var instance_id = options.data.root['is_config_templates_feature_supported'] ? options.data.root.instance_id : options.data.root._instance_id;
		var id = ename = '';
		var method_id = options.data.root.method_id;
		try {
			fields = JSON.parse(fields);
		} catch (e) {}
		if ("undefined" !== typeof fields && Array === fields.constructor) {
			for (var i=0; i<fields.length; i++) {
				id += '_' + fields[i];
				ename += '[' + fields[i] + ']';
			}
		} else {
			id = '_' + fields;
			ename = '[' + fields + ']';
		}
		if ('id' === input_attribute) {
			return 'backupplus_'+method_id+id+'_'+instance_id;
		} else if ('name' === input_attribute) {
			return 'backupplus_'+method_id+'[settings]['+instance_id+']'+ename;
		} else {
			return '';
		}
	});

	/**
	 * Return HTML a row of HTML elements for a test button
	 *
	 * @see BackupPlus_BackupModule::get_test_button_html()
	 *
	 * @param {string} title The text to be used in the button
	 * @return {string} The HTML to be inserted into the settings page
	 */
	Handlebars.registerHelper('get_template_test_button_html', function(title, options) {
		var instance_id = options.data.root['is_config_templates_feature_supported'] ? Handlebars.escapeExpression(options.data.root.instance_id) : Handlebars.escapeExpression(options.data.root._instance_id);
		var css_classes = Handlebars.escapeExpression(Handlebars.helpers.get_template_css_classes.apply(this, [true, options]));
		var input_label = Handlebars.escapeExpression(options.data.root.input_test_label);
		var method_id = Handlebars.escapeExpression(options.data.root.method_id);
		return '\
		<tr class="'+css_classes+'"> \
			<th></th> \
			<td> \
				<p> \
					<button id="backupplus-'+method_id+'-test-'+instance_id+'" type="button" class="button-primary backupplus-test-button backupplus-'+method_id+'-test-'+instance_id+'" data-instance_id="'+instance_id+'" data-method="'+method_id+'" data-method_label="'+title+'">'+input_label+'</button> \
				</p> \
			</td> \
		</tr>';
	});

	// Add remote methods html using handlebarjs
	if ($('#remote-storage-holder').length) {
		var html = '';
		var not_instance_ids = ['default', 'template_properties'];
		for (var method in backupplus_i18n.remote_storage_templates) {
			if ('undefined' != typeof backupplus_i18n.remote_storage_options[method] && not_instance_ids.length < Object.keys(backupplus_i18n.remote_storage_options[method]).length) {
				var template = Handlebars.compile(backupplus_i18n.remote_storage_templates[method]);
				for (var partial_template_name in backupplus_i18n.remote_storage_partial_templates[method]) {
					Handlebars.registerPartial(partial_template_name, Handlebars.compile(backupplus_i18n.remote_storage_partial_templates[method][partial_template_name]));
				}
				var first_instance = true;
				var instance_count = 1;
				for (var instance_id in backupplus_i18n.remote_storage_options[method]) {
					if (not_instance_ids.indexOf(instance_id) > -1) continue;
					
					var context = {}; // Initiate a reference by assigning an empty object to a variable (in this case the context variable) so that it can be used as a target of merging one or more other objects. Unlike basic values (boolean, string, integer, etc.), in Javascript objects and arrays are passed by reference
					// copy what are in the template properties to the context overwriting the same object properties, and then copy what are in the instance settings to the context overwriting all the same properties from the previous merging operation (if any). The context properties are overwritten by other objects that have the same properties later in the parameters order
					Object.assign(context, backupplus_i18n.remote_storage_options[method]['template_properties'], backupplus_i18n.remote_storage_options[method][instance_id]);

					if ('undefined' == typeof context['instance_conditional_logic']) {
						context['instance_conditional_logic'] = {
							type: '', // always by default
							rules: [],
						};
					}
					context['instance_conditional_logic'].day_of_the_week_options = backupplus_i18n.conditional_logic.day_of_the_week_options;
					context['instance_conditional_logic'].logic_options = backupplus_i18n.conditional_logic.logic_options;
					context['instance_conditional_logic'].operand_options = backupplus_i18n.conditional_logic.operand_options;
					context['instance_conditional_logic'].operator_options = backupplus_i18n.conditional_logic.operator_options;

					context['first_instance'] = first_instance;
					if ('undefined' == typeof context['instance_enabled']) {
						context['instance_enabled'] = 1;
					}
					if ('undefined' == typeof context['instance_label'] || '' == context['instance_label']) {
						var method_name = backupplus_i18n.remote_storage_methods[method];
						var instance_label = ' (' + instance_count + ')';
						if (1 == instance_count) {
							instance_label = '';
						}
						context['instance_label'] = method_name + instance_label;
					}
					html += template(context);
					first_instance = false;
					instance_count++;
				}
			} else {
				html += backupplus_i18n.remote_storage_templates[method];
			}
		}
		$('#remote-storage-holder').append(html).ready(function () {
			$('.backupplusmethod').not('.none').hide();
			backupplus_remote_storage_tabs_setup();
			backupplus_setup_remote_storage_config_link();
			backupplus_scroll_to_remote_storage_config();
			// Displays warning to the user of their mistake if they try to enter a URL in the OneDrive settings and saved
			$('#remote-storage-holder .backupplus_onedrive_folder_input').trigger('keyup');
			initialize_remote_storage_select2_elements(jQuery('#remote-storage-holder'));
		});
	}

});
