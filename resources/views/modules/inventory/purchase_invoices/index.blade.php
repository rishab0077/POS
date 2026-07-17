<x-master-layout>
    @section('title', 'Purchase Invoices')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Purchase Invoices</h1>
            <a href="{{ route('inventory.purchase-invoices.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Add Purchase Invoice</a>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($purchaseInvoices as $invoice)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ $invoice->invoice_no }}
                                <div class="text-xs text-gray-500">{{ optional($invoice->bill_date)->format('Y-m-d') }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $invoice->supplier->name }}</td>
                            <td class="px-4 py-3 text-sm text-center">
                                <span class="px-2 py-1 rounded text-xs font-semibold {{ $invoice->status === 'posted' ? 'bg-green-100 text-green-800' : ($invoice->status === 'void' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                    {{ ucfirst($invoice->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($invoice->total_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right">Rs {{ number_format($invoice->balance_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right space-x-2">
                                <a href="{{ route('inventory.purchase-invoices.show', $invoice) }}" class="text-blue-700 font-semibold">View</a>
                                @if ($invoice->isDraft())
                                    <a href="{{ route('inventory.purchase-invoices.edit', $invoice) }}" class="text-blue-700 font-semibold">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No purchase invoices yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $purchaseInvoices->links() }}</div>
    </div>
</x-master-layout>
