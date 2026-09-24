'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = {};
const fields = {};
for (const name of ['destination', 'credential_mode', 'ssh_host', 'ssh_port', 'ssh_user', 'ssh_path', 's3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_sse', 's3_access_key', 's3_secret_key', 's3_session_token', 'retention_days', 'stale_hours']) fields[name] = {value: '', disabled: false};
const groups = {'bm-ssh-mode': ['credential_mode'], 'bm-ssh-fields': ['ssh_host', 'ssh_port', 'ssh_user', 'ssh_path'], 'bm-s3-fields': ['s3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_sse', 's3_access_key', 's3_secret_key', 's3_session_token'], 'bm-s3-endpoint': ['s3_endpoint'], 'bm-compatible-region': ['s3_region']};
for (const id of [...Object.keys(groups), 'bm-aws-region', 'bm-aws-region-select', 'bm-managed-connection', 'bm-refresh-provider', 'bm-ssh-section', 'bm-provider-section']) {
    elements[id] = {hidden: false, querySelectorAll: () => (groups[id] || []).map(name => fields[name])};
}
// Model the browser FormData rule that disabled named controls are omitted.
class FormDataFixture {
    constructor(form) { this.form = form; }
    entries() { return Object.entries(this.form.elements).filter(([, input]) => !input.disabled).map(([name, input]) => [name, String(input.value)])[Symbol.iterator](); }
}
const context = {FormData: FormDataFixture, OC: {L10N: {translate: (_, text) => text}}, document: {readyState: 'loading', addEventListener() {}, getElementById: id => elements[id]}};
vm.createContext(context);
const source = fs.readFileSync('app/backupstatus/js/admin.js', 'utf8');
vm.runInContext(source.replace("    if (document.readyState === 'loading')", "    globalThis.visibility = storageVisibility; globalThis.renderTest = renderConnectionTest; globalThis.serialize = serializeSettings; globalThis.switchProfile = switchStorageProfile;\n    if (document.readyState === 'loading')"), context);
// Exercise initial states and repeated transitions through every combination.
for (let round = 0; round < 2; round++) {
    for (const destination of ['ssh', 'aws_s3', 's3_compatible']) {
        for (const mode of ['managed', 'manual']) {
            fields.destination.value = destination; fields.credential_mode.value = mode;
            context.visibility({}, {elements: fields});
            const ssh = destination === 'ssh';
            assert.equal(elements['bm-ssh-fields'].hidden, !(ssh && mode === 'manual'));
            assert.equal(fields.ssh_host.disabled, !(ssh && mode === 'manual'));
            assert.equal(elements['bm-provider-section'].hidden, !(ssh && mode === 'managed'));
            assert.equal(elements['bm-s3-fields'].hidden, ssh);
            assert.equal(elements['bm-s3-endpoint'].hidden, destination !== 's3_compatible');
            assert.equal(fields.s3_endpoint.disabled, destination !== 's3_compatible');
            assert.equal(elements['bm-aws-region'].hidden, destination !== 'aws_s3');
            assert.equal(elements['bm-ssh-section'].hidden, !ssh);
            fields.ssh_port.value = '2222'; fields.retention_days.value = '30'; fields.stale_hours.value = '48';
            elements['bm-aws-region-select'].value = 'eu-west-1';
            const payload = context.serialize({elements: fields});
            assert.equal(Object.hasOwn(payload, 'ssh_host'), ssh && mode === 'manual');
            assert.equal(Object.hasOwn(payload, 'credential_mode'), ssh);
            assert.equal(Object.hasOwn(payload, 's3_endpoint'), destination === 's3_compatible');
            assert.equal(Object.hasOwn(payload, 's3_secret_key'), !ssh);
            assert.equal(payload.retention_days, 30);
            if (ssh && mode === 'manual') assert.equal(payload.ssh_port, 2222);
            if (destination === 'aws_s3') assert.equal(payload.s3_region, 'eu-west-1');
        }
    }
}
const css = fs.readFileSync('app/backupstatus/css/admin.css', 'utf8');
assert.ok(!/#bm-(ssh|s3)-fields\s*\{[^}]*display:\s*block\s*!important/.test(css));


elements['bm-message'] = {};
let status;
const section = {classList: {remove() {}, add(value) { status = value; }}, querySelector: () => ({})};
const root = {querySelectorAll: () => [section, section, section]};
for (const state of ['queued', 'running', 'failed', 'completed']) {
    context.renderTest(root, {action: 'test', state, result: {connected: state === 'completed'}, error: state === 'failed' ? 'Fixture failure' : ''});
    assert.equal(status, 'bm-status-' + (state === 'completed' ? 'green' : state === 'failed' ? 'red' : 'orange'));
    assert.equal(elements['bm-message'].textContent === 'Connection tested successfully.', state === 'completed');
}

const state = {name: 's3_compatible', profiles: {
    aws_s3: {s3_region: 'eu-west-1', s3_bucket: 'aws-bucket', s3_prefix: 'aws-prefix', s3_sse: 'AES256'},
    ssh_manual: {ssh_host: 'manual.example', ssh_port: 2222, ssh_user: 'archive', ssh_path: '/archive'},
}};
fields.s3_endpoint.value = 'https://objects.example';
fields.s3_region.value = 'custom'; fields.s3_bucket.value = 'compatible-bucket';
fields.s3_prefix.value = 'compatible-prefix'; fields.s3_sse.value = '';
fields.s3_secret_key.value = 'UNSAVED-SECRET-SENTINEL';
fields.destination.value = 'aws_s3';
context.switchProfile({elements: fields}, state);
assert.equal(fields.s3_bucket.value, 'aws-bucket');
assert.equal(elements['bm-aws-region-select'].value, 'eu-west-1');
assert.equal(fields.s3_secret_key.value, '');
assert.ok(!JSON.stringify(state).includes('UNSAVED-SECRET-SENTINEL'));
fields.destination.value = 'ssh'; fields.credential_mode.value = 'manual';
context.switchProfile({elements: fields}, state);
assert.equal(fields.ssh_host.value, 'manual.example');
fields.destination.value = 's3_compatible';
context.switchProfile({elements: fields}, state);
assert.equal(fields.s3_endpoint.value, 'https://objects.example');
assert.equal(fields.s3_region.value, 'custom');
assert.equal(fields.s3_bucket.value, 'compatible-bucket');
console.log('Storage visibility, serialization, mode switching and connection-test regressions passed.');
