<?php

namespace App\Modules\Business\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\PayrollRun;
use App\Modules\Business\Payroll\Http\Requests\GeneratePayrollRequest;
use App\Modules\Business\Payroll\Services\PayrollService;

class PayrollController extends Controller
{
    public function index(): array
    {
        abort_unless(app(TenantContext::class)->hasAllBranchesAccess, 403, 'Business-wide payroll requires access to all branches.');

        return ['data' => PayrollRun::with('items')->orderByDesc('period_start')->get()];
    }

    public function store(GeneratePayrollRequest $request, PayrollService $payroll)
    {
        return response()->json(['data' => $payroll->generate($request->user(), $request->validated())], 201);
    }
}
