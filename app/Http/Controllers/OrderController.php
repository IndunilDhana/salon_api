<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Staff;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function options(Request $request): array
    {
        $user = $request->user();
        $tenantId = app(TenantContext::class)->id();
        $currentStaff = $user ? Staff::resolveForUser($user, $tenantId) : null;

        $canAssignStaff = in_array('orders.assign_staff', app(TenantContext::class)->permissions, true);

        return [
            'staff' => $canAssignStaff
                ? Staff::where('active', true)->orderBy('name')->get(['id', 'name', 'job_title', 'commission_bps'])
                : ($currentStaff ? collect([$currentStaff->only(['id', 'name', 'job_title', 'commission_bps'])]) : collect([])),
            'current_staff_id' => $currentStaff?->id,
            'can_assign_staff' => $canAssignStaff,
        ];
    }

    public function index(): array
    {
        $context = app(TenantContext::class);
        $query = Order::with(['items', 'branch']);
        if ($context->branchId) {
            $query->where('branch_id', $context->branchId);
        } elseif ($context->allowedBranchIds !== null) {
            $query->whereIn('branch_id', $context->allowedBranchIds);
        }

        return ['data' => $query->latest()->limit(250)->get()->map(fn (Order $order) => $this->orderData($order))];
    }

    public function show(string $id): array
    {
        return ['data' => $this->orderData(Order::with(['items', 'branch'])->findOrFail($id))];
    }

    public function store(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'uuid'],
            'staff_id' => ['nullable', 'uuid', Rule::exists('tenant.staff', 'id')->where('tenant_id', app(TenantContext::class)->id())->where('active', true)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:catalog_item_id,quantity'],
            'items.*.catalog_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'status' => ['sometimes', Rule::in(['unpaid', 'paid'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'other'])],
            'tenant_id' => ['prohibited'],
            'subtotal_minor' => ['prohibited'],
            'total_minor' => ['prohibited'],
        ]);
        if (trim($data['customer_name']) === '') {
            throw ValidationException::withMessages(['customer_name' => ['Enter a customer name.']]);
        }

        return response()->json(['data' => $this->orderData($service->create($data, $request->user()->id))], 201);
    }

    public function update(Request $request, string $id, OrderService $service): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['unpaid', 'paid'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'other'])],
            'tenant_id' => ['prohibited'],
            'customer_name' => ['prohibited'],
            'items' => ['prohibited'],
            'subtotal_minor' => ['prohibited'],
            'total_minor' => ['prohibited'],
        ]);

        return ['data' => $this->orderData($service->updatePayment($id, $data['status'], $data['payment_method'] ?? null))];
    }

    private function orderData(Order $order): array
    {
        return [
            ...$order->only(['id', 'number', 'customer_name', 'status', 'payment_method', 'subtotal_minor', 'total_minor', 'staff_id', 'staff_name', 'commission_bps', 'commission_minor', 'branch_id']),
            'branch_name' => $order->branch?->name,
            'created_at' => $order->created_at->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'items' => $order->items->map(fn ($item) => $item->only(['id', 'catalog_item_id', 'name', 'type', 'quantity', 'unit_price_minor', 'total_minor'])),
        ];
    }
}
