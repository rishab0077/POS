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
            document: { addEventListener: (name, callback) => { if (name === 'submit') handler = callback; } },
            window: { confirm: () => scenario !== 'declined', dispatchEvent() {}, location: { reload: () => reloads++ } },
            Event: class {},
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

test('minus opens a cancellation dialog and keeping the item sends nothing', async () => {
    let click, opened = 0, closed = 0;
    const dialog = { showModal: () => opened++, close: () => closed++ };
    let action = 'data-cancel-one';
    const button = { closest: () => ({ querySelector: () => dialog }), hasAttribute: name => name === action };
    const context = { document: { addEventListener: (name, handler) => { if (name === 'click') click = handler; } } };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('resources/js/order-cancellation.js', 'utf8'), context);
    await click({ target: { closest: () => button } });
    assert.equal(opened, 1);
    action = 'data-close-cancellation';
    await click({ target: { closest: () => button } });
    assert.equal(closed, 1);
});

test('confirming an item cancellation sends the form once and preserves draft before refreshing', async () => {
    let submit, sent = 0;
    const events = [];
    const button = { disabled: false };
    const form = { action: '/order/cancel', dataset: { item: 'Momo', kot: 'KOT-TEST' }, matches: () => true,
        querySelector: selector => selector.includes('error') ? { textContent: '' } : button };
    const context = {
        document: { addEventListener: (name, handler) => { if (name === 'submit') submit = handler; } },
        window: { confirm: assert.fail, dispatchEvent: event => events.push(event.type),
            location: { reload: () => events.push('reload') } },
        Event: class { constructor(type) { this.type = type; } },
        FormData: class { constructor(input) { assert.equal(input, form); } },
        fetch: async () => { sent++; return { ok: true, json: async () => ({ status: 'success' }) }; },
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('resources/js/order-cancellation.js', 'utf8'), context);
    await submit({ target: form, preventDefault() {} });
    assert.equal(sent, 1);
    assert.deepEqual(events, ['order-cancellation-recorded', 'reload']);
    assert.equal(button.disabled, false);
});

test('POS preserves pending items and notes around cancellation refresh', () => {
    const source = fs.readFileSync('resources/views/pos/pos-index.blade.php', 'utf8');
    const start = source.indexOf('const savedOrderDraft =');
    const storage = new Map();
    let save;
    const context = { orderDraftKey: 'pos-order-draft:3', orderItems: [{ id: 12, quantity: 2 }], selectedNotes: ['No chilli'],
        sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value),
            removeItem: key => storage.delete(key) },
        window: { addEventListener: (name, handler) => { save = handler; } } };
    vm.createContext(context);
    const script = source.slice(start, source.indexOf('</script>', start));
    vm.runInContext(script, context);
    save();
    context.orderItems = [];
    context.selectedNotes = [];
    vm.runInContext(`{ ${script} }`, context);
    assert.equal(context.orderItems[0].quantity, 2);
    assert.equal(context.selectedNotes[0], 'No chilli');
    assert.equal(storage.size, 0);
});

test('KOT plus submits only one new unit through the trusted ordering endpoint', async () => {
    for (const accepted of [false, true]) {
        let click, sent = 0, reloads = 0;
        const button = { disabled: false, hasAttribute: () => false,
            closest: () => ({ querySelector: () => ({}) }),
            dataset: { name: 'Momo', menuId: '12', tableId: '3', source: 'waiter', url: '/order/submit' } };
        const context = {
            document: { addEventListener: (name, handler) => { if (name === 'click') click = handler; },
                querySelector: () => ({ content: 'csrf-test' }) },
            window: { confirm: () => accepted, location: { reload: () => reloads++ }, alert: assert.fail },
            fetch: async (url, options) => {
                sent++;
                assert.equal(url, '/order/submit');
                assert.deepEqual(JSON.parse(options.body), { source: 'waiter', tableId: 3,
                    isPickUpOrder: false, billTable: false, paymentMethod: 'cash',
                    order: { orderItems: [{ id: 12, quantity: 1 }] } });
                return { ok: true, json: async () => ({ status: 'success' }) };
            },
        };
        vm.createContext(context);
        vm.runInContext(fs.readFileSync('resources/js/order-cancellation.js', 'utf8'), context);
        await click({ target: { closest: () => button } });
        assert.equal(sent, Number(accepted));
        assert.equal(reloads, Number(accepted));
        assert.equal(button.disabled, false);
    }
});

test('waiter menu minus cancels sent food only after new cart units are removed, and draft survives refresh', () => {
    const source = fs.readFileSync('resources/views/waiter/order-screen.blade.php', 'utf8');
    const start = source.indexOf('function waiterOrderScreen(data)');
    let opened = 0, save;
    const storage = new Map();
    const context = {
        document: { querySelector: selector => {
            assert.match(selector, /data-menu-id="12"/);
            return { click: () => opened++ };
        } },
        window: { addEventListener: (name, handler) => { save = handler; } },
        sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value),
            removeItem: key => storage.delete(key) },
    };
    vm.createContext(context);
    vm.runInContext(source.slice(start, source.indexOf('</script>', start)), context);
    const data = { table: { id: 3 }, existingTableTotal: 300, menus: [], notes: [],
        sentItems: [{ menu_id: 12, quantity: 3 }] };
    const screen = context.waiterOrderScreen(data);
    screen.init();
    assert.equal(screen.getSentQuantity(12), 3);
    screen.updateQuantity(12, 1, 'Momo', 100);
    screen.updateQuantity(12, -1);
    assert.equal(opened, 0, 'unsent food is removed without cancelling a KOT');
    screen.updateQuantity(12, -1);
    assert.equal(opened, 1);
    screen.updateQuantity(12, 1, 'Momo', 100);
    screen.customNotes = 'No chilli';
    save();
    const refreshed = context.waiterOrderScreen(data);
    refreshed.init();
    assert.equal(refreshed.cart[0].quantity, 1);
    assert.equal(refreshed.customNotes, 'No chilli');
    assert.equal(storage.size, 0, 'draft is restored only once');
});
