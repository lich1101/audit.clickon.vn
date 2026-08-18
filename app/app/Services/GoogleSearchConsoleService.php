<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSearchConsoleService
{
    private const WEBMASTERS_SCOPE = 'https://www.googleapis.com/auth/webmasters';

    /**
     * @return list<array{siteUrl:string,permissionLevel:?string}>
     */
    public function listSites(string $credentialsPath): array
    {
        $token = $this->accessToken($credentialsPath, [self::WEBMASTERS_SCOPE]);
        $response = $this->googleHttp($token)->get('https://www.googleapis.com/webmasters/v3/sites');

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $entries = $response->json('siteEntry') ?? [];

        return collect($entries)
            ->map(fn (array $entry) => [
                'siteUrl' => (string) ($entry['siteUrl'] ?? ''),
                'permissionLevel' => isset($entry['permissionLevel']) ? (string) $entry['permissionLevel'] : null,
            ])
            ->filter(fn (array $entry) => $entry['siteUrl'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{siteUrl:string,permissionLevel:?string}>  $sites
     */
    public function isOwnedSite(array $sites, string $siteHost, string $siteOrigin): bool
    {
        return $this->findMatchingSite($sites, $siteHost, $siteOrigin) !== null;
    }

    public function siteHostFromGscEntry(string $siteUrl): ?string
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return strtolower(substr($siteUrl, strlen('sc-domain:')));
        }

        $parts = parse_url($siteUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['host']);
    }

    /**
     * @return array{siteUrl:string,permissionLevel:?string}|null
     */
    public function findMatchingSite(array $sites, string $siteHost, string $siteOrigin): ?array
    {
        foreach ($sites as $site) {
            $permission = $site['permissionLevel'] ?? null;
            if (! $permission || $permission === 'siteUnverifiedUser') {
                continue;
            }

            $entryHost = $this->siteHostFromGscEntry($site['siteUrl']);
            if ($entryHost && $entryHost === strtolower($siteHost)) {
                return $site;
            }

            $normalizedOrigin = rtrim($siteOrigin, '/');
            $entryUrl = rtrim($site['siteUrl'], '/');
            if ($entryUrl === $normalizedOrigin || $entryUrl === $siteOrigin) {
                return $site;
            }
        }

        return null;
    }

    public function testConnection(string $credentialsPath): array
    {
        $payload = $this->readCredentialsPayload($credentialsPath);
        $sites = $this->listSites($credentialsPath);

        return [
            'ok' => true,
            'serviceAccountEmail' => (string) ($payload['client_email'] ?? ''),
            'projectId' => (string) ($payload['project_id'] ?? ''),
            'siteCount' => count($sites),
            'sites' => $sites,
            'message' => 'Kết nối Google Search Console thành công.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readCredentialsPayload(string $credentialsPath): array
    {
        if (! is_file($credentialsPath)) {
            throw new RuntimeException('File Service Account JSON không tồn tại.');
        }

        $payload = json_decode((string) file_get_contents($credentialsPath), true);
        if (! is_array($payload)) {
            throw new RuntimeException('Service Account JSON không hợp lệ.');
        }

        foreach (['type', 'project_id', 'private_key', 'client_email'] as $field) {
            if (empty($payload[$field])) {
                throw new RuntimeException("Service Account JSON thiếu trường {$field}.");
            }
        }

        if (($payload['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('File JSON phải là Service Account (type=service_account).');
        }

        return $payload;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function accessToken(string $credentialsPath, array $scopes): string
    {
        $payload = $this->readCredentialsPayload($credentialsPath);
        $credentials = new ServiceAccountCredentials($scopes, $payload);
        $token = $credentials->fetchAuthToken($this->authHttpHandler());

        if (empty($token['access_token'])) {
            throw new RuntimeException('Không lấy được access token từ Google.');
        }

        return (string) $token['access_token'];
    }

    private function googleHttp(string $token): PendingRequest
    {
        $request = Http::withToken($token)->acceptJson();
        $caBundle = $this->caBundlePath();

        if ($caBundle !== null) {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        return $request;
    }

    private function authHttpHandler(): callable
    {
        $options = ['timeout' => 30];
        $caBundle = $this->caBundlePath();
        if ($caBundle !== null) {
            $options['verify'] = $caBundle;
        }

        return HttpHandlerFactory::build(new Client($options));
    }

    private function caBundlePath(): ?string
    {
        $path = config('index.ca_bundle');
        if (is_string($path) && is_file($path)) {
            return $path;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function errorMessage(?array $payload, int $status): string
    {
        $message = is_array($payload) ? ($payload['error']['message'] ?? $payload['error_description'] ?? null) : null;

        if (is_string($message) && $message !== '') {
            if (str_contains($message, 'Search Console API has not been used') || str_contains($message, 'accessNotConfigured')) {
                return 'Chưa bật Google Search Console API trên Google Cloud. Vào APIs & Services → Library → bật "Google Search Console API" cho project của Service Account, đợi 1-2 phút rồi Test lại.';
            }

            if (str_contains($message, 'Indexing API has not been used')) {
                return 'Chưa bật Web Search Indexing API trên Google Cloud. Vào APIs & Services → Library → bật "Web Search Indexing API".';
            }

            if (str_contains($message, 'SSL certificate') || str_contains($message, 'cURL error 60')) {
                return 'Lỗi SSL khi kết nối Google. Khởi động lại Laravel API sau khi cập nhật cấu hình CA bundle.';
            }

            return $message;
        }

        return "Google API trả về lỗi HTTP {$status}.";
    }
}
