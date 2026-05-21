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
        $minimumCreditsPerAiCall = $this->tokenBillingService->estimateMinimumCreditsForAiCall($settings['aiProvider'], $settings['aiModel']);
        $minimumCreditsPerRun = $this->tokenBillingService->estimateMinimumCreditsForBatchRun($settings['aiProvider'], $settings['aiModel']);

        return response()->json([
            'data' => [
                'aiProvider' => $settings['aiProvider'],
                'aiModel' => $settings['aiModel'],
                'step2FormatterProvider' => $settings['step2FormatterProvider'],
                'step2FormatterModel' => $settings['step2FormatterModel'],
                'step3FormatterProvider' => $settings['step3FormatterProvider'],
                'step3FormatterModel' => $settings['step3FormatterModel'],
                'maxParallelItems' => $settings['maxParallelItems'],
                'step2BatchSize' => $settings['step2BatchSize'],
                'step3BatchSize' => $settings['step3BatchSize'],
                'minCreditsPerAiCall' => $minimumCreditsPerAiCall,
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
