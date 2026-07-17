<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemStockMappingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $mapping = $this->route('menu_mapping');

        return [
            'menu_item_id' => [
                'required',
                'exists:menus,id',
                Rule::unique('menu_item_stock_mappings', 'menu_item_id')
                    ->where('stock_item_id', $this->input('stock_item_id'))
                    ->ignore($mapping?->id),
            ],
            'stock_item_id' => ['required', 'exists:stock_items,id'],
            'quantity_per_sale' => ['required', 'numeric', 'min:0.001'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
