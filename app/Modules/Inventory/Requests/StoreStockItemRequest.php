<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('unit')) {
            $this->merge([
                'unit' => strtolower(trim((string) $this->input('unit'))),
            ]);
        }

        if ($this->boolean('sku_auto_generated')) {
            $this->merge(['sku' => null]);
        }
    }

    public function rules()
    {
        $stockItem = $this->route('stock_item');
        $stockItemId = $stockItem?->id;
        $units = array_keys(config('pos.inventory_units', []));

        $currentUnit = $stockItem?->unit ? strtolower(trim((string) $stockItem->unit)) : null;

        if ($currentUnit && !in_array($currentUnit, $units, true)) {
            $units[] = $currentUnit;
        }

        return [
            'category_id' => ['required', 'exists:inventory_categories,id'],
            'default_supplier_id' => ['nullable', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30', Rule::in($units)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('stock_items', 'sku')->ignore($stockItemId)],
            'sku_auto_generated' => ['nullable', 'boolean'],
            'current_quantity' => ['nullable', 'numeric', 'min:0'],
            'low_stock_threshold' => ['required', 'numeric', 'min:0'],
            'average_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'last_purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'auto_deduct' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
