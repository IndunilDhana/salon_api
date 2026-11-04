<?php

namespace App\Models;

class Staff extends TenantRecord
{
    protected $table = 'staff';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'base_salary_minor' => 'integer', 'commission_bps' => 'integer'];
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public static function resolveForUser(User $user, string $tenantId): ?self
    {
        // 1. Match by user_id in this tenant
        $staff = self::where('active', true)
            ->where('user_id', $user->id)
            ->first();

        if ($staff) {
            return $staff;
        }

        // Lookup is read-only: a display name or contact email is not an identity link.
        return null;
    }
}
