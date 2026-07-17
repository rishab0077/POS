<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $invoice = $this->route('purchase_invoice');
        $paymentMethods = array_keys(config('pos.purchase_payments'));

        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'invoice_no' => [
                'required',
                'string',
                'max:100',
                Rule::unique('purchase_invoices', 'invoice_no')
                    ->where(fn ($query) => $query->where('supplier_id', $this->supplier_id))
                    ->ignore($invoice?->id),
            ],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'payment_method' => ['nullable', Rule::in($paymentMethods)],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'exists:stock_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
