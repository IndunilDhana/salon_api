<?php

namespace App\Domain\Orders;

if (! class_exists(OrderService::class, false)) {
    class_alias(\App\Modules\Business\Billing\Services\OrderService::class, OrderService::class);
}
