<?php

namespace App\Modules\Shared\Subscriptions\Contracts;

use App\Models\BusinessSubscription;
use App\Models\Tenant;

interface SubscriptionReaderInterface
{
    public function summary(?BusinessSubscription $subscription): ?array;

    public function syncDynamicPricing(Tenant $tenant, ?string $actorId = null): ?BusinessSubscription;

    public function beginTrial(Tenant $tenant): BusinessSubscription;
}
