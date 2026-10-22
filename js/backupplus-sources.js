/* Built-in backup source controls. All names are rendered as text, never HTML. */
jQuery(function ($) {
    function update($selector) {
        $selector.find('.bp-table-choices').toggle($selector.find('.bp-table-mode').val() === 'selected');
    }
    $('.bp-table-selector').each(function () { update($(this)); });
    $(document).on('change', '.bp-table-mode', function () { update($(this).closest('.bp-table-selector')); });
    $(document).on('input', '.bp-table-search', function () {
        var query = this.value.toLowerCase();
        $(this).closest('.bp-table-selector').find('.bp-table-list label').each(function () { $(this).toggle($(this).text().toLowerCase().includes(query)); });
    });
    $(document).on('click', '.bp-select-tables', function () {
        $(this).closest('.bp-table-selector').find('.bp-table-list label:visible input').prop('checked', $(this).data('check') === 1);
    });
    $(document).on('click', '.bp-discover', function () {
        var $selector = $(this).closest('.bp-table-selector'), $connection = $selector.closest('.bp-connection');
        var request = {action: 'backupplus_source_tables', nonce: backupplus_sources.nonce};
        if ($connection.length) {
            if ($connection.find('.bp-saved-connection').length) { request.saved = $connection.find('.bp-saved-connection').val(); }
            else { request.connection = {}; $connection.find('[data-field]').each(function () { request.connection[$(this).data('field')] = this.value; }); }
        }
        var $button = $(this).prop('disabled', true), $status = $selector.find('.bp-connection-status').text(' …');
        $.post(backupplus_sources.ajax, request).done(function (response) {
            if (!response.success) { $status.text(backupplus_sources.error); return; }
            var selected = $selector.find('.bp-table:checked').map(function () { return this.value; }).get();
            var name = $selector.data('manual') === 1 ? '' : 'backupplus_database_selection[' + $selector.data('database') + '][tables][]';
            var $list = $selector.find('.bp-table-list').empty();
            // Retain missing selected names so a refresh cannot silently broaden or reduce coverage.
            Array.from(new Set(response.data.tables.concat(selected))).forEach(function (table) {
                var $input = $('<input>', {type: 'checkbox', class: 'bp-table', name: name, value: table}).prop('checked', selected.includes(table));
                $('<label>').addClass('bp-table-label').append($input, document.createTextNode(' ' + table + (response.data.tables.includes(table) ? '' : ' (missing)'))).appendTo($list);
            });
            $status.text(' ✓');
        }).fail(function () { $status.text(backupplus_sources.error); }).always(function () { $button.prop('disabled', false); });
    });
    $(document).on('click', '.bp-add-database', function () {
        var $container = $(this).closest('.bp-databases'), $next = $container.find('.bp-next-db-id'), id = Number($next.val());
        $next.val(id + 1);
        $container.find('.bp-external-connections').append($container.find('template').html().replace(/NEWID/g, String(id)));
        update($container.find('.bp-table-selector').last());
    });
    $(document).on('click', '.bp-remove-database', function () { $(this).closest('.bp-connection').remove(); });
    function collect($selectors) {
        var result = {}, invalid = false;
        $selectors.each(function () {
            var $selector = $(this), entry = {enabled: $selector.find('.bp-db-enabled').prop('checked'), mode: $selector.find('.bp-table-mode').val(), tables: $selector.find('.bp-table:checked').map(function () { return this.value; }).get()};
            if (entry.enabled && entry.mode === 'selected' && !entry.tables.length) { invalid = true; }
            result[$selector.data('database')] = entry;
        });
        return invalid ? null : result;
    }
    // One JSON field prevents PHP's max_input_vars from truncating exact table lists.
    $(document).on('submit', 'form', function (event) {
        var $selectors = $(this).find('.bp-databases[data-manual="0"] .bp-table-selector');
        if (!$selectors.length) { return; }
        var selections = collect($selectors);
        if (!selections) { event.preventDefault(); alert(backupplus_sources.empty); return; }
        $selectors.find('[name^="backupplus_database_selection["]').prop('disabled', true);
        $(this).find('input.bp-selection-payload').remove();
        $('<input>', {type:'hidden', class:'bp-selection-payload', name:'backupplus_database_selection', value:JSON.stringify(selections)}).appendTo(this);
    });
    window.backupplus_database_override = function () {
        return collect($('#backupnow_database_moreoptions .bp-table-selector'));
    };
});
