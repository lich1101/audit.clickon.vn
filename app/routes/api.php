<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\CreditTransactionController;
use App\Http\Controllers\Api\AuditRunController;
use App\Http\Controllers\Api\AuditGeminiPdfController;
use App\Http\Controllers\Api\AuditSettingsController;
use App\Http\Controllers\Api\AiModelController;
use App\Http\Controllers\Api\AuditPromptTemplateController;
use App\Http\Controllers\Api\KeywordRankCaptchaController;
use App\Http\Controllers\Api\KeywordRankSettingsController;
use App\Http\Controllers\Api\KeywordRankController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanRequestController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductRequestController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('credits')->group(function (): void {
    Route::post('/add', [CreditController::class, 'add'])->middleware('admin.or.api-key');
    Route::post('/subtract', [CreditController::class, 'subtract'])->middleware('admin.or.api-key');
    Route::get('/balance', [CreditController::class, 'balance'])->middleware('admin.or.api-key');
});

Route::middleware('firebase.auth')->group(function (): void {
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me', [MeController::class, 'update']);
    Route::put('/me/keyword-rank-preferences', [KeywordRankController::class, 'updatePreferences']);
    Route::get('/credit-transactions', [CreditTransactionController::class, 'index']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/websites', [WebsiteController::class, 'index']);
    Route::post('/websites', [WebsiteController::class, 'store']);
    Route::get('/websites/{websiteId}', [WebsiteController::class, 'show']);
    Route::delete('/websites/{websiteId}', [WebsiteController::class, 'destroy']);
    Route::get('/websites/{websiteId}/audit', [WebsiteController::class, 'showAudit']);
    Route::get('/websites/{websiteId}/audit-runs', [AuditRunController::class, 'indexByWebsite']);
    Route::get('/websites/{websiteId}/audit-board', [AuditRunController::class, 'board']);
    Route::get('/websites/{websiteId}/audit-step1-content', [AuditRunController::class, 'step1Content']);
    Route::get('/websites/{websiteId}/keyword-ranks', [KeywordRankController::class, 'board']);
    Route::put('/websites/{websiteId}/keyword-ranks/keywords', [KeywordRankController::class, 'replaceKeywords']);
    Route::post('/websites/{websiteId}/keyword-rank-runs', [KeywordRankController::class, 'createRun']);
    Route::post('/keyword-rank-runs/{publicId}/heartbeat', [KeywordRankController::class, 'heartbeat']);
    Route::post('/keyword-rank-runs/{publicId}/items', [KeywordRankController::class, 'recordItem']);
    Route::post('/keyword-rank-runs/{publicId}/complete', [KeywordRankController::class, 'completeRun']);
    Route::post('/keyword-rank-captcha-tasks', [KeywordRankCaptchaController::class, 'createTask']);
    Route::get('/keyword-rank-captcha-tasks/{publicId}', [KeywordRankCaptchaController::class, 'poll']);
    Route::post('/website-audits', [WebsiteController::class, 'storeAudit']);
    Route::get('/keyword-rank-settings', [KeywordRankSettingsController::class, 'showPublic']);
    Route::get('/plan-requests', [PlanRequestController::class, 'index']);
    Route::post('/plan-requests', [PlanRequestController::class, 'store']);
    Route::get('/product-requests', [ProductRequestController::class, 'index']);
    Route::post('/product-requests', [ProductRequestController::class, 'store']);
    Route::post('/audit-runs', [AuditRunController::class, 'store']);
    Route::get('/audit-runs/{publicId}', [AuditRunController::class, 'show']);
    Route::post('/audit-runs/{publicId}/stop', [AuditRunController::class, 'stop']);
    Route::get('/audit-settings', [AuditSettingsController::class, 'showPublic']);
});

Route::prefix('admin')
    ->middleware('admin.or.api-key')
    ->group(function (): void {
        Route::get('/audit-settings', [AuditSettingsController::class, 'showAdmin']);
        Route::match(['GET', 'POST'], '/audit-settings/check', [AuditSettingsController::class, 'checkAdmin']);
        Route::put('/audit-settings', [AuditSettingsController::class, 'updateAdmin']);
        Route::post('/audit-settings/gemini-pdf/{slot}', [AuditGeminiPdfController::class, 'upload']);
        Route::delete('/audit-settings/gemini-pdf/{slot}', [AuditGeminiPdfController::class, 'destroy']);
        Route::get('/ai-models/{provider}', [AiModelController::class, 'index']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::get('/users/{firebaseUid}', [AdminUserController::class, 'show']);
        Route::put('/users/{firebaseUid}', [AdminUserController::class, 'update']);
        Route::delete('/users/{firebaseUid}', [AdminUserController::class, 'destroy']);
        Route::post('/websites/{websiteId}/same-day-reaudit', [WebsiteController::class, 'grantSameDayReaudit']);
        Route::delete('/websites/{websiteId}/same-day-reaudit', [WebsiteController::class, 'revokeSameDayReaudit']);
        Route::get('/keyword-rank-settings', [KeywordRankSettingsController::class, 'showAdmin']);
        Route::put('/keyword-rank-settings', [KeywordRankSettingsController::class, 'updateAdmin']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{productId}', [ProductController::class, 'show']);
        Route::put('/products/{productId}', [ProductController::class, 'update']);
        Route::get('/product-requests', [ProductRequestController::class, 'adminIndex']);
        Route::post('/product-requests/{productRequest}/approve', [ProductRequestController::class, 'approve']);
        Route::post('/product-requests/{productRequest}/reject', [ProductRequestController::class, 'reject']);
        Route::get('/plans', [PlanController::class, 'index']);
        Route::post('/plans', [PlanController::class, 'store']);
        Route::get('/plans/{planId}', [PlanController::class, 'show']);
        Route::put('/plans/{planId}', [PlanController::class, 'update']);
        Route::get('/credit-transactions', [CreditTransactionController::class, 'index']);
        Route::get('/plan-requests', [PlanRequestController::class, 'adminIndex']);
        Route::post('/plan-requests/{planRequest}/approve', [PlanRequestController::class, 'approve']);
        Route::post('/plan-requests/{planRequest}/reject', [PlanRequestController::class, 'reject']);
        Route::get('/audit-prompt-templates', [AuditPromptTemplateController::class, 'index']);
        Route::put('/audit-prompt-templates/{step}', [AuditPromptTemplateController::class, 'update']);
        Route::post('/audit-prompt-templates/{step}/reset', [AuditPromptTemplateController::class, 'reset']);
    });
