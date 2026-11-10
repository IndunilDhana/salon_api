<?php

namespace App\Modules\Business\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\SubscriptionRenewal;
use App\Models\User;
use App\Notifications\RenewalSubmittedNotification;
use App\Modules\Business\Subscription\Http\Requests\StoreSubscriptionRenewalRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionRenewalController extends Controller
{
    use AuthorizesRequests;
    public function index(TenantContext $context)
    {
        $tenant = $context->tenant;

        abort_unless($this->isTenantOwner($tenant->id, auth()->id()), 403, 'You do not have access to this business renewal history.');

        $renewals = SubscriptionRenewal::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $renewals->map(fn (SubscriptionRenewal $renewal) => $renewal->toArray())->values()], 200);
    }

    public function store(StoreSubscriptionRenewalRequest $request, TenantContext $context)
    {
        $tenant = $context->tenant;
        $this->authorize('create', [SubscriptionRenewal::class, $tenant]);

        $subscription = $tenant->subscription()->lockForUpdate()->first();
        if (! $subscription) {
            abort(404, 'Subscription not found for this business.');
        }

        if (! in_array($subscription->status, ['grace_period', 'expired', 'pending_renewal', 'active'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Renewal requests can only be submitted while the subscription is active, in grace period, or pending review.'],
            ]);
        }

        if (SubscriptionRenewal::where('tenant_id', $tenant->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'reference' => ['A renewal request is already pending review.'],
            ]);
        }

        $renewal = DB::transaction(function () use ($request, $tenant, $subscription) {
            $proofUrl = $request->validated('proof_url');
            $data = [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'payment_method' => $request->validated('payment_method'),
                'amount_minor' => (int) $request->validated('amount_minor'),
                'reference' => trim((string) $request->validated('reference')),
                'notes' => $request->validated('notes'),
                'type' => $request->validated('type') ?? ($subscription->status === 'active' ? 'early' : 'late'),
                'status' => 'pending',
                'submitted_by_user_id' => $request->user()->id,
            ];

            if ($proofUrl) {
                $data['proof_url'] = $proofUrl;
            }

            $renewal = SubscriptionRenewal::create($data);

            $subscription->update(['status' => 'pending_renewal']);

            return $renewal->fresh();
        });

        $this->notifyOwners($tenant, $renewal);
        $this->notifyAdmins($renewal);

        return response()->json([
            'data' => $renewal->toArray(),
            'message' => 'Your renewal request has been submitted successfully.',
        ], 201);
    }

    private function notifyOwners($tenant, SubscriptionRenewal $renewal): void
    {
        $ownerIds = DB::connection('central')
            ->table('memberships')
            ->join('tenant_roles', function ($join) {
                $join->on('memberships.tenant_id', '=', 'tenant_roles.tenant_id')
                    ->on('memberships.role_id', '=', 'tenant_roles.id');
            })
            ->where('memberships.tenant_id', $tenant->id)
            ->where('tenant_roles.is_owner', true)
            ->pluck('memberships.user_id');

        User::whereIn('id', $ownerIds)->get()->each(fn (User $user) => $user->notify(new RenewalSubmittedNotification($renewal)));
    }

    private function notifyAdmins(SubscriptionRenewal $renewal): void
    {
        User::where('is_platform_admin', true)->get()->each(function (User $admin) use ($renewal) {
            $admin->notify(new RenewalSubmittedNotification($renewal));
        });
    }

    private function isTenantOwner(string $tenantId, string $userId): bool
    {
        return DB::connection('central')
            ->table('memberships')
            ->join('tenant_roles', function ($join) {
                $join->on('memberships.tenant_id', '=', 'tenant_roles.tenant_id')
                    ->on('memberships.role_id', '=', 'tenant_roles.id');
            })
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.user_id', $userId)
            ->where('tenant_roles.is_owner', true)
            ->exists();
    }
}
