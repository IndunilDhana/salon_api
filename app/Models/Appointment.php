<?php

namespace App\Models;

class Appointment extends TenantRecord
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'price_minor' => 'integer', 'commission_bps' => 'integer'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
