<?php

namespace App\Modules\Shared\Subscriptions\Contracts;

use App\Models\BusinessSubscription;
use App\Models\Tenant;

interface SubscriptionManagerInterface extends SubscriptionReaderInterface
{
    public function change(Tenant $tenant, array $data, string $actorId): BusinessSubscription;

    public function extendTrial(Tenant $tenant, int $days, string $actorId): BusinessSubscription;
}
