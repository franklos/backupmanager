(function () {
    'use strict';

    function start() {
        const dashboard = document.getElementById('backupstatus-dashboard');
        if (!dashboard) return;
        const options = {dateStyle: 'medium', timeStyle: 'long'};
        let formatter;
        try {
            formatter = new Intl.DateTimeFormat(dashboard.dataset.locale.replace(/_/g, '-'), options);
        } catch (error) {
            formatter = new Intl.DateTimeFormat(undefined, options);
        }
        // Use the user's browser timezone, including daylight-saving rules.
        dashboard.querySelectorAll('time[datetime]').forEach(function (element) {
            const date = new Date(element.getAttribute('datetime'));
            if (!Number.isNaN(date.getTime())) element.textContent = formatter.format(date);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
