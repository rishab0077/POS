<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderSubmitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'source' => ['required', Rule::in(['pos', 'waiter'])],
            'tableId' => ['nullable', 'integer', 'exists:tables,id'],
            'specialInstructions' => ['nullable', 'array'],
            'specialInstructions.*' => ['string', 'max:255'],
            'isPickUpOrder' => ['required', Rule::in(['true', 'false', true, false, 1, 0, '1', '0'])],
            'paymentMethod' => ['required', Rule::in(array_keys(config('pos.payments')))],
            'print_copies' => ['nullable', Rule::in(['customer', 'both'])],
            'credit_customer_name' => ['nullable', 'required_if:paymentMethod,credit', 'string', 'max:255'],
            'credit_customer_contact' => ['nullable', 'string', 'max:50'],
            'billTable' => ['required', Rule::in(['true', 'false', true, false, 1, 0, '1', '0'])],
            'order' => ['required', 'array'],
            'order.orderItems' => ['required', 'array', 'min:1'],
            'order.orderItems.*.id' => ['required', 'integer', 'distinct', 'exists:menus,id'],
            'order.orderItems.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
