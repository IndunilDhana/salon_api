<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends TenantRecord
{
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'total_minor' => 'integer',
            'commission_bps' => 'integer',
            'commission_minor' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
