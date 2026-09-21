'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const details = {};
let state;
const root = {
    dataset: {},
    querySelectorAll: () => [{
        classList: {remove() {}, add(value) { state = value; }},
        querySelector: () => ({}),
    }],
};
const context = {
    OC: {L10N: {translate: (_, text) => text}},
    document: {readyState: 'loading', addEventListener() {}, getElementById: () => details},
};
vm.createContext(context);
const code = fs.readFileSync('app/backupstatus/js/admin.js', 'utf8');
vm.runInContext(code.replace("    if (document.readyState === 'loading')", "    globalThis.update = updateProviderStatus;\n    if (document.readyState === 'loading')"), context);
function check(provider, recovery, runtime, clients, expected) {
    root.dataset = {providerStatus: provider, recoveryStatus: recovery, clientId: 'BM-000001', runtimeClientId: runtime};
    root.providerClients = clients;
    context.update(root);
    assert.equal(state, 'bm-status-' + expected);
    assert.ok(details.textContent.includes('Access recovery request (SSH key replacement): ' + recovery));
    assert.equal(root.dataset.recoveryStatus, recovery, 'Rendering must preserve the request state');
}
const active = [{client_id: 'BM-000001', status: 'active'}];
for (const recovery of ['pending', 'stale', 'approved', 'rejected', 'expired']) {
    check('approved', recovery, 'BM-000001', active, 'green');
}
check('pending', 'pending', 'BM-000001', active, 'green');
check('approved', 'pending', 'BM-000002', active, 'orange');
check('approved', 'pending', '', active, 'orange');
check('approved', 'pending', 'BM-000001', [{client_id: 'BM-000002', status: 'active'}], 'orange');
check('approved', 'pending', 'BM-000001', [{client_id: 'BM-000001', status: 'suspended'}], 'orange');
check('approved', 'pending', 'BM-000001', undefined, 'orange');
check('approved', 'none', 'BM-000001', undefined, 'green');
check('approved', 'none', '', undefined, 'orange');
// Both polling orders must retain the live client verdict and both request details.
for (const statuses of [['pending', 'approved'], ['approved', 'pending']]) {
    for (const status of statuses) check(status, 'pending', 'BM-000001', active, 'green');
}
console.log('Provider status regression tests passed.');
