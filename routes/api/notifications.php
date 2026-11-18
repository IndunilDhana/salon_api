<?php

use App\Http\Controllers\NotificationStoreController;
use Illuminate\Support\Facades\Route;

// Server-to-server storage: the controller verifies its own shared secret.
Route::match(['GET', 'POST', 'DELETE'], 'internal/notification-store/{collection}', NotificationStoreController::class)
    ->whereIn('collection', ['devices', 'broadcasts', 'subscriptions']);
