<x-master-layout>
    @section('title', 'Supplier Payments')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Supplier Payments</h1>
            <a href="{{ route('inventory.supplier-payments.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Record Payment</a>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="px-4 py-3 text-sm">{{ optional($payment->payment_date)->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-sm font-medium">{{ $payment->supplier->name }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($payment->purchaseInvoice)
                                    <a href="{{ route('inventory.purchase-invoices.show', $payment->purchaseInvoice) }}" class="text-blue-700 font-semibold">{{ $payment->purchaseInvoice->invoice_no }}</a>
                                @else
                                    General
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">{{ config('pos.purchase_payments')[$payment->payment_method] ?? $payment->payment_method }}</td>
                            <td class="px-4 py-3 text-sm text-right font-semibold">Rs {{ number_format($payment->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No supplier payments yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $payments->links() }}</div>
    </div>
</x-master-layout>
