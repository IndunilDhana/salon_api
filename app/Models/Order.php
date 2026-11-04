<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends TenantRecord
{
    public function scopePaidDuring(Builder $query, $start, $end): Builder
    {
        return $query->where('status', 'paid')->where(function (Builder $date) use ($start, $end) {
            $date->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('paid_at')->where('created_at', '>=', $start)->where('created_at', '<', $end));
        });
    }

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

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
