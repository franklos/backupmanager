(function () {
    'use strict';
    const tr = text => OC.L10N.translate('backupstatus', text);
    const byId = id => document.getElementById(id);
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
        function visibility() {
            const ssh = form.elements.destination.value === 'ssh';
            byId('bm-ssh-fields').hidden = !ssh;
            byId('bm-s3-fields').hidden = ssh;
            byId('bm-trust').hidden = !ssh;
            byId('bm-provider').hidden = !ssh || form.elements.credential_mode.value !== 'managed';
        }
        async function loadSettings() {
            const data = await request('runtime', {}, 'GET');
            for (const [name, value] of Object.entries(data.settings)) {
                if (form.elements[name]) form.elements[name].value = value;
            }
            if (data.settings.client_id) byId('bm-client-id').value = data.settings.client_id;
            visibility();
        }
        form.elements.destination.addEventListener('change', visibility);
        form.elements.credential_mode.addEventListener('change', visibility);
        form.addEventListener('submit', async event => {
            event.preventDefault();
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
                byId('bm-job').textContent = tr('Operation status') + ': ' + job.state + (job.error ? ' — ' + job.error : '');
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
                catch (error) { message(error.message); }
                finally { if (id !== 'bm-cancel') button.disabled = false; }
            });
        }
        action('bm-pin', 'trust', () => ({key: byId('bm-host-key').value.trim(), fingerprint: byId('bm-host-fingerprint').value.trim()}));
        action('bm-test', 'test');
        action('bm-backup', 'backup');
        const polls = {};
        async function pollEnrollment(kind) {
            window.clearTimeout(polls[kind]);
            try {
                const data = await request(kind + '-status');
                byId('bm-enrollment-status').textContent = kind + ': ' + data.status;
                if (data.status === 'pending') polls[kind] = window.setTimeout(() => pollEnrollment(kind), 15000);
                else await loadSettings();
            } catch (error) {
                byId('bm-enrollment-status').textContent = error.message;
                polls[kind] = window.setTimeout(() => pollEnrollment(kind), 30000);
            }
        }
        action('bm-enroll', 'request-provider', () => ({consent: byId('bm-consent').checked ? '1' : '0', providerUrl: byId('bm-provider-url').value.trim(), providerEmail: byId('bm-provider-email').value.trim()}), data => {
            message(data.notificationSent ? tr('Request sent') : tr('Request created; notification failed. Contact the provider administrator.'));
            pollEnrollment('provider');
        });
        action('bm-resend', 'provider-resend');
        action('bm-recover', 'request-recovery', () => ({clientId: byId('bm-client-id').value.trim(), providerUrl: byId('bm-provider-url').value.trim()}), () => pollEnrollment('recovery'));
        byId('bm-inventory').addEventListener('click', async () => {
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
            } catch (error) { message(error.message); }
        });
        action('bm-verify', 'disaster-recovery', () => ({mode: 'verify', generation: byId('bm-generation').value}));
        action('bm-restore', 'restore', () => {
            if (!window.confirm(tr('Overwrite the selected Nextcloud components with this recovery point?'))) return null;
            return {type: byId('bm-restore-type').value, generation: byId('bm-generation').value, confirm: 'RESTORE'};
        });
        action('bm-cancel', 'cancel', () => ({id: currentJob}), () => message(tr('Cancellation requested. It is only applied before live restore changes.')));
        action('bm-remove', 'remove', () => {
            if (!window.confirm(tr('Proceed with the selected removal actions?'))) return null;
            return {removeLocal: byId('bm-remove-local').checked ? '1' : '0', removeRemote: byId('bm-remove-remote').checked ? '1' : '0', confirm: byId('bm-delete-confirm').value};
        }, data => { track(data); message(data.localRemovalScheduled ? tr('Local removal scheduled') : tr('Deletion request accepted')); });
        try { await loadSettings(); } catch (error) { message(error.message); }
        if (root.dataset.providerPending === '1') pollEnrollment('provider');
        if (root.dataset.recoveryPending === '1') pollEnrollment('recovery');
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
                throw new Error(data.error || "Unable to load clients");
            }

            container.textContent = "";

            if (!Array.isArray(data.clients) || data.clients.length === 0) {
                container.textContent =
                    OC.L10N.translate("backupstatus", "No clients found.");
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
                                    "HTTP " + response.status + ": " + raw
                                );
                            }
                        }

                        if (!response.ok || !result || !result.success) {
                            throw new Error(
                                (result && result.error)
                                    ? result.error
                                    : "HTTP " + response.status + ": empty response"
                            );
                        }

                        await loadManagementClients();
                    } catch (error) {
                        button.disabled = false;
                        window.alert(
                            error.message ||
                            OC.L10N.translate("backupstatus", "Client action failed")
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
                            "Are you sure you want to remove " + client.client_id +
                            "? Backup data will be preserved."
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
                            "Are you sure you want to permanently delete " + client.client_id +
                            "? All backup data will also be deleted. This cannot be undone."
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
                error.message ||
                OC.L10N.translate("backupstatus", "Unable to load clients.");
        }
    }


    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
