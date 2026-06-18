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
    });

}(jQuery));
