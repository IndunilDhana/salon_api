<?php

namespace App\Models;

class PayrollItem extends TenantRecord
{
    protected function casts(): array
    {
        return ['base_salary_minor' => 'integer', 'commission_minor' => 'integer', 'allowance_minor' => 'integer', 'deduction_minor' => 'integer', 'net_minor' => 'integer'];
    }
}
