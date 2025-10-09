(function ($) {
    $(function () {
        var roleField = $('#role');

        if (!roleField.length) {
            return;
        }

        var warningId = 'isolated-sites-site-editor-warning';
        var messageText = "⚠️ Configuration warning: 'Site Editor' requires 'limit_to_granted_sites' enabled and at least one default site for items.";
        var warningContainer = $('#' + warningId);

        if (!warningContainer.length) {
            warningContainer = $('<div>', {
                id: warningId,
                class: 'messages warning',
                css: { display: 'none' },
                text: messageText
            });

            var roleFieldWrapper = roleField.closest('.field');

            if (roleFieldWrapper.length) {
                roleFieldWrapper.before(warningContainer);
            } else {
                roleField.before(warningContainer);
            }
        }

        var limitToGrantedSitesCheckbox = $('input[type="checkbox"][name="user-settings[limit_to_granted_sites]"]');
        var defaultSitesSelect = $('#default_item_sites');

        if (!defaultSitesSelect.length) {
            defaultSitesSelect = $('select[name="user-settings[default_item_sites][]"], select[name="user-settings[default_item_sites]"]');
        }

        function isLimitChecked() {
            if (!limitToGrantedSitesCheckbox.length) {
                return true;
            }

            return limitToGrantedSitesCheckbox.is(':checked');
        }

        function hasDefaultSitesSelected() {
            if (!defaultSitesSelect.length) {
                return true;
            }

            var value = defaultSitesSelect.val();

            if (!value) {
                return false;
            }

            if (Array.isArray(value)) {
                return value.filter(function (item) {
                    return item !== null && item !== '';
                }).length > 0;
            }

            return true;
        }

        function toggleWarning() {
            var isSiteEditor = roleField.val() === 'site_editor';
            var configurationIsValid = isLimitChecked() && hasDefaultSitesSelected();

            if (isSiteEditor && !configurationIsValid) {
                warningContainer.show();
            } else {
                warningContainer.hide();
            }
        }

        roleField.on('change', toggleWarning);
        limitToGrantedSitesCheckbox.on('change', toggleWarning);
        defaultSitesSelect.on('change', toggleWarning);

        toggleWarning();
    });
})(jQuery);
