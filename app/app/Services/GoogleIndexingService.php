<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleIndexingService
{
    private const INDEXING_SCOPE = 'https://www.googleapis.com/auth/indexing';

    /**
     * @return array{status:int,data:array<string,mixed>}
     */
    public function publishUrl(string $credentialsPath, string $urlExact, string $notificationType = 'URL_UPDATED'): array
    {
        $token = $this->accessToken($credentialsPath);
        $response = $this->googleHttp($token)->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'url' => $urlExact,
            'type' => $notificationType,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        return [
            'status' => $response->status(),
            'data' => is_array($response->json()) ? $response->json() : [],
        ];
    }

    private function accessToken(string $credentialsPath): string
    {
        $payload = app(GoogleSearchConsoleService::class)->readCredentialsPayload($credentialsPath);
        $credentials = new ServiceAccountCredentials([self::INDEXING_SCOPE], $payload);
        $token = $credentials->fetchAuthToken($this->authHttpHandler());

        if (empty($token['access_token'])) {
            throw new RuntimeException('Không lấy được access token Indexing API.');
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
        $message = is_array($payload) ? ($payload['error']['message'] ?? null) : null;

        if (is_string($message) && $message !== '') {
            if (str_contains($message, 'Indexing API has not been used') || str_contains($message, 'accessNotConfigured')) {
                return 'Chưa bật Web Search Indexing API trên Google Cloud.';
            }

            return $message;
        }

        return "Indexing API trả về lỗi HTTP {$status}.";
    }
}
