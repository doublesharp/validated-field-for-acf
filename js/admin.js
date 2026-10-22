(function ($) {
    'use strict';
    function initialize($scope) {
        $scope = $scope && $scope.$el ? $scope.$el : $($scope || document);
        $scope.find('.vf-rule-setting textarea').each(function () {
            if ($(this).data('vfEditor') || !window.wp || !wp.codeEditor || !acfVfSettings.editor) return;
            $(this).data('vfEditor', true);
            var editor = wp.codeEditor.initialize(this, acfVfSettings.editor);
            if (editor) {
                editor.codemirror.on('change', function (cm) { cm.save(); });
                setTimeout(function () { editor.codemirror.refresh(); }, 0);
            }
        });
    }
    $(document).on('change', '.vf-type-setting select', function () {
        var $select = $(this), $field = $select.closest('.acf-field-object'),
            $panel = $field.find('.vf-native-settings').first();
        var previous = $panel.data('vfRequest');
        if (previous) previous.abort();
        $select.prop('disabled', true);
        var request = $.post(acf.get('ajaxurl'), {
            action: 'acf_vf_sub_settings', nonce: acfVfSettings.nonce,
            type: $select.val(), key: $panel.attr('data-key'), prefix: $panel.attr('data-prefix')
        }).done(function (response) {
            if (!response.success) return;
            acf.doAction('remove', $panel);
            $panel.html(response.data.html);
            acf.doAction('append', $panel);
        }).fail(function (xhr, status) {
            if (status !== 'abort') window.alert('The input settings could not be loaded. Please reload before saving.');
        }).always(function () { $select.prop('disabled', false); $panel.removeData('vfRequest'); });
        $panel.data('vfRequest', request);
    });
    if (window.acf) {
        acf.addAction('ready append', initialize);
        acf.addAction('open_field', initialize);
    }
})(jQuery);
