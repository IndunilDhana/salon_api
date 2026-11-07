<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        protected TenantResolver $resolver,
        protected TenantContext $context
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->resolver->resolve($request);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
