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

    private const CACHE_KEY = 'system_settings.keyword_rank_proxies';

    private const SETTING_KEY = 'keyword_rank_proxies';

    private const MAX_STORED = 4000;

    private const DEFAULT_RUN_SAMPLE = 120;

    /**
     * @return array{
     *   fetchedAt: string,
     *   httpCount: int,
     *   socks5Count: int,
     *   totalCount: int,
     *   runProxyCount: int,
     *   proxyUrls: array<int, string>,
     *   sources: array{http: string, socks5: string}
     * }
     */
    public function refreshPool(?int $runSampleSize = null): array
    {
        $runSampleSize = max(10, min(300, $runSampleSize ?? self::DEFAULT_RUN_SAMPLE));

        $httpLines = $this->fetchSourceLines(self::HTTP_SOURCE_URL, 'HTTP');
        $socks5Lines = $this->fetchSourceLines(self::SOCKS5_SOURCE_URL, 'SOCKS5');

        $httpProxies = $this->linesToProxies($httpLines, 'http');
        $socks5Proxies = $this->linesToProxies($socks5Lines, 'socks5');
        $merged = array_values(array_unique(array_merge($httpProxies, $socks5Proxies)));

        $usedCache = false;
        $fetchedAt = now()->toIso8601String();
        $httpCount = count($httpProxies);
        $socks5Count = count($socks5Proxies);
        $stored = [];

        if ($merged !== []) {
            shuffle($merged);
            $stored = array_slice($merged, 0, self::MAX_STORED);

            try {
                SystemSetting::query()->updateOrCreate(
                    ['key' => self::SETTING_KEY],
                    ['value' => [
                        'fetchedAt' => $fetchedAt,
                        'httpSource' => self::HTTP_SOURCE_URL,
                        'socks5Source' => self::SOCKS5_SOURCE_URL,
                        'httpCount' => $httpCount,
                        'socks5Count' => $socks5Count,
                        'totalCount' => count($stored),
                        'proxies' => $stored,
                    ]],
                );
                Cache::forget(self::CACHE_KEY);
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
        }

        if ($stored === []) {
            throw new RuntimeException('Không lấy được proxy nào từ nguồn GitHub. Thử lại sau vài phút.');
        }

        $runProxies = $this->sampleFromList($stored, $runSampleSize);

        return [
            'fetchedAt' => $fetchedAt,
            'httpCount' => $httpCount,
            'socks5Count' => $socks5Count,
            'totalCount' => count($stored),
            'runProxyCount' => count($runProxies),
            'proxyUrls' => $runProxies,
            'usedCache' => $usedCache,
            'sources' => [
                'http' => self::HTTP_SOURCE_URL,
                'socks5' => self::SOCKS5_SOURCE_URL,
            ],
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
        return Cache::remember(self::CACHE_KEY, 30, function (): array {
            $record = SystemSetting::query()->where('key', self::SETTING_KEY)->first();
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

                $key = mb_strtolower($normalized, 'UTF-8');

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

            $key = mb_strtolower($normalized, 'UTF-8');

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
