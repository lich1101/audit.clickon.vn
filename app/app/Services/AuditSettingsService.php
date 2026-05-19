<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class AuditSettingsService
{
    private const CACHE_KEY = 'system_settings.audit';

    /**
     * @return array{aiProvider: string, aiModel: string|null, maxParallelItems: int}
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

            $aiModel = trim((string) ($value['aiModel'] ?? ''));

            return [
                'aiProvider' => $provider,
                'aiModel' => $aiModel !== '' ? $aiModel : null,
                'maxParallelItems' => $maxParallel,
            ];
        });
    }

    /**
     * @param  array{aiProvider?: string, aiModel?: string|null, maxParallelItems?: int}  $payload
     * @return array{aiProvider: string, aiModel: string|null, maxParallelItems: int}
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

        $aiModel = array_key_exists('aiModel', $payload)
            ? (trim((string) ($payload['aiModel'] ?? '')) ?: null)
            : $current['aiModel'];

        $value = [
            'aiProvider' => $provider,
            'aiModel' => $aiModel,
            'maxParallelItems' => $maxParallel,
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

    public function aiProvider(): string
    {
        return $this->getAuditSettings()['aiProvider'];
    }

    public function aiModel(): ?string
    {
        return $this->getAuditSettings()['aiModel'];
    }
}
