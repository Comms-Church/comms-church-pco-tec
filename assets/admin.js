/* comms-church-pco-tec — admin.js */
(function ($) {
    'use strict';

    $(function () {
        // Scroll sync log to bottom so most-recent entries are visible
        var $log = $('#cctec-log');
        if ($log.length) {
            $log.scrollTop($log[0].scrollHeight);
        }

        // Confirm before force re-sync (destructive-ish). The name lives on the
        // hidden input, not the button.
        $('input[name="cctec_force"]').closest('form').on('submit', function (e) {
            if (!window.confirm('This will overwrite all TEC event fields from PCO, ignoring change detection. Continue?')) {
                e.preventDefault();
            }
        });

        // A sync runs inside the request and can take a while: show it's working
        // and block a second click from starting another one.
        $('input[name="cctec_action"][value="sync_now"]').closest('form').on('submit', function (e) {
            if (e.isDefaultPrevented()) return;
            $(this).find('button[type="submit"]').prop('disabled', true).text('Syncing\u2026')
                .after('<span class="spinner is-active" style="float:none;margin:0 0 0 6px"></span>');
        });

        // Keep brand color picker and hex text input in sync
        var $picker = $('#cctec_brand_color');
        var $hex    = $('#cctec_brand_color_hex');
        if ($picker.length && $hex.length) {
            $picker.on('input', function () { $hex.val($picker.val()); });
            $hex.on('input', function () {
                var valid = /^#[0-9a-fA-F]{6}$/.test($hex.val());
                // sanitize_hex_color() blanks anything else on save — say so now.
                $hex.attr('aria-invalid', valid ? null : 'true')
                    .css('border-color', valid ? '' : '#d63638');
                if (valid) {
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
