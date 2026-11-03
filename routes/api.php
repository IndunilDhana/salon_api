<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeductionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PlatformAccessController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\BusinessSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::middleware('platform_admin')->prefix('admin')->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard'])->middleware('platform_permission:platform.dashboard.view');
            Route::get('reports', [AdminController::class, 'reports'])->middleware('platform_permission:platform.reports.view');
            Route::patch('tenants/{id}', [AdminController::class, 'updateTenant'])->middleware('platform_permission:platform.tenants.manage');
            Route::get('plans', [SubscriptionPlanController::class, 'index'])->middleware('platform_permission:platform.plans.view');
            Route::post('plans', [SubscriptionPlanController::class, 'store'])->middleware('platform_permission:platform.plans.manage');
            Route::patch('plans/{id}', [SubscriptionPlanController::class, 'update'])->middleware('platform_permission:platform.plans.manage');
            Route::get('tenants/{id}/subscription', [BusinessSubscriptionController::class, 'adminShow'])->middleware('platform_permission:platform.subscriptions.manage');
            Route::patch('tenants/{id}/subscription', [BusinessSubscriptionController::class, 'update'])->middleware('platform_permission:platform.subscriptions.manage');
            Route::post('tenants/{id}/subscription/trial', [BusinessSubscriptionController::class, 'extendTrial'])->middleware('platform_permission:platform.subscriptions.manage');
            Route::get('access', [PlatformAccessController::class, 'index'])->middleware('platform_permission:platform.access.manage');
            Route::post('access/roles', [PlatformAccessController::class, 'createRole'])->middleware('platform_permission:platform.access.manage');
            Route::patch('access/roles/{id}', [PlatformAccessController::class, 'updateRole'])->middleware('platform_permission:platform.access.manage');
            Route::delete('access/roles/{id}', [PlatformAccessController::class, 'deleteRole'])->middleware('platform_permission:platform.access.manage');
            Route::patch('users/{id}/role', [PlatformAccessController::class, 'assignUserRole'])->middleware('platform_permission:platform.access.manage');
        });
        Route::middleware('tenant')->group(function () {
            Route::get('branches', [BranchController::class, 'index'])->middleware(['permission:branches.view|dashboard.view', 'subscription']);
            Route::post('branches', [BranchController::class, 'store'])->middleware(['permission:branches.manage|access.manage', 'subscription']);
            Route::patch('branches/{id}', [BranchController::class, 'update'])->middleware(['permission:branches.manage|access.manage', 'subscription']);
            Route::delete('branches/{id}', [BranchController::class, 'destroy'])->middleware(['permission:branches.manage|access.manage', 'subscription']);
            Route::get('subscription', [BusinessSubscriptionController::class, 'show']);
            Route::get('catalog', [CatalogController::class, 'index'])->middleware(['permission:catalog.view', 'subscription:catalog']);
            Route::post('catalog', [CatalogController::class, 'store'])->middleware(['permission:catalog.manage', 'subscription:catalog']);
            Route::patch('catalog/{id}', [CatalogController::class, 'update'])->middleware(['permission:catalog.manage', 'subscription:catalog']);
            Route::get('orders', [OrderController::class, 'index'])->middleware(['permission:orders.view', 'subscription:orders']);
            Route::get('order-options', [OrderController::class, 'options'])->middleware(['permission:orders.manage', 'subscription:orders']);
            Route::post('orders', [OrderController::class, 'store'])->middleware(['permission:orders.manage', 'subscription:orders']);
            Route::get('orders/{id}', [OrderController::class, 'show'])->middleware(['permission:orders.view', 'subscription:orders']);
            Route::patch('orders/{id}', [OrderController::class, 'update'])->middleware(['permission:orders.manage', 'subscription:orders']);
            Route::get('dashboard', [DashboardController::class, 'dashboard'])->middleware(['permission:dashboard.view', 'subscription']);
            Route::get('reports', [ReportController::class, 'index'])->middleware(['permission:reports.view|dashboard.view', 'subscription']);
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
});
