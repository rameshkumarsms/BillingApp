<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// Hot path: rate-limit first (cheap, keyed by API key) then authenticate.
Route::post('/usage', [UsageController::class, 'store'])->middleware(['throttle:usage', 'merchant.auth']);

Route::middleware('merchant.auth')->group(function () {
    Route::get('/merchants/{id}/dashboard', [DashboardController::class, 'show'])->whereNumber('id');
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan');
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::post('/subscriptions/{subscription}/change-plan', [SubscriptionController::class, 'changePlan'])->whereNumber('subscription');
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
