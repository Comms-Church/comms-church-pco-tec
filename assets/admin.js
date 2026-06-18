/* comms-church-pco-tec — admin.js */
(function ($) {
    'use strict';

    $(function () {
        // Scroll sync log to bottom so most-recent entries are visible
        var $log = $('#cctec-log');
        if ($log.length) {
            $log.scrollTop($log[0].scrollHeight);
        }

        // Confirm before force re-sync (destructive-ish)
        $('button[name="cctec_force"]').closest('form').on('submit', function (e) {
            if (!window.confirm('This will overwrite all TEC event fields from PCO, ignoring change detection. Continue?')) {
                e.preventDefault();
            }
        });

        // Keep brand color picker and hex text input in sync
        var $picker = $('#cctec_brand_color');
        var $hex    = $('#cctec_brand_color_hex');
        if ($picker.length && $hex.length) {
            $picker.on('input', function () { $hex.val($picker.val()); });
            $hex.on('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test($hex.val())) {
                    $picker.val($hex.val());
                    // Keep both inputs submitting the same name
                    $picker.attr('name', '');
                    $hex.attr('name', 'cctec_brand_color');
                }
            });
            // On load, ensure the hex field is the one that submits
            $picker.attr('name', '');
            $hex.attr('name', 'cctec_brand_color');
        }
    });

}(jQuery));
