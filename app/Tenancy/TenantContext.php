<?php

namespace App\Tenancy;

use App\Models\Tenant;
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
