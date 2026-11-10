<?php

namespace App\Modules\Business\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['bank_transfer', 'online_transfer'])],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'type' => ['nullable', Rule::in(['early', 'late'])],
            'proof_url' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.required' => 'Choose a payment method for the renewal request.',
            'payment_method.in' => 'Payment method must be bank_transfer or online_transfer.',
            'amount_minor.required' => 'Enter the payment amount in minor currency units.',
            'amount_minor.integer' => 'The payment amount must be a whole number.',
            'reference.required' => 'Enter a reference or payment proof identifier.',
            'reference.max' => 'The payment reference cannot exceed 255 characters.',
            'type.in' => 'The renewal type must be early or late.',
        ];
    }
}
