<?php

namespace App\Domain\Payroll;

if (! class_exists(PayrollService::class, false)) {
    class_alias(\App\Modules\Business\Payroll\Services\PayrollService::class, PayrollService::class);
}
