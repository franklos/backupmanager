(function () {
    'use strict';
    const tr = text => OC.L10N.translate('backupstatus', text);
    const errorMessage = record => tr(record.error_message || 'The operation failed. Check the server logs.');
    const uiError = error => error instanceof TypeError || error instanceof SyntaxError ? tr('The operation failed. Check the server logs.') : (error.message || tr('The operation failed. Check the server logs.'));
    const byId = id => document.getElementById(id);
    const statusLabels = {green: 'Ready', orange: 'Attention required', red: 'Failed', neutral: 'Not tested'};
    const requestStates = {none:'Not tested', pending:'Waiting for approval', stale:'Request expired', superseded:'Request expired', expired:'Request expired', approved:'Approved', rejected:'Rejected', active:'Ready', suspended:'Paused', removed:'Removed', terminated:'Removed', failed:'Failed'};
    const requestState = value => tr(requestStates[value] || 'Unknown');
    const jobStates = {queued: 'Queued', running: 'Running', verifying: 'Verifying', restoring: 'Restoring', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled'};
    // Keep backup state terminology aligned with BackupStatusService (dashboard/header).
    const backupStates = {
        ok: 'Backup current', restored: 'Restore completed',
        running: 'Backup running', restoring: 'Restore running',
        stale: 'Backup overdue', missing: 'No successful backup',
        maintenance_required: 'Operator intervention required',
        failed: 'Backup failed',
    };
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
            tr('Provider status') + ': ' + requestState(live ? live.status : provider),
            tr('Enrollment request') + ': ' + requestState(provider),
            tr('Access recovery request (SSH key replacement)') + ': ' + requestState(recovery),
        ].join(' — ');
        // A recovery request rotates keys independently of the existing client's lifecycle.
        if (root.dataset.providerError === '1') {
            setSectionStatus(root, 0, 'red');
        } else if (['pending', 'stale', 'expired', 'failed', 'rejected'].includes(recovery)) {
            setSectionStatus(root, 0, ['failed', 'rejected'].includes(recovery) ? 'red' : 'orange');
        } else if (live && matches) {
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
        byId('bm-ssh-verification-status').textContent = settings.host_error ? tr(settings.host_error_message || 'SSH host verification failed.') : tr(settings.host_trusted ? 'Host key pinned. Test the connection to check write and read access.' : 'Host verification has not been completed.');
        setSectionStatus(root, 1, settings.host_error ? 'red' : settings.host_trusted ? 'green' : 'orange');
        const label = root.querySelectorAll('.bm-accordion')[1]?.querySelector('[data-status-label]');
        if (label && settings.host_trusted && !settings.host_error) label.textContent = tr('Host key pinned');
    }
    function renderConnectionTest(root, job) {
        if (job.action !== 'test') return;
        if (job.state === 'completed' && job.result?.connected) {
            setSectionStatus(root, 2, 'green');
            const label = root.querySelectorAll('.bm-accordion')[2]?.querySelector('[data-status-label]');
            if (label) label.textContent = tr('Connection test passed');
            message(tr('Connection tested successfully.'));
        } else if (['failed', 'cancelled', 'completed'].includes(job.state)) {
            setSectionStatus(root, 2, 'red');
            message(errorMessage(job));
        } else {
            setSectionStatus(root, 2, 'orange');
            message(tr('Connection test running.'));
        }
    }

    const storageFields = {
        ssh_manual: ['ssh_host', 'ssh_port', 'ssh_user', 'ssh_path'],
        ssh_managed: [],
        aws_s3: ['s3_region', 's3_bucket', 's3_prefix', 's3_sse'],
        s3_compatible: ['s3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_sse'],
    };
    function storageProfile(form) {
        return form.elements.destination.value === 'ssh' ? 'ssh_' + form.elements.credential_mode.value : form.elements.destination.value;
    }
    function switchStorageProfile(form, state) {
        // Keep only nonsecret drafts. Never carry typed credentials to another destination.
        if (state.name) {
            const draft = {...state.profiles[state.name]};
            for (const key of storageFields[state.name]) {
                draft[key] = key === 's3_region' && state.name === 'aws_s3'
                    ? byId('bm-aws-region-select').value : form.elements[key].value;
            }
            state.profiles[state.name] = draft;
        }
        state.name = storageProfile(form);
        const values = {ssh_host: '', ssh_port: 22, ssh_user: 'backupstore', ssh_path: '/',
            s3_endpoint: '', s3_region: 'us-east-1', s3_bucket: '', s3_prefix: '', s3_sse: '',
            ...state.profiles[state.name]};
        for (const key of storageFields[state.name]) {
            if (key === 's3_region' && state.name === 'aws_s3') byId('bm-aws-region-select').value = values[key];
            else form.elements[key].value = values[key];
        }
        for (const key of ['s3_access_key', 's3_secret_key', 's3_session_token']) form.elements[key].value = '';
        byId('bm-managed-connection').textContent = values.client_id && values.ssh_host
            ? values.client_id + ' — ' + values.ssh_user + '@' + values.ssh_host + ':' + values.ssh_port
            : tr('Complete provider enrollment to configure managed SSH storage.');
    }
    function serializeSettings(form) {
        const settings = Object.fromEntries(new FormData(form).entries());
        if (settings.destination === 'aws_s3') settings.s3_region = byId('bm-aws-region-select').value;
        for (const key of ['ssh_port', 'retention_days', 'stale_hours']) {
            if (Object.hasOwn(settings, key)) settings[key] = Number(settings[key]);
        }
        return settings;
    }

    function storageVisibility(root, form) {
        const destination = form.elements.destination.value;
        const ssh = destination === 'ssh';
        const managed = ssh && form.elements.credential_mode.value === 'managed';
        function group(id, visible) {
            const element = byId(id);
            element.hidden = !visible;
            element.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = !visible; });
        }
        group('bm-ssh-mode', ssh);
        group('bm-ssh-fields', ssh && !managed);
        group('bm-s3-fields', !ssh);
        group('bm-s3-endpoint', destination === 's3_compatible');
        group('bm-compatible-region', destination === 's3_compatible');
        group('bm-aws-region', destination === 'aws_s3');
        byId('bm-managed-connection').hidden = !managed;
        byId('bm-refresh-provider').hidden = !managed;
        byId('bm-ssh-section').hidden = !ssh;
        byId('bm-provider-section').hidden = !managed;
        for (const key of ['ssh_host', 'ssh_port', 'ssh_user', 'ssh_path']) form.elements[key].required = ssh && !managed;
        for (const key of ['s3_bucket', 's3_prefix']) form.elements[key].required = !ssh;
        form.elements.s3_endpoint.required = destination === 's3_compatible';
        form.elements.s3_region.required = destination === 's3_compatible';
        byId('bm-aws-region-select').required = destination === 'aws_s3';
    }

    async function request(route, values = {}, method = 'POST') {
        const options = {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'requesttoken': OC.requestToken}};
        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            options.body = new URLSearchParams(values).toString();
        }
        const response = await fetch(OC.generateUrl('/apps/backupstatus/settings/' + route), options);
        let data;
        try { data = await response.json(); }
        catch (_) { data = {success: false, error_message: 'The server returned an unreadable response. Check the server logs.'}; }
        if (!response.ok || !data.success) {
            const error = new Error(errorMessage(data));
            error.detail = data.error_detail || ('HTTP ' + response.status + ' / ' + (data.error_code || 'invalid_response'));
            throw error;
        }
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
        const storageState = {name: '', profiles: {}};
        let settingsRevision = 0;
        let settingsDirty = false;
        function markSettingsDirty() { settingsRevision++; settingsDirty = true; }
        form.addEventListener('input', markSettingsDirty);
        form.addEventListener('change', markSettingsDirty);
        function visibility() { storageVisibility(root, form); }
        function changeStorage() { switchStorageProfile(form, storageState); visibility(); }
        async function loadSettings(background = false) {
            const revision = settingsRevision;
            if (background && settingsDirty) return;
            const data = await request('runtime', {}, 'GET');
            if (revision !== settingsRevision || (background && settingsDirty)) return;
            for (const [name, value] of Object.entries(data.settings)) {
                if (form.elements[name]) form.elements[name].value = value;
            }
            const regionSelect = byId('bm-aws-region-select');
            regionSelect.replaceChildren();
            for (const region of data.settings.aws_regions || []) {
                const option = document.createElement('option');
                option.value = region; option.textContent = region;
                regionSelect.appendChild(option);
            }
            regionSelect.value = (data.settings.aws_regions || []).includes(data.settings.s3_region) ? data.settings.s3_region : 'us-east-1';
            byId('bm-key-error').textContent = data.settings.ssh_key_error ? tr('SSH public keys unavailable; ask the server administrator to check the installed key pair') : '';
            byId('bm-public-key').value = data.settings.ssh_public_key || '';
            byId('bm-restore-public-key').value = data.settings.ssh_restore_public_key || '';
            byId('bm-managed-connection').textContent = data.settings.client_id && data.settings.ssh_host
                ? data.settings.client_id + ' — ' + data.settings.ssh_user + '@' + data.settings.ssh_host + ':' + data.settings.ssh_port
                : tr('Complete provider enrollment to configure managed SSH storage.');
            storageState.name = storageProfile(form);
            storageState.profiles = data.settings.storage_profiles || {};
            root.dataset.runtimeClientId = data.settings.client_id || '';
            if (data.settings.client_id) byId('bm-client-id').value = data.settings.client_id;
            updateProviderStatus(root);
            syncChecksFromDays(data.settings.days);
            visibility();
            form.hidden = false;
            if (form.elements.destination.value === 'ssh') renderHostStatus(root, data.settings);
            if (data.settings.connection_test) {
                renderConnectionTest(root, data.settings.connection_test);
                if (!currentJob && ['queued', 'running'].includes(data.settings.connection_test.state)) track({job: data.settings.connection_test});
            }
            else setSectionStatus(root, 2, 'neutral');
        }
        form.elements.destination.addEventListener('change', changeStorage);
        form.elements.credential_mode.addEventListener('change', changeStorage);
        async function loadStatus() {
            try {
                const data = await request('runtime-status', {}, 'GET');
                const status = data.status || {};
                byId('bm-status-state').textContent = tr(backupStates[status.state] || 'Status unavailable');
                byId('bm-status-success').textContent = status.last_success || '—';
                byId('bm-status-attempt').textContent = status.last_attempt || '—';
                byId('bm-status-generation').textContent = status.generation || '—';
                byId('bm-status-error').textContent = status.error ? errorMessage(status) : '';
                byId('bm-status-diagnostic').textContent = status.error_detail || status.error_code || '';
                byId('bm-status-cleanup').textContent = status.cleanup_error ? tr('Operation cleanup failed. Review the installation.') : '';
                const state = status.state;
                const overall = ['ok', 'restored'].includes(state) ? 'green' : ['failed', 'maintenance_required'].includes(state) ? 'red' : ['stale', 'missing'].includes(state) ? 'orange' : 'neutral';
                setSectionStatus(root, 5, overall);
                if (state === 'failed' || state === 'maintenance_required') setSectionStatus(root, 4, 'red');
                else if (state === 'ok' || state === 'restored') setSectionStatus(root, 4, 'green');
                else if (state) setSectionStatus(root, 4, 'orange');
            } catch (error) { byId('bm-status-error').textContent = uiError(error); }
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            syncDaysFromChecks();
            const settings = serializeSettings(form);
            try {
                const response = await fetch(OC.generateUrl('/apps/backupstatus/settings'), {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'requesttoken': OC.requestToken},
                    body: new URLSearchParams({settings: JSON.stringify(settings)}).toString()
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(errorMessage(data));
                settingsDirty = false;
                await loadSettings();
                message(tr('Settings saved'));
            } catch (error) { message(uiError(error)); }
            finally {
                for (const key of ['s3_access_key', 's3_secret_key', 's3_session_token']) form.elements[key].value = '';
            }
        });
        let currentJob = '';
        try { currentJob = localStorage.getItem('backupmanager-job') || ''; } catch (_) { /* Server status remains authoritative. */ }
        let jobTimer;
        let pollingJob = false;
        async function pollJob() {
            if (!currentJob || pollingJob) return;
            const requestedJob = currentJob;
            pollingJob = true;
            window.clearTimeout(jobTimer);
            try {
                const data = await request('job', {id: requestedJob});
                if (currentJob !== requestedJob) return;
                const job = data.job;
                renderConnectionTest(root, job);
                byId('bm-job').textContent = tr('Operation status') + ': ' + tr(jobStates[job.state] || 'Unknown') + (job.error ? ' — ' + errorMessage(job) : '');
                byId('bm-job-diagnostic').textContent = job.error_detail || job.error_code || '';
                byId('bm-job-cleanup').textContent = job.cleanup_error ? tr('Operation cleanup failed. Review the installation.') : '';
                byId('bm-cancel').disabled = !['verify', 'restore', 'test'].includes(job.action) || !['queued', 'running'].includes(job.state);
                if (['completed', 'failed', 'cancelled'].includes(job.state)) {
                    try { localStorage.removeItem('backupmanager-job'); } catch (_) {}
                    currentJob = '';
                    window.dispatchEvent(new CustomEvent('backupstatus:changed'));
                    await loadStatus();
                } else jobTimer = window.setTimeout(pollJob, 3000);
            } catch (error) {
                if (currentJob === requestedJob) byId('bm-job').textContent = uiError(error);
                jobTimer = window.setTimeout(pollJob, 15000);
            } finally {
                pollingJob = false;
                if (currentJob && currentJob !== requestedJob) {
                    window.clearTimeout(jobTimer);
                    jobTimer = window.setTimeout(pollJob, 0);
                }
            }
        }
        function track(data) {
            if (data.job) {
                currentJob = data.job.id;
                try { localStorage.setItem('backupmanager-job', currentJob); } catch (_) {}
                window.clearTimeout(jobTimer);
                pollJob();
            }
        }
        function action(id, route, values = () => ({}), after = data => { track(data); message(tr('Request accepted')); }) {
            byId(id).addEventListener('click', async () => {
                const button = byId(id);
                if (button.disabled) return;
                button.disabled = true;
                try {
                    const input = values();
                    if (input === null) return;
                    if (['bm-recover', 'bm-enroll'].includes(id)) {
                        root.dataset.providerError = '0';
                        byId('bm-provider-message').textContent = tr('Sending request…');
                        byId('bm-provider-diagnostic').textContent = '';
                        pollEpoch++;
                        Object.values(polls).forEach(timer => window.clearTimeout(timer));
                    }
                    await after(await request(route, input));
                }
                catch (error) {
                    const sectionMap = {'bm-enroll': 0, 'bm-resend': 0, 'bm-recover': 0, 'bm-pin': 1, 'bm-test': 2, 'bm-backup': 2, 'bm-inventory': 3, 'bm-verify': 4, 'bm-restore': 4, 'bm-cancel': 4, 'bm-remove': 0};
                    if (sectionMap[id] !== undefined) setSectionStatus(root, sectionMap[id], 'red');
                    if (id === 'bm-pin') { byId('bm-technical').open = true; byId('bm-ssh-verification-status').textContent = uiError(error); root.dataset.hostTrusted = '0'; }
                    if (['bm-recover', 'bm-enroll', 'bm-resend'].includes(id)) {
                        root.dataset.providerError = '1';
                        byId('bm-provider-message').textContent = uiError(error);
                        byId('bm-provider-diagnostic').textContent = error.detail || error.name || 'request_failed';
                    }
                    message(uiError(error));
                }
                finally { if (id !== 'bm-cancel') button.disabled = false; }
            });
        }
        action('bm-pin', 'trust', () => (byId('bm-host-key').value.trim() === root.dataset.hostKey && byId('bm-host-fingerprint').value.trim() === root.dataset.hostFingerprint) ? {} : ({key: byId('bm-host-key').value.trim(), fingerprint: byId('bm-host-fingerprint').value.trim()}), data => { renderHostStatus(root, data.settings); byId('bm-technical').open = false; });
        action('bm-test', 'test', () => ({}), data => { renderConnectionTest(root, data.job); track(data); });
        action('bm-backup', 'backup', () => ({}), data => { track(data); setSectionStatus(root, 2, 'orange'); message(tr('Backup started.')); });
        const polls = {};
        let pollEpoch = 0;
        async function pollEnrollment(kind) {
            window.clearTimeout(polls[kind]);
            const epoch = pollEpoch;
            try {
                const data = await request(kind + '-status');
                if (epoch !== pollEpoch) return;
                root.dataset[ kind === 'provider' ? 'providerStatus' : 'recoveryStatus'] = data.status;
                if (data.clientId) root.dataset.clientId = data.clientId;
                updateProviderStatus(root);
                if (data.status === 'pending') polls[kind] = window.setTimeout(() => pollEnrollment(kind), 15000);
                else if (data.applied) await loadSettings(true);
            } catch (error) {
                if (epoch !== pollEpoch) return;
                root.dataset.providerError = '1';
                byId('bm-provider-message').textContent = uiError(error);
                byId('bm-provider-diagnostic').textContent = error.detail || error.name || 'request_failed';
                updateProviderStatus(root);
                message(uiError(error));
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
        action('bm-refresh-provider', 'refresh-provider', () => ({}), async () => {
            settingsDirty = false;
            settingsRevision++;
            await loadSettings();
            message(tr('Settings saved'));
        });
        action('bm-recover', 'request-recovery', () => ({consent: byId('bm-consent').checked ? '1' : '0', clientId: byId('bm-client-id').value.trim(), providerUrl: byId('bm-provider-url').value.trim()}), data => { byId('bm-provider-message').textContent = tr('Request sent') + ' — ' + data.requestId; root.dataset.recoveryStatus = 'pending'; updateProviderStatus(root); pollEnrollment('recovery'); });
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
            } catch (error) { setSectionStatus(root, 3, 'red'); message(uiError(error)); }
        }
        byId('bm-inventory').addEventListener('click', loadInventory);
        mainSections[3].addEventListener('toggle', () => { if (mainSections[3].open) loadInventory(); });
        action('bm-verify', 'disaster-recovery', () => ({mode: 'verify', generation: byId('bm-generation').value}), data => { track(data); setSectionStatus(root, 4, 'orange'); message(tr('Verification started.')); });
        action('bm-restore', 'restore', () => {
            if (!window.confirm(tr('Overwrite the selected Nextcloud components with this recovery point?'))) return null;
            return {type: byId('bm-restore-type').value, generation: byId('bm-generation').value, confirm: 'RESTORE'};
        }, data => { track(data); setSectionStatus(root, 4, 'orange'); message(tr('Restore started.')); });
        action('bm-cancel', 'cancel', () => ({id: currentJob}), () => message(tr('Cancellation requested. The operation will stop at its next safe checkpoint.')));
        action('bm-remove', 'remove', () => {
            if (!window.confirm(tr('Proceed with the selected removal actions?'))) return null;
            return {removeLocal: byId('bm-remove-local').checked ? '1' : '0', removeRemote: byId('bm-remove-remote').checked ? '1' : '0', confirm: byId('bm-delete-confirm').value};
        }, data => { track(data); message(data.localRemovalScheduled ? tr('Local removal scheduled') : tr('Deletion request accepted')); });
        try { await loadSettings(); } catch (error) { message(uiError(error)); }
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
                throw new Error(errorMessage(data));
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
                    requestState(client.status);

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
                                    ? errorMessage(result)
                                    : tr('HTTP empty response') + " " + response.status
                            );
                        }

                        await loadManagementClients();
                    } catch (error) {
                        button.disabled = false;
                        window.alert(
                            uiError(error)
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
                uiError(error);
        }
    }


    async function loadProviderAdministration() {
        const feedback = byId('bm-provider-admin-message');
        if (!feedback) return;
        const refresh = byId('bm-provider-admin-refresh');
        refresh.disabled = true;
        try {
            const data = await request('provider-admin-requests');
            byId('bm-provider-admin-pending').replaceChildren();
            byId('bm-provider-admin-history').replaceChildren();
            feedback.textContent = data.configured ? '' : tr('Provider administration is not configured.');
            byId('bm-provider-admin-storage').textContent = data.configured && !data.storage_configured ? tr('Storage host is not configured. Ask the provider administrator to configure the client-reachable SSH host and port.') : '';
            for (const row of data.requests) {
                const card = document.createElement('article'); card.className = 'bm-request-card';
                card.dataset.requestId = row.request_id;
                const title = document.createElement('h4');
                title.textContent = tr(row.type === 'recovery' ? 'Access recovery' : 'New enrollment') + ' — ' + row.request_id;
                card.appendChild(title);
                const fields = [
                    ['Status', tr({pending:'Waiting for approval', approved:'Approved', rejected:'Rejected', expired:'Expired'}[row.status] || 'Unknown')],
                    ['Client ID', row.client_id], ['Source ID', row.source_id], ['Existing source ID', row.current_source_id], ['Source URL', row.source_url],
                    ['Contact', row.contact], ['Requested', row.requested_at + ' UTC'], ['Expires', row.expires_at ? row.expires_at + ' UTC' : '—'],
                    ['Write-key fingerprint', row.write_fingerprint || tr('Missing key')], ['Read-key fingerprint', row.read_fingerprint || tr('Missing key')],
                ];
                const details = document.createElement('dl');
                for (const [label, value] of fields) {
                    if (!value) continue;
                    const term = document.createElement('dt'); term.textContent = tr(label);
                    const description = document.createElement('dd'); description.textContent = value;
                    details.append(term, description);
                }
                card.appendChild(details);
                for (const [action, allowed, label] of [['approve', row.can_approve, 'Approve'], ['reject', row.can_reject, 'Reject']]) {
                    if (!allowed) continue;
                    const button = document.createElement('button'); button.type = 'button'; button.textContent = tr(label);
                    button.dataset.action = action;
                    button.addEventListener('click', async () => {
                        if (!window.confirm(tr(action === 'approve' ? 'Approve this request?' : 'Reject this request?') + '\n' + row.request_id + (row.client_id ? '\n' + row.client_id : ''))) return;
                        button.disabled = true;
                        try {
                            await request('provider-admin-action', {requestType: row.type, requestId: row.request_id, action, confirm:'1'});
                            await loadProviderAdministration();
                            feedback.textContent = tr(action === 'approve' ? 'Request approved.' : 'Request rejected.');
                        } catch (error) { feedback.textContent = uiError(error); button.disabled = false; }
                    });
                    card.appendChild(button);
                }
                byId(row.status === 'pending' ? 'bm-provider-admin-pending' : 'bm-provider-admin-history').appendChild(card);
            }
            if (data.configured && !data.requests.some(row => row.status === 'pending')) {
                byId('bm-provider-admin-pending').textContent = tr('No requests awaiting approval.');
            }
        } catch (error) {
            feedback.textContent = uiError(error);
            // A failed refresh must not leave stale approval controls actionable.
            byId('bm-provider-admin-pending').replaceChildren();
        } finally { refresh.disabled = false; }
    }

    function initialize() {
        const refresh = byId('bm-provider-admin-refresh');
        if (refresh) {
            refresh.addEventListener('click', loadProviderAdministration);
            loadProviderAdministration();
        }
        ready().catch(error => {
            message(uiError(error));
            const details = byId('bm-provider-message');
            if (details) details.textContent = uiError(error);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
})();
