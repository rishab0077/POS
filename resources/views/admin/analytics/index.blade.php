<x-master-layout>
    @section('title', 'Reporting')

    <div class="max-w-6xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Reporting</h1>
            <p class="text-sm text-gray-600">Sales collection, credit, discount, item, and category reports.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <a href="{{ route('reporting.daily-summary') }}" class="bg-white border border-gray-300 rounded p-4 hover:border-green-600">
                <span class="block font-semibold text-gray-900">Daily Payment Summary</span>
                <span class="text-sm text-gray-600">Cash, card, wallets, credit collections, VAT, and outstanding credit.</span>
            </a>
            <a href="{{ route('reporting.credits') }}" class="bg-white border border-gray-300 rounded p-4 hover:border-green-600">
                <span class="block font-semibold text-gray-900">Credit Ledger</span>
                <span class="text-sm text-gray-600">Outstanding balances, partial payments, and settlement history.</span>
            </a>
            <a href="{{ route('reporting.discounts') }}" class="bg-white border border-gray-300 rounded p-4 hover:border-green-600">
                <span class="block font-semibold text-gray-900">Discount Report</span>
                <span class="text-sm text-gray-600">Monthly discount amounts, reasons, types, and approvers.</span>
            </a>
            <a href="{{ route('reporting.view', ['sales-by-item']) }}" class="bg-white border border-gray-300 rounded p-4 hover:border-green-600">
                <span class="block font-semibold text-gray-900">Sales By Item</span>
                <span class="text-sm text-gray-600">Item quantities and sales values.</span>
            </a>
            <a href="{{ route('reporting.view', ['sales-by-category']) }}" class="bg-white border border-gray-300 rounded p-4 hover:border-green-600">
                <span class="block font-semibold text-gray-900">Sales By Category</span>
                <span class="text-sm text-gray-600">Category quantities and sales values.</span>
            </a>
        </div>
    </div>
</x-master-layout>
