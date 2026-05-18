<?php

use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\AuditRunController;
use App\Http\Controllers\Api\PlanRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('credits')->group(function (): void {
    Route::post('/add', [CreditController::class, 'add'])->middleware('admin.or.api-key');
    Route::post('/subtract', [CreditController::class, 'subtract'])->middleware('admin.or.api-key');
    Route::get('/balance', [CreditController::class, 'balance'])->middleware('admin.or.api-key');
});

Route::middleware('firebase.auth')->group(function (): void {
    Route::get('/plan-requests', [PlanRequestController::class, 'index']);
    Route::post('/plan-requests', [PlanRequestController::class, 'store']);
    Route::post('/audit-runs', [AuditRunController::class, 'store']);
    Route::get('/audit-runs/{publicId}', [AuditRunController::class, 'show']);
});

Route::prefix('admin')
    ->middleware('admin.or.api-key')
    ->group(function (): void {
        Route::get('/plan-requests', [PlanRequestController::class, 'adminIndex']);
        Route::post('/plan-requests/{planRequest}/approve', [PlanRequestController::class, 'approve']);
        Route::post('/plan-requests/{planRequest}/reject', [PlanRequestController::class, 'reject']);
    });
