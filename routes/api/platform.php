<?php

use App\Modules\Platform\Access\Http\Controllers\PlatformAccessController;
use App\Modules\Platform\Businesses\Http\Controllers\PlatformBusinessController;
use App\Modules\Platform\Dashboard\Http\Controllers\PlatformDashboardController;
use App\Modules\Platform\Plans\Http\Controllers\PlanController;
use App\Modules\Platform\Reports\Http\Controllers\PlatformReportController;
use App\Modules\Platform\Subscriptions\Http\Controllers\PlatformRenewalController;
use App\Modules\Platform\Subscriptions\Http\Controllers\PlatformSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform_admin')->prefix('admin')->group(function () {
    Route::get('dashboard', [PlatformDashboardController::class, 'dashboard'])->middleware('platform_permission:platform.dashboard.view|platform.tenants.view|platform.subscriptions.manage|platform.users.view');
    Route::get('reports', [PlatformReportController::class, 'reports'])->middleware('platform_permission:platform.reports.view');
    Route::patch('tenants/{id}', [PlatformBusinessController::class, 'updateTenant'])->middleware('platform_permission:platform.tenants.manage');
    Route::get('plans', [PlanController::class, 'index'])->middleware('platform_permission:platform.plans.view');
    Route::post('plans', [PlanController::class, 'store'])->middleware('platform_permission:platform.plans.manage');
    Route::patch('plans/{id}', [PlanController::class, 'update'])->middleware('platform_permission:platform.plans.manage');
    Route::get('tenants/{id}/subscription', [PlatformSubscriptionController::class, 'show'])->middleware('platform_permission:platform.subscriptions.manage');
    Route::patch('tenants/{id}/subscription', [PlatformSubscriptionController::class, 'update'])->middleware('platform_permission:platform.subscriptions.manage');
    Route::post('tenants/{id}/subscription/trial', [PlatformSubscriptionController::class, 'extendTrial'])->middleware('platform_permission:platform.subscriptions.manage');
    Route::get('renewals', [PlatformRenewalController::class, 'index'])->middleware('platform_permission:platform.subscriptions.manage');
    Route::patch('renewals/{id}', [PlatformRenewalController::class, 'update'])->middleware('platform_permission:platform.subscriptions.manage');
    Route::get('access', [PlatformAccessController::class, 'index'])->middleware('platform_permission:platform.access.manage');
    Route::post('access/roles', [PlatformAccessController::class, 'createRole'])->middleware('platform_permission:platform.access.manage');
    Route::patch('access/roles/{id}', [PlatformAccessController::class, 'updateRole'])->middleware('platform_permission:platform.access.manage');
    Route::delete('access/roles/{id}', [PlatformAccessController::class, 'deleteRole'])->middleware('platform_permission:platform.access.manage');
    Route::patch('users/{id}/role', [PlatformAccessController::class, 'assignUserRole'])->middleware('platform_permission:platform.access.manage');
});
