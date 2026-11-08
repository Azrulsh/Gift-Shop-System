<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        $rules = parent::rules() + ['original_stock_quantity' => ['required', 'integer', 'min:0', 'max:4294967295']];
        $product = $this->route('product');
        $selected = $this->input('supplier_id');
        if ($product?->supplier_id !== null && is_scalar($selected) && (string) $selected === (string) $product->supplier_id) {
            $rules['supplier_id'] = ['nullable', 'integer', Rule::exists('suppliers', 'id')];
        }

        return $rules;
    }
}
