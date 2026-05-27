<?php

namespace App\Services;

use App\Models\AuditRun;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class AuditSettingsService
{
    private const CACHE_KEY = 'system_settings.audit';

    /**
     * @return array{aiProvider: string, aiModel: string|null, step2AiProvider: string, step2AiModel: string|null, step3AiProvider: string, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, step3FlowMode: string, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int, deepResearchBatchSize: int, deepResearchResearchProvider: string, deepResearchResearchModel: string|null, deepResearchReasoningProvider: string, deepResearchReasoningModel: string|null, deepResearchFormatterProvider: string, deepResearchFormatterModel: string|null}
     */
    public function getAuditSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $record = SystemSetting::query()->where('key', 'audit')->first();
            $value = is_array($record?->value) ? $record->value : [];

            $provider = $this->normalizeAiProvider($value['aiProvider'] ?? env('AUDIT_DEFAULT_AI_PROVIDER', 'openai'));
            $step3FlowMode = $this->normalizeStep3FlowMode(
                $value['step3FlowMode'] ?? env('AUDIT_STEP3_FLOW_MODE', AuditRun::WORKFLOW_STANDARD)
            );

            $maxParallel = (int) ($value['maxParallelItems'] ?? 3);
            $maxParallel = max(1, min(10, $maxParallel));

            $step2BatchSize = (int) ($value['step2BatchSize'] ?? 60);
            $step2BatchSize = max(1, min(300, $step2BatchSize));

            $step3BatchSize = (int) ($value['step3BatchSize'] ?? 30);
            $step3BatchSize = max(1, min(300, $step3BatchSize));

            $deepResearchBatchSize = (int) ($value['deepResearchBatchSize'] ?? env('AUDIT_DEEP_RESEARCH_BATCH_SIZE', 5));
            $deepResearchBatchSize = max(1, min(100, $deepResearchBatchSize));
            $deepResearchResearchProvider = $this->normalizeDeepResearchResearchProvider(
                $value['deepResearchResearchProvider'] ?? env('AUDIT_DEEP_RESEARCH_RESEARCH_PROVIDER', config('services.audit.deep_research_research_provider', 'perplexity'))
            );
            $deepResearchResearchModel = $this->normalizeModel(
                $value['deepResearchResearchModel'] ?? env('AUDIT_DEEP_RESEARCH_RESEARCH_MODEL', null),
                $this->defaultDeepResearchResearchModel($deepResearchResearchProvider),
            );
            $deepResearchReasoningProvider = $this->normalizeDeepResearchReasoningProvider(
                $value['deepResearchReasoningProvider'] ?? env('AUDIT_DEEP_RESEARCH_REASONING_PROVIDER', config('services.audit.deep_research_reasoning_provider', 'openai'))
            );
            $deepResearchReasoningModel = $this->normalizeModel(
                $value['deepResearchReasoningModel'] ?? env('AUDIT_DEEP_RESEARCH_REASONING_MODEL', null),
                $this->defaultDeepResearchReasoningModel($deepResearchReasoningProvider),
            );
            $deepResearchFormatterProvider = $this->normalizeFormatterProvider(
                $value['deepResearchFormatterProvider'] ?? env('AUDIT_DEEP_RESEARCH_FORMATTER_PROVIDER', 'openai')
            );
            $deepResearchFormatterModel = $this->normalizeModel(
                $value['deepResearchFormatterModel'] ?? env('AUDIT_DEEP_RESEARCH_FORMATTER_MODEL', null),
                $this->defaultFormatterModel($deepResearchFormatterProvider),
            );

            $aiModel = $this->normalizeOptionalModel($value['aiModel'] ?? env('AUDIT_DEFAULT_AI_MODEL', $this->defaultModelForProvider($provider)));
            $step2AiProvider = $this->normalizeAiProvider($value['step2AiProvider'] ?? env('AUDIT_STEP2_AI_PROVIDER', $provider));
            $step3AiProvider = $this->normalizeAiProvider($value['step3AiProvider'] ?? env('AUDIT_STEP3_AI_PROVIDER', $provider));
            $step2AiModel = $this->normalizeOptionalModel($value['step2AiModel'] ?? env('AUDIT_STEP2_AI_MODEL', $this->defaultModelForProvider($step2AiProvider)));
            $step3AiModel = $this->normalizeOptionalModel($value['step3AiModel'] ?? env('AUDIT_STEP3_AI_MODEL', $this->defaultModelForProvider($step3AiProvider)));
            $defaultFormatterProvider = $provider === 'openai' ? 'openai' : 'gemini';
            $step2FormatterProvider = $this->normalizeFormatterProvider($value['step2FormatterProvider'] ?? env('AUDIT_STEP2_FORMATTER_PROVIDER', $defaultFormatterProvider));
            $step2FormatterModel = $this->normalizeModel(
                $value['step2FormatterModel'] ?? env('AUDIT_STEP2_FORMATTER_MODEL', null),
                $this->defaultFormatterModel($step2FormatterProvider),
            );
            $step3FormatterProvider = $this->normalizeFormatterProvider($value['step3FormatterProvider'] ?? env('AUDIT_STEP3_FORMATTER_PROVIDER', $defaultFormatterProvider));
            $step3FormatterModel = $this->normalizeModel(
                $value['step3FormatterModel'] ?? env('AUDIT_STEP3_FORMATTER_MODEL', null),
                $this->defaultFormatterModel($step3FormatterProvider),
            );

            return [
                'aiProvider' => $provider,
                'aiModel' => $aiModel,
                'step2AiProvider' => $step2AiProvider,
                'step2AiModel' => $step2AiModel,
                'step3AiProvider' => $step3AiProvider,
                'step3AiModel' => $step3AiModel,
                'step2FormatterProvider' => $step2FormatterProvider,
                'step2FormatterModel' => $step2FormatterModel,
                'step3FormatterProvider' => $step3FormatterProvider,
                'step3FormatterModel' => $step3FormatterModel,
                'step3FlowMode' => $step3FlowMode,
                'maxParallelItems' => $maxParallel,
                'step2BatchSize' => $step2BatchSize,
                'step3BatchSize' => $step3BatchSize,
                'deepResearchBatchSize' => $deepResearchBatchSize,
                'deepResearchResearchProvider' => $deepResearchResearchProvider,
                'deepResearchResearchModel' => $deepResearchResearchModel,
                'deepResearchReasoningProvider' => $deepResearchReasoningProvider,
                'deepResearchReasoningModel' => $deepResearchReasoningModel,
                'deepResearchFormatterProvider' => $deepResearchFormatterProvider,
                'deepResearchFormatterModel' => $deepResearchFormatterModel,
            ];
        });
    }

    /**
     * @param  array{aiProvider?: string, aiModel?: string|null, step2AiProvider?: string, step2AiModel?: string|null, step3AiProvider?: string, step3AiModel?: string|null, step2FormatterProvider?: string, step2FormatterModel?: string|null, step3FormatterProvider?: string, step3FormatterModel?: string|null, step3FlowMode?: string, maxParallelItems?: int, step2BatchSize?: int, step3BatchSize?: int, deepResearchBatchSize?: int, deepResearchResearchProvider?: string, deepResearchResearchModel?: string|null, deepResearchReasoningProvider?: string, deepResearchReasoningModel?: string|null, deepResearchFormatterProvider?: string, deepResearchFormatterModel?: string|null}  $payload
     * @return array{aiProvider: string, aiModel: string|null, step2AiProvider: string, step2AiModel: string|null, step3AiProvider: string, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, step3FlowMode: string, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int, deepResearchBatchSize: int, deepResearchResearchProvider: string, deepResearchResearchModel: string|null, deepResearchReasoningProvider: string, deepResearchReasoningModel: string|null, deepResearchFormatterProvider: string, deepResearchFormatterModel: string|null}
     */
    public function previewAuditSettings(array $payload): array
    {
        return $this->mergeAuditSettings($payload, $this->getAuditSettings());
    }

    /**
     * @param  array{aiProvider?: string, aiModel?: string|null, step2AiProvider?: string, step2AiModel?: string|null, step3AiProvider?: string, step3AiModel?: string|null, step2FormatterProvider?: string, step2FormatterModel?: string|null, step3FormatterProvider?: string, step3FormatterModel?: string|null, step3FlowMode?: string, maxParallelItems?: int, step2BatchSize?: int, step3BatchSize?: int, deepResearchBatchSize?: int, deepResearchResearchProvider?: string, deepResearchResearchModel?: string|null, deepResearchReasoningProvider?: string, deepResearchReasoningModel?: string|null, deepResearchFormatterProvider?: string, deepResearchFormatterModel?: string|null}  $payload
     * @return array{aiProvider: string, aiModel: string|null, step2AiProvider: string, step2AiModel: string|null, step3AiProvider: string, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, step3FlowMode: string, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int, deepResearchBatchSize: int, deepResearchResearchProvider: string, deepResearchResearchModel: string|null, deepResearchReasoningProvider: string, deepResearchReasoningModel: string|null, deepResearchFormatterProvider: string, deepResearchFormatterModel: string|null}
     */
    public function updateAuditSettings(array $payload): array
    {
        $value = $this->mergeAuditSettings($payload, $this->getAuditSettings());

        SystemSetting::query()->updateOrCreate(
            ['key' => 'audit'],
            ['value' => $value],
        );

        Cache::forget(self::CACHE_KEY);

        return $value;
    }

    /**
     * @param  array{aiProvider?: string, aiModel?: string|null, step2AiProvider?: string, step2AiModel?: string|null, step3AiProvider?: string, step3AiModel?: string|null, step2FormatterProvider?: string, step2FormatterModel?: string|null, step3FormatterProvider?: string, step3FormatterModel?: string|null, step3FlowMode?: string, maxParallelItems?: int, step2BatchSize?: int, step3BatchSize?: int, deepResearchBatchSize?: int, deepResearchResearchProvider?: string, deepResearchResearchModel?: string|null, deepResearchReasoningProvider?: string, deepResearchReasoningModel?: string|null, deepResearchFormatterProvider?: string, deepResearchFormatterModel?: string|null}  $payload
     * @param  array{aiProvider: string, aiModel: string|null, step2AiProvider: string, step2AiModel: string|null, step3AiProvider: string, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, step3FlowMode: string, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int, deepResearchBatchSize: int, deepResearchResearchProvider: string, deepResearchResearchModel: string|null, deepResearchReasoningProvider: string, deepResearchReasoningModel: string|null, deepResearchFormatterProvider: string, deepResearchFormatterModel: string|null}  $current
     * @return array{aiProvider: string, aiModel: string|null, step2AiProvider: string, step2AiModel: string|null, step3AiProvider: string, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, step3FlowMode: string, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int, deepResearchBatchSize: int, deepResearchResearchProvider: string, deepResearchResearchModel: string|null, deepResearchReasoningProvider: string, deepResearchReasoningModel: string|null, deepResearchFormatterProvider: string, deepResearchFormatterModel: string|null}
     */
    private function mergeAuditSettings(array $payload, array $current): array
    {
        $provider = array_key_exists('aiProvider', $payload)
            ? $this->normalizeAiProvider($payload['aiProvider'])
            : $current['aiProvider'];
        $step3FlowMode = array_key_exists('step3FlowMode', $payload)
            ? $this->normalizeStep3FlowMode($payload['step3FlowMode'])
            : $current['step3FlowMode'];

        $maxParallel = isset($payload['maxParallelItems'])
            ? max(1, min(10, (int) $payload['maxParallelItems']))
            : $current['maxParallelItems'];

        $step2BatchSize = isset($payload['step2BatchSize'])
            ? max(1, min(300, (int) $payload['step2BatchSize']))
            : $current['step2BatchSize'];

        $step3BatchSize = isset($payload['step3BatchSize'])
            ? max(1, min(300, (int) $payload['step3BatchSize']))
            : $current['step3BatchSize'];

        $deepResearchBatchSize = isset($payload['deepResearchBatchSize'])
            ? max(1, min(100, (int) $payload['deepResearchBatchSize']))
            : $current['deepResearchBatchSize'];
        $deepResearchResearchProvider = array_key_exists('deepResearchResearchProvider', $payload)
            ? $this->normalizeDeepResearchResearchProvider($payload['deepResearchResearchProvider'])
            : $current['deepResearchResearchProvider'];
        $deepResearchResearchModel = array_key_exists('deepResearchResearchModel', $payload)
            ? $this->normalizeModel($payload['deepResearchResearchModel'], $this->defaultDeepResearchResearchModel($deepResearchResearchProvider))
            : $current['deepResearchResearchModel'];
        $deepResearchReasoningProvider = array_key_exists('deepResearchReasoningProvider', $payload)
            ? $this->normalizeDeepResearchReasoningProvider($payload['deepResearchReasoningProvider'])
            : $current['deepResearchReasoningProvider'];
        $deepResearchReasoningModel = array_key_exists('deepResearchReasoningModel', $payload)
            ? $this->normalizeModel($payload['deepResearchReasoningModel'], $this->defaultDeepResearchReasoningModel($deepResearchReasoningProvider))
            : $current['deepResearchReasoningModel'];
        $deepResearchFormatterProvider = array_key_exists('deepResearchFormatterProvider', $payload)
            ? $this->normalizeFormatterProvider($payload['deepResearchFormatterProvider'])
            : $current['deepResearchFormatterProvider'];
        $deepResearchFormatterModel = array_key_exists('deepResearchFormatterModel', $payload)
            ? $this->normalizeModel($payload['deepResearchFormatterModel'], $this->defaultFormatterModel($deepResearchFormatterProvider))
            : $current['deepResearchFormatterModel'];

        $aiModel = array_key_exists('aiModel', $payload)
            ? $this->normalizeOptionalModel($payload['aiModel'] ?? null)
            : $current['aiModel'];
        $step2AiProvider = array_key_exists('step2AiProvider', $payload)
            ? $this->normalizeAiProvider($payload['step2AiProvider'])
            : $current['step2AiProvider'];
        $step2AiModel = array_key_exists('step2AiModel', $payload)
            ? $this->normalizeOptionalModel($payload['step2AiModel'])
            : $current['step2AiModel'];
        $step3AiProvider = array_key_exists('step3AiProvider', $payload)
            ? $this->normalizeAiProvider($payload['step3AiProvider'])
            : $current['step3AiProvider'];
        $step3AiModel = array_key_exists('step3AiModel', $payload)
            ? $this->normalizeOptionalModel($payload['step3AiModel'])
            : $current['step3AiModel'];
        $step2FormatterProvider = array_key_exists('step2FormatterProvider', $payload)
            ? $this->normalizeFormatterProvider($payload['step2FormatterProvider'])
            : $current['step2FormatterProvider'];
        $step2FormatterModel = array_key_exists('step2FormatterModel', $payload)
            ? $this->normalizeModel($payload['step2FormatterModel'], $this->defaultFormatterModel($step2FormatterProvider))
            : $current['step2FormatterModel'];
        $step3FormatterProvider = array_key_exists('step3FormatterProvider', $payload)
            ? $this->normalizeFormatterProvider($payload['step3FormatterProvider'])
            : $current['step3FormatterProvider'];
        $step3FormatterModel = array_key_exists('step3FormatterModel', $payload)
            ? $this->normalizeModel($payload['step3FormatterModel'], $this->defaultFormatterModel($step3FormatterProvider))
            : $current['step3FormatterModel'];

        return [
            'aiProvider' => $provider,
            'aiModel' => $aiModel ?: $this->defaultModelForProvider($provider),
            'step2AiProvider' => $step2AiProvider,
            'step2AiModel' => $step2AiModel ?: $this->defaultModelForProvider($step2AiProvider),
            'step3AiProvider' => $step3AiProvider,
            'step3AiModel' => $step3AiModel ?: $this->defaultModelForProvider($step3AiProvider),
            'step2FormatterProvider' => $step2FormatterProvider,
            'step2FormatterModel' => $step2FormatterModel,
            'step3FormatterProvider' => $step3FormatterProvider,
            'step3FormatterModel' => $step3FormatterModel,
            'step3FlowMode' => $step3FlowMode,
            'maxParallelItems' => $maxParallel,
            'step2BatchSize' => $step2BatchSize,
            'step3BatchSize' => $step3BatchSize,
            'deepResearchBatchSize' => $deepResearchBatchSize,
            'deepResearchResearchProvider' => $deepResearchResearchProvider,
            'deepResearchResearchModel' => $deepResearchResearchModel,
            'deepResearchReasoningProvider' => $deepResearchReasoningProvider,
            'deepResearchReasoningModel' => $deepResearchReasoningModel,
            'deepResearchFormatterProvider' => $deepResearchFormatterProvider,
            'deepResearchFormatterModel' => $deepResearchFormatterModel,
        ];
    }

    public function maxParallelItems(): int
    {
        return $this->getAuditSettings()['maxParallelItems'];
    }

    public function step2BatchSize(): int
    {
        return $this->getAuditSettings()['step2BatchSize'];
    }

    public function step3BatchSize(): int
    {
        return $this->getAuditSettings()['step3BatchSize'];
    }

    public function step3FlowMode(): string
    {
        return $this->getAuditSettings()['step3FlowMode'];
    }

    public function deepResearchBatchSize(): int
    {
        return $this->getAuditSettings()['deepResearchBatchSize'];
    }

    public function deepResearchResearchProvider(): string
    {
        return $this->getAuditSettings()['deepResearchResearchProvider'];
    }

    public function deepResearchResearchModel(): ?string
    {
        return $this->getAuditSettings()['deepResearchResearchModel'];
    }

    public function deepResearchReasoningProvider(): string
    {
        return $this->getAuditSettings()['deepResearchReasoningProvider'];
    }

    public function deepResearchReasoningModel(): ?string
    {
        return $this->getAuditSettings()['deepResearchReasoningModel'];
    }

    public function deepResearchFormatterProvider(): string
    {
        return $this->getAuditSettings()['deepResearchFormatterProvider'];
    }

    public function deepResearchFormatterModel(): ?string
    {
        return $this->getAuditSettings()['deepResearchFormatterModel'];
    }

    public function aiProvider(): string
    {
        return $this->getAuditSettings()['aiProvider'];
    }

    public function aiModel(): ?string
    {
        return $this->getAuditSettings()['aiModel'];
    }

    public function step2AiModel(): ?string
    {
        return $this->getAuditSettings()['step2AiModel'];
    }

    public function step2AiProvider(): string
    {
        return $this->getAuditSettings()['step2AiProvider'];
    }

    public function step3AiModel(): ?string
    {
        return $this->getAuditSettings()['step3AiModel'];
    }

    public function step3AiProvider(): string
    {
        return $this->getAuditSettings()['step3AiProvider'];
    }

    private function normalizeAiProvider(mixed $value): string
    {
        return in_array($value, ['openai', 'gemini', 'gemini_deep_research'], true) ? (string) $value : 'openai';
    }

    private function normalizeFormatterProvider(mixed $value): string
    {
        return in_array($value, ['openai', 'gemini'], true) ? (string) $value : 'gemini';
    }

    private function normalizeDeepResearchResearchProvider(mixed $value): string
    {
        return in_array($value, ['perplexity', 'gemini_deep_research'], true) ? (string) $value : 'perplexity';
    }

    private function normalizeDeepResearchReasoningProvider(mixed $value): string
    {
        return in_array($value, ['openai', 'gemini'], true) ? (string) $value : 'openai';
    }

    private function normalizeStep3FlowMode(mixed $value): string
    {
        return in_array($value, AuditRun::WORKFLOWS, true)
            ? (string) $value
            : AuditRun::WORKFLOW_STANDARD;
    }

    private function normalizeModel(mixed $value, string $default): ?string
    {
        $model = trim((string) ($value ?? ''));

        return $model !== '' ? $model : $default;
    }

    private function normalizeOptionalModel(mixed $value): ?string
    {
        $model = trim((string) ($value ?? ''));

        return $model !== '' ? $model : null;
    }

    private function defaultFormatterModel(string $provider): string
    {
        return $provider === 'openai'
            ? (string) config('services.openai.model', 'gpt-5.5')
            : 'gemini-2.5-flash';
    }

    private function defaultDeepResearchResearchModel(string $provider): string
    {
        return $provider === 'gemini_deep_research'
            ? (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025')
            : (string) config('services.audit.deep_research_research_model', config('services.perplexity.model', 'sonar-deep-research'));
    }

    private function defaultDeepResearchReasoningModel(string $provider): string
    {
        return $provider === 'gemini'
            ? (string) config('services.gemini.model', 'gemini-2.5-pro')
            : (string) config('services.audit.deep_research_reasoning_model', config('services.openai.model', 'gpt-5.5'));
    }

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            'gemini' => (string) config('services.gemini.model', 'gemini-2.5-pro'),
            'gemini_deep_research' => (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025'),
            default => (string) config('services.openai.model', 'gpt-5.5'),
        };
    }
}
