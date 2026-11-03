<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Deduction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Staff;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant;
        $tz = $tenant->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        $range = $request->query('range', '30d');

        switch ($range) {
            case 'today':
                $startLocal = $now->startOfDay();
                $endLocal = $now->endOfDay();
                $salaryMultiplier = 1 / 30;
                break;
            case '7d':
                $startLocal = $now->subDays(6)->startOfDay();
                $endLocal = $now->endOfDay();
                $salaryMultiplier = 7 / 30;
                break;
            case 'month':
                $startLocal = $now->startOfMonth()->startOfDay();
                $endLocal = $now->endOfMonth()->endOfDay();
                $salaryMultiplier = 1.0;
                break;
            case 'last_month':
                $startLocal = $now->subMonth()->startOfMonth()->startOfDay();
                $endLocal = $now->subMonth()->endOfMonth()->endOfDay();
                $salaryMultiplier = 1.0;
                break;
            case 'all':
                $startLocal = $now->subYears(5)->startOfDay();
                $endLocal = $now->endOfDay();
                $salaryMultiplier = 12.0;
                break;
            case '30d':
            default:
                $range = '30d';
                $startLocal = $now->subDays(29)->startOfDay();
                $endLocal = $now->endOfDay();
                $salaryMultiplier = 1.0;
                break;
        }

        $startUtc = $startLocal->utc();
        $endUtc = $endLocal->utc();

        // Branch scoping
        $selectedBranchId = $request->query('branch_id') ?: $context->branchId;
        if ($selectedBranchId === 'all') {
            $selectedBranchId = null;
        }

        $enforceBranches = null;
        if ($selectedBranchId) {
            $enforceBranches = [$selectedBranchId];
        } elseif ($context->allowedBranchIds !== null) {
            $enforceBranches = $context->allowedBranchIds;
        }

        // 1. Orders within range
        $ordersQuery = Order::where('created_at', '>=', $startUtc)
            ->where('created_at', '<=', $endUtc);
        if ($enforceBranches !== null) {
            $ordersQuery->whereIn('branch_id', $enforceBranches);
        }
        $orders = $ordersQuery->get();

        $paidOrders = $orders->where('status', 'paid');
        $unpaidOrders = $orders->where('status', 'unpaid');

        $grossRevenueMinor = (int) $paidOrders->sum('total_minor');
        $unpaidRevenueMinor = (int) $unpaidOrders->sum('total_minor');
        $paidCount = $paidOrders->count();
        $unpaidCount = $unpaidOrders->count();
        $totalOrdersCount = $orders->count();
        $avgTicketMinor = $paidCount > 0 ? (int) round($grossRevenueMinor / $paidCount) : 0;
        $totalCommissionMinor = (int) $paidOrders->sum('commission_minor');

        // Payment method breakdown
        $cashOrders = $paidOrders->where('payment_method', 'cash');
        $cardOrders = $paidOrders->where('payment_method', 'card');
        $otherOrders = $paidOrders->filter(fn ($o) => !in_array($o->payment_method, ['cash', 'card'], true));

        $paymentMethods = [
            'cash' => [
                'count' => $cashOrders->count(),
                'total_minor' => (int) $cashOrders->sum('total_minor'),
                'percentage' => $grossRevenueMinor > 0 ? round(($cashOrders->sum('total_minor') / $grossRevenueMinor) * 100, 1) : 0,
            ],
            'card' => [
                'count' => $cardOrders->count(),
                'total_minor' => (int) $cardOrders->sum('total_minor'),
                'percentage' => $grossRevenueMinor > 0 ? round(($cardOrders->sum('total_minor') / $grossRevenueMinor) * 100, 1) : 0,
            ],
            'other' => [
                'count' => $otherOrders->count(),
                'total_minor' => (int) $otherOrders->sum('total_minor'),
                'percentage' => $grossRevenueMinor > 0 ? round(($otherOrders->sum('total_minor') / $grossRevenueMinor) * 100, 1) : 0,
            ],
        ];

        // Daily trend timeline
        $dailyTrendMap = [];
        foreach ($paidOrders as $order) {
            $dateKey = CarbonImmutable::parse($order->created_at)->setTimezone($tz)->toDateString();
            if (!isset($dailyTrendMap[$dateKey])) {
                $dailyTrendMap[$dateKey] = [
                    'date' => $dateKey,
                    'total_minor' => 0,
                    'orders_count' => 0,
                    'cash_minor' => 0,
                    'card_minor' => 0,
                    'other_minor' => 0,
                ];
            }
            $dailyTrendMap[$dateKey]['total_minor'] += (int) $order->total_minor;
            $dailyTrendMap[$dateKey]['orders_count'] += 1;
            if ($order->payment_method === 'cash') {
                $dailyTrendMap[$dateKey]['cash_minor'] += (int) $order->total_minor;
            } elseif ($order->payment_method === 'card') {
                $dailyTrendMap[$dateKey]['card_minor'] += (int) $order->total_minor;
            } else {
                $dailyTrendMap[$dateKey]['other_minor'] += (int) $order->total_minor;
            }
        }
        ksort($dailyTrendMap);
        $dailyTrend = array_values($dailyTrendMap);

        // 2. Staff performance
        $staffQuery = Staff::query();
        if ($enforceBranches !== null) {
            $staffQuery->where(function ($q) use ($enforceBranches) {
                $q->whereIn('branch_id', $enforceBranches)->orWhereNull('branch_id');
            });
        }
        $allStaff = $staffQuery->get();

        $apptsQuery = Appointment::where('starts_at', '>=', $startUtc)
            ->where('starts_at', '<=', $endUtc);
        if ($enforceBranches !== null) {
            $apptsQuery->whereIn('branch_id', $enforceBranches);
        }
        $appointmentsInRange = $apptsQuery->get();

        $staffPerformance = [];
        foreach ($allStaff as $member) {
            $memberOrders = $paidOrders->where('staff_id', $member->id);
            $memberAppointments = $appointmentsInRange->where('staff_id', $member->id);
            $completedAppts = $memberAppointments->where('status', 'completed')->count();
            $revMinor = (int) $memberOrders->sum('total_minor');
            $commMinor = (int) $memberOrders->sum('commission_minor');
            $billsCount = $memberOrders->count();

            $staffPerformance[] = [
                'id' => $member->id,
                'name' => $member->name,
                'job_title' => $member->job_title,
                'active' => (bool) $member->active,
                'branch_id' => $member->branch_id,
                'commission_rate' => $member->commission_bps / 100,
                'orders_count' => $billsCount,
                'appointments_count' => $memberAppointments->count(),
                'completed_appointments' => $completedAppts,
                'revenue_minor' => $revMinor,
                'commission_minor' => $commMinor,
                'avg_bill_minor' => $billsCount > 0 ? (int) round($revMinor / $billsCount) : 0,
                'contribution_percentage' => $grossRevenueMinor > 0 ? round(($revMinor / $grossRevenueMinor) * 100, 1) : 0,
            ];
        }

        usort($staffPerformance, fn ($a, $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        // 3. Services & Products breakdown
        $paidOrderIds = $paidOrders->pluck('id')->all();
        $orderItems = !empty($paidOrderIds)
            ? OrderItem::whereIn('order_id', $paidOrderIds)->get()
            : collect([]);

        $itemsGrouped = [];
        $totalServiceRevMinor = 0;
        $totalProductRevMinor = 0;
        $totalItemsSold = 0;

        foreach ($orderItems as $item) {
            $key = $item->name . '::' . $item->type;
            if (!isset($itemsGrouped[$key])) {
                $itemsGrouped[$key] = [
                    'name' => $item->name,
                    'type' => $item->type,
                    'quantity' => 0,
                    'total_minor' => 0,
                    'unit_price_minor' => (int) $item->unit_price_minor,
                ];
            }
            $itemsGrouped[$key]['quantity'] += (int) $item->quantity;
            $itemsGrouped[$key]['total_minor'] += (int) $item->total_minor;
            $totalItemsSold += (int) $item->quantity;

            if ($item->type === 'service') {
                $totalServiceRevMinor += (int) $item->total_minor;
            } else {
                $totalProductRevMinor += (int) $item->total_minor;
            }
        }

        foreach ($itemsGrouped as &$entry) {
            $entry['share_percentage'] = $grossRevenueMinor > 0
                ? round(($entry['total_minor'] / $grossRevenueMinor) * 100, 1)
                : 0;
        }
        unset($entry);

        usort($itemsGrouped, fn ($a, $b) => $b['total_minor'] <=> $a['total_minor']);

        $catalogSummary = [
            'total_items_sold' => $totalItemsSold,
            'service_revenue_minor' => $totalServiceRevMinor,
            'product_revenue_minor' => $totalProductRevMinor,
            'service_share' => $grossRevenueMinor > 0 ? round(($totalServiceRevMinor / $grossRevenueMinor) * 100, 1) : 0,
            'product_share' => $grossRevenueMinor > 0 ? round(($totalProductRevMinor / $grossRevenueMinor) * 100, 1) : 0,
            'items' => array_values($itemsGrouped),
        ];

        // 4. Appointments & Client Trends
        $totalAppts = $appointmentsInRange->count();
        $completedApptsCount = $appointmentsInRange->where('status', 'completed')->count();
        $cancelledApptsCount = $appointmentsInRange->where('status', 'cancelled')->count();
        $bookedApptsCount = $appointmentsInRange->where('status', 'booked')->count();

        $uniqueClientsCount = $appointmentsInRange->pluck('customer_name')->filter()->unique()->count();

        $morningCount = 0;
        $afternoonCount = 0;
        $eveningCount = 0;

        $dayOfWeekMap = [
            'Monday' => 0,
            'Tuesday' => 0,
            'Wednesday' => 0,
            'Thursday' => 0,
            'Friday' => 0,
            'Saturday' => 0,
            'Sunday' => 0,
        ];

        foreach ($appointmentsInRange as $appt) {
            $localTime = CarbonImmutable::parse($appt->starts_at)->setTimezone($tz);
            $hour = (int) $localTime->format('H');
            if ($hour < 12) {
                $morningCount++;
            } elseif ($hour < 17) {
                $afternoonCount++;
            } else {
                $eveningCount++;
            }

            $dayName = $localTime->format('l');
            if (isset($dayOfWeekMap[$dayName])) {
                $dayOfWeekMap[$dayName]++;
            }
        }

        $appointmentTrends = [
            'total_appointments' => $totalAppts,
            'completed' => $completedApptsCount,
            'cancelled' => $cancelledApptsCount,
            'booked' => $bookedApptsCount,
            'completion_rate' => $totalAppts > 0 ? round(($completedApptsCount / $totalAppts) * 100, 1) : 0,
            'cancellation_rate' => $totalAppts > 0 ? round(($cancelledApptsCount / $totalAppts) * 100, 1) : 0,
            'unique_clients' => $uniqueClientsCount,
            'time_distribution' => [
                'morning' => ['count' => $morningCount, 'percentage' => $totalAppts > 0 ? round(($morningCount / $totalAppts) * 100, 1) : 0],
                'afternoon' => ['count' => $afternoonCount, 'percentage' => $totalAppts > 0 ? round(($afternoonCount / $totalAppts) * 100, 1) : 0],
                'evening' => ['count' => $eveningCount, 'percentage' => $totalAppts > 0 ? round(($eveningCount / $totalAppts) * 100, 1) : 0],
            ],
            'day_distribution' => $dayOfWeekMap,
        ];

        // 5. Financial P&L Profit Summary
        $activeStaffMonthlyBase = (int) $allStaff->where('active', true)->sum('base_salary_minor');
        $estimatedBaseSalaryExpense = (int) round($activeStaffMonthlyBase * $salaryMultiplier);
        $totalStaffCostMinor = $totalCommissionMinor + $estimatedBaseSalaryExpense;

        $deductionsQuery = Deduction::whereBetween('date', [$startLocal->toDateString(), $endLocal->toDateString()]);
        if ($enforceBranches !== null) {
            $deductionsQuery->whereIn('branch_id', $enforceBranches);
        }
        $deductionsInRange = $deductionsQuery->get();

        $dailyDeductionsExpenseMinor = (int) $deductionsInRange->where('type', 'business')->sum('amount_minor');
        $staffAdvancesTotalMinor = (int) $deductionsInRange->where('type', 'staff_advance')->sum('amount_minor');

        $totalOperatingCostsMinor = $totalStaffCostMinor + $dailyDeductionsExpenseMinor;
        $netMarginMinor = $grossRevenueMinor - $totalOperatingCostsMinor;
        $netMarginPercentage = $grossRevenueMinor > 0 ? round(($netMarginMinor / $grossRevenueMinor) * 100, 1) : 0;

        $financialSummary = [
            'gross_revenue_minor' => $grossRevenueMinor,
            'commissions_expense_minor' => $totalCommissionMinor,
            'estimated_base_salary_expense_minor' => $estimatedBaseSalaryExpense,
            'total_staff_cost_minor' => $totalStaffCostMinor,
            'daily_deductions_expense_minor' => $dailyDeductionsExpenseMinor,
            'staff_advances_total_minor' => $staffAdvancesTotalMinor,
            'total_operating_costs_minor' => $totalOperatingCostsMinor,
            'net_margin_minor' => $netMarginMinor,
            'net_margin_percentage' => $netMarginPercentage,
        ];

        // 6. Branch comparison table
        $allBranches = DB::connection('tenant')->table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $branchBreakdown = [];
        foreach ($allBranches as $b) {
            $bOrders = $paidOrders->where('branch_id', $b->id);
            $bRev = (int) $bOrders->sum('total_minor');
            $bCommission = (int) $bOrders->sum('commission_minor');
            $bStaffBase = (int) $allStaff->where('branch_id', $b->id)->where('active', true)->sum('base_salary_minor');
            $bEstBaseSalary = (int) round($bStaffBase * $salaryMultiplier);
            $bTotalStaffCost = $bCommission + $bEstBaseSalary;
            $bDed = (int) $deductionsInRange->where('branch_id', $b->id)->where('type', 'business')->sum('amount_minor');
            $bAdvances = (int) $deductionsInRange->where('branch_id', $b->id)->where('type', 'staff_advance')->sum('amount_minor');
            $bNetMargin = $bRev - $bTotalStaffCost - $bDed;
            $bAppts = $appointmentsInRange->where('branch_id', $b->id)->count();

            $branchBreakdown[] = [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'is_main' => (bool) $b->is_main,
                'orders_count' => $bOrders->count(),
                'appointments_count' => $bAppts,
                'revenue_minor' => $bRev,
                'staff_cost_minor' => $bTotalStaffCost,
                'deductions_minor' => $bDed,
                'advances_minor' => $bAdvances,
                'profit_minor' => $bNetMargin,
                'profit_margin_percentage' => $bRev > 0 ? round(($bNetMargin / $bRev) * 100, 1) : 0,
                'contribution_percentage' => $grossRevenueMinor > 0 ? round(($bRev / $grossRevenueMinor) * 100, 1) : 0,
            ];
        }

        return [
            'meta' => [
                'range' => $range,
                'start_date' => $startLocal->toDateString(),
                'end_date' => $endLocal->toDateString(),
                'timezone' => $tz,
                'currency' => $tenant->currency ?: 'LKR',
                'branch_id' => $selectedBranchId,
                'is_centralized' => $selectedBranchId === null,
                'branches' => $allBranches->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'is_main' => (bool) $b->is_main,
                ]),
            ],
            'sales' => [
                'gross_revenue_minor' => $grossRevenueMinor,
                'unpaid_revenue_minor' => $unpaidRevenueMinor,
                'total_orders' => $totalOrdersCount,
                'paid_orders' => $paidCount,
                'unpaid_orders' => $unpaidCount,
                'avg_ticket_minor' => $avgTicketMinor,
                'total_commission_minor' => $totalCommissionMinor,
                'payment_methods' => $paymentMethods,
                'daily_trend' => $dailyTrend,
            ],
            'staff' => $staffPerformance,
            'catalog' => $catalogSummary,
            'appointments' => $appointmentTrends,
            'financial' => $financialSummary,
            'branches_comparison' => $branchBreakdown,
        ];
    }
}
