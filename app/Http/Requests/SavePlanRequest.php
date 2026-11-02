<?php

namespace App\Http\Requests;

use App\Domain\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $planId = $this->route('id');

        return [
            'name' => [
                $required,
                'string',
                'max:100',
                Rule::unique('central.subscription_plans', 'name')->ignore($planId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_minor' => [$required, 'integer', 'between:0,100000000000'],
            'currency' => [$required, Rule::in(['LKR', 'USD', 'GBP', 'EUR', 'INR', 'AUD'])],
            'billing_interval' => [$required, Rule::in(['month', 'year'])],
            'active' => ['sometimes', 'boolean'],
            'features' => [$this->isMethod('PATCH') ? 'sometimes' : 'present', 'array', 'max:5'],
            'features.*' => ['string', 'distinct', Rule::in(array_keys(SubscriptionService::FEATURES))],
            'storage_mode' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A subscription plan with this name already exists.',
        ];
    }
}
