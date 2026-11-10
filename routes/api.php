<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api/auth.php';

    Route::middleware('auth:sanctum')->group(function () {
        require __DIR__.'/api/platform.php';
        require __DIR__.'/api/business.php';
    });
});
