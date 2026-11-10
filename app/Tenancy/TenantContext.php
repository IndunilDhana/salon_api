<?php

namespace App\Tenancy;

if (! class_exists(TenantContext::class, false)) {
    class_alias(\App\Infrastructure\Tenancy\TenantContext::class, TenantContext::class);
}
