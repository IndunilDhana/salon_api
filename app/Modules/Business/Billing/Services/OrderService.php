<?php

namespace App\Modules\Business\Billing\Services;

use App\Infrastructure\Tenancy\TenantContext;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public const MAX_TOTAL_MINOR = 1000000000000;

    public function create(array $data, string $userId): Order
    {
        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $status = $data['status'] ?? 'unpaid';
            $method = $data['payment_method'] ?? null;
            $this->validatePayment($status, $method);
            $ids = array_column($data['items'], 'catalog_item_id');
            // Lock and re-read the current tenant's prices instead of trusting the browser.
            $catalog = CatalogItem::whereIn('id', $ids)->where('active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($catalog->count() !== count($ids)) {
                throw ValidationException::withMessages(['items' => ['Choose active services or products from this business.']]);
            }
            $lines = [];
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $source = $catalog->get($item['catalog_item_id']);
                $lineTotal = $source->price_minor * $item['quantity'];
                $subtotal += $lineTotal;
                if ($subtotal > self::MAX_TOTAL_MINOR) {
                    throw ValidationException::withMessages(['items' => ['The bill total exceeds the supported amount.']]);
                }
                $lines[] = ['catalog_item_id' => $source->id, 'name' => $source->name, 'type' => $source->type, 'quantity' => (int) $item['quantity'], 'unit_price_minor' => $source->price_minor, 'total_minor' => $lineTotal];
            }
            $staffId = $data['staff_id'] ?? null;
            $staff = null;

            $canAssignStaff = in_array('orders.assign_staff', app(TenantContext::class)->permissions, true);

            $userStaff = null;
            if ($userId) {
                $user = User::find($userId);
                $tenantId = app(TenantContext::class)->id();
                if ($user) {
                    $userStaff = Staff::resolveForUser($user, $tenantId);
                }
            }

            if (! $canAssignStaff) {
                // If user doesn't have orders.assign_staff permission,
                // the created bill is automatically assigned to himself!
                $staff = $userStaff;
                $staffId = $staff?->id;
            } else {
                if ($staffId) {
                    $staff = Staff::where('active', true)->lockForUpdate()->find($staffId);
                    if (! $staff) {
                        throw ValidationException::withMessages(['staff_id' => ['The selected team member is not available.']]);
                    }
                } else {
                    $staff = $userStaff;
                    $staffId = $staff?->id;
                }
            }

            $commissionBps = $staff ? $staff->commission_bps : 0;
            $commissionMinor = $staff ? intdiv($subtotal * $commissionBps + 5000, 10000) : 0;

            $context = app(TenantContext::class);
            $branchId = $data['branch_id'] ?? $context->branchId;
            if (! $branchId) {
                $mainBranch = DB::connection('tenant')->table('branches')
                    ->where('tenant_id', $context->id())
                    ->where('is_main', true)
                    ->first();
                $branchId = $mainBranch?->id;
            }

            $order = Order::create([
                'number' => 'BILL-'.Str::ulid(),
                'customer_name' => trim($data['customer_name']),
                'currency' => $context->tenant->currency,
                'status' => $status,
                'payment_method' => $method,
                'subtotal_minor' => $subtotal,
                'total_minor' => $subtotal,
                'branch_id' => $branchId,
                'staff_id' => $staff?->id,
                'staff_name' => $staff?->name,
                'commission_bps' => $commissionBps,
                'commission_minor' => $commissionMinor,
                'created_by' => $userId,
                'paid_at' => $status === 'paid' ? now()->utc() : null,
            ]);
            foreach ($lines as $line) {
                OrderItem::create([...$line, 'order_id' => $order->id]);
            }

            return $order->load('items');
        }, 3);
    }

    public function updatePayment(string $orderId, string $status, ?string $method): Order
    {
        return DB::connection('tenant')->transaction(function () use ($orderId, $status, $method) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'paid') {
                // Retried mark-paid requests are harmless; reversals need a separate workflow.
                abort_unless($status === 'paid' && ($method === null || $method === $order->payment_method), 409, 'A paid bill cannot be changed.');

                return $order->load('items');
            }
            $this->validatePayment($status, $method);
            if ($status === 'paid') {
                $order->update(['status' => 'paid', 'payment_method' => $method, 'paid_at' => now()->utc()]);
            }

            return $order->load('items');
        }, 3);
    }

    private function validatePayment(string $status, ?string $method): void
    {
        if ($status === 'paid' && ! in_array($method, ['cash', 'card', 'other'], true)) {
            throw ValidationException::withMessages(['payment_method' => ['Select how this bill was paid.']]);
        }
        if ($status === 'unpaid' && $method !== null) {
            throw ValidationException::withMessages(['payment_method' => ['An unpaid bill cannot have a payment method.']]);
        }
    }
}
