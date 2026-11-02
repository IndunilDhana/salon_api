<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Deduction;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Staff;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant;
        $tz = $tenant->timezone ?: 'UTC';
        $day = CarbonImmutable::now($tz)->startOfDay();
        $endOfDay = $day->endOfDay();

        $monthStart = $day->startOfMonth()->startOfDay();
        $monthEnd = $day->endOfMonth()->endOfDay();

        // 1. Appointments today
        $appointments = Appointment::with('staff')
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->orderBy('starts_at')
            ->get();

        $completedTodayAppointments = $appointments->where('status', 'completed');
        $appointmentRevenueTodayMinor = (int) $completedTodayAppointments->sum('price_minor');

        // 2. Orders today and this month
        $paidOrdersToday = Order::where('status', 'paid')
            ->whereBetween('created_at', [$day->utc(), $endOfDay->utc()])
            ->get();
        $paidOrdersMonth = Order::where('status', 'paid')
            ->whereBetween('created_at', [$monthStart->utc(), $monthEnd->utc()])
            ->get();

        $ordersRevenueTodayMinor = (int) $paidOrdersToday->sum('total_minor');
        $ordersRevenueMonthMinor = (int) $paidOrdersMonth->sum('total_minor');

        // Month completed appointments revenue
        $completedMonthAppointmentsRevenueMinor = (int) Appointment::where('status', 'completed')
            ->whereBetween('starts_at', [$monthStart->utc(), $monthEnd->utc()])
            ->sum('price_minor');

        $effectiveTodayRevenueMinor = max($appointmentRevenueTodayMinor, $ordersRevenueTodayMinor);
        $effectiveMonthRevenueMinor = max($ordersRevenueMonthMinor, $completedMonthAppointmentsRevenueMinor);

        // 3. Deductions today & this month
        $canViewDeductions = in_array('deductions.view', $context->permissions, true)
            || in_array('payroll.view', $context->permissions, true);

        $todayDeductions = $canViewDeductions
            ? Deduction::where('date', $day->toDateString())->get()
            : collect();

        $monthDeductions = $canViewDeductions
            ? Deduction::whereBetween('date', [$day->startOfMonth()->toDateString(), $day->endOfMonth()->toDateString()])->get()
            : collect();

        $todayBusinessDeductionsMinor = (int) $todayDeductions->where('type', 'business')->sum('amount_minor');
        $todayStaffAdvancesMinor = (int) $todayDeductions->where('type', 'staff_advance')->sum('amount_minor');
        $todayTotalDeductionsMinor = $todayBusinessDeductionsMinor + $todayStaffAdvancesMinor;

        $monthBusinessDeductionsMinor = (int) $monthDeductions->where('type', 'business')->sum('amount_minor');
        $monthStaffAdvancesMinor = (int) $monthDeductions->where('type', 'staff_advance')->sum('amount_minor');
        $monthTotalDeductionsMinor = $monthBusinessDeductionsMinor + $monthStaffAdvancesMinor;

        // 4. Staff costs & Net Margin
        $activeStaff = Staff::where('active', true)->get();
        $activeStaffCount = $activeStaff->count();
        $monthlyBaseSalariesMinor = (int) $activeStaff->sum('base_salary_minor');
        $monthlyCommissionsMinor = (int) $paidOrdersMonth->sum('commission_minor');
        $totalStaffCostMonthMinor = $monthlyBaseSalariesMinor + $monthlyCommissionsMinor;

        $monthTotalOperatingCostsMinor = $totalStaffCostMonthMinor + $monthBusinessDeductionsMinor;
        $monthNetMarginMinor = $effectiveMonthRevenueMinor - $monthTotalOperatingCostsMinor;
        $monthNetMarginPercentage = $effectiveMonthRevenueMinor > 0
            ? round(($monthNetMarginMinor / $effectiveMonthRevenueMinor) * 100, 1)
            : ($monthNetMarginMinor < 0 ? -100.0 : 0.0);

        // Status determination
        if ($effectiveMonthRevenueMinor === 0 && $monthTotalOperatingCostsMinor === 0) {
            $monthStatus = 'neutral';
        } elseif ($monthNetMarginMinor > 0) {
            $monthStatus = 'growth';
        } elseif ($monthNetMarginMinor < 0) {
            $monthStatus = 'loss';
        } else {
            $monthStatus = 'break_even';
        }

        $todayNetMinor = $appointmentRevenueTodayMinor - $todayTotalDeductionsMinor;
        $todayStatus = $todayNetMinor > 0 ? 'growth' : ($todayNetMinor < 0 ? 'loss' : 'neutral');

        $businessPosition = [
            'status' => $monthStatus,
            'month_revenue_minor' => $effectiveMonthRevenueMinor,
            'month_deductions_minor' => $monthTotalDeductionsMinor,
            'month_business_expenses_minor' => $monthBusinessDeductionsMinor,
            'month_staff_advances_minor' => $monthStaffAdvancesMinor,
            'month_estimated_staff_cost_minor' => $totalStaffCostMonthMinor,
            'month_total_operating_costs_minor' => $monthTotalOperatingCostsMinor,
            'month_net_margin_minor' => $monthNetMarginMinor,
            'month_net_margin_percentage' => $monthNetMarginPercentage,
            'today_revenue_minor' => $appointmentRevenueTodayMinor,
            'today_deductions_minor' => $todayTotalDeductionsMinor,
            'today_business_expenses_minor' => $todayBusinessDeductionsMinor,
            'today_staff_advances_minor' => $todayStaffAdvancesMinor,
            'today_net_minor' => $todayNetMinor,
            'today_status' => $todayStatus,
        ];

        $deductionsSummary = [
            'today_total_minor' => $todayTotalDeductionsMinor,
            'today_business_minor' => $todayBusinessDeductionsMinor,
            'today_staff_advance_minor' => $todayStaffAdvancesMinor,
            'today_count' => $todayDeductions->count(),
            'month_total_minor' => $monthTotalDeductionsMinor,
            'month_business_minor' => $monthBusinessDeductionsMinor,
            'month_staff_advance_minor' => $monthStaffAdvancesMinor,
            'month_count' => $monthDeductions->count(),
        ];

        return [
            'tenant' => [
                ...$context->tenant->only(['id', 'name', 'slug', 'currency', 'timezone', 'storage_mode', 'status']),
                'role' => $context->role,
                'permissions' => $context->permissions,
                'subscription' => app(SubscriptionService::class)->summary($context->tenant->subscription),
            ],
            'date' => $day->toDateString(),
            'metrics' => [
                'appointments_today' => $appointments->count(),
                'completed_today' => $completedTodayAppointments->count(),
                'revenue_today_minor' => $appointmentRevenueTodayMinor,
                'active_staff' => $activeStaffCount,
                'deductions_today_minor' => $todayTotalDeductionsMinor,
                'deductions_month_minor' => $monthTotalDeductionsMinor,
                'net_today_minor' => $todayNetMinor,
                'business_status' => $monthStatus,
            ],
            'business_position' => $canViewDeductions ? $businessPosition : null,
            'deductions_summary' => $canViewDeductions ? $deductionsSummary : null,
            'appointments' => in_array('appointments.view', $context->permissions, true)
                ? AppointmentResource::collection($appointments)
                : [],
            'staff' => in_array('staff.view', $context->permissions, true)
                ? Staff::orderBy('name')->get()
                : [],
        ];
    }
}
