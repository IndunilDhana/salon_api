<?php

namespace App\Modules\Platform\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BusinessSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PlatformReportController extends Controller
{
    public function reports(SubscriptionReaderInterface $subscriptionReader)
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
        $mrrByCurrency = [];

        foreach ($subs as $sub) {
            $summary = $subscriptionReader->summary($sub);
            $effectiveStatus = $summary['status'] ?? 'expired';
            $statusCounts[$effectiveStatus] = ($statusCounts[$effectiveStatus] ?? 0) + 1;

            $planName = $sub->plan_name ?: 'Free trial';
            $planDistribution[$planName] = ($planDistribution[$planName] ?? 0) + 1;

            if ($effectiveStatus === 'active') {
                $mrrByCurrency[$sub->currency] = ($mrrByCurrency[$sub->currency] ?? 0)
                    + ($sub->billing_interval === 'year' ? (int) round($sub->price_minor / 12) : $sub->price_minor);
            }
        }

        $sevenDaysFromNow = $now->addDays(7);
        $expiringTrials = Tenant::with('subscription')
            ->whereHas('subscription', function ($query) use ($sevenDaysFromNow) {
                $query->where('status', 'trialing')
                    ->where('current_period_end', '<=', $sevenDaysFromNow);
            })
            ->get()
            ->map(function ($tenant) use ($subscriptionReader) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'currency' => $tenant->currency,
                    'subscription' => $subscriptionReader->summary($tenant->subscription),
                ];
            });

        $totalTenants = Tenant::count();
        $newTenantsLast30 = Tenant::where('created_at', '>=', $thirtyDaysAgo)->count();
        $activeTenants = Tenant::where('status', 'active')->count();

        $totalOrders = DB::connection('tenant')->table('orders')->count();
        $gmvByCurrency = DB::connection('tenant')->table('orders')->where('status', 'paid')
            ->selectRaw('currency, SUM(total_minor) as total')->groupBy('currency')->pluck('total', 'currency')->all();
        $currencies = array_unique([...array_keys($mrrByCurrency), ...array_keys($gmvByCurrency)]);
        sort($currencies);
        $financials = array_map(fn ($currency) => [
            'currency' => $currency,
            'mrr_minor' => $mrrByCurrency[$currency] ?? 0,
            'arr_minor' => ($mrrByCurrency[$currency] ?? 0) * 12,
            'total_gmv_minor' => (int) ($gmvByCurrency[$currency] ?? 0),
        ], $currencies ?: ['LKR']);
        $totalAppointments = DB::connection('tenant')->table('appointments')->count();
        $completedAppointments = DB::connection('tenant')->table('appointments')->where('status', 'completed')->count();
        $totalStaff = DB::connection('tenant')->table('staff')->count();

        return [
            'financials' => [
                'mrr_minor' => $mrrByCurrency['LKR'] ?? 0,
                'arr_minor' => ($mrrByCurrency['LKR'] ?? 0) * 12,
                'total_gmv_minor' => (int) ($gmvByCurrency['LKR'] ?? 0),
                'currency' => 'LKR',
                'by_currency' => $financials,
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
}
