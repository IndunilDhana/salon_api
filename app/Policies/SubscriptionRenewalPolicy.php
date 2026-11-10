<?php

namespace App\Policies;

use App\Models\SubscriptionRenewal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubscriptionRenewalPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, SubscriptionRenewal $renewal): bool
    {
        return $user->is_platform_admin || $this->isTenantOwner($user, $renewal->tenant_id);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->isTenantOwner($user, $tenant->id);
    }

    public function update(User $user, SubscriptionRenewal $renewal): bool
    {
        return $user->is_platform_admin;
    }

    private function isTenantOwner(User $user, string $tenantId): bool
    {
        return DB::connection('central')
            ->table('memberships')
            ->join('tenant_roles', function ($join) {
                $join->on('memberships.tenant_id', '=', 'tenant_roles.tenant_id')
                    ->on('memberships.role_id', '=', 'tenant_roles.id');
            })
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.user_id', $user->id)
            ->where('tenant_roles.is_owner', true)
            ->exists();
    }
}
