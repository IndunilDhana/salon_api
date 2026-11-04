<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless($request->user()?->is_platform_admin, 403, 'Platform administrator access is required.');
        $allowed = collect(explode('|', $permission))->contains(fn (string $candidate) => $request->user()->hasPlatformPermission($candidate));
        abort_unless($allowed, 403, "Platform permission [{$permission}] is required.");

        return $next($request);
    }
}
