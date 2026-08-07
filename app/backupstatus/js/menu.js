(function () {
    'use strict';

    const BUTTON_ID = 'backupstatus-header-button';
    const STATUS_ROUTE = '/apps/backupstatus/status';
    const DASHBOARD_ROUTE = '/apps/backupstatus/dashboard';

    function findHeaderRight() {
        return document.querySelector('#header-right')
            || document.querySelector('#header .header-right')
            || document.querySelector('header .header-right')
            || document.querySelector('[data-cy="header-right"]');
    }

    function findNotificationItem(headerRight) {
        const selectors = [
            '#notifications',
            '#notification-container',
            '[data-cy="notifications"]',
            '[data-testid="notifications"]',
            '[aria-label*="otific"]',
            '[title*="otific"]',
            '.notifications',
            '.notification-container'
        ];

        for (const selector of selectors) {
            const found = headerRight.querySelector(selector);
            if (found) {
                return found.closest('li, .header-menu, .header-menu__trigger, .header-menu__item, div, a, button') || found;
            }
        }

        // Nextcloud gebruikt vaak een bel-icoon in een button of anchor.
        const candidates = headerRight.querySelectorAll('button, a');
        for (const candidate of candidates) {
            const text = [
                candidate.getAttribute('aria-label') || '',
                candidate.getAttribute('title') || '',
                candidate.id || '',
                candidate.className || ''
            ].join(' ').toLowerCase();
            if (text.includes('notification') || text.includes('notificatie') || text.includes('melding')) {
                return candidate.closest('li, .header-menu, .header-menu__trigger, .header-menu__item, div') || candidate;
            }
        }

        return null;
    }

    function placeButton(button) {
        const headerRight = findHeaderRight();
        if (!headerRight) {
            if (!button.isConnected) {
                button.classList.add('backupstatus-fixed-fallback');
                document.body.appendChild(button);
            }
            return;
        }

        button.classList.remove('backupstatus-fixed-fallback');
        button.classList.add('backupstatus-in-header');

        const notificationItem = findNotificationItem(headerRight);
        if (notificationItem && notificationItem.parentNode) {
            if (button.nextElementSibling !== notificationItem || button.parentNode !== notificationItem.parentNode) {
                notificationItem.parentNode.insertBefore(button, notificationItem);
            }
            return;
        }

        // Fallback: vóór het eerste header-item, zodat de knop links van de bel blijft.
        if (headerRight.firstElementChild) {
            if (headerRight.firstElementChild !== button) {
                headerRight.insertBefore(button, headerRight.firstElementChild);
            }
        } else {
            headerRight.appendChild(button);
        }
    }

    function createButton() {
        let button = document.getElementById(BUTTON_ID);
        if (button) {
            placeButton(button);
            return button;
        }

        button = document.createElement('a');
        button.id = BUTTON_ID;
        button.className = 'backupstatus-header-button backupstatus-offline';
        button.title = t('backupstatus', 'Offline');
        button.setAttribute('aria-label', t('backupstatus', 'Offline'));
        button.innerHTML = '<span class="backupstatus-header-icon" aria-hidden="true"></span>';

        placeButton(button);
        return button;
    }

    function apply(button, state, label) {
        const safeState = ['ok', 'issue', 'offline'].includes(state) ? state : 'offline';
        button.classList.remove('backupstatus-ok', 'backupstatus-issue', 'backupstatus-offline');
        button.classList.add('backupstatus-' + safeState);
        button.title = label;
        button.setAttribute('aria-label', label);
        button.href = OC.generateUrl(DASHBOARD_ROUTE);
        button.target = '_self';
        button.tabIndex = 0;
    }

    async function refresh() {
        if (typeof OC === 'undefined') {
            return;
        }

        const button = createButton();
        try {
            const response = await fetch(OC.generateUrl(STATUS_ROUTE), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            const data = await response.json();
            apply(button, data.state || 'offline', data.label || t('backupstatus', 'Offline'));
        } catch (error) {
            apply(button, 'offline', t('backupstatus', 'Offline'));
        }
    }

    function start() {
        refresh();
        window.addEventListener('backupstatus:changed', refresh);
        window.setInterval(refresh, 300000);

        // Nextcloud kan de header dynamisch opnieuw opbouwen. Zet de knop dan terug.
        const observer = new MutationObserver(function () {
            const button = document.getElementById(BUTTON_ID);
            if (button) {
                placeButton(button);
            }
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
