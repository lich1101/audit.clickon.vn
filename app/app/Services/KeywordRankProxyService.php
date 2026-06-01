<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KeywordRankProxyService
{
    public const HTTP_SOURCE_URL = 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt';

    public const SOCKS5_SOURCE_URL = 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt';

    private const POOL_CACHE_KEY = 'system_settings.keyword_rank_proxies';

    private const CONFIG_CACHE_KEY = 'system_settings.keyword_rank_proxy';

    private const POOL_SETTING_KEY = 'keyword_rank_proxies';

    private const CONFIG_SETTING_KEY = 'keyword_rank_proxy';

    private const MAX_STORED = 4000;

    private const MAX_MANUAL = 500;

    private const DEFAULT_RUN_SAMPLE = 120;

    /**
     * @return array{
     *   enabled: bool,
     *   useGithubHttp: bool,
     *   useGithubSocks5: bool,
     *   refreshGithubOnRun: bool,
     *   manualProxies: array<int, string>,
     *   runSampleSize: int
     * }
     */
    public function getAdminConfig(): array
    {
        return Cache::remember(self::CONFIG_CACHE_KEY, 30, function (): array {
            $record = SystemSetting::query()->where('key', self::CONFIG_SETTING_KEY)->first();
            $value = is_array($record?->value) ? $record->value : [];

            return $this->normalizeAdminConfig($value);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   enabled: bool,
     *   useGithubHttp: bool,
     *   useGithubSocks5: bool,
     *   refreshGithubOnRun: bool,
     *   manualProxies: array<int, string>,
     *   runSampleSize: int
     * }
     */
    public function updateAdminConfig(array $payload): array
    {
        $current = $this->getAdminConfig();
        $manualText = array_key_exists('manualProxiesText', $payload)
            ? (string) ($payload['manualProxiesText'] ?? '')
            : implode("\n", $current['manualProxies']);

        $next = $this->normalizeAdminConfig([
            'enabled' => array_key_exists('enabled', $payload) ? $payload['enabled'] : $current['enabled'],
            'useGithubHttp' => array_key_exists('useGithubHttp', $payload) ? $payload['useGithubHttp'] : $current['useGithubHttp'],
            'useGithubSocks5' => array_key_exists('useGithubSocks5', $payload) ? $payload['useGithubSocks5'] : $current['useGithubSocks5'],
            'refreshGithubOnRun' => array_key_exists('refreshGithubOnRun', $payload) ? $payload['refreshGithubOnRun'] : $current['refreshGithubOnRun'],
            'runSampleSize' => array_key_exists('runSampleSize', $payload) ? $payload['runSampleSize'] : $current['runSampleSize'],
            'manualProxies' => $this->parseManualProxyText($manualText),
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => self::CONFIG_SETTING_KEY],
            ['value' => $next],
        );

        Cache::forget(self::CONFIG_CACHE_KEY);

        return $next;
    }

    /**
     * Proxy cho một lần Run — chỉ theo cấu hình admin.
     *
     * @return array{
     *   proxyEnabled: bool,
     *   proxyUrls: array<int, string>,
     *   fetchedAt: string|null,
     *   httpCount: int,
     *   socks5Count: int,
     *   totalCount: int,
     *   runProxyCount: int,
     *   manualCount: int,
     *   usedCache: bool,
     *   sources: array{http: string, socks5: string}
     * }
     */
    public function resolveForRun(): array
    {
        $config = $this->getAdminConfig();
        $sources = [
            'http' => self::HTTP_SOURCE_URL,
            'socks5' => self::SOCKS5_SOURCE_URL,
        ];

        if (! $config['enabled']) {
            return [
                'proxyEnabled' => false,
                'proxyUrls' => [],
                'fetchedAt' => null,
                'httpCount' => 0,
                'socks5Count' => 0,
                'totalCount' => 0,
                'runProxyCount' => 0,
                'manualCount' => 0,
                'usedCache' => false,
                'sources' => $sources,
            ];
        }

        $manual = $config['manualProxies'];
        $github = [];
        $usedCache = false;
        $fetchedAt = null;
        $httpCount = 0;
        $socks5Count = 0;

        if ($config['useGithubHttp'] || $config['useGithubSocks5']) {
            if ($config['refreshGithubOnRun']) {
                $refreshed = $this->refreshGithubPool($config['useGithubHttp'], $config['useGithubSocks5']);
                $github = $refreshed['proxies'];
                $fetchedAt = $refreshed['fetchedAt'];
                $httpCount = $refreshed['httpCount'];
                $socks5Count = $refreshed['socks5Count'];
                $usedCache = $refreshed['usedCache'];
            } else {
                $pool = $this->getPool();
                $github = $pool['proxies'];
                $fetchedAt = $pool['fetchedAt'];
                $httpCount = $pool['httpCount'];
                $socks5Count = $pool['socks5Count'];
                $usedCache = true;
            }
        }

        $combined = array_values(array_unique(array_merge($manual, $github)));

        if ($combined === []) {
            throw new RuntimeException(
                'Admin đã bật proxy nhưng chưa có proxy hợp lệ. Thêm proxy thủ công hoặc bật nguồn GitHub rồi cào pool.',
            );
        }

        $runProxies = $this->sampleFromList($combined, $config['runSampleSize']);

        return [
            'proxyEnabled' => true,
            'proxyUrls' => $runProxies,
            'fetchedAt' => $fetchedAt,
            'httpCount' => $httpCount,
            'socks5Count' => $socks5Count,
            'totalCount' => count($combined),
            'runProxyCount' => count($runProxies),
            'manualCount' => count($manual),
            'usedCache' => $usedCache,
            'sources' => $sources,
        ];
    }

    /**
     * Cào GitHub theo nguồn admin chọn (không phụ thuộc user Run).
     *
     * @return array{
     *   fetchedAt: string,
     *   httpCount: int,
     *   socks5Count: int,
     *   totalCount: int,
     *   proxies: array<int, string>,
     *   usedCache: bool
     * }
     */
    public function refreshGithubPool(bool $useHttp = true, bool $useSocks5 = true): array
    {
        $config = $this->getAdminConfig();
        $useHttp = $useHttp && $config['useGithubHttp'];
        $useSocks5 = $useSocks5 && $config['useGithubSocks5'];

        $httpProxies = [];
        $socks5Proxies = [];

        if ($useHttp) {
            $httpProxies = $this->linesToProxies(
                $this->fetchSourceLines(self::HTTP_SOURCE_URL, 'HTTP'),
                'http',
            );
        }

        if ($useSocks5) {
            $socks5Proxies = $this->linesToProxies(
                $this->fetchSourceLines(self::SOCKS5_SOURCE_URL, 'SOCKS5'),
                'socks5',
            );
        }

        $merged = array_values(array_unique(array_merge($httpProxies, $socks5Proxies)));
        $fetchedAt = now()->toIso8601String();
        $usedCache = false;
        $stored = [];

        if ($merged !== []) {
            shuffle($merged);
            $stored = array_slice($merged, 0, self::MAX_STORED);

            try {
                SystemSetting::query()->updateOrCreate(
                    ['key' => self::POOL_SETTING_KEY],
                    ['value' => [
                        'fetchedAt' => $fetchedAt,
                        'httpSource' => self::HTTP_SOURCE_URL,
                        'socks5Source' => self::SOCKS5_SOURCE_URL,
                        'httpCount' => count($httpProxies),
                        'socks5Count' => count($socks5Proxies),
                        'totalCount' => count($stored),
                        'proxies' => $stored,
                    ]],
                );
                Cache::forget(self::POOL_CACHE_KEY);
            } catch (\Throwable $exception) {
                Log::error('Keyword rank proxy pool save failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        } else {
            $cached = $this->getPool();
            $stored = $cached['proxies'];
            $usedCache = $stored !== [];
            $fetchedAt = $cached['fetchedAt'] ?? $fetchedAt;
            $httpCount = $cached['httpCount'];
            $socks5Count = $cached['socks5Count'];

            return [
                'fetchedAt' => $fetchedAt,
                'httpCount' => $httpCount,
                'socks5Count' => $socks5Count,
                'totalCount' => count($stored),
                'proxies' => $stored,
                'usedCache' => $usedCache,
            ];
        }

        if ($stored === []) {
            throw new RuntimeException('Không lấy được proxy từ GitHub. Thử lại sau vài phút.');
        }

        return [
            'fetchedAt' => $fetchedAt,
            'httpCount' => count($httpProxies),
            'socks5Count' => count($socks5Proxies),
            'totalCount' => count($stored),
            'proxies' => $stored,
            'usedCache' => $usedCache,
        ];
    }

    /**
     * @return array{
     *   fetchedAt: string|null,
     *   httpCount: int,
     *   socks5Count: int,
     *   totalCount: int,
     *   proxies: array<int, string>,
     *   sources: array{http: string, socks5: string}
     * }
     */
    public function getPool(): array
    {
        return Cache::remember(self::POOL_CACHE_KEY, 30, function (): array {
            $record = SystemSetting::query()->where('key', self::POOL_SETTING_KEY)->first();
            $value = is_array($record?->value) ? $record->value : [];
            $proxies = [];

            if (is_array($value['proxies'] ?? null)) {
                foreach ($value['proxies'] as $line) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $proxies[] = $line;
                    }
                }
            }

            return [
                'fetchedAt' => isset($value['fetchedAt']) && is_string($value['fetchedAt']) ? $value['fetchedAt'] : null,
                'httpCount' => (int) ($value['httpCount'] ?? 0),
                'socks5Count' => (int) ($value['socks5Count'] ?? 0),
                'totalCount' => count($proxies),
                'proxies' => $proxies,
                'sources' => [
                    'http' => (string) ($value['httpSource'] ?? self::HTTP_SOURCE_URL),
                    'socks5' => (string) ($value['socks5Source'] ?? self::SOCKS5_SOURCE_URL),
                ],
            ];
        });
    }

    public function parseManualProxyText(string $text): array
    {
        $lines = preg_split('/\R+/', $text) ?: [];
        $proxies = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $normalized = null;

            if (preg_match('/^(https?|socks4|socks5):\/\//i', $line)) {
                $normalized = $this->normalizeProxyUrl($line);
            } elseif (preg_match('/^([\d.]+|[\da-f:]+):(\d{2,5})$/i', $line)) {
                $normalized = $this->normalizeProxyUrl('http://'.$line);
            }

            if ($normalized === null) {
                continue;
            }

            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $proxies[] = $normalized;

            if (count($proxies) >= self::MAX_MANUAL) {
                break;
            }
        }

        return $proxies;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{
     *   enabled: bool,
     *   useGithubHttp: bool,
     *   useGithubSocks5: bool,
     *   refreshGithubOnRun: bool,
     *   manualProxies: array<int, string>,
     *   runSampleSize: int
     * }
     */
    private function normalizeAdminConfig(array $value): array
    {
        $manual = [];
        if (is_array($value['manualProxies'] ?? null)) {
            $manual = $this->parseManualProxyText(implode("\n", $value['manualProxies']));
        }

        $enabled = (bool) ($value['enabled'] ?? false);
        $useGithubHttp = (bool) ($value['useGithubHttp'] ?? true);
        $useSocks5 = (bool) ($value['useGithubSocks5'] ?? true);
        $refreshOnRun = (bool) ($value['refreshGithubOnRun'] ?? true);

        if ($enabled && ! $useGithubHttp && ! $useSocks5 && $manual === []) {
            $enabled = false;
        }

        return [
            'enabled' => $enabled,
            'useGithubHttp' => $useGithubHttp,
            'useGithubSocks5' => $useSocks5,
            'refreshGithubOnRun' => $refreshOnRun,
            'manualProxies' => $manual,
            'runSampleSize' => max(10, min(300, (int) ($value['runSampleSize'] ?? self::DEFAULT_RUN_SAMPLE))),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fetchSourceLines(string $url, string $label): array
    {
        try {
            $response = Http::timeout(45)
                ->withHeaders(['User-Agent' => 'ClickonAuditBot/1.0 (+https://audit.clickon.vn)'])
                ->get($url);

            if (! $response->successful()) {
                Log::warning("Keyword rank proxy source {$label} failed.", [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return preg_split('/\R+/', (string) $response->body()) ?: [];
        } catch (\Throwable $exception) {
            Log::warning("Keyword rank proxy source {$label} exception.", [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function linesToProxies(array $lines, string $scheme): array
    {
        $proxies = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(https?|socks4|socks5):\/\//i', $line)) {
                $normalized = $this->normalizeProxyUrl($line);

                if ($normalized === null) {
                    continue;
                }

                $key = strtolower($normalized);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $proxies[] = $normalized;

                continue;
            }

            if (! preg_match('/^([\d.]+|[\da-f:]+):(\d{2,5})$/i', $line, $matches)) {
                continue;
            }

            $host = $matches[1];
            $port = (int) $matches[2];

            if ($port < 1 || $port > 65535) {
                continue;
            }

            $normalized = $this->normalizeProxyUrl(sprintf('%s://%s:%d', $scheme, $host, $port));

            if ($normalized === null) {
                continue;
            }

            $key = strtolower($normalized);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $proxies[] = $normalized;
        }

        return $proxies;
    }

    private function normalizeProxyUrl(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || strlen($raw) > 512) {
            return null;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $raw)) {
            $raw = 'http://'.$raw;
        }

        $parts = parse_url($raw);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));

        if (! in_array($scheme, ['http', 'https', 'socks4', 'socks5'], true)) {
            return null;
        }

        $port = (int) ($parts['port'] ?? match ($scheme) {
            'https' => 443,
            'socks4', 'socks5' => 1080,
            default => 80,
        });

        if ($port < 1 || $port > 65535) {
            return null;
        }

        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';
        $auth = $user !== '' ? $user.($pass !== '' ? ':'.$pass : '').'@' : '';

        return sprintf('%s://%s%s:%d', $scheme, $auth, $parts['host'], $port);
    }

    /**
     * @param  array<int, string>  $proxies
     * @return array<int, string>
     */
    private function sampleFromList(array $proxies, int $limit): array
    {
        if ($proxies === []) {
            return [];
        }

        $copy = $proxies;
        shuffle($copy);

        return array_slice($copy, 0, min($limit, count($copy)));
    }
}
