<?php

namespace App\Modules\Platform\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Modules\Platform\Subscriptions\Http\Requests\ChangeSubscriptionRequest;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionManagerInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformSubscriptionController extends Controller
{
    public function show(SubscriptionManagerInterface $service, string $id): array
    {
        $tenant = Tenant::findOrFail($id);
        $result = $this->data($tenant, $service);
        $result['history'] = DB::connection('central')->table('subscription_changes')
            ->leftJoin('users', 'subscription_changes.actor_id', '=', 'users.id')
            ->where('subscription_changes.tenant_id', $tenant->id)
            ->select('subscription_changes.*', 'users.name as actor_name', 'users.email as actor_email')
            ->orderByDesc('subscription_changes.created_at')
            ->limit(20)
            ->get();

        return $result;
    }

    public function extendTrial(Request $request, SubscriptionManagerInterface $service, string $id): array
    {
        $days = (int) ($request->input('days', 30));
        $subscription = $service->extendTrial(Tenant::findOrFail($id), $days, $request->user()->id);

        return ['data' => $service->summary($subscription)];
    }

    public function update(ChangeSubscriptionRequest $request, SubscriptionManagerInterface $service, string $id): array
    {
        $subscription = $service->change(Tenant::findOrFail($id), $request->validated(), $request->user()->id);

        return ['data' => $service->summary($subscription)];
    }

    private function data(Tenant $tenant, SubscriptionManagerInterface $service): array
    {
        return [
            'data' => $service->summary($tenant->subscription),
            'plans' => SubscriptionPlan::where('active', true)->orderBy('price_minor')->get(),
        ];
    }
}
