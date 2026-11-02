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

    public static function resolveForUser(User $user, string $tenantId): self
    {
        // 1. Match by user_id in this tenant
        $staff = self::where('active', true)
            ->where('user_id', $user->id)
            ->first();

        if ($staff) {
            return $staff;
        }

        // 2. Match by email in this tenant
        if (! empty($user->email)) {
            $staff = self::where('active', true)
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($user->email))])
                ->first();

            if ($staff) {
                if (! $staff->user_id) {
                    $staff->update(['user_id' => $user->id]);
                }

                return $staff;
            }
        }

        // 3. Match by name in this tenant
        $staff = self::where('active', true)
            ->where('name', trim($user->name))
            ->whereNull('user_id')
            ->first();

        if ($staff) {
            $staff->update([
                'user_id' => $user->id,
                'email' => $staff->email ?: $user->email,
            ]);

            return $staff;
        }

        // 4. Automatically create staff record for this tenant user
        return self::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => trim($user->name) ?: 'Staff Member',
            'email' => $user->email ? strtolower(trim($user->email)) : null,
            'job_title' => 'Stylist',
            'base_salary_minor' => 0,
            'commission_bps' => 0,
            'active' => true,
        ]);
    }
}
