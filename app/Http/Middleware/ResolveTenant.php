<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
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
            $context->permissions = $role->is_owner ? array_keys(\App\Access\RoleCatalog::permissions()) : $role->permissions;

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
