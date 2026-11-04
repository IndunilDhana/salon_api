<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TenantContext::class)->tenant->hasActiveBranch()) {
            return response()->json([
                'message' => 'Create your first active branch before using this workspace.',
                'code' => 'branch_setup_required',
            ], 409);
        }

        return $next($request);
    }
}
