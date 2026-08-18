<?php

namespace App\Services;

use App\Models\IndexProperty;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

class IndexSettingsService
{
    private const CACHE_PREFIX = 'index_settings.user.';

    public function __construct(
        private readonly GoogleSearchConsoleService $googleSearchConsoleService,
    ) {
    }

    public function settingsKey(string $userUid): string
    {
        return "index_user_{$userUid}";
    }

    public function credentialsPath(string $userUid): string
    {
        $safeUid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userUid) ?: 'user';

        return storage_path("app/index-credentials/{$safeUid}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $userUid): array
    {
        $record = SystemSetting::query()->where('key', $this->settingsKey($userUid))->first();
        $value = is_array($record?->value) ? $record->value : [];
        $path = $this->credentialsPath($userUid);
        $configured = is_file($path);

        if ($configured && (empty($value['serviceAccountEmail']) || empty($value['projectId']))) {
            try {
                $payload = $this->googleSearchConsoleService->readCredentialsPayload($path);
                $value['serviceAccountEmail'] = (string) ($payload['client_email'] ?? '');
                $value['projectId'] = (string) ($payload['project_id'] ?? '');
            } catch (\Throwable) {
                // ignore invalid json here; save flow validates separately
            }
        }

        return [
            'configured' => $configured,
            'serviceAccountEmail' => $configured ? (string) ($value['serviceAccountEmail'] ?? '') : null,
            'projectId' => $configured ? (string) ($value['projectId'] ?? '') : null,
            'dryRun' => array_key_exists('dryRun', $value)
                ? (bool) $value['dryRun']
                : (bool) config('index.dry_run'),
            'updatedAt' => isset($value['updatedAt']) ? (string) $value['updatedAt'] : null,
            'gscSiteCount' => $this->ownedSiteCount($userUid),
        ];
    }

    public function ownedSiteCount(string $userUid): int
    {
        return IndexProperty::query()
            ->where('user_uid', $userUid)
            ->where('is_owned', true)
            ->where('enabled', true)
            ->count();
    }

    public function persistOwnedSiteCount(string $userUid): int
    {
        $count = $this->ownedSiteCount($userUid);
        $record = SystemSetting::query()->where('key', $this->settingsKey($userUid))->first();
        if ($record) {
            $value = is_array($record->value) ? $record->value : [];
            $value['gscSiteCount'] = $count;
            $record->forceFill(['value' => $value])->save();
            Cache::forget(self::CACHE_PREFIX.$userUid);
        }

        return $count;
    }

    public function resolveCredentialsPath(string $userUid): ?string
    {
        $userPath = $this->credentialsPath($userUid);
        if (is_file($userPath)) {
            return $userPath;
        }

        $fallback = config('index.sa_json_path');
        if (is_string($fallback) && is_file($fallback)) {
            return $fallback;
        }

        return null;
    }

    public function isDryRun(string $userUid): bool
    {
        return (bool) ($this->getSettings($userUid)['dryRun'] ?? config('index.dry_run'));
    }

    /**
     * @return array<string, mixed>
     */
    public function saveCredentials(string $userUid, string $serviceAccountJson, ?bool $dryRun = null): array
    {
        $payload = json_decode(trim($serviceAccountJson), true);
        if (! is_array($payload)) {
            throw new RuntimeException('JSON không hợp lệ. Dán toàn bộ nội dung file Service Account.');
        }

        foreach (['type', 'project_id', 'private_key', 'client_email'] as $field) {
            if (empty($payload[$field])) {
                throw new RuntimeException("JSON thiếu trường bắt buộc: {$field}.");
            }
        }

        if (($payload['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('Đây không phải file Service Account (type phải là service_account).');
        }

        $directory = storage_path('app/index-credentials');
        File::ensureDirectoryExists($directory);

        $path = $this->credentialsPath($userUid);
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $settings = [
            'serviceAccountEmail' => (string) $payload['client_email'],
            'projectId' => (string) $payload['project_id'],
            'dryRun' => $dryRun ?? $this->getSettings($userUid)['dryRun'] ?? (bool) config('index.dry_run'),
            'updatedAt' => now()->toIso8601String(),
        ];

        $test = null;
        $testError = null;

        try {
            $test = $this->googleSearchConsoleService->testConnection($path);
            $settings['gscSiteCount'] = $this->ownedSiteCount($userUid);
        } catch (\Throwable $exception) {
            $testError = $exception->getMessage();
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => $this->settingsKey($userUid)],
            ['value' => $settings]
        );

        Cache::forget(self::CACHE_PREFIX.$userUid);

        if ($testError !== null) {
            return [
                'ok' => true,
                'partial' => true,
                'settings' => $this->getSettings($userUid),
                'message' => 'Đã lưu Service Account JSON, nhưng test GSC thất bại: '.$testError,
            ];
        }

        return [
            'ok' => true,
            'settings' => $this->getSettings($userUid),
            'test' => $test,
            'message' => 'Đã lưu Service Account và kết nối GSC thành công.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePreferences(string $userUid, ?bool $dryRun = null): array
    {
        $record = SystemSetting::query()->where('key', $this->settingsKey($userUid))->first();
        $value = is_array($record?->value) ? $record->value : [];
        $path = $this->credentialsPath($userUid);

        if ($configured = is_file($path)) {
            try {
                $payload = $this->googleSearchConsoleService->readCredentialsPayload($path);
                $value['serviceAccountEmail'] = (string) ($payload['client_email'] ?? $value['serviceAccountEmail'] ?? '');
                $value['projectId'] = (string) ($payload['project_id'] ?? $value['projectId'] ?? '');
            } catch (\Throwable) {
                // keep existing metadata
            }
        }

        if ($dryRun !== null) {
            $value['dryRun'] = $dryRun;
        }

        $value['updatedAt'] = now()->toIso8601String();

        SystemSetting::query()->updateOrCreate(
            ['key' => $this->settingsKey($userUid)],
            ['value' => $value]
        );

        Cache::forget(self::CACHE_PREFIX.$userUid);

        return [
            'ok' => true,
            'settings' => $this->getSettings($userUid),
            'message' => $dryRun === false
                ? 'Đã tắt DRY_RUN — sẽ gửi API lập chỉ mục Google thật.'
                : 'Đã bật DRY_RUN (mô phỏng, không gửi Google).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(string $userUid): array
    {
        $path = $this->resolveCredentialsPath($userUid);
        if (! $path) {
            throw new RuntimeException('Chưa cấu hình Service Account JSON.');
        }

        $test = $this->googleSearchConsoleService->testConnection($path);

        $record = SystemSetting::query()->where('key', $this->settingsKey($userUid))->first();
        $value = is_array($record?->value) ? $record->value : [];

        $ownedCount = $this->ownedSiteCount($userUid);

        if ($record) {
            $value['gscSiteCount'] = $ownedCount;
            $value['updatedAt'] = now()->toIso8601String();
            $record->forceFill(['value' => $value])->save();
        } else {
            $payload = $this->googleSearchConsoleService->readCredentialsPayload($path);
            SystemSetting::query()->updateOrCreate(
                ['key' => $this->settingsKey($userUid)],
                ['value' => [
                    'serviceAccountEmail' => (string) ($payload['client_email'] ?? ''),
                    'projectId' => (string) ($payload['project_id'] ?? ''),
                    'dryRun' => (bool) config('index.dry_run'),
                    'gscSiteCount' => $ownedCount,
                    'updatedAt' => now()->toIso8601String(),
                ]]
            );
        }

        Cache::forget(self::CACHE_PREFIX.$userUid);

        return $test;
    }

    public function clearCredentials(string $userUid): void
    {
        $path = $this->credentialsPath($userUid);
        if (is_file($path)) {
            File::delete($path);
        }

        SystemSetting::query()->where('key', $this->settingsKey($userUid))->delete();
        Cache::forget(self::CACHE_PREFIX.$userUid);
    }
}
