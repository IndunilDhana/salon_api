<?php

namespace App\Providers;

use App\Infrastructure\Tenancy\TenantContext;
use App\Models\PersonalAccessToken;
use App\Models\SubscriptionRenewal;
use App\Modules\Platform\Subscriptions\Services\SubscriptionService;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionManagerInterface;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use App\Policies\SubscriptionRenewalPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->alias(TenantContext::class, \App\Tenancy\TenantContext::class);

        $this->app->singleton(SubscriptionReaderInterface::class, SubscriptionService::class);
        $this->app->singleton(SubscriptionManagerInterface::class, SubscriptionService::class);
        $this->app->singleton(SubscriptionService::class);

        // Two logical boundaries, one physical database in the starter.
        $source = config('database.connections.'.config('database.default'));
        config(['database.connections.central' => $source, 'database.connections.tenant' => $source, 'database.default' => 'central']);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

            return $frontend.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        Gate::policy(SubscriptionRenewal::class, SubscriptionRenewalPolicy::class);
    }
}
