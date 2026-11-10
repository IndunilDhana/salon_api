<?php

namespace App\Http\Requests;

if (! class_exists(ChangeSubscriptionRequest::class, false)) {
    class_alias(\App\Modules\Platform\Subscriptions\Http\Requests\ChangeSubscriptionRequest::class, ChangeSubscriptionRequest::class);
}
