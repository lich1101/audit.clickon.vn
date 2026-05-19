<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAuditSettingsRequest;
use App\Services\AuditSettingsService;
use App\Services\TokenBillingService;
use Illuminate\Http\Request;

class AuditSettingsController extends Controller
{
    public function __construct(
        private readonly AuditSettingsService $auditSettingsService,
        private readonly TokenBillingService $tokenBillingService,
    ) {
    }

    public function showPublic(Request $request)
    {
        $settings = $this->auditSettingsService->getAuditSettings();
        $minimumCreditsPerRun = $this->tokenBillingService->estimateMinimumCreditsForBatchRun($settings['aiProvider'], $settings['aiModel']);

        return response()->json([
            'data' => [
                'aiProvider' => $settings['aiProvider'],
                'aiModel' => $settings['aiModel'],
                'maxParallelItems' => $settings['maxParallelItems'],
                'minCreditsPerRun' => $minimumCreditsPerRun,
                'minCreditsPerUrl' => $minimumCreditsPerRun,
            ],
        ]);
    }

    public function showAdmin(Request $request)
    {
        return response()->json([
            'data' => [
                ...$this->auditSettingsService->getAuditSettings(),
                'modelPricing' => $this->tokenBillingService->listPricing(),
            ],
        ]);
    }

    public function updateAdmin(UpdateAuditSettingsRequest $request)
    {
        $settings = $this->auditSettingsService->updateAuditSettings($request->validated());

        return response()->json([
            'data' => $settings,
        ]);
    }
}
