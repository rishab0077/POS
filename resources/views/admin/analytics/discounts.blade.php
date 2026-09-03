<x-master-layout>
    @section('title', 'Discount Report')

    <div class="max-w-7xl space-y-6">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Discount Report</h1>
                <p class="text-sm text-gray-600">Finalized bills with discount approval details.</p>
            </div>
            <form method="GET" action="{{ route('reporting.discounts') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">From</label>
                    <input id="start_date" name="start_date" type="date" value="{{ $report['start_date'] }}" class="border rounded p-2">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">To</label>
                    <input id="end_date" name="end_date" type="date" value="{{ $report['end_date'] }}" class="border rounded p-2">
                </div>
                <button class="bg-green-700 text-white rounded px-4 py-2">View</button>
            </form>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-white border rounded p-4"><div class="text-xs uppercase text-gray-500">Discounted Bills</div><strong class="text-xl">{{ $report['totals']['bill_count'] }}</strong></div>
            <div class="bg-white border rounded p-4"><div class="text-xs uppercase text-gray-500">Total Discount</div><strong class="text-xl">NPR {{ number_format($report['totals']['discount_amount'], 2) }}</strong></div>
            <div class="bg-white border rounded p-4"><div class="text-xs uppercase text-gray-500">Before Discount</div><strong class="text-xl">NPR {{ number_format($report['totals']['sales_before_discount'], 2) }}</strong></div>
            <div class="bg-white border rounded p-4"><div class="text-xs uppercase text-gray-500">Final Invoice Total</div><strong class="text-xl">NPR {{ number_format($report['totals']['sales_after_discount'], 2) }}</strong></div>
        </div>

        <section class="bg-white border rounded overflow-x-auto">
            <div class="p-4 border-b"><h2 class="font-semibold">By Reason</h2></div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100"><tr><th class="p-3 text-left">Reason</th><th class="p-3 text-right">Bills</th><th class="p-3 text-right">Discount</th></tr></thead>
                <tbody>
                    @forelse ($report['by_reason'] as $reason)
                        <tr class="border-t"><td class="p-3">{{ $reason['label'] }}</td><td class="p-3 text-right">{{ $reason['count'] }}</td><td class="p-3 text-right font-semibold">NPR {{ number_format($reason['amount'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="p-4 text-center text-gray-500">No discounts in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="bg-white border rounded overflow-x-auto">
            <div class="p-4 border-b"><h2 class="font-semibold">Discounted Bills</h2></div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Date</th>
                        <th class="p-3 text-left">Invoice</th>
                        <th class="p-3 text-left">Table</th>
                        <th class="p-3 text-left">Type</th>
                        <th class="p-3 text-left">Reason</th>
                        <th class="p-3 text-left">Approved By</th>
                        <th class="p-3 text-right">Discount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['bills'] as $bill)
                        <tr class="border-t">
                            <td class="p-3">{{ ($bill->locked_at ?: $bill->created_at)->format('Y-m-d') }}</td>
                            <td class="p-3">{{ $bill->invoice_no ?: $bill->bill_id }}</td>
                            <td class="p-3">{{ $bill->sourceTable?->name ?? $bill->table?->name ?? 'Takeaway' }}</td>
                            <td class="p-3">{{ $bill->discount_type === 'percentage' ? number_format($bill->discount_value, 2) . '%' : 'Amount' }}</td>
                            <td class="p-3">{{ config('pos.discount_reasons.' . $bill->discount_reason, ucfirst(str_replace('_', ' ', $bill->discount_reason ?: 'Unrecorded'))) }}</td>
                            <td class="p-3">{{ $bill->discountApprover?->name ?? '-' }}</td>
                            <td class="p-3 text-right font-semibold">NPR {{ number_format($bill->discount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-4 text-center text-gray-500">No discounted bills in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-master-layout>
