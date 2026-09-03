<x-master-layout>
    @section('title', 'Record Supplier Payment')

    <div class="max-w-5xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Record Supplier Payment</h1>
            <form method="POST" action="{{ route('inventory.supplier-payments.store') }}">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Supplier</label>
                        <select name="supplier_id" id="supplier_id" class="w-full border rounded p-2">
                            <option value="">Select supplier</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected(old('supplier_id', request('supplier_id')) == $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                        @error('supplier_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Purchase Invoice</label>
                        <select name="purchase_invoice_id" class="w-full border rounded p-2">
                            <option value="">General supplier payment</option>
                            @foreach ($purchaseInvoices as $invoice)
                                <option value="{{ $invoice->id }}" data-supplier="{{ $invoice->supplier_id }}" @selected(old('purchase_invoice_id', request('purchase_invoice_id')) == $invoice->id)>
                                    {{ $invoice->supplier->name }} - {{ $invoice->invoice_no }} (NPR {{ number_format($invoice->balance_amount, 2) }})
                                </option>
                            @endforeach
                        </select>
                        @error('purchase_invoice_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Payment Date</label>
                        <input name="payment_date" type="date" value="{{ old('payment_date', now()->format('Y-m-d')) }}" class="w-full border rounded p-2">
                        @error('payment_date') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Amount</label>
                        <input name="amount" type="number" step="0.01" value="{{ old('amount') }}" class="w-full border rounded p-2">
                        @error('amount') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Payment Method</label>
                        <select name="payment_method" class="w-full border rounded p-2">
                            @foreach ($paymentMethods as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('payment_method') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-semibold text-gray-700">Notes</label>
                    <textarea name="notes" class="w-full border rounded p-2" rows="3">{{ old('notes') }}</textarea>
                    @error('notes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Save</button>
                    <a href="{{ route('inventory.supplier-payments.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-master-layout>
