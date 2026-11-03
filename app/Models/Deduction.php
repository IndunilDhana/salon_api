<?php

namespace App\Models;

class Deduction extends TenantRecord
{
    protected $table = 'deductions';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'date' => 'date:Y-m-d',
        ];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
