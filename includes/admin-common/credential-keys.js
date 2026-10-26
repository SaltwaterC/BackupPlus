// Installation encryption key management.

// Installation encryption keys: server-side file management without shell access.
jQuery(function($) {
	var panel = $('#backupplus-credential-keys');
	if (!panel.length) return;
	var status = panel.find('.backupplus-key-status');
	function send(operation, value) {
		panel.find('button').prop('disabled', true);
		$.ajax({
			url: ajaxurl, type: 'POST', dataType: 'json',
			data: {action: 'backupplus_credential_keys', nonce: panel.data('nonce'), operation: operation, value: value, directory: $('#backupplus-key-directory').val()},
			success: function(response) {
				status.text(response.message);
				if (response.success) $('#backupplus-key-directory').val(response.directory);
			},
			error: function() { status.text(backupplus_i18n.unexpectedresponse + ' ' + backupplus_i18n.reload_page); },
			complete: function() {
				panel.find('button').prop('disabled', false);
				if ($('#backupplus-key-directory').prop('disabled')) panel.find('[data-key-operation="move"]').prop('disabled', true);
			}
		});
	}
	panel.on('click', '[data-key-operation]', function() {
		var operation = $(this).data('key-operation');
		if ('restore' === operation) {
			var file = $('#backupplus-key-recovery')[0].files[0];
			if (!file) { status.text($(this).data('choose-file')); return; }
			if (file.size > 1048576) { status.text($(this).data('choose-file')); return; }
			var reader = new FileReader();
			reader.onload = function(event) { send(operation, event.target.result); };
			reader.onerror = function() { status.text(backupplus_i18n.unexpectedresponse); };
			reader.readAsText(file);
		} else {
			send(operation, 'move' === operation ? $('#backupplus-key-directory').val() : '');
		}
	});
	$('#backupplus-key-download').on('click', function() {
		var form = $('<form>', {method: 'POST', action: panel.data('download-url')});
		$('<input>', {type: 'hidden', name: 'action', value: 'backupplus_recovery_key'}).appendTo(form);
		$('<input>', {type: 'hidden', name: '_wpnonce', value: panel.data('nonce')}).appendTo(form);
		form.appendTo(document.body).trigger('submit').remove();
	});
});
