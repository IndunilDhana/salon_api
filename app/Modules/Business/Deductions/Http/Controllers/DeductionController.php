<?php

namespace App\Modules\Business\Deductions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\Deduction;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeductionController extends Controller
{
    public const CATEGORIES = [
        'supplies' => 'Salon Supplies & Towels',
        'refreshments' => 'Refreshments & Tea',
        'utilities' => 'Utilities (Electricity/Water)',
        'maintenance' => 'Repairs & Maintenance',
        'rent' => 'Rent & Space',
        'transport' => 'Transport & Errands',
        'marketing' => 'Marketing & Promotions',
        'salary_advance' => 'Staff Salary Advance',
        'other' => 'Other Expenses',
    ];

    public function options(): array
    {
        $context = app(TenantContext::class);
        $branches = Branch::query()
            ->where('active', true)
            ->get(['id', 'name', 'is_main']);

        return [
            'staff' => Staff::where('active', true)->orderBy('name')->get(['id', 'name', 'job_title', 'branch_id']),
            'categories' => collect(self::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'branches' => $branches,
        ];
    }

    public function index(Request $request): array
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant;
        $tz = $tenant->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        $query = Deduction::query();

        // Branch filter
        if ($context->branchId) {
            $query->where('branch_id', $context->branchId);
        } elseif ($context->allowedBranchIds !== null) {
            $query->whereIn('branch_id', $context->allowedBranchIds);
        }

        // Type filter
        $type = $request->query('type');
        if (in_array($type, ['business', 'staff_advance'], true)) {
            $query->where('type', $type);
        }

        // Staff filter
        $staffId = $request->query('staff_id');
        if ($staffId && is_string($staffId)) {
            $query->where('staff_id', $staffId);
        }

        // Range filter
        $range = $request->query('range', '30d');
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $query->whereBetween('date', [$startDate, $endDate]);
        } else {
            switch ($range) {
                case 'today':
                    $query->where('date', $now->toDateString());
                    break;
                case '7d':
                    $query->where('date', '>=', $now->subDays(6)->toDateString())
                        ->where('date', '<=', $now->toDateString());
                    break;
                case '30d':
                    $query->where('date', '>=', $now->subDays(29)->toDateString())
                        ->where('date', '<=', $now->toDateString());
                    break;
                case 'month':
                    $query->where('date', '>=', $now->startOfMonth()->toDateString())
                        ->where('date', '<=', $now->endOfMonth()->toDateString());
                    break;
                case 'last_month':
                    $lastMonth = $now->subMonthNoOverflow();
                    $query->where('date', '>=', $lastMonth->startOfMonth()->toDateString())
                        ->where('date', '<=', $lastMonth->endOfMonth()->toDateString());
                    break;
                case 'all':
                default:
                    break;
            }
        }

        $count = (clone $query)->count();
        $totals = (clone $query)->selectRaw('type, SUM(amount_minor) as total')->groupBy('type')->pluck('total', 'type');
        $items = $query->orderByDesc('date')->orderByDesc('created_at')->limit(300)->get();

        $businessTotal = (int) ($totals['business'] ?? 0);
        $staffAdvanceTotal = (int) ($totals['staff_advance'] ?? 0);
        $grandTotal = $businessTotal + $staffAdvanceTotal;

        return [
            'data' => $items,
            'summary' => [
                'total_business_minor' => $businessTotal,
                'total_staff_advance_minor' => $staffAdvanceTotal,
                'total_minor' => $grandTotal,
                'count' => $count,
                'returned_count' => $items->count(),
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $tenant = app(TenantContext::class)->tenant;
        $tz = $tenant->timezone ?: 'UTC';
        $todayStr = CarbonImmutable::now($tz)->toDateString();

        $data = $request->validate([
            'type' => ['required', Rule::in(['business', 'staff_advance'])],
            'category' => ['nullable', 'string', 'max:64'],
            'branch_id' => ['nullable', 'uuid'],
            'staff_id' => [
                'nullable',
                'uuid',
                Rule::exists('tenant.staff', 'id')->where('tenant_id', $tenantId)->where('active', true),
            ],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'payment_method' => ['sometimes', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tenant_id' => ['prohibited'],
        ]);

        $context = app(TenantContext::class);
        $branchId = $data['branch_id'] ?? $context->branchId;
        if (! $branchId) {
            $mainBranch = DB::connection('tenant')->table('branches')
                ->where('tenant_id', $tenantId)
                ->where('is_main', true)
                ->first();
            $branchId = $mainBranch?->id;
        }

        if ($data['type'] === 'staff_advance') {
            if (empty($data['staff_id'])) {
                throw ValidationException::withMessages([
                    'staff_id' => ['Select a team member for the salary advance.'],
                ]);
            }
            $staff = Staff::findOrFail($data['staff_id']);
            $staffName = $staff->name;
            $category = ! empty($data['category']) ? $data['category'] : 'salary_advance';
        } else {
            $staffName = null;
            $data['staff_id'] = null;
            $category = ! empty($data['category']) ? $data['category'] : 'other';
        }

        $deduction = Deduction::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'type' => $data['type'],
            'category' => $category,
            'staff_id' => $data['staff_id'],
            'staff_name' => $staffName,
            'amount_minor' => $data['amount_minor'],
            'currency' => $tenant->currency ?: 'LKR',
            'date' => $data['date'] ?? $todayStr,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'notes' => isset($data['notes']) ? trim($data['notes']) : null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $deduction], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $deduction = Deduction::findOrFail($id);
        $deduction->delete();

        return response()->json(['success' => true]);
    }
}
