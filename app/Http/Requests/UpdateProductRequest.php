<?php

namespace App\Http\Requests;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        return parent::rules() + ['original_stock_quantity' => ['required', 'integer', 'min:0', 'max:4294967295']];
    }
}
