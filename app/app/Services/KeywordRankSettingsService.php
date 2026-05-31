<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class KeywordRankSettingsService
{
    private const CACHE_KEY = 'system_settings.keyword_rank';

    public const SERP_PAGES = 10;

    /**
     * @return array{extensionInstallUrl:string,serpPages:int}
     */
    public function getSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $record = SystemSetting::query()->where('key', 'keyword_rank')->first();
            $value = is_array($record?->value) ? $record->value : [];

            return [
                'extensionInstallUrl' => trim((string) ($value['extensionInstallUrl'] ?? '')),
                'serpPages' => self::SERP_PAGES,
            ];
        });
    }

    /**
     * @param  array{extensionInstallUrl?:string|null}  $payload
     * @return array{extensionInstallUrl:string,serpPages:int}
     */
    public function updateSettings(array $payload): array
    {
        $current = $this->getSettings();
        $value = [
            'extensionInstallUrl' => array_key_exists('extensionInstallUrl', $payload)
                ? trim((string) ($payload['extensionInstallUrl'] ?? ''))
                : $current['extensionInstallUrl'],
            'serpPages' => self::SERP_PAGES,
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'keyword_rank'],
            ['value' => $value],
        );

        Cache::forget(self::CACHE_KEY);

        return $value;
    }
}
