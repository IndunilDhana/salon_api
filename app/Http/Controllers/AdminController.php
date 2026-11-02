<?php

namespace App\Http\Controllers;

use App\Domain\Subscriptions\SubscriptionService;
use App\Models\BusinessSubscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard()
    {
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

        $mrrMinor = 0;
        foreach ($activeSubs as $sub) {
            if ($sub->billing_interval === 'year') {
                $mrrMinor += (int) round($sub->price_minor / 12);
            } else {
                $mrrMinor += $sub->price_minor;
            }
        }

        return [
            'metrics' => [
                'businesses' => Tenant::count(),
                'active_businesses' => Tenant::where('status', 'active')->count(),
                'users' => User::count(),
                'active_paid_subscriptions' => $activePaidCount,
                'active_trials' => $trialCount,
                'mrr_minor' => $mrrMinor,
            ],
            'tenants' => Tenant::with('subscription')->orderBy('name')->get()->map(fn ($tenant) => [
                ...$tenant->only(['id', 'name', 'slug', 'currency', 'timezone', 'storage_mode', 'status', 'created_at']),
                'owner_email' => $owners->get($tenant->id)?->email,
                'subscription' => app(SubscriptionService::class)->summary($tenant->subscription),
            ]),
            'users' => User::with('platformRole')->orderBy('name')->get(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id', 'created_at'])->map(fn ($user) => [
                ...$user->only(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id', 'created_at']),
                'platform_role' => $user->platformRole?->name ?? ($user->is_platform_admin ? 'Super Administrator' : null),
            ]),
        ];
    }

    public function reports()
    {
        $now = CarbonImmutable::now('UTC');
        $thirtyDaysAgo = $now->subDays(30);

        $subs = BusinessSubscription::all();
        $statusCounts = [
            'trialing' => 0,
            'active' => 0,
            'expired' => 0,
            'cancelled' => 0,
        ];
        $planDistribution = [];
        $mrrMinor = 0;

        foreach ($subs as $sub) {
            $summary = app(SubscriptionService::class)->summary($sub);
            $effectiveStatus = $summary['status'] ?? 'expired';
            $statusCounts[$effectiveStatus] = ($statusCounts[$effectiveStatus] ?? 0) + 1;

            $planName = $sub->plan_name ?: 'Free trial';
            $planDistribution[$planName] = ($planDistribution[$planName] ?? 0) + 1;

            if ($effectiveStatus === 'active') {
                $mrrMinor += ($sub->billing_interval === 'year')
                    ? (int) round($sub->price_minor / 12)
                    : $sub->price_minor;
            }
        }

        $sevenDaysFromNow = $now->addDays(7);
        $expiringTrials = Tenant::with('subscription')
            ->whereHas('subscription', function ($query) use ($sevenDaysFromNow) {
                $query->where('status', 'trialing')
                    ->where('current_period_end', '<=', $sevenDaysFromNow);
            })
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'currency' => $tenant->currency,
                    'subscription' => app(SubscriptionService::class)->summary($tenant->subscription),
                ];
            });

        $totalTenants = Tenant::count();
        $newTenantsLast30 = Tenant::where('created_at', '>=', $thirtyDaysAgo)->count();
        $activeTenants = Tenant::where('status', 'active')->count();

        $totalOrders = DB::connection('tenant')->table('orders')->count();
        $totalGmvMinor = DB::connection('tenant')->table('orders')->where('status', 'paid')->sum('total_minor');
        $totalAppointments = DB::connection('tenant')->table('appointments')->count();
        $completedAppointments = DB::connection('tenant')->table('appointments')->where('status', 'completed')->count();
        $totalStaff = DB::connection('tenant')->table('staff')->count();

        return [
            'financials' => [
                'mrr_minor' => $mrrMinor,
                'arr_minor' => $mrrMinor * 12,
                'total_gmv_minor' => (int) $totalGmvMinor,
                'currency' => 'LKR',
            ],
            'subscriptions' => [
                'total' => count($subs),
                'by_status' => $statusCounts,
                'by_plan' => $planDistribution,
                'expiring_trials' => $expiringTrials,
            ],
            'growth' => [
                'total_businesses' => $totalTenants,
                'active_businesses' => $activeTenants,
                'suspended_businesses' => $totalTenants - $activeTenants,
                'new_last_30_days' => $newTenantsLast30,
                'total_users' => User::count(),
            ],
            'ecosystem' => [
                'total_orders' => $totalOrders,
                'total_appointments' => $totalAppointments,
                'completed_appointments' => $completedAppointments,
                'total_staff' => $totalStaff,
            ],
        ];
    }

    public function updateTenant(Request $request, string $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update($request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]));

        return ['data' => $tenant];
    }
}
