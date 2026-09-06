<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryStoreRequest extends FormRequest
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
     * @return array
     */
    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'print_destination' => ['nullable', Rule::in([
                Category::PRINT_DESTINATION_KOT,
                Category::PRINT_DESTINATION_BOT,
            ])],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'loyalty_eligible' => ['nullable', 'boolean'],
        ];
    }
}
