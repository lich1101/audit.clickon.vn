<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class AuditSettingsService
{
    private const CACHE_KEY = 'system_settings.audit';

    /**
     * @return array{aiProvider: string, aiModel: string|null, step2AiModel: string|null, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int}
     */
    public function getAuditSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $record = SystemSetting::query()->where('key', 'audit')->first();
            $value = is_array($record?->value) ? $record->value : [];

            $provider = in_array($value['aiProvider'] ?? null, ['openai', 'gemini', 'gemini_deep_research'], true)
                ? $value['aiProvider']
                : 'openai';

            $maxParallel = (int) ($value['maxParallelItems'] ?? 3);
            $maxParallel = max(1, min(10, $maxParallel));

            $step2BatchSize = (int) ($value['step2BatchSize'] ?? 60);
            $step2BatchSize = max(1, min(300, $step2BatchSize));

            $step3BatchSize = (int) ($value['step3BatchSize'] ?? 30);
            $step3BatchSize = max(1, min(300, $step3BatchSize));

            $aiModel = $this->normalizeOptionalModel($value['aiModel'] ?? '');
            $step2AiModel = $this->normalizeOptionalModel($value['step2AiModel'] ?? env('AUDIT_STEP2_AI_MODEL', $aiModel));
            $step3AiModel = $this->normalizeOptionalModel($value['step3AiModel'] ?? env('AUDIT_STEP3_AI_MODEL', $aiModel));
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
                'step2AiModel' => $step2AiModel,
                'step3AiModel' => $step3AiModel,
                'step2FormatterProvider' => $step2FormatterProvider,
                'step2FormatterModel' => $step2FormatterModel,
                'step3FormatterProvider' => $step3FormatterProvider,
                'step3FormatterModel' => $step3FormatterModel,
                'maxParallelItems' => $maxParallel,
                'step2BatchSize' => $step2BatchSize,
                'step3BatchSize' => $step3BatchSize,
            ];
        });
    }

    /**
     * @param  array{aiProvider?: string, aiModel?: string|null, step2AiModel?: string|null, step3AiModel?: string|null, step2FormatterProvider?: string, step2FormatterModel?: string|null, step3FormatterProvider?: string, step3FormatterModel?: string|null, maxParallelItems?: int, step2BatchSize?: int, step3BatchSize?: int}  $payload
     * @return array{aiProvider: string, aiModel: string|null, step2AiModel: string|null, step3AiModel: string|null, step2FormatterProvider: string, step2FormatterModel: string|null, step3FormatterProvider: string, step3FormatterModel: string|null, maxParallelItems: int, step2BatchSize: int, step3BatchSize: int}
     */
    public function updateAuditSettings(array $payload): array
    {
        $current = $this->getAuditSettings();

        $provider = in_array($payload['aiProvider'] ?? null, ['openai', 'gemini', 'gemini_deep_research'], true)
            ? $payload['aiProvider']
            : $current['aiProvider'];

        $maxParallel = isset($payload['maxParallelItems'])
            ? max(1, min(10, (int) $payload['maxParallelItems']))
            : $current['maxParallelItems'];

        $step2BatchSize = isset($payload['step2BatchSize'])
            ? max(1, min(300, (int) $payload['step2BatchSize']))
            : $current['step2BatchSize'];

        $step3BatchSize = isset($payload['step3BatchSize'])
            ? max(1, min(300, (int) $payload['step3BatchSize']))
            : $current['step3BatchSize'];

        $aiModel = array_key_exists('aiModel', $payload)
            ? $this->normalizeOptionalModel($payload['aiModel'])
            : $current['aiModel'];
        $step2AiModel = array_key_exists('step2AiModel', $payload)
            ? $this->normalizeOptionalModel($payload['step2AiModel'])
            : $current['step2AiModel'];
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

        $value = [
            'aiProvider' => $provider,
            'aiModel' => $aiModel,
            'step2AiModel' => $step2AiModel ?: $aiModel,
            'step3AiModel' => $step3AiModel ?: $aiModel,
            'step2FormatterProvider' => $step2FormatterProvider,
            'step2FormatterModel' => $step2FormatterModel,
            'step3FormatterProvider' => $step3FormatterProvider,
            'step3FormatterModel' => $step3FormatterModel,
            'maxParallelItems' => $maxParallel,
            'step2BatchSize' => $step2BatchSize,
            'step3BatchSize' => $step3BatchSize,
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'audit'],
            ['value' => $value],
        );

        Cache::forget(self::CACHE_KEY);

        return $value;
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

    public function step3AiModel(): ?string
    {
        return $this->getAuditSettings()['step3AiModel'];
    }

    private function normalizeFormatterProvider(mixed $value): string
    {
        return in_array($value, ['openai', 'gemini'], true) ? (string) $value : 'gemini';
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
}
