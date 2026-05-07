<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingsController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/charts', [DashboardController::class, 'charts']);
    Route::post('imports/csv', [ImportController::class, 'uploadCsv']);
    Route::get('imports/{import}/status', [ImportController::class, 'status']);
    Route::post('imports/{import}/map', [ImportController::class, 'map']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{report}/status', [ReportController::class, 'status']);
    Route::get('reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::get('settings', [SettingsController::class, 'show']);
    Route::patch('settings', [SettingsController::class, 'update']);
    Route::prefix('notifications')->controller(NotificationController::class)
        ->name('notifications.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::patch('read-all', 'markAllAsRead')->name('read-all');
            Route::patch('{id}/read', 'markAsRead')->name('read');
        });
    Route::post('accounts/{account}/reconcile', [AccountController::class, 'reconcile'])
        ->name('accounts.reconcile');

    Route::apiResource('accounts',     AccountController::class);
    Route::apiResource('categories', CategoryController::class)
        ->except(['show']);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('budgets', BudgetController::class);
});
