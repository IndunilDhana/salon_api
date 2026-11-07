<?php

namespace App\Domain\Subscriptions;

if (! class_exists(SubscriptionService::class, false)) {
    class_alias(\App\Modules\Platform\Subscriptions\Services\SubscriptionService::class, SubscriptionService::class);
}
