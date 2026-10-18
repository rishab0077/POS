const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('table clicks follow the current status after settlement and finalization', () => {
    const source = fs.readFileSync('resources/views/pos/tables.blade.php', 'utf8');
    const attrs = { 'data-table-status': 'printed' };
    const context = {
        $: () => ({ attr: (key, value) => value === undefined ? attrs[key] : (attrs[key] = value) }),
        window: { location: { href: '' } }, selectTableURL: '/pos/order',
        clearElapsedTime() {}, clearTableTotal() {}, updateRunningTables() {},
        updateTableStyles() {}, updateElapsedTimes() {},
    };
    vm.createContext(context);
    for (const [start, end] of [
        ['function selectTable(', '// Update elapsed time'],
        ['function updateTableStatus(', '// Clear elapsed time'],
    ]) {
        const offset = source.indexOf(start);
        vm.runInContext(source.slice(offset, source.indexOf(end, offset)), context);
    }
    assert.ok(/onclick="selectTable\(\{\{ \$table->id \}\}\)"/.test(source), 'table handler must read live status');
    context.updateTableStatus(1, 'available');
    context.selectTable(1);
    assert.equal(context.window.location.href, '/pos/order?tableId=1');
    context.window.location.href = '';
    context.updateTableStatus(1, 'printed');
    context.selectTable(1);
    assert.equal(context.window.location.href, '');
    context.selectTable('takeaway');
    assert.equal(context.window.location.href, '/pos/order?tableId=takeaway');
});

test('KOT polling compares visible IDs and statuses, including an empty screen', () => {
    const source = fs.readFileSync('resources/js/KOT.js', 'utf8');
    let visible = [], orders = [], reloads = 0, cancellationOpen = false;
    const jq = () => ({
        map(callback) {
            const values = visible.map(row => callback.call(row));
            return { get: () => values, filter: () => ({ get: () => values.filter(Number.isFinite) }) };
        },
        attr() {},
    });
    jq.ajax = options => options.success({ status: 'success', hasNewOrders: true, orders });
    const context = {
        $: jq, console, checkOrderUpdatesRoute: '/sync/check-pending-orders-updates',
        document: { querySelector: () => cancellationOpen },
        window: { location: { reload() { reloads++; } } },
    };
    vm.createContext(context);
    vm.runInContext(source.slice(source.indexOf('function checkOrderUpdates()')), context);
    context.checkOrderUpdates();
    context.checkOrderUpdates();
    assert.equal(reloads, 0);
    orders = [{ id: 2, status: 'new' }];
    cancellationOpen = true;
    context.checkOrderUpdates();
    assert.equal(reloads, 0);
    cancellationOpen = false;
    context.checkOrderUpdates();
    assert.equal(reloads, 1);
    visible = [{ id: 'order2', getAttribute: name => name === 'data-order-status' ? 'new' : '0' }];
    context.checkOrderUpdates();
    assert.equal(reloads, 1);
    orders = [{ id: 2, status: 'processing' }];
    context.checkOrderUpdates();
    assert.equal(reloads, 2);
    orders = [{ id: 2, status: 'new', item_count: 2 }];
    visible = [{ id: 'order2', getAttribute: name => name === 'data-order-status' ? 'new' : '3' }];
    context.checkOrderUpdates();
    assert.equal(reloads, 3, 'quantity-only cancellations must refresh the KOT');
    orders = [];
    context.checkOrderUpdates();
    assert.equal(reloads, 4);
});
