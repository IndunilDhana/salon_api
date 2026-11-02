<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequirePlatformPermission;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['tenant' => ResolveTenant::class, 'permission' => RequirePermission::class, 'platform_admin' => RequirePlatformAdmin::class, 'platform_permission' => RequirePlatformPermission::class, 'subscription' => \App\Http\Middleware\EnsureSubscription::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
