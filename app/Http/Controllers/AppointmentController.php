<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Staff;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function options(): array
    {
        $context = app(TenantContext::class);
        $query = Staff::where('active', true);
        if ($context->branchId) {
            $query->where(function ($q) use ($context) {
                $q->where('branch_id', $context->branchId)->orWhereNull('branch_id');
            });
        } elseif ($context->allowedBranchIds !== null) {
            $query->where(function ($q) use ($context) {
                $q->whereIn('branch_id', $context->allowedBranchIds)->orWhereNull('branch_id');
            });
        }

        return ['staff' => $query->orderBy('name')->get(['id', 'name', 'job_title', 'active'])];
    }

    public function appointments()
    {
        $context = app(TenantContext::class);
        $query = Appointment::with(['staff', 'branch']);
        if ($context->branchId) {
            $query->where('branch_id', $context->branchId);
        } elseif ($context->allowedBranchIds !== null) {
            $query->whereIn('branch_id', $context->allowedBranchIds);
        }

        return ['data' => $query->orderByDesc('starts_at')->limit(250)->get()->map(fn ($appointment) => $this->appointmentData($appointment))];
    }

    public function createAppointment(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'uuid', Rule::exists('tenant.staff', 'id')->where('tenant_id', app(TenantContext::class)->id())->where('active', true)],
            'branch_id' => ['nullable', 'uuid'],
            'customer_name' => ['required', 'string', 'max:100'],
            'service_name' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'ends_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', 'after:starts_at'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'status' => ['sometimes', Rule::in(['booked', 'completed'])],
            'tenant_id' => ['prohibited'],
        ]);
        $appointment = DB::connection('tenant')->transaction(function () use ($data) {
            $staff = Staff::where('active', true)->lockForUpdate()->findOrFail($data['staff_id']);
            $start = CarbonImmutable::parse($data['starts_at'])->utc();
            $end = CarbonImmutable::parse($data['ends_at'])->utc();
            if ($end->diffInMinutes($start, true) > 1440) {
                throw ValidationException::withMessages(['ends_at' => ['Appointments must last no longer than 24 hours.']]);
            }
            $overlaps = Appointment::withoutGlobalScope('branch')->where('staff_id', $staff->id)->where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if ($overlaps) {
                throw ValidationException::withMessages(['starts_at' => ['This team member already has an appointment at that time.']]);
            }

            $context = app(TenantContext::class);
            $branchId = $data['branch_id'] ?? $context->branchId;
            if (! $branchId) {
                $mainBranch = DB::connection('tenant')->table('branches')
                    ->where('tenant_id', $context->id())
                    ->where('is_main', true)
                    ->first();
                $branchId = $mainBranch?->id;
            }

            return Appointment::create([...$data, 'branch_id' => $branchId, 'starts_at' => $start, 'ends_at' => $end, 'commission_bps' => $staff->commission_bps]);
        }, 3);

        return response()->json(['data' => $this->appointmentData($appointment->load('staff'))], 201);
    }

    public function updateAppointment(Request $request, string $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])], 'tenant_id' => ['prohibited']]);
        $appointment = DB::connection('tenant')->transaction(function () use ($id, $data) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);
            abort_unless($appointment->status === 'booked' || $appointment->status === $data['status'], 409, 'Completed and cancelled appointments cannot be changed.');
            $appointment->update($data);

            return $appointment->load('staff');
        });

        return ['data' => $this->appointmentData($appointment)];
    }

    private function appointmentData(Appointment $appointment): array
    {
        return AppointmentResource::make($appointment)->resolve();
    }
}
