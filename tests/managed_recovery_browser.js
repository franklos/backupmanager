'use strict';
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.BM_CHROMIUM_EXECUTABLE || undefined});
    try {
        for (const lang of ['nl', 'en']) {
            const page = await browser.newPage();
            const errors = [];
            page.on('pageerror', e => errors.push(String(e)));
            let pendingPolls = [], saved, refreshes = 0;
            const managed = {client_id: 'BM-000007', ssh_host: 'localhost', ssh_port: 22, ssh_user: 'backupstore', ssh_path: '/'};
            const manual = {ssh_host: 'manual.example', ssh_port: 2222, ssh_user: 'archive', ssh_path: '/archive'};
            let settings = {destination: 'ssh', credential_mode: 'manual', client_id: 'BM-000007',
                ...manual, storage_profiles: {ssh_managed: {...managed}, ssh_manual: {...manual}},
                days: 'Mon', time: '02:00', timezone: 'UTC', retention_days: 30, stale_hours: 48,
                aws_regions: ['us-east-1'], s3_region: 'us-east-1', host_trusted: true};
            await page.route('http://fixture.test/**', async route => {
                const request = route.request();
                const name = new URL(request.url()).pathname.split('/').pop();
                if (name === 'page') return route.fulfill({contentType: 'text/html', body: execFileSync('php', ['tests/admin_fixture.php'], {input: JSON.stringify({lang})}).toString()});
                let result = {success: true};
                if (name === 'runtime') result.settings = settings;
                else if (name === 'runtime-status') result.status = {state: 'missing'};
                else if (name === 'provider-admin-requests') { result.requests = []; result.storage = {configured: true}; }
                else if (name === 'management-clients') result.clients = [{client_id: 'BM-000007', status: 'active'}];
                else if (name === 'provider-status' || name === 'recovery-status') {
                    pendingPolls.push(route);
                    return;
                } else if (name === 'refresh-provider') {
                    refreshes++;
                    settings = {...settings, ...managed, ssh_host: 'backup.ncdev.local', credential_mode: 'managed'};
                    settings.storage_profiles.ssh_managed = {...managed, ssh_host: 'backup.ncdev.local'};
                    result.applied = true;
                } else if (name === 'settings') {
                    saved = JSON.parse(new URLSearchParams(request.postData()).get('settings'));
                    const profile = settings.storage_profiles['ssh_' + saved.credential_mode];
                    settings = {...settings, ...profile, ...saved};
                    result.settings = settings;
                } else throw Error('Unexpected route ' + name);
                await route.fulfill({contentType: 'application/json', body: JSON.stringify(result)});
            });
            await page.goto('http://fixture.test/page');
            await page.locator('#bm-settings').waitFor({state: 'visible'});
            await page.locator('#bm-settings > details > summary').click();
            await page.locator('[name=credential_mode]').selectOption('managed');
            assert.equal(await page.locator('#bm-ssh-fields').isVisible(), false);
            // Late terminal status responses must not put the form back into Manual.
            await page.waitForFunction(() => !!document.querySelector('#backupstatus-admin').dataset.runtimeClientId);
            while (pendingPolls.length < 2) await page.waitForTimeout(10);
            for (const route of pendingPolls) await route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, status: 'approved', clientId: 'BM-000007'})});
            pendingPolls = [];
            await page.waitForTimeout(100);
            assert.equal(await page.locator('[name=credential_mode]').inputValue(), 'managed');
            await page.locator('#bm-refresh-provider').click();
            await page.waitForFunction(() => document.querySelector('#bm-managed-connection').textContent.includes('backup.ncdev.local'));
            assert.equal(refreshes, 1);
            await page.locator('#bm-settings button[type=submit]').click();
            await page.waitForFunction(() => document.querySelector('#bm-message').textContent.includes('saved') || document.querySelector('#bm-message').textContent.includes('opgeslagen'));
            assert.equal(saved.credential_mode, 'managed');
            for (const name of ['ssh_host', 'ssh_user', 'ssh_port', 'ssh_path']) assert(!Object.hasOwn(saved, name));
            assert.equal(settings.ssh_host, 'backup.ncdev.local');
            assert.equal(settings.storage_profiles.ssh_managed.ssh_host, 'backup.ncdev.local');
            await page.locator('[name=credential_mode]').selectOption('manual');
            assert.equal(await page.locator('[name=ssh_host]').inputValue(), 'manual.example');
            await page.locator('[name=credential_mode]').selectOption('managed');
            assert.equal(await page.locator('#bm-ssh-fields').isVisible(), false);
            assert((await page.locator('#bm-managed-connection').textContent()).includes('backup.ncdev.local'));
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Managed recovery browser regression passed (Dutch and English): late polls, canonical refresh, serialization, and manual isolation.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
