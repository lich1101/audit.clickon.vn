<?php

namespace App\Services;

use App\Models\AuditPromptTemplate;
use App\Models\AuditRun;

class AuditConfigurationCheckService
{
    public function __construct(
        private readonly AuditSettingsService $auditSettingsService,
        private readonly AuditPromptTemplateService $promptTemplateService,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{
     *   ready: bool,
     *   checkedAt: string,
     *   step3FlowMode: string,
     *   summary: array{ok:int,warning:int,error:int},
     *   groups: array<int, array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}>
     * }
     */
    public function check(?array $settings = null): array
    {
        $settings ??= $this->auditSettingsService->getAuditSettings();
        $step3FlowMode = in_array(($settings['step3FlowMode'] ?? AuditRun::WORKFLOW_STANDARD), AuditRun::WORKFLOWS, true)
            ? (string) $settings['step3FlowMode']
            : AuditRun::WORKFLOW_STANDARD;
        $auditPipelineMode = in_array(($settings['auditPipelineMode'] ?? AuditRun::PIPELINE_STANDARD), AuditRun::PIPELINE_MODES, true)
            ? (string) $settings['auditPipelineMode']
            : AuditRun::PIPELINE_STANDARD;

        if ($auditPipelineMode === AuditRun::PIPELINE_FAST && $step3FlowMode === AuditRun::WORKFLOW_STANDARD) {
            $groups = [
                $this->checkFastPipelineGroup($settings),
            ];
        } else {
            $groups = [
                $this->checkStep2Group($settings),
            ];

            if ($step3FlowMode === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH) {
                $groups[] = $this->checkDeepResearchStep3Group($settings);
            } else {
                $groups[] = $this->checkStandardStep3Group($settings);
            }
        }

        $groups[] = $this->checkRuntimeGroup($settings, $step3FlowMode, $auditPipelineMode);

        $summary = ['ok' => 0, 'warning' => 0, 'error' => 0];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if (isset($summary[$item['status']])) {
                    $summary[$item['status']]++;
                }
            }
        }

        return [
            'ready' => $summary['error'] === 0,
            'checkedAt' => now()->toIso8601String(),
            'step3FlowMode' => $step3FlowMode,
            'auditPipelineMode' => $auditPipelineMode,
            'summary' => $summary,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function checkStep2Group(array $settings): array
    {
        $provider = (string) ($settings['step2AiProvider'] ?? $settings['aiProvider'] ?? 'openai');
        $model = $this->effectiveModelForProvider($provider, $settings['step2AiModel'] ?? $settings['aiModel'] ?? null);
        $formatterProvider = (string) ($settings['step2FormatterProvider'] ?? 'gemini');
        $formatterModel = $this->effectiveFormatterModel($formatterProvider, $settings['step2FormatterModel'] ?? null);

        return $this->buildGroup('step2', 'Bước 2: keyword + danh mục', [
            $this->okItem('Provider/model bước 2', sprintf('%s / %s', $provider, $model)),
            $this->providerCredentialCheck($provider, 'API key bước 2'),
            $this->promptCheck(AuditPromptTemplate::STEP_KEYWORD_CATEGORY_MAPPING, 'Prompt bước 2'),
            $this->okItem('Provider/model bước 2.5', sprintf('%s / %s', $formatterProvider, $formatterModel)),
            $this->providerCredentialCheck($formatterProvider, 'API key bước 2.5'),
            $this->promptCheck(AuditPromptTemplate::STEP_KEYWORD_CATEGORY_JSON_FORMATTER, 'Prompt bước 2.5'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function checkFastPipelineGroup(array $settings): array
    {
        $provider = (string) ($settings['fastAiProvider'] ?? $settings['step2AiProvider'] ?? $settings['aiProvider'] ?? 'openai');
        $model = $this->effectiveModelForProvider($provider, $settings['fastAiModel'] ?? $settings['step2AiModel'] ?? $settings['aiModel'] ?? null);
        $formatterProvider = (string) ($settings['fastFormatterProvider'] ?? 'deepseek');
        $formatterModel = $this->effectiveFormatterModel($formatterProvider, $settings['fastFormatterModel'] ?? $settings['step2FormatterModel'] ?? null);

        return $this->buildGroup('fast_pipeline', 'Fast mode: keyword + audit gộp', [
            $this->okItem('Pipeline', 'Fast mode (gộp bước 2 + 3)'),
            $this->okItem('Provider/model fast audit', sprintf('%s / %s', $provider, $model)),
            $this->providerCredentialCheck($provider, 'API key fast audit'),
            $this->promptCheck(AuditPromptTemplate::STEP_FAST_AUDIT, 'Prompt fast audit'),
            $this->okItem('Provider/model formatter', sprintf('%s / %s', $formatterProvider, $formatterModel)),
            $this->providerCredentialCheck($formatterProvider, 'API key formatter'),
            $this->promptCheck(AuditPromptTemplate::STEP_FAST_AUDIT_JSON_FORMATTER, 'Prompt fast formatter'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function checkStandardStep3Group(array $settings): array
    {
        $provider = (string) ($settings['step3AiProvider'] ?? $settings['aiProvider'] ?? 'openai');
        $model = $this->effectiveModelForProvider($provider, $settings['step3AiModel'] ?? $settings['aiModel'] ?? null);
        $formatterProvider = (string) ($settings['step3FormatterProvider'] ?? 'gemini');
        $formatterModel = $this->effectiveFormatterModel($formatterProvider, $settings['step3FormatterModel'] ?? null);

        return $this->buildGroup('step3_standard', 'Bước 3 chuẩn', [
            $this->okItem('Provider/model bước 3', sprintf('%s / %s', $provider, $model)),
            $this->providerCredentialCheck($provider, 'API key bước 3'),
            $this->promptCheck(AuditPromptTemplate::STEP_ONPAGE_AUDIT, 'Prompt bước 3'),
            $this->okItem('Provider/model bước 3.5', sprintf('%s / %s', $formatterProvider, $formatterModel)),
            $this->providerCredentialCheck($formatterProvider, 'API key bước 3.5'),
            $this->promptCheck(AuditPromptTemplate::STEP_ONPAGE_AUDIT_JSON_FORMATTER, 'Prompt bước 3.5'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function checkDeepResearchStep3Group(array $settings): array
    {
        $researchProvider = $this->normalizeDeepResearchResearchProvider($settings['deepResearchResearchProvider'] ?? null);
        $researchModel = $this->effectiveDeepResearchResearchModel($researchProvider, $settings['deepResearchResearchModel'] ?? null);
        $reasoningProvider = $this->normalizeDeepResearchReasoningProvider($settings['deepResearchReasoningProvider'] ?? null);
        $reasoningModel = $this->effectiveDeepResearchReasoningModel($reasoningProvider, $settings['deepResearchReasoningModel'] ?? null);
        $formatterProvider = (string) ($settings['deepResearchFormatterProvider'] ?? 'openai');
        $formatterModel = $this->effectiveFormatterModel($formatterProvider, $settings['deepResearchFormatterModel'] ?? null);

        $items = [
            $this->okItem('Provider/model bước 3A', sprintf('%s / %s', $researchProvider, $researchModel)),
            $this->providerCredentialCheck($researchProvider, 'API key bước 3A'),
            $this->promptCheck(AuditPromptTemplate::STEP_DEEP_RESEARCH_RESEARCH, 'Prompt bước 3A'),
            $this->okItem('Provider/model bước 3B', sprintf('%s / %s', $reasoningProvider, $reasoningModel)),
            $this->providerCredentialCheck($reasoningProvider, 'API key bước 3B'),
            $this->promptCheck(AuditPromptTemplate::STEP_DEEP_RESEARCH_AUDIT, 'Prompt bước 3B'),
            $this->okItem('Provider/model bước 3C', sprintf('%s / %s', $formatterProvider, $formatterModel)),
            $this->providerCredentialCheck($formatterProvider, 'API key bước 3C'),
            $this->promptCheck(AuditPromptTemplate::STEP_DEEP_RESEARCH_JSON_FORMATTER, 'Prompt bước 3C'),
        ];

        if ($researchProvider === 'perplexity'
            && str_contains(strtolower($researchModel), 'deep-research')
            && ! (bool) config('services.audit.deep_research_research_use_async', true)
        ) {
            $items[] = $this->warningItem(
                'Async Perplexity',
                'Model deep research đang bật nhưng AUDIT_DEEP_RESEARCH_RESEARCH_USE_ASYNC đang tắt; batch lớn có thể timeout.'
            );
        }

        return $this->buildGroup('step3_deep_research', 'Bước 3 Deep Research', $items);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function checkRuntimeGroup(array $settings, string $step3FlowMode, string $auditPipelineMode = AuditRun::PIPELINE_STANDARD): array
    {
        $maxParallel = max(1, (int) ($settings['maxParallelItems'] ?? 1));
        $step2BatchSize = max(1, (int) ($settings['step2BatchSize'] ?? 60));
        $step3BatchSize = max(1, (int) ($settings['step3BatchSize'] ?? 30));
        $fastBatchSize = max(1, (int) ($settings['fastBatchSize'] ?? 15));
        $minValidUrlsAfterStep1 = max(1, (int) ($settings['minValidUrlsAfterStep1'] ?? 50));
        $deepResearchBatchSize = max(1, (int) ($settings['deepResearchBatchSize'] ?? 5));

        $items = [
            $this->okItem('Pipeline audit', $auditPipelineMode === AuditRun::PIPELINE_FAST ? 'Fast mode' : 'Chuẩn (tách bước 2/3)'),
            $this->parallelCheck($maxParallel),
        ];

        if ($auditPipelineMode === AuditRun::PIPELINE_FAST) {
            $items[] = $this->okItem('Flow đang hoạt động', 'Fast mode dùng flow gộp keyword + audit. Cấu hình bước 3 chuẩn / Deep Research vẫn được giữ để dùng lại khi chuyển mode.');
        } else {
            $items[] = $this->okItem('Mode bước 3', $step3FlowMode === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH ? 'Deep Research' : 'Chuẩn');
        }

        if ($auditPipelineMode === AuditRun::PIPELINE_FAST && $step3FlowMode === AuditRun::WORKFLOW_STANDARD) {
            $items[] = $this->batchSizeCheck('Batch fast mode', $fastBatchSize, 30);
            $items[] = $minValidUrlsAfterStep1 > $fastBatchSize
                ? $this->warningItem(
                    'Ngưỡng sau B1',
                    sprintf(
                        'Ngưỡng tối thiểu sau bước 1 đang là %d URL, lớn hơn batch fast mode %d URL. Nếu bước 1 chỉ giữ lại ít URL hợp lệ hơn ngưỡng này thì run sẽ dừng trước khi gọi AI.',
                        $minValidUrlsAfterStep1,
                        $fastBatchSize,
                    )
                )
                : $this->okItem('Ngưỡng sau B1', sprintf('%d URL hợp lệ tối thiểu để chạy tiếp bước AI.', $minValidUrlsAfterStep1));
        } else {
            $items[] = $this->batchSizeCheck('Batch bước 2', $step2BatchSize, 150);
            $items[] = $minValidUrlsAfterStep1 > $step2BatchSize
                ? $this->warningItem(
                    'Ngưỡng sau B1',
                    sprintf(
                        'Ngưỡng tối thiểu sau bước 1 đang là %d URL, lớn hơn batch bước 2 %d URL. Nếu bạn thường chạy ít URL, nên giảm ngưỡng này để tránh run dừng sớm.',
                        $minValidUrlsAfterStep1,
                        $step2BatchSize,
                    )
                )
                : $this->okItem('Ngưỡng sau B1', sprintf('%d URL hợp lệ tối thiểu để chạy tiếp bước AI.', $minValidUrlsAfterStep1));
        }

        if ($step3FlowMode === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH) {
            $items[] = $this->batchSizeCheck('Batch Deep Research', $deepResearchBatchSize, 60);
        } elseif ($auditPipelineMode !== AuditRun::PIPELINE_FAST) {
            $items[] = $this->batchSizeCheck('Batch bước 3 chuẩn', $step3BatchSize, 100);
        }

        return $this->buildGroup('runtime', 'Thông số vận hành', $items);
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function promptCheck(string $step, string $label): array
    {
        try {
            $rendered = $this->promptTemplateService->render($step, []);
        } catch (\Throwable $exception) {
            return $this->errorItem($label, sprintf('Không render được prompt `%s`: %s', $step, $exception->getMessage()));
        }

        $system = trim((string) ($rendered['system'] ?? ''));
        $user = trim((string) ($rendered['user'] ?? ''));

        if ($system === '' || $user === '') {
            return $this->errorItem($label, sprintf('Prompt `%s` đang rỗng ở phần system hoặc user.', $step));
        }

        return $this->okItem($label, sprintf('Prompt `%s` render được và có đủ system/user prompt.', $step));
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function providerCredentialCheck(string $provider, string $label): array
    {
        [$envName, $value] = match ($provider) {
            'openai' => ['OPENAI_API_KEY', (string) config('services.openai.api_key', '')],
            'deepseek' => ['DEEPSEEK_API_KEY', (string) config('services.deepseek.api_key', '')],
            'gemini', 'gemini_deep_research' => ['GEMINI_API_KEY', (string) config('services.gemini.api_key', '')],
            'perplexity' => ['PERPLEXITY_API_KEY', (string) config('services.perplexity.api_key', '')],
            default => [null, ''],
        };

        if ($envName === null) {
            return $this->warningItem($label, sprintf('Provider `%s` không có rule kiểm tra API key riêng.', $provider));
        }

        if (trim($value) === '') {
            return $this->errorItem($label, sprintf('Thiếu biến môi trường `%s` cho provider `%s`.', $envName, $provider));
        }

        return $this->okItem($label, sprintf('Đã có `%s` cho provider `%s`.', $envName, $provider));
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function batchSizeCheck(string $label, int $value, int $warningThreshold): array
    {
        if ($value > $warningThreshold) {
            return $this->warningItem($label, sprintf('%d URL/batch đang khá lớn; nên giảm nếu output JSON dài hoặc model hay timeout.', $value));
        }

        return $this->okItem($label, sprintf('%d URL/batch.', $value));
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function parallelCheck(int $value): array
    {
        if ($value > 5) {
            return $this->warningItem('Batch chạy đồng thời', sprintf('Giới hạn %d batch cùng lúc có thể làm queue/AI quota căng hơn bình thường.', $value));
        }

        return $this->okItem('Batch chạy đồng thời', sprintf('%d batch cùng lúc.', $value));
    }

    /**
     * @param  array<int, array{status:string,label:string,message:string}>  $items
     * @return array{id:string,title:string,status:string,items:array<int, array{status:string,label:string,message:string}>}
     */
    private function buildGroup(string $id, string $title, array $items): array
    {
        $status = 'ok';

        foreach ($items as $item) {
            if ($item['status'] === 'error') {
                $status = 'error';
                break;
            }

            if ($item['status'] === 'warning') {
                $status = 'warning';
            }
        }

        return [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'items' => $items,
        ];
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function okItem(string $label, string $message): array
    {
        return [
            'status' => 'ok',
            'label' => $label,
            'message' => $message,
        ];
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function warningItem(string $label, string $message): array
    {
        return [
            'status' => 'warning',
            'label' => $label,
            'message' => $message,
        ];
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function errorItem(string $label, string $message): array
    {
        return [
            'status' => 'error',
            'label' => $label,
            'message' => $message,
        ];
    }

    private function effectiveModelForProvider(string $provider, mixed $configuredModel): string
    {
        $configured = trim((string) ($configuredModel ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return match ($provider) {
            'deepseek' => (string) config('services.deepseek.model', 'deepseek-v4-flash'),
            'gemini' => (string) config('services.gemini.model', 'gemini-2.5-pro'),
            'gemini_deep_research' => (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025'),
            default => (string) config('services.openai.model', 'gpt-5.5'),
        };
    }

    private function effectiveFormatterModel(string $provider, mixed $configuredModel): string
    {
        $configured = trim((string) ($configuredModel ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return match ($provider) {
            'openai' => (string) config('services.openai.model', 'gpt-5.5'),
            'deepseek' => (string) config('services.deepseek.model', 'deepseek-v4-flash'),
            default => 'gemini-2.5-flash',
        };
    }

    private function effectiveDeepResearchResearchModel(string $provider, mixed $configuredModel): string
    {
        $configured = trim((string) ($configuredModel ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return $provider === 'gemini_deep_research'
            ? (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025')
            : (string) config('services.audit.deep_research_research_model', config('services.perplexity.model', 'sonar-deep-research'));
    }

    private function effectiveDeepResearchReasoningModel(string $provider, mixed $configuredModel): string
    {
        $configured = trim((string) ($configuredModel ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return $provider === 'gemini'
            ? (string) config('services.gemini.model', 'gemini-2.5-pro')
            : (string) config('services.audit.deep_research_reasoning_model', config('services.openai.model', 'gpt-5.5'));
    }

    private function normalizeDeepResearchResearchProvider(mixed $provider): string
    {
        return in_array($provider, ['perplexity', 'gemini_deep_research'], true)
            ? (string) $provider
            : 'perplexity';
    }

    private function normalizeDeepResearchReasoningProvider(mixed $provider): string
    {
        return in_array($provider, ['openai', 'gemini'], true)
            ? (string) $provider
            : 'openai';
    }
}
