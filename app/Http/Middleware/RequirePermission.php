<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $allowed = str_contains($permission, '|') ? explode('|', $permission) : [$permission];
        $contextPermissions = app(TenantContext::class)->permissions;
        abort_unless(array_intersect($allowed, $contextPermissions) !== [], 403, 'Your salon role does not allow this action.');

        return $next($request);
    }
}
