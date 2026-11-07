<?php

namespace App\Modules\Business\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route permission middleware authorizes this operation.
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
