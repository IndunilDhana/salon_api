<?php

namespace App\Modules\Platform\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BusinessSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformDashboardController extends Controller
{
    public function dashboard(Request $request, SubscriptionReaderInterface $subscriptionReader)
    {
        $canViewMetrics = $request->user()->hasPlatformPermission('platform.dashboard.view');
        $canViewTenants = $request->user()->hasPlatformPermission('platform.tenants.view')
            || $request->user()->hasPlatformPermission('platform.subscriptions.manage');
        $canViewUsers = $request->user()->hasPlatformPermission('platform.users.view');

        $owners = DB::connection('central')->table('memberships')
            ->join('tenant_roles', 'memberships.role_id', '=', 'tenant_roles.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->where('tenant_roles.is_owner', true)
            ->select('memberships.tenant_id', 'users.email')
            ->get()
            ->keyBy('tenant_id');

        $activePaidCount = BusinessSubscription::where('status', 'active')
            ->where('current_period_end', '>', now('UTC'))
            ->count();

        $trialCount = BusinessSubscription::where('status', 'trialing')
            ->where('current_period_end', '>', now('UTC'))
            ->count();

        $activeSubs = BusinessSubscription::where('status', 'active')
            ->where('current_period_end', '>', now('UTC'))
            ->get();

        $mrrByCurrency = [];
        foreach ($activeSubs as $sub) {
            $mrrByCurrency[$sub->currency] = ($mrrByCurrency[$sub->currency] ?? 0)
                + ($sub->billing_interval === 'year' ? (int) round($sub->price_minor / 12) : $sub->price_minor);
        }

        return [
            'metrics' => $canViewMetrics ? [
                'businesses' => Tenant::count(),
                'active_businesses' => Tenant::where('status', 'active')->count(),
                'users' => User::count(),
                'active_paid_subscriptions' => $activePaidCount,
                'active_trials' => $trialCount,
                'mrr_minor' => $mrrByCurrency['LKR'] ?? 0,
                'mrr_by_currency' => $mrrByCurrency,
            ] : null,
            'tenants' => $canViewTenants ? Tenant::with('subscription')->orderBy('name')->get()->map(fn ($tenant) => [
                ...$tenant->only(['id', 'name', 'slug', 'currency', 'timezone', 'storage_mode', 'status', 'created_at']),
                'owner_email' => $owners->get($tenant->id)?->email,
                'subscription' => $subscriptionReader->summary($tenant->subscription),
            ]) : [],
            'users' => $canViewUsers ? User::with('platformRole')->orderBy('name')->get(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id', 'created_at'])->map(fn ($user) => [
                ...$user->only(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id', 'created_at']),
                'platform_role' => $user->platformRole?->name ?? ($user->is_platform_admin ? 'Super Administrator' : null),
            ]) : [],
        ];
    }
}
