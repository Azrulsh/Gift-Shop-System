<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\SupplierStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(Supplier::class, 'email')],
            'phone' => ['required', 'string', 'max:30', 'regex:/\A(?=.*[0-9])[+0-9(). \-]+\z/'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(SupplierStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'contact_person', 'email', 'phone', 'address'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = $field === 'email' ? Str::lower(trim($value)) : trim($value);
            }
        }
        $this->merge($normalized);
    }
}
