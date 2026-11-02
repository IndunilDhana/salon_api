<?php

namespace App\Http\Controllers;

use App\Domain\Payroll\PayrollService;
use App\Http\Requests\GeneratePayrollRequest;
use App\Models\PayrollRun;

class PayrollController extends Controller
{
    public function index(): array
    {
        return ['data' => PayrollRun::with('items')->orderByDesc('period_start')->get()];
    }

    public function store(GeneratePayrollRequest $request, PayrollService $payroll)
    {
        return response()->json(['data' => $payroll->generate($request->user(), $request->validated())], 201);
    }
}
