<x-master-layout>
    @section('title', 'Inventory Reports')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="bg-white rounded shadow p-5">
                <div class="text-sm text-gray-500">Stock Valuation</div>
                <div class="text-2xl font-semibold">Rs {{ number_format($stockValue, 2) }}</div>
            </div>
            <div class="bg-white rounded shadow p-5">
                <div class="text-sm text-gray-500">Open Payables</div>
                <div class="text-2xl font-semibold">Rs {{ number_format($payables->sum('balance_amount'), 2) }}</div>
            </div>
            <div class="bg-white rounded shadow p-5">
                <div class="text-sm text-gray-500">Low Stock Items</div>
                <div class="text-2xl font-semibold">{{ $stockItems->filter(fn ($item) => $item->current_quantity <= $item->low_stock_threshold)->count() }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="bg-white rounded shadow overflow-x-auto">
                <div class="p-4 border-b">
                    <h1 class="text-lg font-semibold">Supplier Ledger Summary</h1>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Purchases</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Payments</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($suppliers as $supplier)
                            @php
                                $purchaseTotal = (float) ($supplier->posted_purchase_total ?? 0);
                                $paymentTotal = (float) ($supplier->payment_total ?? 0);
                                $balance = (float) $supplier->opening_balance + $purchaseTotal - $paymentTotal;
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium">{{ $supplier->name }}</td>
                                <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($purchaseTotal, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($paymentTotal, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold">Rs {{ number_format($balance, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded shadow overflow-x-auto">
                <div class="p-4 border-b">
                    <h1 class="text-lg font-semibold">Open Payables</h1>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($payables as $invoice)
                            <tr>
                                <td class="px-4 py-3 text-sm">
                                    <a href="{{ route('inventory.purchase-invoices.show', $invoice) }}" class="text-blue-700 font-semibold">{{ $invoice->invoice_no }}</a>
                                    <div class="text-xs text-gray-500">{{ optional($invoice->due_date ?: $invoice->bill_date)->format('Y-m-d') }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">{{ $invoice->supplier->name }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold">Rs {{ number_format($invoice->balance_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-500">No open payables.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto mt-4">
            <div class="p-4 border-b">
                <h1 class="text-lg font-semibold">Stock Valuation</h1>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Avg Cost</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($stockItems as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium">{{ $item->name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $item->category->name }}</td>
                            <td class="px-4 py-3 text-sm text-right">{{ number_format($item->current_quantity, 3) }} {{ $item->unit }}</td>
                            <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($item->average_unit_cost, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right font-semibold">Rs {{ number_format((float) $item->current_quantity * (float) $item->average_unit_cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
            <div class="bg-white rounded shadow overflow-x-auto">
                <div class="p-4 border-b">
                    <h1 class="text-lg font-semibold">Recent Purchases</h1>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($recentPurchases as $invoice)
                            <tr>
                                <td class="px-4 py-3 text-sm">
                                    <a href="{{ route('inventory.purchase-invoices.show', $invoice) }}" class="text-blue-700 font-semibold">{{ $invoice->invoice_no }}</a>
                                    <div class="text-xs text-gray-500">{{ $invoice->supplier->name }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($invoice->total_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded shadow overflow-x-auto">
                <div class="p-4 border-b">
                    <h1 class="text-lg font-semibold">Recent Payments</h1>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($recentPayments as $payment)
                            <tr>
                                <td class="px-4 py-3 text-sm">
                                    {{ $payment->supplier->name }}
                                    <div class="text-xs text-gray-500">{{ optional($payment->payment_date)->format('Y-m-d') }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-master-layout>
