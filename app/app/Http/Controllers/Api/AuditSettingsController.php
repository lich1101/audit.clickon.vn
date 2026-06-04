<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAuditSettingsRequest;
use App\Models\AuditRun;
use App\Services\AuditConfigurationCheckService;
use App\Services\AuditSettingsService;
use App\Services\TokenBillingService;
use Illuminate\Http\Request;

class AuditSettingsController extends Controller
{
    public function __construct(
        private readonly AuditSettingsService $auditSettingsService,
        private readonly AuditConfigurationCheckService $auditConfigurationCheckService,
        private readonly TokenBillingService $tokenBillingService,
    ) {
    }

    public function showPublic(Request $request)
    {
        $settings = $this->auditSettingsService->getAuditSettings();
        $activeProvider = ($settings['auditPipelineMode'] ?? AuditRun::PIPELINE_STANDARD) === AuditRun::PIPELINE_FAST
            ? ($settings['fastAiProvider'] ?? $settings['step2AiProvider'] ?? $settings['aiProvider'])
            : ($settings['aiProvider'] ?? 'openai');
        $activeModel = ($settings['auditPipelineMode'] ?? AuditRun::PIPELINE_STANDARD) === AuditRun::PIPELINE_FAST
            ? ($settings['fastAiModel'] ?? $settings['step2AiModel'] ?? $settings['aiModel'] ?? null)
            : ($settings['aiModel'] ?? null);
        $minimumCreditsPerAiCall = $this->tokenBillingService->estimateMinimumCreditsForAiCall($activeProvider, $activeModel);
        $minimumCreditsPerRun = $this->tokenBillingService->estimateMinimumCreditsForBatchRun($activeProvider, $activeModel);

        return response()->json([
            'data' => [
                'aiProvider' => $settings['aiProvider'],
                'aiModel' => $settings['aiModel'],
                'step2AiProvider' => $settings['step2AiProvider'],
                'step2AiModel' => $settings['step2AiModel'],
                'step3AiProvider' => $settings['step3AiProvider'],
                'step3AiModel' => $settings['step3AiModel'],
                'step2FormatterProvider' => $settings['step2FormatterProvider'],
                'step2FormatterModel' => $settings['step2FormatterModel'],
                'step3FormatterProvider' => $settings['step3FormatterProvider'],
                'step3FormatterModel' => $settings['step3FormatterModel'],
                'fastAiProvider' => $settings['fastAiProvider'],
                'fastAiModel' => $settings['fastAiModel'],
                'fastFormatterProvider' => $settings['fastFormatterProvider'],
                'fastFormatterModel' => $settings['fastFormatterModel'],
                'step3FlowMode' => $settings['step3FlowMode'],
                'auditPipelineMode' => $settings['auditPipelineMode'] ?? AuditRun::PIPELINE_STANDARD,
                'fastBatchSize' => (int) ($settings['fastBatchSize'] ?? 15),
                'maxParallelItems' => $settings['maxParallelItems'],
                'step2BatchSize' => $settings['step2BatchSize'],
                'step3BatchSize' => $settings['step3BatchSize'],
                'minValidUrlsAfterStep1' => (int) ($settings['minValidUrlsAfterStep1'] ?? 50),
                'deepResearchBatchSize' => $settings['deepResearchBatchSize'],
                'deepResearchResearchProvider' => $settings['deepResearchResearchProvider'],
                'deepResearchResearchModel' => $settings['deepResearchResearchModel'],
                'deepResearchReasoningProvider' => $settings['deepResearchReasoningProvider'],
                'deepResearchReasoningModel' => $settings['deepResearchReasoningModel'],
                'deepResearchFormatterProvider' => $settings['deepResearchFormatterProvider'],
                'deepResearchFormatterModel' => $settings['deepResearchFormatterModel'],
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
        $validated = $request->validated();
        $settings = $this->auditSettingsService->updateAuditSettings($validated);

        if (array_key_exists('modelPricing', $validated) && is_array($validated['modelPricing'])) {
            $this->tokenBillingService->syncPricing($validated['modelPricing']);
        }

        return response()->json([
            'data' => [
                ...$settings,
                'modelPricing' => $this->tokenBillingService->listPricing(),
            ],
        ]);
    }

    public function checkAdmin(Request $request)
    {
        $settings = $this->auditSettingsService->getAuditSettings();

        if ($request->all() !== []) {
            $validated = validator($request->all(), (new UpdateAuditSettingsRequest())->rules())->validate();
            $settings = $this->auditSettingsService->previewAuditSettings($validated);
        }

        return response()->json([
            'data' => $this->auditConfigurationCheckService->check($settings),
        ]);
    }
}
