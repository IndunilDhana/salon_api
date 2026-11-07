<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\TenantContext;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscription
{
    public function handle(Request $request, Closure $next, ?string $feature = null)
    {
        $summary = app(SubscriptionReaderInterface::class)->summary(app(TenantContext::class)->tenant->subscription);
        if (! $summary || ! $summary['access_allowed']) {
            return response()->json(['message' => 'This business needs an active subscription. Open Subscription to review your access.', 'code' => 'subscription_required'], 403);
        }
        if ($feature !== null && ! in_array($feature, $summary['features'], true)) {
            return response()->json(['message' => 'This feature is not included in the business subscription.', 'code' => 'feature_not_in_plan', 'feature' => $feature], 403);
        }

        return $next($request);
    }
}
