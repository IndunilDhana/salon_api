<?php

namespace App\Http\Middleware;

use App\Access\RoleCatalog;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $context->clear();
        try {
            $slug = $request->header('X-Tenant');
            abort_unless(is_string($slug) && preg_match('/^[a-z0-9-]{2,64}$/', $slug), 422, 'Select a salon using the X-Tenant header.');
            // Membership lookup stays central. Never route a database before authorization.
            $tenant = $request->user()->tenants()->where('slug', $slug)->first();
            abort_unless($tenant instanceof Tenant, 403, 'You do not have access to this salon.');
            abort_unless($tenant->status === 'active', 403, 'This salon is suspended. Contact the platform administrator.');
            $role = TenantRole::where('tenant_id', $tenant->id)->find($tenant->pivot->role_id);
            abort_unless($role, 403, 'This salon membership does not have a valid role.');
            abort_unless($tenant->storage_mode === 'shared', 503, 'This salon storage mode is not provisioned.');
            $context->tenant = $tenant;
            $context->role = $role->name;
            $context->permissions = $role->is_owner ? array_keys(RoleCatalog::permissions()) : $role->permissions;

            // Resolve branch permissions for this user
            $assignedBranchIds = DB::connection('central')->table('branch_users')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $request->user()->id)
                ->pluck('branch_id')
                ->all();

            if ($role->is_owner || empty($assignedBranchIds)) {
                $context->hasAllBranchesAccess = true;
                $context->allowedBranchIds = null;
            } else {
                $context->hasAllBranchesAccess = false;
                $context->allowedBranchIds = $assignedBranchIds;
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

                if (! $context->hasAllBranchesAccess) {
                    abort_unless(in_array($requestedBranch, $context->allowedBranchIds, true), 403, 'You do not have access to this branch.');
                }
                $context->branchId = $requestedBranch;
            } elseif ($requestedBranch === 'all') {
                abort_unless($context->hasAllBranchesAccess, 403, 'You do not have permission to view all branches.');
                $context->branchId = null;
            } else {
                if (! $context->hasAllBranchesAccess && ! empty($context->allowedBranchIds)) {
                    $context->branchId = $context->allowedBranchIds[0];
                } else {
                    $context->branchId = null;
                }
            }

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
