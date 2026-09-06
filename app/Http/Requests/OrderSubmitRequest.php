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
        $paymentMethods = array_keys(config('pos.payments'));
        $directMethods = array_values(array_diff($paymentMethods, ['credit']));

        return [
            'source' => ['required', Rule::in(['pos', 'waiter'])],
            'tableId' => ['nullable', 'integer', 'exists:tables,id'],
            'specialInstructions' => ['nullable', 'array'],
            'specialInstructions.*' => ['string', 'max:255'],
            'isPickUpOrder' => ['required', Rule::in(['true', 'false', true, false, 1, 0, '1', '0'])],
            'paymentMethod' => ['required', Rule::in([...$paymentMethods, 'split'])],
            'payments' => ['nullable', 'required_if:paymentMethod,split', 'array', 'size:2'],
            'payments.*.method' => ['required', Rule::in($directMethods)],
            'payments.*.amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'print_copies' => ['nullable', Rule::in(['customer', 'both'])],
            'loyalty_rewards' => ['nullable', 'array'],
            'loyalty_rewards.*.menu_id' => ['required', 'integer', 'distinct', 'exists:menus,id'],
            'loyalty_rewards.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
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
