<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()->is_platform_admin, 403, 'Platform administrator access is required.');

        return $next($request);
    }
}
