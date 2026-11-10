<?php

namespace App\Infrastructure\Tenancy;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Modules\Business\Access\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantResolver
{
    public function __construct(
        protected TenantContext $context
    ) {}

    public function resolve(Request $request): void
    {
        $this->context->clear();

        $slug = $request->header('X-Tenant');
        abort_unless(is_string($slug) && preg_match('/^[a-z0-9-]{2,64}$/', $slug), 422, 'Select a salon using the X-Tenant header.');

        // Membership lookup stays central. Never route a database before authorization.
        $tenant = $request->user()->tenants()->where('slug', $slug)->first();
        abort_unless($tenant instanceof Tenant, 403, 'You do not have access to this salon.');
        abort_unless($tenant->status === 'active', 403, 'This salon is suspended. Contact the platform administrator.');

        $role = TenantRole::where('tenant_id', $tenant->id)->find($tenant->pivot->role_id);
        abort_unless($role, 403, 'This salon membership does not have a valid role.');
        abort_unless($tenant->storage_mode === 'shared', 503, 'This salon storage mode is not provisioned.');

        $this->context->tenant = $tenant;
        $this->context->role = $role->name;
        $this->context->permissions = $role->is_owner ? array_keys(RoleCatalog::permissions()) : $role->permissions;

        // Resolve branch permissions for this user
        $assignedBranchIds = DB::connection('central')->table('branch_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->pluck('branch_id')
            ->all();

        if ($role->is_owner || empty($assignedBranchIds)) {
            $this->context->hasAllBranchesAccess = true;
            $this->context->allowedBranchIds = null;
        } else {
            $this->context->hasAllBranchesAccess = false;
            $this->context->allowedBranchIds = $assignedBranchIds;
        }

        $requestedBranch = $request->header('X-Branch') ?: $request->query('branch_id');
        if ($request->header('X-Branch') && $request->query('branch_id') && $request->header('X-Branch') !== $request->query('branch_id')) {
            abort(403, 'Conflicting branch selections are not allowed.');
        }
        abort_unless($requestedBranch === null || is_string($requestedBranch), 422, 'Choose a valid branch.');

        if ($requestedBranch && $requestedBranch !== 'all') {
            $branchExists = DB::connection('tenant')->table('branches')
                ->where('tenant_id', $tenant->id)
                ->where('id', $requestedBranch)
                ->exists();
            abort_unless($branchExists, 404, 'The requested branch was not found.');

            if (! $this->context->hasAllBranchesAccess) {
                abort_unless(in_array($requestedBranch, $this->context->allowedBranchIds, true), 403, 'You do not have access to this branch.');
            }
            $this->context->branchId = $requestedBranch;
        } elseif ($requestedBranch === 'all') {
            abort_unless($this->context->hasAllBranchesAccess, 403, 'You do not have permission to view all branches.');
            $this->context->branchId = null;
        } else {
            if (! $this->context->hasAllBranchesAccess && ! empty($this->context->allowedBranchIds)) {
                $this->context->branchId = $this->context->allowedBranchIds[0];
            } else {
                $this->context->branchId = null;
            }
        }
    }
}
