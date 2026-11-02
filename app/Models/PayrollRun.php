<?php

namespace App\Models;

class PayrollRun extends TenantRecord
{
    protected function casts(): array
    {
        return ['total_minor' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }
}
