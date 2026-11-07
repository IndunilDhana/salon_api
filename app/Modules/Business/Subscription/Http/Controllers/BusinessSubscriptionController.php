<?php

namespace App\Modules\Business\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Modules\Shared\Subscriptions\Contracts\SubscriptionReaderInterface;

class BusinessSubscriptionController extends Controller
{
    public function show(SubscriptionReaderInterface $service): array
    {
        return $this->data(app(TenantContext::class)->tenant, $service);
    }

    private function data(Tenant $tenant, SubscriptionReaderInterface $service): array
    {
        return [
            'data' => $service->summary($tenant->subscription),
            'plans' => SubscriptionPlan::where('active', true)->orderBy('price_minor')->get(),
        ];
    }
}
