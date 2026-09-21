(function () {
    'use strict';
    const tr = text => OC.L10N.translate('backupstatus', text);
    const byId = id => document.getElementById(id);
    const statusLabels = {green: 'Ready', orange: 'Attention required', red: 'Failed', neutral: 'Not tested'};
    const jobStates = {queued: 'Queued', running: 'Running', verifying: 'Verifying', restoring: 'Restoring', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled'};
    function setSectionStatus(root, index, state) {
        const section = root.querySelectorAll('.bm-accordion')[index];
        if (!section) return;
        section.classList.remove('bm-status-green', 'bm-status-orange', 'bm-status-red', 'bm-status-neutral');
        section.classList.add('bm-status-' + state);
        const label = section.querySelector('[data-status-label]');
        if (label) label.textContent = tr(statusLabels[state]);
    }
    function updateProviderStatus(root) {
        const provider = root.dataset.providerStatus || 'none';
        const recovery = root.dataset.recoveryStatus || 'none';
        const runtimeId = root.dataset.runtimeClientId || '';
        const clientId = root.dataset.clientId || '';
        const live = root.providerClients?.find(client => client.client_id === clientId);
        const matches = Boolean(clientId && runtimeId === clientId);
        const details = byId('bm-enrollment-status');
        if (details) details.textContent = [
            tr('Provider status') + ': ' + (live ? live.status : provider),
            tr('Enrollment request') + ': ' + provider,
            tr('Access recovery request (SSH key replacement)') + ': ' + recovery,
        ].join(' — ');
        // A recovery request rotates keys independently of the existing client's lifecycle.
        if (live && matches) {
            setSectionStatus(root, 0, live.status === 'active' ? 'green' : 'orange');
        } else if (['failed', 'rejected'].includes(provider) || ['failed', 'rejected'].includes(recovery)) {
            setSectionStatus(root, 0, 'red');
        } else if (['pending', 'stale'].includes(recovery) || ['pending', 'stale'].includes(provider) || (provider === 'approved' && (!runtimeId || (clientId && runtimeId !== clientId)))) {
            setSectionStatus(root, 0, 'orange');
        } else if (provider === 'approved' && matches && !root.providerClients) {
            setSectionStatus(root, 0, 'green');
        } else {
            setSectionStatus(root, 0, 'neutral');
        }
    }

    function renderHostStatus(root, settings) {
        root.dataset.hostTrusted = settings.host_trusted ? '1' : '0';
        root.dataset.hostKey = settings.host_key || '';
        root.dataset.hostFingerprint = settings.host_fingerprint || '';
        byId('bm-host-key').value = settings.host_key || '';
        byId('bm-host-fingerprint').value = settings.host_fingerprint || '';
        byId('bm-ssh-verification-status').textContent = settings.host_error || tr(settings.host_trusted ? 'Host verified.' : 'Host verification has not been completed.');
        setSectionStatus(root, 1, settings.host_error ? 'red' : settings.host_trusted ? 'green' : 'orange');
    }
    async function request(route, values = {}, method = 'POST') {
        const options = {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'requesttoken': OC.requestToken}};
        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            options.body = new URLSearchParams(values).toString();
        }
        const response = await fetch(OC.generateUrl('/apps/backupstatus/settings/' + route), options);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || tr('Operation failed'));
        return data;
    }
    function message(text) { byId('bm-message').textContent = text; }
    async function ready() {
        const root = byId('backupstatus-admin');
        if (!root) return;
        const form = byId('bm-settings');
        const dayChecks = Array.from(root.querySelectorAll('.bm-day'));
        function syncDaysFromChecks() { byId('bm-days').value = dayChecks.filter(check => check.checked).map(check => check.dataset.day).join(','); }
        function syncChecksFromDays(value) { const selected = new Set(String(value || '').split(',').filter(Boolean)); dayChecks.forEach(check => { check.checked = selected.has(check.dataset.day); }); syncDaysFromChecks(); }
        dayChecks.forEach(check => check.addEventListener('change', syncDaysFromChecks));
        const mainSections = Array.from(root.querySelectorAll('.bm-accordion'));
        let foundOpen = false;
        mainSections.forEach(section => { if (section.open && foundOpen) section.open = false; else if (section.open) foundOpen = true; });
        mainSections.forEach((section, index) => {
            setSectionStatus(root, index, 'neutral');
            section.addEventListener('toggle', () => {
                if (!section.open) return;
                mainSections.forEach(other => { if (other !== section) other.open = false; });
                loadStatus();
            });
        });
        updateProviderStatus(root);
        setSectionStatus(root, 1, 'orange');
        function visibility() {
            const ssh = form.elements.destination.value === 'ssh';
            byId('bm-ssh-fields').hidden = !ssh;
            byId('bm-s3-fields').hidden = ssh;
            byId('bm-trust').hidden = !ssh;
            byId('bm-provider').hidden = !ssh || form.elements.credential_mode.value !== 'managed';
            setSectionStatus(root, 1, ssh ? 'orange' : 'neutral');
        }
        async function loadSettings() {
            const data = await request('runtime', {}, 'GET');
            for (const [name, value] of Object.entries(data.settings)) {
                if (form.elements[name]) form.elements[name].value = value;
            }
            root.dataset.runtimeClientId = data.settings.client_id || '';
            if (data.settings.client_id) byId('bm-client-id').value = data.settings.client_id;
            updateProviderStatus(root);
            syncChecksFromDays(data.settings.days);
            visibility();
            if (form.elements.destination.value === 'ssh') renderHostStatus(root, data.settings);
        }
        form.elements.destination.addEventListener('change', visibility);
        form.elements.credential_mode.addEventListener('change', visibility);
        async function loadStatus() {
            try {
                const data = await request('runtime-status', {}, 'GET');
                const status = data.status || {};
                byId('bm-status-state').textContent = status.state || tr('Unknown');
                byId('bm-status-success').textContent = status.last_success || '—';
                byId('bm-status-attempt').textContent = status.last_attempt || '—';
                byId('bm-status-generation').textContent = status.generation || '—';
                byId('bm-status-error').textContent = status.error || '';
                const state = status.state;
                const overall = ['ok', 'restored'].includes(state) ? 'green' : ['failed', 'maintenance_required'].includes(state) ? 'red' : ['stale', 'missing'].includes(state) ? 'orange' : 'neutral';
                setSectionStatus(root, 2, overall); setSectionStatus(root, 5, overall);
                if (state === 'failed' || state === 'maintenance_required') setSectionStatus(root, 4, 'red');
                else if (state === 'ok' || state === 'restored') setSectionStatus(root, 4, 'green');
                else if (state) setSectionStatus(root, 4, 'orange');
            } catch (error) { byId('bm-status-error').textContent = error.message; }
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            syncDaysFromChecks();
            const settings = Object.fromEntries(new FormData(form).entries());
            for (const key of ['ssh_port', 'retention_days', 'stale_hours']) settings[key] = Number(settings[key]);
            try {
                const response = await fetch(OC.generateUrl('/apps/backupstatus/settings'), {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'requesttoken': OC.requestToken},
                    body: new URLSearchParams({settings: JSON.stringify(settings)}).toString()
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || tr('Save failed'));
                await loadSettings();
                message(tr('Settings saved'));
            } catch (error) { message(error.message); }
            finally {
                for (const key of ['s3_access_key', 's3_secret_key', 's3_session_token']) form.elements[key].value = '';
            }
        });
        let currentJob = localStorage.getItem('backupmanager-job') || '';
        let jobTimer;
        async function pollJob() {
            if (!currentJob) return;
            try {
                const data = await request('job', {id: currentJob});
                const job = data.job;
                byId('bm-job').textContent = tr('Operation status') + ': ' + tr(jobStates[job.state] || 'Unknown') + (job.error ? ' — ' + job.error : '');
                byId('bm-cancel').disabled = !['verify', 'restore'].includes(job.action) || !['queued', 'running'].includes(job.state);
                if (['completed', 'failed', 'cancelled'].includes(job.state)) {
                    localStorage.removeItem('backupmanager-job');
                    currentJob = '';
                    window.dispatchEvent(new CustomEvent('backupstatus:changed'));
                } else jobTimer = window.setTimeout(pollJob, 3000);
            } catch (error) {
                byId('bm-job').textContent = error.message;
                jobTimer = window.setTimeout(pollJob, 15000);
            }
        }
        function track(data) {
            if (data.job) {
                currentJob = data.job.id;
                localStorage.setItem('backupmanager-job', currentJob);
                window.clearTimeout(jobTimer);
                pollJob();
            }
        }
        function action(id, route, values = () => ({}), after = data => { track(data); message(tr('Request accepted')); }) {
            byId(id).addEventListener('click', async () => {
                const button = byId(id);
                const input = values();
                if (input === null) return;
                button.disabled = true;
                try { after(await request(route, input)); }
                catch (error) {
                    const sectionMap = {'bm-enroll': 0, 'bm-resend': 0, 'bm-recover': 0, 'bm-pin': 1, 'bm-test': 2, 'bm-backup': 2, 'bm-inventory': 3, 'bm-verify': 4, 'bm-restore': 4, 'bm-cancel': 4, 'bm-remove': 0};
                    if (sectionMap[id] !== undefined) setSectionStatus(root, sectionMap[id], 'red');
                    if (id === 'bm-pin') { byId('bm-technical').open = true; byId('bm-ssh-verification-status').textContent = error.message; root.dataset.hostTrusted = '0'; }
                    message(error.message);
                }
                finally { if (id !== 'bm-cancel') button.disabled = false; }
            });
        }
        action('bm-pin', 'trust', () => (byId('bm-host-key').value.trim() === root.dataset.hostKey && byId('bm-host-fingerprint').value.trim() === root.dataset.hostFingerprint) ? {} : ({key: byId('bm-host-key').value.trim(), fingerprint: byId('bm-host-fingerprint').value.trim()}), data => { renderHostStatus(root, data.settings); byId('bm-technical').open = false; });
        action('bm-test', 'test', () => ({}), () => { setSectionStatus(root, 2, 'green'); message(tr('Connection tested successfully.')); });
        action('bm-backup', 'backup', () => ({}), data => { track(data); setSectionStatus(root, 2, 'orange'); message(tr('Backup started.')); });
        const polls = {};
        async function pollEnrollment(kind) {
            window.clearTimeout(polls[kind]);
            try {
                const data = await request(kind + '-status');
                root.dataset[ kind === 'provider' ? 'providerStatus' : 'recoveryStatus'] = data.status;
                if (data.clientId) root.dataset.clientId = data.clientId;
                updateProviderStatus(root);
                if (data.status === 'pending') polls[kind] = window.setTimeout(() => pollEnrollment(kind), 15000);
                else await loadSettings();
            } catch (error) {
                byId('bm-enrollment-status').textContent = error.message;
                updateProviderStatus(root);
                message(error.message);
                polls[kind] = window.setTimeout(() => pollEnrollment(kind), 30000);
            }
        }
        action('bm-enroll', 'request-provider', () => ({consent: byId('bm-consent').checked ? '1' : '0', providerUrl: byId('bm-provider-url').value.trim(), providerEmail: byId('bm-provider-email').value.trim()}), data => {
            message(data.notificationSent ? tr('Request sent') : tr('Request created; notification failed. Contact the provider administrator.'));
            root.dataset.providerStatus = 'pending';
            updateProviderStatus(root);
            pollEnrollment('provider');
        });
        action('bm-resend', 'provider-resend');
        action('bm-recover', 'request-recovery', () => ({clientId: byId('bm-client-id').value.trim(), providerUrl: byId('bm-provider-url').value.trim()}), () => { root.dataset.recoveryStatus = 'pending'; updateProviderStatus(root); pollEnrollment('recovery'); });
        async function loadInventory() {
            try {
                const data = await request('restore-inventory', {}, 'GET');
                byId('bm-generation').replaceChildren();
                for (const point of data.generations) {
                    const option = document.createElement('option');
                    option.value = point.id;
                    option.textContent = point.created_at + ' — ' + point.id;
                    byId('bm-generation').appendChild(option);
                }
                byId('bm-storage').textContent = tr('Recovery point storage') + ': ' + (data.used_bytes / 1048576).toFixed(1) + ' MiB';
                setSectionStatus(root, 3, data.generations.length ? 'green' : 'orange');
            } catch (error) { setSectionStatus(root, 3, 'red'); message(error.message); }
        }
        byId('bm-inventory').addEventListener('click', loadInventory);
        mainSections[3].addEventListener('toggle', () => { if (mainSections[3].open) loadInventory(); });
        action('bm-verify', 'disaster-recovery', () => ({mode: 'verify', generation: byId('bm-generation').value}), data => { track(data); setSectionStatus(root, 4, 'orange'); message(tr('Verification started.')); });
        action('bm-restore', 'restore', () => {
            if (!window.confirm(tr('Overwrite the selected Nextcloud components with this recovery point?'))) return null;
            return {type: byId('bm-restore-type').value, generation: byId('bm-generation').value, confirm: 'RESTORE'};
        }, data => { track(data); setSectionStatus(root, 4, 'orange'); message(tr('Restore started.')); });
        action('bm-cancel', 'cancel', () => ({id: currentJob}), () => message(tr('Cancellation requested. It is only applied before live restore changes.')));
        action('bm-remove', 'remove', () => {
            if (!window.confirm(tr('Proceed with the selected removal actions?'))) return null;
            return {removeLocal: byId('bm-remove-local').checked ? '1' : '0', removeRemote: byId('bm-remove-remote').checked ? '1' : '0', confirm: byId('bm-delete-confirm').value};
        }, data => { track(data); message(data.localRemovalScheduled ? tr('Local removal scheduled') : tr('Deletion request accepted')); });
        try { await loadSettings(); } catch (error) { message(error.message); }
        loadStatus();
        pollEnrollment('provider');
        pollEnrollment('recovery');
        if (currentJob) pollJob();
        loadManagementClients();
    }
    async function loadManagementClients() {
        const container = document.getElementById("backupstatus-clients");

        if (!container) {
            return;
        }

        try {
            const response = await fetch(
                OC.generateUrl("/apps/backupstatus/settings/management-clients"),
                {
                    method: "GET",
                    headers: {
                        "Accept": "application/json",
                        "requesttoken": OC.requestToken
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || tr('Unable to load clients.'));
            }

            const root = byId('backupstatus-admin');
            root.providerClients = Array.isArray(data.clients) ? data.clients : [];
            updateProviderStatus(root);
            container.textContent = "";

            if (!Array.isArray(data.clients) || data.clients.length === 0) {
                container.textContent =
                    tr('No clients found.');
                return;
            }

            data.clients.forEach(function (client) {
                const row = document.createElement("div");
                row.className = "backupstatus-client-row";

                const text = document.createElement("span");
                text.textContent =
                    client.client_id + " | " +
                    client.source_id + " | " +
                    client.status;

                row.appendChild(text);

                async function runClientAction(action, button) {
                    button.disabled = true;

                    try {
                        const response = await fetch(
                            OC.generateUrl(
                                "/apps/backupstatus/settings/management-clients/{clientId}/{action}",
                                {
                                    clientId: client.client_id,
                                    action: action
                                }
                            ),
                            {
                                method: "POST",
                                headers: {
                                    "Accept": "application/json",
                                    "requesttoken": OC.requestToken
                                }
                            }
                        );

                        const raw = await response.text();
                        let result = null;

                        if (raw) {
                            try {
                                result = JSON.parse(raw);
                            } catch (e) {
                                throw new Error(
                                    tr('HTTP error') + " " + response.status + ": " + raw
                                );
                            }
                        }

                        if (!response.ok || !result || !result.success) {
                            throw new Error(
                                (result && result.error)
                                    ? result.error
                                    : tr('HTTP empty response') + " " + response.status
                            );
                        }

                        await loadManagementClients();
                    } catch (error) {
                        button.disabled = false;
                        window.alert(
                            error.message ||
                            tr('Client action failed')
                        );
                    }
                }

                if (client.status === "active" || client.status === "suspended") {
                    const action = client.status === "active" ? "pause" : "resume";
                    const stateButton = document.createElement("button");

                    stateButton.type = "button";
                    stateButton.textContent =
                        action === "pause"
                            ? OC.L10N.translate("backupstatus", "Pause")
                            : OC.L10N.translate("backupstatus", "Resume");

                    stateButton.addEventListener("click", function () {
                        runClientAction(action, stateButton);
                    });

                    row.appendChild(stateButton);

                    const removeButton = document.createElement("button");
                    removeButton.type = "button";
                    removeButton.textContent =
                        OC.L10N.translate("backupstatus", "Remove");

                    removeButton.addEventListener("click", function () {
                        if (!window.confirm(
                            tr('Confirm remove client') + " " + client.client_id + "?"
                        )) {
                            return;
                        }

                        runClientAction("remove", removeButton);
                    });

                    row.appendChild(removeButton);
                }

                if (client.status === "terminated") {
                    const deleteButton = document.createElement("button");
                    deleteButton.type = "button";
                    deleteButton.textContent =
                        OC.L10N.translate("backupstatus", "Delete permanently");

                    deleteButton.addEventListener("click", function () {
                        if (!window.confirm(
                            tr('Confirm permanent delete client') + " " + client.client_id + "?"
                        )) {
                            return;
                        }

                        runClientAction("delete", deleteButton);
                    });

                    row.appendChild(deleteButton);
                }

                container.appendChild(row);
            });
        } catch (error) {
            container.textContent =
                error.message || tr('Unable to load clients.');
        }
    }


    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
