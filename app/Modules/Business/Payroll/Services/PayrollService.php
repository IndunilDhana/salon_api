<?php

namespace App\Modules\Business\Payroll\Services;

use App\Infrastructure\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Deduction;
use App\Models\Order;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function generate(User $user, array $data): PayrollRun
    {
        abort_unless(app(TenantContext::class)->hasAllBranchesAccess, 403, 'Business-wide payroll requires access to all branches.');
        $zone = app(TenantContext::class)->tenant->timezone;
        $start = CarbonImmutable::parse($data['period_start'], $zone)->startOfDay();
        $end = CarbonImmutable::parse($data['period_end'], $zone)->startOfDay();
        if ($start->day !== 1 || ! $end->isSameDay($start->endOfMonth())) {
            throw ValidationException::withMessages(['period_end' => ['Choose one full calendar month. Salary proration is not supported in this starter.']]);
        }
        $run = DB::connection('tenant')->transaction(function () use ($user, $data, $start, $end) {
            // Unique tenant+month constraint also protects concurrent generation.
            abort_if(PayrollRun::where('period_start', '<=', $data['period_end'])->where('period_end', '>=', $data['period_start'])->exists(), 409, 'A payroll draft already exists for this month.');
            $completed = Appointment::withoutGlobalScope('branch')->where('status', 'completed')->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $end->addDay()->utc())->get()->groupBy('staff_id');
            $paidOrders = Order::withoutGlobalScope('branch')->where('status', 'paid')
                ->whereNotNull('staff_id')
                ->where(function ($q) use ($start, $end) {
                    $q->where('paid_at', '>=', $start->utc())->where('paid_at', '<', $end->addDay()->utc())
                        ->orWhere(function ($q2) use ($start, $end) {
                            $q2->whereNull('paid_at')->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->addDay()->utc());
                        });
                })
                ->get()
                ->groupBy('staff_id');

            $advances = Deduction::withoutGlobalScope('branch')->where('type', 'staff_advance')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get()
                ->groupBy('staff_id');

            $staff = Staff::withoutGlobalScope('branch')->orderBy('name')->get()->filter(fn ($person) => $person->active || $completed->has($person->id) || $paidOrders->has($person->id) || $advances->has($person->id));
            abort_if($staff->isEmpty(), 422, 'Add a team member before generating payroll.');
            $run = PayrollRun::create([...$data, 'status' => 'draft', 'total_minor' => 0, 'created_by' => $user->id]);
            $total = 0;
            foreach ($staff as $person) {
                $appointmentCommission = ($completed->get($person->id) ?? collect())->sum(fn ($appointment) => intdiv($appointment->price_minor * $appointment->commission_bps + 5000, 10000));
                $orderCommission = ($paidOrders->get($person->id) ?? collect())->sum(fn ($order) => (int) $order->commission_minor);
                $commission = $appointmentCommission + $orderCommission;
                $salary = $person->active ? $person->base_salary_minor : 0;
                $deduction = (int) ($advances->get($person->id) ?? collect())->sum('amount_minor');
                $net = max(0, $salary + $commission - $deduction);
                PayrollItem::create([
                    'payroll_run_id' => $run->id,
                    'staff_id' => $person->id,
                    'staff_name' => $person->name,
                    'base_salary_minor' => $salary,
                    'commission_minor' => $commission,
                    'allowance_minor' => 0,
                    'deduction_minor' => $deduction,
                    'net_minor' => $net,
                ]);
                $total += $net;
            }
            $run->update(['total_minor' => $total]);

            return $run->load('items');
        }, 3);

        return $run;
    }
}
