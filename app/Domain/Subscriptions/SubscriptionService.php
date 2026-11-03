<?php

namespace App\Domain\Subscriptions;

use App\Models\BusinessSubscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public const FEATURES = [
        'staff' => 'Team management',
        'appointments' => 'Appointments',
        'catalog' => 'Services and products',
        'orders' => 'Customer billing',
        'payroll' => 'Payroll drafts',
        'branches' => 'Multiple branches',
    ];

    public function beginTrial(Tenant $tenant): BusinessSubscription
    {
        return DB::connection('central')->transaction(function () use ($tenant) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($existing = BusinessSubscription::where('tenant_id', $tenant->id)->first()) {
                return $existing;
            }
            $start = CarbonImmutable::now('UTC');
            $end = $start->setTimezone($tenant->timezone)->addMonthNoOverflow()->utc();
            $subscription = BusinessSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => null,
                'status' => 'trialing',
                'plan_name' => 'Free trial',
                'price_minor' => 0,
                'base_price_minor' => 0,
                'included_branches' => 1,
                'price_per_additional_branch_minor' => 0,
                'active_branches_count' => 1,
                'additional_branch_fee_minor' => 0,
                'currency' => $tenant->currency,
                'billing_interval' => 'month',
                'features' => array_keys(self::FEATURES),
                'trial_starts_at' => $start,
                'trial_ends_at' => $end,
                'current_period_start' => $start,
                'current_period_end' => $end,
            ]);
            $this->record($subscription, 'trial_granted');

            return $subscription;
        }, 3);
    }

    public function summary(?BusinessSubscription $subscription): ?array
    {
        if (!$subscription) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        $status = $subscription->status === 'cancelled' ? 'cancelled' : ($subscription->current_period_end->lessThanOrEqualTo($now) ? 'expired' : $subscription->status);
        $allowed = in_array($status, ['trialing', 'active'], true) && $subscription->current_period_start->lessThanOrEqualTo($now);

        return [
            ...$subscription->only(['id', 'tenant_id', 'plan_id', 'plan_name', 'price_minor', 'currency', 'billing_interval', 'features']),
            'included_branches' => $subscription->included_branches ?? 1,
            'price_per_additional_branch_minor' => $subscription->price_per_additional_branch_minor ?? 0,
            'active_branches_count' => $subscription->active_branches_count ?? 1,
            'base_price_minor' => $subscription->base_price_minor ?? $subscription->price_minor,
            'additional_branch_fee_minor' => $subscription->additional_branch_fee_minor ?? 0,
            'total_price_minor' => $subscription->price_minor,
            'status' => $status,
            'trial_starts_at' => $subscription->trial_starts_at->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at->toISOString(),
            'current_period_start' => $subscription->current_period_start->toISOString(),
            'current_period_end' => $subscription->current_period_end->toISOString(),
            'cancelled_at' => $subscription->cancelled_at?->toISOString(),
            'access_allowed' => $allowed,
            'days_remaining' => $allowed ? (int) ceil($now->diffInSeconds($subscription->current_period_end) / 86400) : 0,
        ];
    }

    public function syncDynamicPricing(Tenant $tenant, ?string $actorId = null): ?BusinessSubscription
    {
        $activeBranchesCount = (int) (DB::connection('tenant')->table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->count() ?: 1);

        return DB::connection('central')->transaction(function () use ($tenant, $actorId, $activeBranchesCount) {
            $subscription = BusinessSubscription::where('tenant_id', $tenant->id)->lockForUpdate()->first();
            if (!$subscription) {
                return null;
            }

            $included = (int) ($subscription->included_branches ?? 1);
            $pricePerExtra = (int) ($subscription->price_per_additional_branch_minor ?? 0);
            $extra = max(0, $activeBranchesCount - $included);
            $additionalFee = $extra * $pricePerExtra;
            $basePrice = (int) ($subscription->base_price_minor ?: $subscription->price_minor);
            $totalPrice = $basePrice + $additionalFee;

            if (
                $subscription->active_branches_count !== $activeBranchesCount ||
                $subscription->additional_branch_fee_minor !== $additionalFee ||
                $subscription->price_minor !== $totalPrice
            ) {
                $subscription->update([
                    'active_branches_count' => $activeBranchesCount,
                    'additional_branch_fee_minor' => $additionalFee,
                    'price_minor' => $totalPrice,
                ]);
                $this->record($subscription, 'dynamic_price_updated', $actorId);
            }

            return $subscription;
        }, 3);
    }

    public function change(Tenant $tenant, array $data, string $actorId): BusinessSubscription
    {
        $activeBranchesCount = (int) (DB::connection('tenant')->table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->count() ?: 1);

        return DB::connection('central')->transaction(function () use ($tenant, $data, $actorId, $activeBranchesCount) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = BusinessSubscription::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'cancelled') {
                if ($subscription->status !== 'cancelled') {
                    $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()->utc()]);
                    $this->record($subscription, 'cancelled', $actorId);
                }
                return $subscription;
            }
            $plan = SubscriptionPlan::lockForUpdate()->find($data['plan_id']);
            if (!$plan || !$plan->active) {
                throw ValidationException::withMessages(['plan_id' => ['Choose an active subscription plan.']]);
            }
            $end = CarbonImmutable::parse($data['current_period_end'])->utc();
            if ($end->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['current_period_end' => ['The subscription period must end in the future.']]);
            }

            $included = (int) ($plan->included_branches ?? 1);
            $pricePerExtra = (int) ($plan->price_per_additional_branch_minor ?? 0);
            $extra = max(0, $activeBranchesCount - $included);
            $additionalFee = $extra * $pricePerExtra;
            $totalPrice = (int) $plan->price_minor + $additionalFee;

            $subscription->update([
                'plan_id' => $plan->id,
                'status' => 'active',
                'plan_name' => $plan->name,
                'base_price_minor' => $plan->price_minor,
                'included_branches' => $included,
                'price_per_additional_branch_minor' => $pricePerExtra,
                'active_branches_count' => $activeBranchesCount,
                'additional_branch_fee_minor' => $additionalFee,
                'price_minor' => $totalPrice,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval,
                'features' => $plan->features,
                'current_period_start' => now()->utc(),
                'current_period_end' => $end,
                'cancelled_at' => null,
            ]);
            $this->record($subscription, 'plan_assigned', $actorId);

            return $subscription;
        }, 3);
    }

    public function extendTrial(Tenant $tenant, int $days, string $actorId): BusinessSubscription
    {
        return DB::connection('central')->transaction(function () use ($tenant, $days, $actorId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = BusinessSubscription::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();

            $baseDate = $subscription->current_period_end->greaterThan(now('UTC'))
                ? CarbonImmutable::instance($subscription->current_period_end)
                : CarbonImmutable::now('UTC');

            $newEnd = $baseDate->addDays($days);

            $subscription->update([
                'status' => 'trialing',
                'trial_ends_at' => $newEnd,
                'current_period_end' => $newEnd,
                'cancelled_at' => null,
            ]);

            $this->record($subscription, 'trial_extended', $actorId);

            return $subscription;
        }, 3);
    }

    private function record(BusinessSubscription $subscription, string $action, ?string $actorId = null): void
    {
        DB::connection('central')->table('subscription_changes')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'actor_id' => $actorId,
            'action' => $action,
            'snapshot' => json_encode($subscription->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now()->utc(),
        ]);
    }
}
