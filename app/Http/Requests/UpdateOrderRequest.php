<?php

namespace App\Http\Requests;

use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends StoreOrderRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['customer_id'] = ['prohibited'];
        $rules['creation_token'] = ['prohibited'];
        $rules['lock_version'] = ['required', 'integer', 'min:0', 'max:4294967295'];
        $rules['status'] = ['required', Rule::enum(OrderStatus::class)];
        $rules['payment_status'] = ['required', Rule::enum(PaymentStatus::class)];
        $rules['items'][0] = 'sometimes';
        $rules['order_date'][0] = 'sometimes';

        return $rules;
    }
}
