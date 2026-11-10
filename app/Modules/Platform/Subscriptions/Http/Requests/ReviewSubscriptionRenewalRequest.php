<?php

namespace App\Modules\Platform\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewSubscriptionRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Select whether the renewal request is approved or rejected.',
            'status.in' => 'The review decision must be approved or rejected.',
            'review_notes.max' => 'The review notes cannot exceed 1000 characters.',
        ];
    }
}
