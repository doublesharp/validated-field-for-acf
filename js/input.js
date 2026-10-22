(function ($) {
    'use strict';
    function initialize($scope) {
        $scope.find('[data-vf="1"]').addBack('[data-vf="1"]').each(function () {
            var $field = $(this);
            if ($field.closest('.acf-clone').length) return;
            if ($field.attr('data-vf-readonly') === '1') {
                $field.find(':input').not('[type="hidden"]').each(function () {
                    var $input = $(this);
                    if ($input.data('vfReadonly')) return;
                    $input.data('vfReadonly', true);
                    if ($input.is('textarea') || ($input.is('input') && ['text', 'email', 'url', 'tel', 'number', 'password'].indexOf(this.type) !== -1)) {
                        $input.prop('readonly', true);
                    } else {
                        var name = $input.attr('name');
                        if (name && (!$input.is(':checkbox,:radio') || $input.prop('checked'))) {
                            var values = $input.val(), $after = $input;
                            $.each(Array.isArray(values) ? values : [values], function (_, value) {
                                $after = $('<input>', {type: 'hidden', name: name, value: value}).insertAfter($after);
                            });
                        }
                        $input.prop('disabled', true);
                    }
                });
                if (!$field.data('vfReadonlyCapture')) {
                    $field.data('vfReadonlyCapture', true);
                    this.addEventListener('click', function (event) {
                        if ($(event.target).closest('a,button,.acf-actions').length) {
                            event.preventDefault(); event.stopImmediatePropagation();
                        }
                    }, true);
                }
            }
            var mask = $field.attr('data-vf-mask');
            if (!mask || !$.fn.mask) return;
            $field.find('input[type="text"], input[type="email"], input[type="url"], input[type="tel"]').each(function () {
                var $input = $(this);
                if ($input.data('vfMask') === mask) return;
                $input.mask(mask, {
                    placeholder: $field.attr('data-vf-placeholder') || '_',
                    autoclear: $field.attr('data-vf-autoclear') === '1'
                });
                $input.data('vfMask', mask);
            });
        });
    }
    if (window.acf) {
        acf.addAction('ready append', initialize);
        acf.addAction('new_field', function (field) { initialize(field.$el); });
    }
})(jQuery);
