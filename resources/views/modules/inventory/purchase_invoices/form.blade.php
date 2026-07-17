@csrf

@php
    $invoiceItems = old('items');
    if (!$invoiceItems) {
        $invoiceItems = $purchaseInvoice->relationLoaded('items') && $purchaseInvoice->items->isNotEmpty()
            ? $purchaseInvoice->items->map(fn ($item) => [
                'stock_item_id' => $item->stock_item_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'vat_amount' => $item->vat_amount,
            ])->toArray()
            : [['stock_item_id' => '', 'quantity' => 1, 'unit_price' => 0, 'discount_amount' => 0, 'vat_amount' => 0]];
    }
    $stockItemLookup = $stockItems->mapWithKeys(fn ($item) => [$item->id => ['unit' => $item->unit, 'last_purchase_cost' => $item->last_purchase_cost]])->toArray();
@endphp

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-semibold text-gray-700">Supplier</label>
        <select name="supplier_id" class="w-full border rounded p-2">
            <option value="">Select supplier</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected(old('supplier_id', $purchaseInvoice->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        @error('supplier_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Supplier Invoice No.</label>
        <input name="invoice_no" value="{{ old('invoice_no', $purchaseInvoice->invoice_no) }}" class="w-full border rounded p-2">
        @error('invoice_no') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Bill Date</label>
        <input name="bill_date" type="date" value="{{ old('bill_date', optional($purchaseInvoice->bill_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="w-full border rounded p-2">
        @error('bill_date') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Due Date</label>
        <input name="due_date" type="date" value="{{ old('due_date', optional($purchaseInvoice->due_date)->format('Y-m-d')) }}" class="w-full border rounded p-2">
        @error('due_date') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Initial Payment Method</label>
        <select name="payment_method" class="w-full border rounded p-2">
            <option value="">None</option>
            @foreach ($paymentMethods as $value => $label)
                <option value="{{ $value }}" @selected(old('payment_method', $purchaseInvoice->payment_method) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('payment_method') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Initial Payment</label>
        <input name="paid_amount" type="number" step="0.01" value="{{ old('paid_amount', $purchaseInvoice->paid_amount ?? 0) }}" class="w-full border rounded p-2">
        @error('paid_amount') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-3">
        <label class="block text-sm font-semibold text-gray-700">Invoice Photo/PDF</label>
        <input name="attachment" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full border rounded p-2 bg-white">
        @if ($purchaseInvoice->attachment_path)
            <p class="text-xs text-gray-500 mt-1">Existing file kept unless a new one is uploaded.</p>
        @endif
        @error('attachment') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div class="mt-6">
    <div class="flex justify-between items-center mb-2">
        <h2 class="text-lg font-semibold">Items</h2>
        <button type="button" id="add-purchase-line" class="px-3 py-2 bg-gray-800 text-white rounded text-sm font-semibold">Add Line</button>
    </div>
    @error('items') <p class="text-sm text-red-600 mb-2">{{ $message }}</p> @enderror

    <div class="overflow-x-auto bg-gray-50 border rounded">
        <table class="min-w-full" id="purchase-lines">
            <thead>
                <tr class="text-xs text-gray-500 uppercase">
                    <th class="px-3 py-2 text-left">Stock Item</th>
                    <th class="px-3 py-2 text-right">Qty</th>
                    <th class="px-3 py-2 text-right">Unit Price</th>
                    <th class="px-3 py-2 text-right">Discount</th>
                    <th class="px-3 py-2 text-right">VAT</th>
                    <th class="px-3 py-2 text-right">Line Total</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoiceItems as $index => $item)
                    <tr class="purchase-line bg-white border-t">
                        <td class="px-3 py-2">
                            <select name="items[{{ $index }}][stock_item_id]" class="stock-item w-64 border rounded p-2">
                                <option value="">Select item</option>
                                @foreach ($stockItems as $stockItem)
                                    <option value="{{ $stockItem->id }}" @selected(($item['stock_item_id'] ?? '') == $stockItem->id)>
                                        {{ $stockItem->name }} ({{ $stockItem->unit }})
                                    </option>
                                @endforeach
                            </select>
                            @error("items.$index.stock_item_id") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </td>
                        <td class="px-3 py-2"><input name="items[{{ $index }}][quantity]" type="number" step="0.001" value="{{ $item['quantity'] ?? 1 }}" class="line-qty w-28 border rounded p-2 text-right"></td>
                        <td class="px-3 py-2"><input name="items[{{ $index }}][unit_price]" type="number" step="0.01" value="{{ $item['unit_price'] ?? 0 }}" class="line-price w-32 border rounded p-2 text-right"></td>
                        <td class="px-3 py-2"><input name="items[{{ $index }}][discount_amount]" type="number" step="0.01" value="{{ $item['discount_amount'] ?? 0 }}" class="line-discount w-28 border rounded p-2 text-right"></td>
                        <td class="px-3 py-2"><input name="items[{{ $index }}][vat_amount]" type="number" step="0.01" value="{{ $item['vat_amount'] ?? 0 }}" class="line-vat w-28 border rounded p-2 text-right"></td>
                        <td class="px-3 py-2 text-right font-semibold line-total">0.00</td>
                        <td class="px-3 py-2 text-right"><button type="button" class="remove-line text-red-700 font-semibold">Remove</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
    <div>
        <label class="block text-sm font-semibold text-gray-700">Notes</label>
        <textarea name="notes" rows="4" class="w-full border rounded p-2">{{ old('notes', $purchaseInvoice->notes) }}</textarea>
        @error('notes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
    <div class="bg-gray-50 border rounded p-4">
        <div class="flex justify-between py-1"><span>Subtotal</span><strong id="purchase-subtotal">0.00</strong></div>
        <div class="flex justify-between py-1"><span>Discount</span><strong id="purchase-discount">0.00</strong></div>
        <div class="flex justify-between py-1"><span>VAT</span><strong id="purchase-vat">0.00</strong></div>
        <div class="flex justify-between py-2 border-t mt-2 text-lg"><span>Total</span><strong id="purchase-total">0.00</strong></div>
    </div>
</div>

<div class="mt-6 flex flex-wrap gap-3">
    <button type="submit" name="action" value="draft" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Save Draft</button>
    <button type="submit" name="action" value="post" class="px-4 py-2 bg-gray-800 text-white rounded font-semibold">Save and Post</button>
    <a href="{{ route('inventory.purchase-invoices.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Cancel</a>
</div>

<template id="purchase-line-template">
    <tr class="purchase-line bg-white border-t">
        <td class="px-3 py-2">
            <select name="items[__INDEX__][stock_item_id]" class="stock-item w-64 border rounded p-2">
                <option value="">Select item</option>
                @foreach ($stockItems as $stockItem)
                    <option value="{{ $stockItem->id }}">{{ $stockItem->name }} ({{ $stockItem->unit }})</option>
                @endforeach
            </select>
        </td>
        <td class="px-3 py-2"><input name="items[__INDEX__][quantity]" type="number" step="0.001" value="1" class="line-qty w-28 border rounded p-2 text-right"></td>
        <td class="px-3 py-2"><input name="items[__INDEX__][unit_price]" type="number" step="0.01" value="0" class="line-price w-32 border rounded p-2 text-right"></td>
        <td class="px-3 py-2"><input name="items[__INDEX__][discount_amount]" type="number" step="0.01" value="0" class="line-discount w-28 border rounded p-2 text-right"></td>
        <td class="px-3 py-2"><input name="items[__INDEX__][vat_amount]" type="number" step="0.01" value="0" class="line-vat w-28 border rounded p-2 text-right"></td>
        <td class="px-3 py-2 text-right font-semibold line-total">0.00</td>
        <td class="px-3 py-2 text-right"><button type="button" class="remove-line text-red-700 font-semibold">Remove</button></td>
    </tr>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.querySelector('#purchase-lines tbody');
        const template = document.querySelector('#purchase-line-template').innerHTML;
        const addButton = document.querySelector('#add-purchase-line');
        const stockItems = @json($stockItemLookup);
        let nextIndex = table.querySelectorAll('.purchase-line').length;

        function numberValue(input) {
            return parseFloat(input.value || '0') || 0;
        }

        function recalculate() {
            let subtotal = 0;
            let discount = 0;
            let vat = 0;
            let total = 0;

            table.querySelectorAll('.purchase-line').forEach(function (row) {
                const qty = numberValue(row.querySelector('.line-qty'));
                const price = numberValue(row.querySelector('.line-price'));
                const lineDiscount = numberValue(row.querySelector('.line-discount'));
                const lineVat = numberValue(row.querySelector('.line-vat'));
                const lineSubtotal = qty * price;
                const lineTotal = Math.max(lineSubtotal - lineDiscount, 0) + lineVat;

                subtotal += lineSubtotal;
                discount += lineDiscount;
                vat += lineVat;
                total += lineTotal;
                row.querySelector('.line-total').textContent = lineTotal.toFixed(2);
            });

            document.querySelector('#purchase-subtotal').textContent = subtotal.toFixed(2);
            document.querySelector('#purchase-discount').textContent = discount.toFixed(2);
            document.querySelector('#purchase-vat').textContent = vat.toFixed(2);
            document.querySelector('#purchase-total').textContent = total.toFixed(2);
        }

        table.addEventListener('input', recalculate);
        table.addEventListener('change', function (event) {
            if (event.target.classList.contains('stock-item')) {
                const selected = stockItems[event.target.value];
                const row = event.target.closest('.purchase-line');
                const priceInput = row.querySelector('.line-price');
                if (selected && parseFloat(priceInput.value || '0') === 0) {
                    priceInput.value = selected.last_purchase_cost || 0;
                }
            }
            recalculate();
        });

        table.addEventListener('click', function (event) {
            if (event.target.classList.contains('remove-line')) {
                if (table.querySelectorAll('.purchase-line').length > 1) {
                    event.target.closest('.purchase-line').remove();
                    recalculate();
                }
            }
        });

        addButton.addEventListener('click', function () {
            table.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', nextIndex));
            nextIndex += 1;
            recalculate();
        });

        recalculate();
    });
</script>
