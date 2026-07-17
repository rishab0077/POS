<x-master-layout>
    @section('title', 'Daily Payment Summary')

    <div class="max-w-7xl space-y-6">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Daily Payment Summary</h1>
                <p class="text-sm text-gray-600">Credit collections are counted on the date payment is received.</p>
            </div>
            <form method="GET" action="{{ route('reporting.daily-summary') }}" class="flex items-end gap-2">
                <div>
                    <label for="date" class="block text-sm font-medium text-gray-700">Date</label>
                    <input id="date" name="date" type="date" value="{{ $summary['date'] }}" class="border rounded p-2">
                </div>
                <button class="bg-green-700 text-white rounded px-4 py-2">View</button>
            </form>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-white border rounded p-4">
                <div class="text-xs uppercase text-gray-500">Collected Sales</div>
                <div class="text-xl font-semibold">Rs {{ number_format($summary['totals']['collected_sales'], 2) }}</div>
            </div>
            <div class="bg-white border rounded p-4">
                <div class="text-xs uppercase text-gray-500">Invoices Issued</div>
                <div class="text-xl font-semibold">Rs {{ number_format($summary['totals']['invoice_total'], 2) }}</div>
                <div class="text-xs text-gray-500">{{ $summary['totals']['invoice_count'] }} invoices</div>
            </div>
            <div class="bg-white border rounded p-4">
                <div class="text-xs uppercase text-gray-500">Credit Issued</div>
                <div class="text-xl font-semibold">Rs {{ number_format($summary['totals']['credit_issued'], 2) }}</div>
            </div>
            <div class="bg-white border rounded p-4">
                <div class="text-xs uppercase text-gray-500">Outstanding Credit</div>
                <div class="text-xl font-semibold">Rs {{ number_format($summary['totals']['outstanding_credit'], 2) }}</div>
            </div>
        </div>

        <section class="bg-white border rounded overflow-x-auto">
            <div class="p-4 border-b">
                <h2 class="font-semibold text-gray-900">Payment Breakdown</h2>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Payment Method</th>
                        <th class="p-3 text-right">Direct Sales</th>
                        <th class="p-3 text-right">Credit Collections</th>
                        <th class="p-3 text-right">Total Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['breakdown'] as $row)
                        <tr class="border-t">
                            <td class="p-3 font-medium">{{ $row['label'] }}</td>
                            <td class="p-3 text-right">Rs {{ number_format($row['direct_sales'], 2) }}</td>
                            <td class="p-3 text-right">Rs {{ number_format($row['credit_collections'], 2) }}</td>
                            <td class="p-3 text-right font-semibold">Rs {{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 bg-green-50">
                        <td class="p-3 font-semibold">E-Wallet Total (eSewa + Khalti + Fonepay)</td>
                        <td class="p-3 text-right">Rs {{ number_format($summary['wallet']['direct_sales'], 2) }}</td>
                        <td class="p-3 text-right">Rs {{ number_format($summary['wallet']['credit_collections'], 2) }}</td>
                        <td class="p-3 text-right font-semibold">Rs {{ number_format($summary['wallet']['total'], 2) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr>
                        <td class="p-3 font-semibold">Total</td>
                        <td class="p-3 text-right font-semibold">Rs {{ number_format($summary['totals']['direct_sales'], 2) }}</td>
                        <td class="p-3 text-right font-semibold">Rs {{ number_format($summary['totals']['credit_collections'], 2) }}</td>
                        <td class="p-3 text-right font-semibold">Rs {{ number_format($summary['totals']['collected_sales'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="bg-white border rounded p-4 flex justify-between">
                <span class="text-gray-600">Discount Given</span>
                <strong>Rs {{ number_format($summary['totals']['discount'], 2) }}</strong>
            </div>
            <div class="bg-white border rounded p-4 flex justify-between">
                <span class="text-gray-600">VAT on Invoices Issued</span>
                <strong>Rs {{ number_format($summary['totals']['vat'], 2) }}</strong>
            </div>
        </div>
    </div>
</x-master-layout>
