<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MenuStoreRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'shortCode' => ['required', 'unique:menus,shortcode', 'regex:/^\S*$/'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000'],
            'category' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', 'in:stock,service'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'production_area' => ['nullable', 'in:kitchen,bar'],
        ];
    }
}
