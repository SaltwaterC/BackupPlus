// Row selection in the existing backups table.

// Manage backups table selection
var backupplus_backups_selection = {};

// @codingStandardsIgnoreStart - to keep the doc blocks, as they're considered block comments by phpcs
(function($) {
	/**
	 * Toggle row seletion
	 *
	 * @param {HTMLDomElement|jQuery} el - row element
	 */
	backupplus_backups_selection.toggle = function(el) {
		var $el = $(el);
		if ($el.is('.backuprowselected')) {
			this.deselect(el);
		} else {
			this.select(el);
		}
	};

	/**
	 * Select row
	 *
	 * @param {HTMLDomElement|jQuery} el - row element
	 */
	backupplus_backups_selection.select = function(el) {
		$(el).addClass('backuprowselected');
		$(el).find('.backup-select input').prop('checked', true);
		this.checkSelectionStatus();
	};

	/**
	 * Deselect row
	 *
	 * @param {HTMLDomElement|jQuery} el - row element
	 */
	backupplus_backups_selection.deselect = function(el) {
		$(el).removeClass('backuprowselected');
		$(el).find('.backup-select input').prop('checked', false);
		this.checkSelectionStatus();
	};

	/**
	 * Select all rows
	 */
	backupplus_backups_selection.selectAll = function() {
		$('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').each(function(index, el) {
			backupplus_backups_selection.select(el);
		})
	};

	/**
	 * Deselect all rows
	 */
	backupplus_backups_selection.deselectAll = function() {
		$('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').each(function(index, el) {
			backupplus_backups_selection.deselect(el);
		})
	};

	/**
	 * Actions after a row selection/deselection
	 */
	backupplus_backups_selection.checkSelectionStatus = function() {
		var num_rows = $('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').length;
		var num_selected = $('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row.backuprowselected').length;
		// toggles actions upon seleted items
		if (num_selected > 0) {
			$('#ud_massactions').addClass('active');
			$('.js--deselect-all-backups, .js--delete-selected-backups, .js--lock-selected-backups, .js--unlock-selected-backups').prop('disabled', false);
		} else {
			$('#ud_massactions').removeClass('active');
			$('.js--deselect-all-backups, .js--delete-selected-backups, .js--lock-selected-backups, .js--unlock-selected-backups').prop('disabled', true);
		}
		// if all rows are selected, check the headind's checkbox
		if (num_rows === num_selected) {
			$('.cb-select-all').prop('checked', true);
		} else {
			$('.cb-select-all').prop('checked', false);
		}
		// if no backups, hide massaction
		if (!num_rows) {
			$('#ud_massactions').hide();
		} else {
			$('#ud_massactions').show();
		}
	}

	/**
	 * Multiple range selection
	 *
	 * @param {HTMLDomElement|jQuery} el - row element
	 */
	backupplus_backups_selection.selectAllInBetween = function(el) {
		var idx_start = this.firstMultipleSelectionIndex, idx_end = el.rowIndex-1;
		if (this.firstMultipleSelectionIndex > el.rowIndex-1) {
			idx_start = el.rowIndex-1; idx_end = this.firstMultipleSelectionIndex;
		}
		for (var i=idx_start; i<=idx_end; i++) {
			this.select($('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').eq(i));
		}
	}

	/**
	 * Multiple range selection event handler that gets executed when hovering the mouse over the row of existing backups. This function highlights the rows with color
	 */
	backupplus_backups_selection.highlight_backup_rows = function() {
		if ("undefined" === typeof backupplus_backups_selection.firstMultipleSelectionIndex) return;
		if (!$(this).hasClass('range-selection') && !$(this).hasClass('backuprowselected')) $(this).addClass('range-selection');
		$(this).siblings().removeClass('range-selection');
		if (backupplus_backups_selection.firstMultipleSelectionIndex+1 > this.rowIndex) {
			$(this).nextUntil('.backupplus_existing_backups_row.range-selection-start').addClass('range-selection');
		} else if (backupplus_backups_selection.firstMultipleSelectionIndex+1 < this.rowIndex) {
			$(this).prevUntil('.backupplus_existing_backups_row.range-selection-start').addClass('range-selection');
		}
	}

	/**
	 * Multiple range selection event handler that gets executed when the user releases the ctrl+shift button, it also gets executed when the mouse pointer is moved out from the browser page
	 * This function clears all the highlighted rows and removes hover and mouseleave event handlers
	 */
	backupplus_backups_selection.unregister_highlight_mode = function() {
		if ("undefined" === typeof backupplus_backups_selection.firstMultipleSelectionIndex) return;
		delete backupplus_backups_selection.firstMultipleSelectionIndex;
		$('#backupplus-navtab-backups-content .backupplus_existing_backups .backupplus_existing_backups_row').removeClass('range-selection range-selection-start');
		$('#backupplus-navtab-backups-content').off('mouseenter', '.backupplus_existing_backups .backupplus_existing_backups_row', this.highlight_backup_rows);
		$('#backupplus-navtab-backups-content').off('mouseleave', '.backupplus_existing_backups .backupplus_existing_backups_row', this.highlight_backup_rows);
		$(document).off('mouseleave', this.unregister_highlight_mode);
	}

	/**
	 * Register mouseleave and hover event handlers for highlighting purposes
	 */
	backupplus_backups_selection.register_highlight_mode = function() {
		$(document).on('mouseleave', backupplus_backups_selection.unregister_highlight_mode);
		$('#backupplus-navtab-backups-content').on('mouseenter', '.backupplus_existing_backups .backupplus_existing_backups_row', backupplus_backups_selection.highlight_backup_rows);
		$('#backupplus-navtab-backups-content').on('mouseleave', '.backupplus_existing_backups .backupplus_existing_backups_row', backupplus_backups_selection.highlight_backup_rows);
	}
})(jQuery);
// @codingStandardsIgnoreEnd
