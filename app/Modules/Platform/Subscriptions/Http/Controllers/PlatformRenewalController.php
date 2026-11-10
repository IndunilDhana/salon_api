<?php

namespace App\Modules\Platform\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BusinessSubscription;
use App\Models\SubscriptionRenewal;
use App\Models\User;
use App\Notifications\RenewalApprovedNotification;
use App\Notifications\RenewalRejectedNotification;
use App\Modules\Platform\Subscriptions\Http\Requests\ReviewSubscriptionRenewalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformRenewalController extends Controller
{
    use AuthorizesRequests;
    public function index()
    {
        $this->authorize('viewAny', SubscriptionRenewal::class);

        $renewals = SubscriptionRenewal::query()
            ->with(['tenant', 'subscription'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $renewals->map(fn (SubscriptionRenewal $renewal) => $renewal->toArray())->values()], 200);
    }

    public function update(ReviewSubscriptionRenewalRequest $request, string $id)
    {
        $renewal = SubscriptionRenewal::query()->whereKey($id)->firstOrFail();
        $this->authorize('update', $renewal);

        $status = $request->validated('status');
        if ($renewal->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This renewal request is no longer pending review.'],
            ]);
        }

        return response()->json([
            'data' => DB::transaction(function () use ($request, $renewal, $status) {
                $renewal->update([
                    'status' => $status,
                    'review_notes' => $request->validated('review_notes'),
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now()->utc(),
                ]);

                if ($status === 'approved') {
                    $this->approve($renewal);
                }

                if ($status === 'rejected') {
                    $this->reject($renewal);
                }

                return $renewal->fresh()->toArray();
            }),
            'message' => $status === 'approved' ? 'The renewal request was approved.' : 'The renewal request was rejected.',
        ]);
    }

    private function approve(SubscriptionRenewal $renewal): void
    {
        $subscription = $renewal->subscription()->lockForUpdate()->firstOrFail();
        $now = CarbonImmutable::now('UTC');

        $intervalMonths = ($subscription->billing_interval === 'year') ? 12 : 1;
        $baseDate = ($renewal->type === 'early' && $subscription->current_period_end?->greaterThan($now))
            ? CarbonImmutable::instance($subscription->current_period_end)
            : $now;

        $newEnd = $baseDate->addMonthsNoOverflow($intervalMonths);
        $graceEnd = $newEnd->addDays(7);

        $subscription->update([
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $newEnd,
            'grace_period_ends_at' => $graceEnd,
            'access_allowed' => true,
        ]);

        $renewal->subscription()->update(['status' => 'active']);

        $ownerIds = DB::connection('central')
            ->table('memberships')
            ->join('tenant_roles', function ($join) {
                $join->on('memberships.tenant_id', '=', 'tenant_roles.tenant_id')
                    ->on('memberships.role_id', '=', 'tenant_roles.id');
            })
            ->where('memberships.tenant_id', $renewal->tenant_id)
            ->where('tenant_roles.is_owner', true)
            ->pluck('memberships.user_id');

        User::whereIn('id', $ownerIds)->get()->each(fn (User $user) => $user->notify(new RenewalApprovedNotification($renewal->fresh())));
    }

    private function reject(SubscriptionRenewal $renewal): void
    {
        $now = CarbonImmutable::now('UTC');
        $subscription = $renewal->subscription;
        if ($subscription) {
            $isGrace = $subscription->grace_period_ends_at && $now->lessThanOrEqualTo($subscription->grace_period_ends_at);
            $subscription->update(['status' => $isGrace ? 'grace_period' : 'expired']);
        }

        $ownerIds = DB::connection('central')
            ->table('memberships')
            ->join('tenant_roles', function ($join) {
                $join->on('memberships.tenant_id', '=', 'tenant_roles.tenant_id')
                    ->on('memberships.role_id', '=', 'tenant_roles.id');
            })
            ->where('memberships.tenant_id', $renewal->tenant_id)
            ->where('tenant_roles.is_owner', true)
            ->pluck('memberships.user_id');

        User::whereIn('id', $ownerIds)->get()->each(fn (User $user) => $user->notify(new RenewalRejectedNotification($renewal->fresh())));
    }
}
