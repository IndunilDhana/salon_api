<?php

namespace App\Modules\Business\Branches\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\Staff;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request, SubscriptionReaderInterface $subscriptionService): array
    {
        $context = app(TenantContext::class);
        $query = Branch::query();

        if ($context->allowedBranchIds !== null) {
            $query->whereIn('id', $context->allowedBranchIds);
        }

        $branches = $query->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $branchIds = $branches->pluck('id')->all();

        $staffCounts = DB::connection('tenant')->table('staff')
            ->where('tenant_id', $context->id())
            ->where('active', true)
            ->whereIn('branch_id', $branchIds)
            ->groupBy('branch_id')
            ->select('branch_id', DB::raw('count(*) as count'))
            ->pluck('count', 'branch_id');

        $orderCounts = DB::connection('tenant')->table('orders')
            ->where('tenant_id', $context->id())
            ->whereIn('branch_id', $branchIds)
            ->groupBy('branch_id')
            ->select('branch_id', DB::raw('count(*) as count'))
            ->pluck('count', 'branch_id');

        $data = $branches->map(function (Branch $branch) use ($staffCounts, $orderCounts) {
            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_main' => (bool) $branch->is_main,
                'active' => (bool) $branch->active,
                'staff_count' => (int) ($staffCounts[$branch->id] ?? 0),
                'orders_count' => (int) ($orderCounts[$branch->id] ?? 0),
                'created_at' => $branch->created_at?->toISOString(),
            ];
        });

        $subscriptionSummary = $subscriptionService->summary($context->tenant->subscription);

        return [
            'data' => $data,
            'active_branch_id' => $context->branchId,
            'has_all_access' => $context->hasAllBranchesAccess,
            'subscription' => $subscriptionSummary,
        ];
    }

    public function store(Request $request, SubscriptionReaderInterface $subscriptionService): JsonResponse
    {
        $context = app(TenantContext::class);
        $tenantId = $context->id();
        $owner = $request->user();
        if ($request->exists('phone')) {
            $phone = trim((string) $request->input('phone'));
            $request->merge(['phone' => $phone === '' ? null : $phone]);
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenant.branches', 'name')->where('tenant_id', $tenantId),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:40', Rule::unique('tenant.branches', 'phone')],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        try {
            $branch = Cache::lock('branches:create:'.$tenantId, 30)->block(5, function () use ($data, $context, $tenantId, $subscriptionService, $owner) {
                return DB::connection('tenant')->transaction(function () use ($data, $context, $tenantId, $subscriptionService, $owner) {
                    $isFirst = ! DB::connection('tenant')->table('branches')->where('tenant_id', $tenantId)->exists();
                    if ($isFirst) {
                        abort_unless($context->hasAllBranchesAccess, 403, 'First branch setup requires access to the whole business.');
                        abort_if(($data['active'] ?? true) === false, 422, 'Your first branch must be active.');
                    } else {
                        $subscription = $subscriptionService->summary($context->tenant->subscription);
                        abort_unless(in_array('branches', $subscription['features'] ?? [], true), 403, 'Multiple branches are not included in this business subscription.');
                    }

                    $branch = Branch::create([
                        'name' => trim($data['name']),
                        'code' => isset($data['code']) ? strtoupper(trim($data['code'])) : null,
                        'phone' => isset($data['phone']) ? trim($data['phone']) : null,
                        'address' => isset($data['address']) ? trim($data['address']) : null,
                        'is_main' => $isFirst,
                        'active' => $data['active'] ?? true,
                    ]);

                    if ($isFirst) {
                        Staff::create([
                            'user_id' => $owner->id,
                            'name' => $owner->name,
                            'email' => $owner->email,
                            'phone' => $owner->phone,
                            'job_title' => 'Owner',
                            'base_salary_minor' => 0,
                            'commission_bps' => 0,
                            'active' => true,
                            'branch_id' => $branch->id,
                        ]);
                    }

                    return $branch;
                }, 3);
            });
        } catch (LockTimeoutException) {
            abort(409, 'Another branch is being created. Please try again.');
        }

        // Sync dynamic subscription pricing
        $subscriptionService->syncDynamicPricing($context->tenant, $request->user()->id);
        $updatedSubscription = $subscriptionService->summary($context->tenant->fresh()->subscription);

        return response()->json([
            'data' => $branch,
            'subscription' => $updatedSubscription,
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function update(Request $request, string $id, SubscriptionReaderInterface $subscriptionService): JsonResponse
    {
        $context = app(TenantContext::class);
        $branch = Branch::findOrFail($id);
        if ($request->exists('phone')) {
            $phone = trim((string) $request->input('phone'));
            $request->merge(['phone' => $phone === '' ? null : $phone]);
        }

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('tenant.branches', 'name')->where('tenant_id', $context->id())->ignore($id),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:40', Rule::unique('tenant.branches', 'phone')->ignore($id)],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['active']) && ! $data['active'] && $branch->is_main) {
            abort(422, 'The main branch cannot be deactivated.');
        }

        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $branch->update($data);

        // Sync dynamic subscription pricing if active status changed
        $subscriptionService->syncDynamicPricing($context->tenant, $request->user()->id);
        $updatedSubscription = $subscriptionService->summary($context->tenant->fresh()->subscription);

        return response()->json([
            'data' => $branch,
            'subscription' => $updatedSubscription,
            'message' => 'Branch updated successfully.',
        ]);
    }

    public function destroy(Request $request, string $id, SubscriptionReaderInterface $subscriptionService): JsonResponse
    {
        $context = app(TenantContext::class);
        $branch = Branch::findOrFail($id);

        if ($branch->is_main) {
            abort(422, 'The main branch cannot be deleted.');
        }

        // Retain staff, expense, membership and historical references to this branch.
        $branch->update(['active' => false]);

        $subscriptionService->syncDynamicPricing($context->tenant, $request->user()->id);
        $updatedSubscription = $subscriptionService->summary($context->tenant->fresh()->subscription);

        return response()->json([
            'message' => 'Branch successfully deactivated.',
            'subscription' => $updatedSubscription,
        ]);
    }
}
