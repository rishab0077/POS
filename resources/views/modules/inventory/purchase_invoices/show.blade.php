<x-master-layout>
    @section('title', 'Purchase Invoice')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Purchase Invoice {{ $purchaseInvoice->invoice_no }}</h1>
                <p class="text-sm text-gray-600">{{ $purchaseInvoice->supplier->name }} | {{ optional($purchaseInvoice->bill_date)->format('Y-m-d') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($purchaseInvoice->isDraft())
                    <a href="{{ route('inventory.purchase-invoices.edit', $purchaseInvoice) }}" class="px-4 py-2 bg-white border rounded font-semibold">Edit</a>
                    <form method="POST" action="{{ route('inventory.purchase-invoices.post', $purchaseInvoice) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Post to Stock</button>
                    </form>
                @endif
                <a href="{{ route('inventory.purchase-invoices.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Back</a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="bg-white rounded shadow p-5 lg:col-span-2">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">Status</div>
                        <div class="font-semibold">{{ ucfirst($purchaseInvoice->status) }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Payment</div>
                        <div class="font-semibold">{{ ucfirst($purchaseInvoice->payment_status) }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Created By</div>
                        <div class="font-semibold">{{ $purchaseInvoice->createdBy->name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Posted By</div>
                        <div class="font-semibold">{{ $purchaseInvoice->postedBy->name ?? '-' }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto mt-5">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">VAT</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($purchaseInvoice->items as $item)
                                <tr>
                                    <td class="px-3 py-2 text-sm font-medium">{{ $item->item_name }}</td>
                                    <td class="px-3 py-2 text-sm text-right">{{ number_format($item->quantity, 3) }} {{ $item->unit }}</td>
                                    <td class="px-3 py-2 text-sm text-right">NPR {{ number_format($item->unit_price, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right">NPR {{ number_format($item->discount_amount, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right">NPR {{ number_format($item->vat_amount, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-semibold">NPR {{ number_format($item->total_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded shadow p-5">
                <h2 class="font-semibold mb-3">Totals</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span>Subtotal</span><strong>NPR {{ number_format($purchaseInvoice->subtotal, 2) }}</strong></div>
                    <div class="flex justify-between"><span>Discount</span><strong>NPR {{ number_format($purchaseInvoice->discount_amount, 2) }}</strong></div>
                    <div class="flex justify-between"><span>Taxable</span><strong>NPR {{ number_format($purchaseInvoice->taxable_amount, 2) }}</strong></div>
                    <div class="flex justify-between"><span>VAT</span><strong>NPR {{ number_format($purchaseInvoice->vat_amount, 2) }}</strong></div>
                    <div class="flex justify-between border-t pt-2 text-lg"><span>Total</span><strong>NPR {{ number_format($purchaseInvoice->total_amount, 2) }}</strong></div>
                    <div class="flex justify-between"><span>Paid</span><strong>NPR {{ number_format($purchaseInvoice->paid_amount, 2) }}</strong></div>
                    <div class="flex justify-between"><span>Balance</span><strong>NPR {{ number_format($purchaseInvoice->balance_amount, 2) }}</strong></div>
                </div>

                @if ($purchaseInvoice->attachment_path)
                    <div class="mt-4">
                        <a href="{{ asset('storage/' . $purchaseInvoice->attachment_path) }}" target="_blank" class="text-blue-700 font-semibold">View invoice attachment</a>
                    </div>
                @endif

                @if ($purchaseInvoice->status === 'posted' && auth()->user()->canVoidPurchase())
                    <form method="POST" action="{{ route('inventory.purchase-invoices.void', $purchaseInvoice) }}" class="mt-5 border-t pt-4">
                        @csrf
                        <label class="block text-sm font-semibold text-gray-700">Void Reason</label>
                        <textarea name="void_reason" rows="3" class="w-full border rounded p-2" required>{{ old('void_reason') }}</textarea>
                        @error('void_reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="mt-2 px-4 py-2 bg-red-600 text-white rounded font-semibold">Void Posted Purchase</button>
                    </form>
                @endif

                @if ($purchaseInvoice->status === 'void')
                    <div class="mt-4 border-t pt-4 text-sm text-red-700">
                        <div class="font-semibold">Voided by {{ $purchaseInvoice->voidedBy->name ?? '-' }}</div>
                        <div>{{ $purchaseInvoice->void_reason }}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="bg-white rounded shadow p-5 mt-4">
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold">Payments</h2>
                @if ($purchaseInvoice->status === 'posted' && $purchaseInvoice->balance_amount > 0)
                    <a href="{{ route('inventory.supplier-payments.create', ['supplier_id' => $purchaseInvoice->supplier_id, 'purchase_invoice_id' => $purchaseInvoice->id]) }}" class="text-blue-700 font-semibold">Add Payment</a>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($purchaseInvoice->payments as $payment)
                            <tr>
                                <td class="px-3 py-2 text-sm">{{ optional($payment->payment_date)->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm">{{ config('pos.purchase_payments')[$payment->payment_method] ?? $payment->payment_method }}</td>
                                <td class="px-3 py-2 text-sm">{{ $payment->createdBy->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-sm text-right font-semibold">NPR {{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-6 text-center text-sm text-gray-500">No payments recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-master-layout>
