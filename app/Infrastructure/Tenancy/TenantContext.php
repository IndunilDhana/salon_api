<?php

namespace App\Infrastructure\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class TenantContext
{
    public ?Tenant $tenant = null;

    public ?string $role = null;

    public array $permissions = [];

    public ?string $branchId = null;

    public ?array $allowedBranchIds = null;

    public bool $hasAllBranchesAccess = false;

    public function id(): string
    {
        return $this->tenant?->id ?? throw new LogicException('Tenant context is required for business data.');
    }

    public function authorizeBranch(?string $branchId): ?string
    {
        $branchId ??= $this->branchId;
        $branchId ??= DB::connection('tenant')->table('branches')->where('tenant_id', $this->id())->where('is_main', true)->value('id');
        if ($branchId === null && $this->allowedBranchIds === null) {
            return null;
        }
        $valid = $branchId && DB::connection('tenant')->table('branches')->where('tenant_id', $this->id())->where('id', $branchId)->where('active', true)->exists();
        if (! $valid || ($this->allowedBranchIds !== null && ! in_array($branchId, $this->allowedBranchIds, true))) {
            throw ValidationException::withMessages(['branch_id' => ['Choose an active branch you can access in this business.']]);
        }

        return $branchId;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->role = null;
        $this->permissions = [];
        $this->branchId = null;
        $this->allowedBranchIds = null;
        $this->hasAllBranchesAccess = false;
    }
}
