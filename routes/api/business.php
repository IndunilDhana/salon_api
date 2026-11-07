<?php

use App\Modules\Business\Access\Http\Controllers\AccessController;
use App\Modules\Business\Appointments\Http\Controllers\AppointmentController;
use App\Modules\Business\Billing\Http\Controllers\OrderController;
use App\Modules\Business\Branches\Http\Controllers\BranchController;
use App\Modules\Business\Catalog\Http\Controllers\CatalogController;
use App\Modules\Business\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Business\Deductions\Http\Controllers\DeductionController;
use App\Modules\Business\Payroll\Http\Controllers\PayrollController;
use App\Modules\Business\Reports\Http\Controllers\ReportController;
use App\Modules\Business\Subscription\Http\Controllers\BusinessSubscriptionController;
use App\Modules\Business\Team\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->group(function () {
    Route::get('branches', [BranchController::class, 'index'])->middleware(['permission:branches.view|dashboard.view', 'subscription']);
    Route::post('branches', [BranchController::class, 'store'])->middleware(['permission:branches.manage', 'subscription']);
    Route::patch('branches/{id}', [BranchController::class, 'update'])->middleware(['permission:branches.manage', 'subscription:branches']);
    Route::delete('branches/{id}', [BranchController::class, 'destroy'])->middleware(['permission:branches.manage', 'subscription:branches']);
    Route::get('subscription', [BusinessSubscriptionController::class, 'show']);
    Route::middleware('branch_setup')->group(function () {
        Route::get('catalog', [CatalogController::class, 'index'])->middleware(['permission:catalog.view', 'subscription:catalog']);
        Route::post('catalog', [CatalogController::class, 'store'])->middleware(['permission:catalog.manage', 'subscription:catalog']);
        Route::patch('catalog/{id}', [CatalogController::class, 'update'])->middleware(['permission:catalog.manage', 'subscription:catalog']);
        Route::get('orders', [OrderController::class, 'index'])->middleware(['permission:orders.view', 'subscription:orders']);
        Route::get('order-options', [OrderController::class, 'options'])->middleware(['permission:orders.manage', 'subscription:orders']);
        Route::post('orders', [OrderController::class, 'store'])->middleware(['permission:orders.manage', 'subscription:orders']);
        Route::get('orders/{id}', [OrderController::class, 'show'])->middleware(['permission:orders.view', 'subscription:orders']);
        Route::patch('orders/{id}', [OrderController::class, 'update'])->middleware(['permission:orders.manage', 'subscription:orders']);
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->middleware(['permission:dashboard.view', 'subscription']);
        Route::get('reports', [ReportController::class, 'index'])->middleware(['permission:reports.view', 'subscription']);
        Route::get('staff', [StaffController::class, 'staff'])->middleware(['permission:staff.view', 'subscription:staff']);
        Route::post('staff', [StaffController::class, 'createStaff'])->middleware(['permission:staff.manage', 'subscription:staff']);
        Route::patch('staff/{id}', [StaffController::class, 'updateStaff'])->middleware(['permission:staff.manage', 'subscription:staff']);
        Route::get('appointments', [AppointmentController::class, 'appointments'])->middleware(['permission:appointments.view', 'subscription:appointments']);
        Route::get('appointment-options', [AppointmentController::class, 'options'])->middleware(['permission:appointments.manage', 'subscription:appointments']);
        Route::post('appointments', [AppointmentController::class, 'createAppointment'])->middleware(['permission:appointments.manage', 'subscription:appointments']);
        Route::patch('appointments/{id}', [AppointmentController::class, 'updateAppointment'])->middleware(['permission:appointments.manage', 'subscription:appointments']);
        Route::get('payroll', [PayrollController::class, 'index'])->middleware(['permission:payroll.view', 'subscription:payroll']);
        Route::post('payroll', [PayrollController::class, 'store'])->middleware(['permission:payroll.manage', 'subscription:payroll']);
        Route::get('deduction-options', [DeductionController::class, 'options'])->middleware(['permission:deductions.view|deductions.manage|payroll.view', 'subscription']);
        Route::get('deductions', [DeductionController::class, 'index'])->middleware(['permission:deductions.view|deductions.manage|payroll.view', 'subscription']);
        Route::post('deductions', [DeductionController::class, 'store'])->middleware(['permission:deductions.manage|payroll.manage', 'subscription']);
        Route::delete('deductions/{id}', [DeductionController::class, 'destroy'])->middleware(['permission:deductions.manage|payroll.manage', 'subscription']);
        Route::middleware(['permission:access.manage', 'subscription'])->prefix('access')->group(function () {
            Route::get('/', [AccessController::class, 'index']);
            Route::post('roles', [AccessController::class, 'createRole']);
            Route::patch('roles/{id}', [AccessController::class, 'updateRole']);
            Route::delete('roles/{id}', [AccessController::class, 'deleteRole']);
            Route::post('members', [AccessController::class, 'createMember']);
            Route::patch('members/{id}', [AccessController::class, 'updateMember']);
            Route::delete('members/{id}', [AccessController::class, 'deleteMember']);
        });
    });
});
