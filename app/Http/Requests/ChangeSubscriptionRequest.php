<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'cancelled'])], 'plan_id' => ['required_if:status,active', 'prohibited_unless:status,active', 'uuid'], 'current_period_end' => ['required_if:status,active', 'prohibited_unless:status,active', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'], 'trial_starts_at' => ['prohibited'], 'trial_ends_at' => ['prohibited'], 'features' => ['prohibited'], 'price_minor' => ['prohibited'], 'storage_mode' => ['prohibited']];
    }
}
