<div class="flex flex-wrap items-center gap-2 mb-6">
    <a href="{{ route('inventory.dashboard') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.dashboard') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Inventory</a>
    <a href="{{ route('inventory.suppliers.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.suppliers.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Suppliers</a>
    <a href="{{ route('inventory.purchase-invoices.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.purchase-invoices.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Purchases</a>
    <a href="{{ route('inventory.supplier-payments.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.supplier-payments.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Supplier Payments</a>
    <a href="{{ route('inventory.stock-items.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.stock-items.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Stock Items</a>
    <a href="{{ route('inventory.stock-movements.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.stock-movements.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Stock Movements</a>
    <a href="{{ route('inventory.menu-mappings.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.menu-mappings.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Menu Mappings</a>
    <a href="{{ route('inventory.reports.index') }}"
        class="px-3 py-2 rounded text-sm font-semibold {{ request()->routeIs('inventory.reports.*') ? 'bg-gray-800 text-white' : 'bg-white text-gray-800 border' }}">Reports</a>
</div>
