// The file tree widget and select2 helpers for remote storage fields.

/**
 * Creates an instance of the backupplus_js_tree class.
 *
 * @constructor
 * @param {string} remote_storage - The remote storage identifier.
 */
function backupplus_js_tree(remote_storage) {

	var self = this; // Store a reference to the object
	self.remote_storage = remote_storage;

	/**
	 * Hides the jstree and related elements.
	 *
	 * @param {jQuery} parent - The parent element containing the jstree.
	 */
	this.hide_jstree = function(parent) {
		parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').removeAttr('readonly');
			
		parent.find('.backupplus_' + self.remote_storage + '_select_folder').show();
		parent.find('.backupplus_' + self.remote_storage + '_container').hide();

		// hide action buttons
		parent.find('.backupplus_jstree_cancel').hide();
		parent.find('.backupplus_jstree_confirm').hide();
	};

	/**
	 * Gets the structured folder path based on the selected node in the jstree.
	 *
	 * @param {Object} selected_node - The selected node in the jstree.
	 * @returns {string} The structured folder path.
	 */
	this.get_structured_folder = function(selected_node) {
		var level = selected_node.node.parents.length;
		var selected_id = selected_node.node.id;
		var parent = jQuery("#" + selected_id);
		var parents =  selected_node.node.text + '/';

		for (var ln = 0; ln <= level -1; ln++) {
			var parent = parent.parent().parent();
			if (parent.children()[1] != undefined) {
				parents = parent.children()[1].text + '/' + parents;
			}
		}
		if (parents.length > 0) {
			parents = parents.substring(0, parents.length - 1);
		}

		return parents;
	};

	/**
	 * Lists folders from the remote storage and initializes the jstree.
	 *
	 * @param {string} entity - The entity type.
	 * @param {jQuery} parent - The parent element where the jstree will be displayed.
	 * @param {string} instance_id - The instance identifier.
	 */
	this.list_folders = function(entity, parent, instance_id) {
		parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').prop('readOnly', true);
		parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').attr('previous-value', parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').val());

		parent.find('.backupplus_' + self.remote_storage + '_select_folder').hide();
		parent.find('.backupplus_' + self.remote_storage + '_container').show();

		// show action buttons
		parent.find('.backupplus_jstree_cancel').show();
		parent.find('.backupplus_jstree_confirm').show();

		// init jstree
		parent.find('.backupplus_' + self.remote_storage + '_jstree_container').jstree({
			"core": {
				"multiple": false,
				"data": function (nodeid, callback) {
					backupplus_send_command('get_jstree_directory_nodes', {entity:entity, node:nodeid, instance_id:instance_id}, function(response) {
						if (response.hasOwnProperty('error')) {
							jQuery('.backupplus_include_' + self.remote_storage + '_paths_error').text(response.error);
						} else {
							jQuery('.backupplus_include_' + self.remote_storage + '_paths_error').text('');
							callback.call(this, response.nodes);
						}
					});
				}
			},
			'plugins' : ['sort','types'],
			'sort' : function(a, b) {
				a1 = this.get_node(a);
				b1 = this.get_node(b);
				if (a1.icon == b1.icon) {
					return (a1.text > b1.text) ? 1 : -1;
				} else {
					return (a1.icon < b1.icon) ? 1 : -1;
				}
			},
		});

		// Detect change on the tree and update the input that has been marked as editing
		parent.find('.backupplus_' + self.remote_storage + '_jstree_container').on("changed.jstree", function (e, data) {
			parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').val(self.get_structured_folder(data));
		});

		parent.find('.backupplus_' + self.remote_storage + '_jstree_confirm, .backupplus_' + self.remote_storage + '_jstree_cancel').on('click', function(e) {
			e.preventDefault();

			if (jQuery(this).hasClass('backupplus_' + self.remote_storage + '_jstree_cancel')) {
				parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').val(parent.find('[id^="backupplus_' + self.remote_storage + '_folder"]').attr('previous-value'));
			}
			
			self.hide_jstree(parent);
		});
	};

	/**
	 * Initializes the backupplus_js_tree instance by setting up event handlers.
	 */
	this.init = function() {
	  jQuery(function() {
		jQuery('.backupplus_' + self.remote_storage + '_select_folder').on('click', function(e) {
		  e.preventDefault();
		  var $parent = jQuery(this).closest('tr');
		  var instance_id = $parent.find('[id^="backupplus_' + self.remote_storage + '_folder_"]').attr('id').replace('backupplus_' + self.remote_storage + '_folder_', '');
		  self.list_folders(self.remote_storage, $parent, instance_id);
		});
	  });
	};

}

/**
 * Initializes Select2 dynamic input for all <select> elements matching the given object of remote storage.
 *
 * @param {string} remote_storage_elements - An object of HTML elements of the remote storage template
 * @returns {void}
 */
function initialize_remote_storage_select2_elements(remote_storage_elements) {
	// <select> tag added to any remote storage configuration should have `select2` defined in its class attribute
	var select_element = jQuery(remote_storage_elements).find('select.select2-storage-config');
	for (var i=0; i < select_element.length; i++) {
		// Initialize select2 text input.
		jQuery(select_element[i]).select2({
			tags: true
		});
		// any specific actions applied to remote storage should be implemented by first checking the remote storage ID
		if ('dreamobjects' === jQuery(select_element[i]).data('storage-id')) {
			if ('endpoint' === jQuery(select_element[i]).data('field-id')) {
				jQuery(select_element[i]).on('change', function(e) {
					validate_dreamobjects_endpoint(e.target);
				});
				validate_dreamobjects_endpoint(select_element[i]);
			}
		}
	}
}

/**
 * Validate selected DreamObjects endpoint.
 *
 * @param {HTMLSelectElement} select_element - The <select> element for DreamObjects endpoint.
 *
 * @returns {void}
 */
function validate_dreamobjects_endpoint(select_element) {
	var endpoint = select_element.value.trim();
	// Show or hide error message depending on endpoint validity.
	if (backupplus_i18n.dreamobject_endpoints.includes(endpoint) || new RegExp(backupplus_i18n.dreamobject_endpoint_regex, 'i').test(endpoint)) {
		select_element.classList.remove('backupplus-input--invalid');
	} else {
		select_element.classList.add('backupplus-input--invalid');
	}
}
