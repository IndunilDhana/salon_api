<?php

namespace App\Http\Requests;

if (! class_exists(GeneratePayrollRequest::class, false)) {
    class_alias(\App\Modules\Business\Payroll\Http\Requests\GeneratePayrollRequest::class, GeneratePayrollRequest::class);
}
