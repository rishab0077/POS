const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('cancellation confirms before sending and keeps validation failures on screen', async () => {
    for (const scenario of ['declined', 'success', 'invalid', 'network']) {
        let handler, sent = 0, reloads = 0;
        const error = { textContent: '' };
        const button = { disabled: false };
        const form = { action: '/order/cancel', dataset: { kot: 'KOT-TEST' },
            matches: () => true, querySelector: selector => selector.includes('error') ? error : button };
        const context = {
            document: { addEventListener: (name, callback) => { handler = callback; } },
            window: { confirm: () => scenario !== 'declined', location: { reload: () => reloads++ } },
            FormData: class { constructor(input) { assert.equal(input, form); } },
            fetch: async (url, options) => {
                sent++;
                assert.equal(url, '/order/cancel');
                assert.equal(options.method, 'POST');
                if (scenario === 'network') throw new Error('Offline');
                return { ok: scenario === 'success', json: async () => ({ errors: { orderId: ['Use returns.'] } }) };
            },
        };
        vm.createContext(context);
        vm.runInContext(fs.readFileSync('resources/js/order-cancellation.js', 'utf8'), context);
        let prevented = false;
        await handler({ target: form, preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(sent, scenario === 'declined' ? 0 : 1);
        assert.equal(reloads, scenario === 'success' ? 1 : 0);
        assert.equal(button.disabled, false);
        if (scenario === 'invalid') assert.equal(error.textContent, 'Use returns.');
        if (scenario === 'network') assert.match(error.textContent, /Refresh to check the order/);
    }
});
